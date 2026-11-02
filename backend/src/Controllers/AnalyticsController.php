<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\RoomResolver;

/**
 * The room's throughput and latency over a window.
 *
 * `stats` answers "how many right now" — four counters for the header. This
 * answers "how did it behave", which is a different question and needs a time
 * axis: a volume chart, the outcome mix, and how long the actions actually took.
 *
 * Latency comes from `workflow_run_steps`, the only place that knows how long an
 * action took. A `forward` that answers in 4 ms and one that times out at 8 s are
 * both `success` or `failed` in `workflow_runs`; only the step duration tells them
 * apart, and a p95 that creeps up is the first sign of a target degrading.
 *
 * Read-only, so a `read` token and a `viewer` are both welcome. Everything is
 * scoped by `?room=`, and an unknown slug is a `404` exactly like the log — the
 * alternative (an empty chart) looks like a quiet room rather than a typo.
 */
final class AnalyticsController
{
    /** Default window, in hours. */
    private const DEFAULT_HOURS = 24;

    /** A week is the longest window the chart can still draw one bar per hour. */
    private const MAX_HOURS = 168;

    /** How many endpoints and methods the breakdown lists before it summarises. */
    private const TOP_ENDPOINTS = 10;
    private const TOP_METHODS = 10;

    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $hours = (int) ($request->query['hours'] ?? self::DEFAULT_HOURS);
        $hours = max(1, min(self::MAX_HOURS, $hours === 0 ? self::DEFAULT_HOURS : $hours));

        $roomId = $this->roomId($request);
        $pdo = Database::pdo();

        // One `since` for every query, so the chart and the breakdowns cannot
        // describe two slightly different windows.
        $since = new \DateTimeImmutable('-' . $hours . ' hours', new \DateTimeZone('UTC'));

        $requestScope = $roomId === null ? '' : ' AND i.room_id = :room_id';
        $runScope = $roomId === null ? '' : ' AND i.room_id = :room_id';
        $bound = ['since' => $since->format('Y-m-d H:i:sP')] + ($roomId === null ? [] : ['room_id' => $roomId]);

        $requests = $this->requestTotals($pdo, $bound, $requestScope, $hours);
        $runs = $this->runTotals($pdo, $bound, $runScope);
        $actions = $this->actionTotals($pdo, $bound, $runScope);

