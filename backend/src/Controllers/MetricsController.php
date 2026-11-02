<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Database;
use App\Http\Request;
use App\Services\CircuitBreaker;
use App\Services\Heartbeat;

/**
 * The installation as Prometheus metrics.
 *
 * `/api/health` answers "can I be relied on right now" with a `200`/`503` and a
 * human-readable body. A monitoring system needs the other shape: numbers it can
 * chart, with a stable name and a type, scraped on a schedule. This is that — the
 * same underlying queries, in the exposition format.
 *
 * Public on purpose, like `/api/health`: a scrape config has no credentials, and
 * the body carries counters, never a payload, a room name, an address or a
 * secret. `hookdock_breaker_open` is therefore a *count*, with no `address` label
 * — a forward target is an internal hostname and an installation with a strict
 * scrape ACL should not learn it from a public endpoint.
 *
 * The text is written by hand instead of pulling in `prometheus_client`: the
 * format is a handful of lines, and this backend has no Composer dependencies by
 * design.
 */
final class MetricsController
{
    public function show(Request $request): void
    {
        $lines = [];

        // Node/process facts first, so a scrape that only keeps the first lines
        // still sees whether the API itself is up.
        $this->add($lines, 'hookdock_api_up', 'gauge', 'The API answered this scrape.', [], 1);
        $this->add($lines, 'hookdock_build_info', 'gauge', 'Build information; the label is the version.',
            ['version' => $this->version()], 1);

        $healthy = true;
        $pdo = null;

        try {
            $pdo = Database::pdo();
            $pdo->query('SELECT 1')->fetchColumn();
            $this->add($lines, 'hookdock_database_up', 'gauge', 'The database answered.', [], 1);
        } catch (\Throwable $e) {
            $healthy = false;
            $this->add($lines, 'hookdock_database_up', 'gauge', 'The database answered.', [], 0);
        }

        if ($pdo !== null) {
            try {
                $this->queue($lines, $pdo);
            } catch (\Throwable $e) {
                $healthy = false;
                $this->add($lines, 'hookdock_queue_up', 'gauge', 'Queue counters were readable.', [], 0);
            }

            try {
                $this->heartbeat($lines);
            } catch (\Throwable $e) {
                $healthy = false;
            }

            try {
                $this->runs($lines, $pdo);
            } catch (\Throwable $e) {
                $healthy = false;
            }
        }

        try {
            $this->breakers($lines);
        } catch (\Throwable $e) {
            $healthy = false;
        }

        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');
        // `503` is what a scraper's own health check reads; the body is still the
        // full exposition so a failing installation keeps producing numbers.
        http_response_code($healthy ? 200 : 503);

        echo implode("\n", $lines) . "\n";
        exit;
    }

    /**
     * @param list<string> $lines
     */
    private function queue(array &$lines, \PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT count(*) FILTER (WHERE status = 'pending')::int AS pending,
                    count(*) FILTER (WHERE status = 'running')::int AS running,
                    COALESCE(max(EXTRACT(EPOCH FROM (now() - available_at)))
                        FILTER (WHERE status = 'pending'), 0)::int AS oldest_pending_seconds
               FROM workflow_runs
              WHERE status IN ('pending', 'running')"
        );
        $row = $stmt->fetch() ?: [];