        Response::json([
            'data' => [
                'window_hours' => $hours,
                'since' => $since->format('c'),
                'generated_at' => gmdate('c'),
                'requests' => $requests,
                'runs' => $runs,
                'actions' => $actions,
                // The same hours, filled with zeroes, so the chart has a bar for a
                // silent hour instead of a gap that reads as a bug.
                'timeline' => $this->timeline($pdo, $bound, $roomId, $hours, $since),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $bound
     * @return array<string, mixed>
     */
    private function requestTotals(\PDO $pdo, array $bound, string $scope, int $hours): array
    {
        $totals = $pdo->prepare(
            "SELECT count(*)::int AS total,
                    count(*) FILTER (WHERE i.duplicate_of IS NOT NULL)::int AS duplicates,
                    count(*) FILTER (WHERE i.replayed_from IS NOT NULL)::int AS replayed,
                    COALESCE(sum(i.body_bytes), 0)::bigint AS bytes
               FROM incoming_requests i
              WHERE i.received_at >= :since" . $scope
        );
        $totals->execute($bound);
        $row = $totals->fetch() ?: [];

        $methods = $pdo->prepare(
            'SELECT upper(i.method) AS method, count(*)::int AS total
               FROM incoming_requests i
              WHERE i.received_at >= :since' . $scope . '
              GROUP BY 1
              ORDER BY total DESC, method
              LIMIT ' . self::TOP_METHODS
        );
        $methods->execute($bound);

        $endpoints = $pdo->prepare(
            'SELECT i.endpoint, count(*)::int AS total
               FROM incoming_requests i
              WHERE i.received_at >= :since' . $scope . '
              GROUP BY 1
              ORDER BY total DESC, endpoint
              LIMIT ' . self::TOP_ENDPOINTS
        );
        $endpoints->execute($bound);

        $total = (int) ($row['total'] ?? 0);

        return [
            'total' => $total,
            'per_hour' => $hours > 0 ? round($total / $hours, 2) : 0,
            'duplicates' => (int) ($row['duplicates'] ?? 0),
            'replayed' => (int) ($row['replayed'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'by_method' => $methods->fetchAll(),
            'top_endpoints' => $endpoints->fetchAll(),
        ];
    }

    /**
     * @param array<string, mixed> $bound
     * @return array<string, mixed>
     */
    private function runTotals(\PDO $pdo, array $bound, string $scope): array
    {
        $stmt = $pdo->prepare(
            "SELECT wr.status, count(*)::int AS total
               FROM workflow_runs wr
               JOIN incoming_requests i ON i.id = wr.request_id
              WHERE wr.created_at >= :since" . $scope . '
              GROUP BY 1'
        );
        $stmt->execute($bound);

        $counts = ['pending' => 0, 'running' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        $finished = $counts['success'] + $counts['failed'] + $counts['skipped'];
        $total = $finished + $counts['pending'] + $counts['running'];

        return [
            'total' => $total,
            // Over *finished* runs only: a run still in the queue has no outcome
            // yet, and counting it as "not successful" makes the rate drop every
            // time the worker is briefly behind.
            'success_rate' => $finished === 0 ? null : round($counts['success'] / $finished, 4),
            'by_status' => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $bound
     * @return array<string, mixed>
     */
    private function actionTotals(\PDO $pdo, array $bound, string $scope): array
    {
        $stmt = $pdo->prepare(
            "SELECT s.type,
                    count(*)::int AS total,
                    count(*) FILTER (WHERE s.status = 'failed')::int AS failed,
                    count(*) FILTER (WHERE s.status = 'skipped')::int AS skipped,
                    COALESCE(round(percentile_cont(0.5) WITHIN GROUP (ORDER BY s.duration_ms)), 0)::int AS p50_ms,
                    COALESCE(round(percentile_cont(0.95) WITHIN GROUP (ORDER BY s.duration_ms)), 0)::int AS p95_ms,
                    COALESCE(max(s.duration_ms), 0)::int AS max_ms
               FROM workflow_run_steps s
               JOIN workflow_runs wr ON wr.id = s.run_id
               JOIN incoming_requests i ON i.id = wr.request_id
              WHERE s.created_at >= :since" . $scope . '
              GROUP BY s.type
              ORDER BY total DESC, s.type'
        );
        $stmt->execute($bound);

        $byType = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $total += (int) $row['total'];
            $byType[] = [
                'type' => (string) $row['type'],
                'total' => (int) $row['total'],
                'failed' => (int) $row['failed'],
                'skipped' => (int) $row['skipped'],
                'p50_ms' => (int) $row['p50_ms'],
                'p95_ms' => (int) $row['p95_ms'],
                'max_ms' => (int) $row['max_ms'],
            ];
        }

        return ['total' => $total, 'by_type' => $byType];
    }

    /**
     * Incoming requests and finished runs per hour, with the silent hours filled in.
     *
     * The buckets are formatted in UTC *by Postgres* (`AT TIME ZONE 'UTC'`) rather
     * than left as a `timestamptz`: the session timezone would otherwise decide
     * what "hour" means, and the keys built here in PHP would silently miss every
     * row on an installation whose database runs in another zone.
     *
     * @param array<string, mixed> $bound
     * @return list<array<string, mixed>>
     */
    private function timeline(\PDO $pdo, array $bound, ?int $roomId, int $hours, \DateTimeImmutable $since): array
    {
        $scope = $roomId === null ? '' : ' AND i.room_id = :room_id';

        $requests = $pdo->prepare(
            "SELECT to_char(date_trunc('hour', i.received_at) AT TIME ZONE 'UTC',
                            'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    count(*)::int AS total
               FROM incoming_requests i
              WHERE i.received_at >= :since" . $scope . '
              GROUP BY 1'
        );
        $requests->execute($bound);

        $runs = $pdo->prepare(
            "SELECT to_char(date_trunc('hour', wr.created_at) AT TIME ZONE 'UTC',
                            'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    count(*) FILTER (WHERE wr.status = 'success')::int AS success,
                    count(*) FILTER (WHERE wr.status = 'failed')::int AS failed,
                    count(*) FILTER (WHERE wr.status = 'skipped')::int AS skipped
               FROM workflow_runs wr
               JOIN incoming_requests i ON i.id = wr.request_id
              WHERE wr.created_at >= :since" . $scope . '
              GROUP BY 1'
        );
        $runs->execute($bound);

        $buckets = [];
        $cursor = $since->setTime((int) $since->format('H'), 0);
        // One bar per hour plus the hour in progress, so "now" is on the chart.
        for ($i = 0; $i <= $hours; $i++) {
            $buckets[$cursor->format('Y-m-d\TH:00:00\Z')] = [
                'hour' => $cursor->format('Y-m-d\TH:00:00\Z'),
                'requests' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
            $cursor = $cursor->modify('+1 hour');
        }

        foreach ($requests->fetchAll() as $row) {
            $key = (string) $row['hour'];
            if (isset($buckets[$key])) {
                $buckets[$key]['requests'] = (int) $row['total'];
            }
        }

        foreach ($runs->fetchAll() as $row) {
            $key = (string) $row['hour'];
            if (isset($buckets[$key])) {
                $buckets[$key]['success'] = (int) $row['success'];
                $buckets[$key]['failed'] = (int) $row['failed'];
                $buckets[$key]['skipped'] = (int) $row['skipped'];
            }
        }

        return array_values($buckets);
    }

    /**
     * `?room=<slug>` narrows every number to one room; absent means the whole
     * installation. An unknown slug is a `404`, not an empty chart.
     */
    private function roomId(Request $request): ?int
    {
        $slug = trim((string) ($request->query['room'] ?? ''));
        if ($slug === '') {
            return null;
        }

        $room = RoomResolver::findBySlug($slug);
        if ($room === null) {
            Response::error('Комната не найдена: ' . $slug, 404);
        }

        return (int) $room['id'];
    }
}