        $this->add($lines, 'hookdock_queue_up', 'gauge', 'Queue counters were readable.', [], 1);
        $this->add($lines, 'hookdock_runs_pending', 'gauge',
            'Runs accepted but not claimed yet.', [], (int) ($row['pending'] ?? 0));
        $this->add($lines, 'hookdock_runs_running', 'gauge',
            'Runs a worker is executing right now.', [], (int) ($row['running'] ?? 0));
        $this->add($lines, 'hookdock_queue_oldest_pending_seconds', 'gauge',
            'Age of the oldest pending run; the number `/api/health` fails on.',
            [], (int) ($row['oldest_pending_seconds'] ?? 0));
    }

    /**
     * @param list<string> $lines
     */
    private function heartbeat(array &$lines): void
    {
        $status = Heartbeat::status();

        $this->add($lines, 'hookdock_worker_alive', 'gauge',
            'At least one worker beat within WORKER_STALE_SECONDS.', [], $status['alive'] ? 1 : 0);
        $this->add($lines, 'hookdock_workers', 'gauge',
            'Workers that have ever written a heartbeat.', [], (int) $status['workers']);
        $this->add($lines, 'hookdock_worker_stale_after_seconds', 'gauge',
            'How long a heartbeat stays trusted.', [], (int) $status['stale_after']);
        $this->add($lines, 'hookdock_runs_claimed_total', 'counter',
            'Runs claimed by the workers, cumulative.',
            [], (int) $status['claimed']);

        $last = $status['last_beat_at'];
        if (is_string($last) && $last !== '') {
            $at = strtotime($last);
            if ($at !== false) {
                $this->add($lines, 'hookdock_worker_last_beat_timestamp_seconds', 'gauge',
                    'Unix time of the newest heartbeat.', [], $at);
            }
        }
    }

    /**
     * Runs by status, cumulative. Labelled by status because that is the split a
     * dashboard needs; a single total would not show a rule that stopped failing.
     *
     * @param list<string> $lines
     */
    private function runs(array &$lines, \PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT status, count(*)::int AS total FROM workflow_runs GROUP BY 1');

        $counts = ['pending' => 0, 'running' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        $lines[] = '# HELP hookdock_runs Number of runs in the journal by status.';
        $lines[] = '# TYPE hookdock_runs gauge';
        foreach ($counts as $status => $total) {
            $lines[] = sprintf('hookdock_runs{status="%s"} %d', $status, $total);
        }

        $stmt = $pdo->query('SELECT count(*)::int FROM incoming_requests');
        $this->add($lines, 'hookdock_requests_total', 'gauge',
            'Stored deliveries.', [], (int) $stmt->fetchColumn());

        // Failed in the last hour: the number an alert rule is actually written
        // against, because a cumulative counter never goes back down.
        $stmt = $pdo->query(
            "SELECT count(*)::int FROM workflow_runs
              WHERE status = 'failed' AND created_at >= now() - interval '1 hour'"
        );
        $this->add($lines, 'hookdock_runs_failed_last_hour', 'gauge',
            'Runs that ended failed in the last hour.', [], (int) $stmt->fetchColumn());
    }

    /**
     * @param list<string> $lines
     */
    private function breakers(array &$lines): void
    {
        $stmt = Database::pdo()->query(
            'SELECT count(*) FILTER (WHERE state = \'open\')::int AS open,
                    count(*) FILTER (WHERE state = \'half_open\')::int AS half_open
               FROM forward_targets'
        );
        $row = $stmt->fetch() ?: [];

        $this->add($lines, 'hookdock_breaker_open', 'gauge',
            'Forward addresses currently refused. Deliberately unlabelled: an address is internal.',
            [], (int) ($row['open'] ?? 0));
        $this->add($lines, 'hookdock_breaker_half_open', 'gauge',
            'Forward addresses letting exactly one probe through.', [], (int) ($row['half_open'] ?? 0));
        $this->add($lines, 'hookdock_breaker_threshold', 'gauge',
            'Terminal failures that open a breaker.', [], CircuitBreaker::threshold());
    }

    private function version(): string
    {
        return (string) (Config::get('APP_VERSION', 'dev'));
    }

    /**
     * @param list<string> $lines
     * @param array<string, string|int> $labels
     */
    private function add(array &$lines, string $name, string $type, string $help, array $labels, int|float $value): void
    {
        // The HELP/TYPE pair is emitted once per metric name: repeating it for every
        // labelled series is what makes a scraper reject the whole payload.
        $header = '# HELP ' . $name . ' ' . $help;
        $typed = '# TYPE ' . $name . ' ' . $type;
        if (!in_array($header, $lines, true)) {
            $lines[] = $header;
            $lines[] = $typed;
        }

        $rendered = '';
        if ($labels !== []) {
            $parts = [];
            foreach ($labels as $key => $label) {
                $parts[] = $key . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $label) . '"';
            }
            $rendered = '{' . implode(',', $parts) . '}';
        }

        $lines[] = sprintf('%s%s %s', $name, $rendered, $this->number($value));
    }

    private function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
