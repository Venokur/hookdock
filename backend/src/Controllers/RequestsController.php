<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Support\JsonColumns;

final class RequestsController
{
    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 25)));

        $where = [];
        $params = [];

        $roomId = $this->roomId($request);
        if ($roomId !== null) {
            $where[] = 'r.room_id = :room_id';
            $params['room_id'] = $roomId;
        }

        $method = strtoupper(trim((string) ($request->query['method'] ?? '')));
        if ($method !== '' && $method !== 'ALL') {
            $where[] = 'r.method = :method';
            $params['method'] = $method;
        }

        $endpoint = trim((string) ($request->query['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $where[] = 'r.endpoint = :endpoint';
            $params['endpoint'] = $endpoint;
        }

        $q = trim((string) ($request->query['q'] ?? ''));
        if ($q !== '') {
            // No `COALESCE(raw_body, '')` here: a NULL body already fails `ILIKE`, and
            // wrapping the column in a function would stop Postgres from using the
            // trigram index (`incoming_requests_raw_body_trgm_idx`), turning each
            // keystroke into a sequential scan of every stored payload.
            //
            // `%` and `_` are LIKE wildcards. Unescaped, a search for "50%" matches
            // every payload containing "50" — and the user cannot tell why. Backslash
            // is Postgres' default LIKE escape character, so escaping the value is
            // enough; a literal `ESCAPE '\'` clause is deliberately avoided because
            // PDO's statement parser reads `\'` as an escaped quote and then fails to
            // bind the remaining placeholders (`HY093`).
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(r.path ILIKE :q_path OR r.endpoint ILIKE :q_endpoint OR r.raw_body ILIKE :q_body)';
            $params['q_path'] = $like;
            $params['q_endpoint'] = $like;
            $params['q_body'] = $like;
        }

        $from = $this->parseDate((string) ($request->query['from'] ?? ''));
        if ($from !== null) {
            $where[] = 'r.received_at >= :received_from';
            $params['received_from'] = $from;
        }

        $to = $this->parseDate((string) ($request->query['to'] ?? ''));
        if ($to !== null) {
            $where[] = 'r.received_at <= :received_to';
            $params['received_to'] = $to;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $include = $this->includeFields($request);
        // The workflow editor builds condition paths from a real payload, so it
        // asks for the heavy columns explicitly; the plain list stays lean.
        $extraColumns = in_array('body', $include, true) ? ', r.body, r.raw_body' : '';

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT count(*) FROM incoming_requests r' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT r.id, r.room_id, r.endpoint, r.method, r.path, r.content_type, r.body_bytes, r.ip, r.received_at,
                       COALESCE(stats.runs, 0)::int AS run_count,
                       COALESCE(stats.failed, 0)::int AS failed_runs,
                       COALESCE(stats.pending, 0)::int AS pending_runs" . $extraColumns . "
                FROM incoming_requests r
                LEFT JOIN LATERAL (
                    SELECT count(*) AS runs,
                           count(*) FILTER (WHERE wr.status = 'failed') AS failed,
                           count(*) FILTER (WHERE wr.status IN ('pending', 'running')) AS pending
                    FROM workflow_runs wr
                    WHERE wr.request_id = r.id
                ) stats ON TRUE" . $clause . '
                ORDER BY r.received_at DESC, r.id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        if (in_array('body', $include, true)) {
            // Only `body` is jsonb; `raw_body` is text and must stay a string.
            $rows = JsonColumns::decodeMany($rows, ['body']);
        }

        Response::json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('SELECT * FROM incoming_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Запрос не найден', 404);
        }

        $row = JsonColumns::decode($row, ['query', 'headers', 'body']);

        $pdo = Database::pdo();

        $runs = $pdo->prepare(
            'SELECT wr.id, wr.workflow_id, wr.status, wr.message, wr.attempts, wr.created_at, wr.updated_at,
                    w.name AS workflow_name
             FROM workflow_runs wr
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE wr.request_id = :id
             ORDER BY wr.id'
        );
        $runs->execute(['id' => $id]);
        $runRows = $runs->fetchAll();

        // Step logs for every run of this request in one query. A per-run query
        // would make the detail card scale with the number of workflows.
        $steps = [];
        if ($runRows !== []) {
            $ids = array_map(static fn (array $run): int => (int) $run['id'], $runRows);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            $stepsStmt = $pdo->prepare(
                "SELECT id, run_id, attempt, action_index, type, status, message,
                        response_status, response_body, duration_ms, created_at
                   FROM workflow_run_steps
                  WHERE run_id IN ({$placeholders})
                  ORDER BY run_id, id"
            );
            $stepsStmt->execute($ids);

            foreach ($stepsStmt->fetchAll() as $step) {
                $steps[(int) $step['run_id']][] = $step;
            }
        }

        foreach ($runRows as &$run) {
            $run['steps'] = $steps[(int) $run['id']] ?? [];
        }
        unset($run);

        Response::json([
            'data' => [
                'request' => $row,
                'runs' => $runRows,
            ],
        ]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('DELETE FROM incoming_requests WHERE id = :id RETURNING room_id');
        $stmt->execute(['id' => $id]);

        $deleted = $stmt->fetch();
        if ($deleted !== false) {
            Realtime::publish('request.deleted', [
                'id' => $id,
                'room_id' => (int) $deleted['room_id'],
            ]);
        }

        Response::json(['ok' => true, 'deleted' => $deleted !== false]);
    }

    public function stats(Request $request): void
    {
        Auth::requireUser($request);
        $pdo = Database::pdo();

        $roomId = $this->roomId($request);

        // `room` narrows every counter to one room; without it the totals stay
        // installation-wide (the SPA always sends a room).
        $requestWhere = $roomId === null ? '' : ' WHERE room_id = :room_id';
        $runScope = $roomId === null
            ? ''
            : ' AND request_id IN (SELECT id FROM incoming_requests WHERE room_id = :room_id)';

        $requestsStmt = $pdo->prepare(
            "SELECT count(*)::int AS total,
                    count(*) FILTER (WHERE received_at >= now() - interval '1 hour')::int AS last_hour,
                    count(*) FILTER (WHERE received_at >= now() - interval '24 hours')::int AS last_24h
             FROM incoming_requests" . $requestWhere
        );
        $requestsStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        $workflowsStmt = $pdo->prepare(
            'SELECT count(*)::int AS active FROM workflows WHERE enabled'
            . ($roomId === null ? '' : ' AND room_id = :room_id')
        );
        $workflowsStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        $failedStmt = $pdo->prepare(
            "SELECT count(*)::int AS failed FROM workflow_runs
              WHERE status = 'failed'
                AND created_at >= now() - interval '24 hours'" . $runScope
        );
        $failedStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        // How much routing is still in flight: the number the UI shows when the
        // receiver has answered 202 but the worker has not finished yet.
        $queuedStmt = $pdo->prepare(
            "SELECT count(*)::int AS queued FROM workflow_runs
              WHERE status IN ('pending', 'running')" . $runScope
        );
        $queuedStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        $endpointsStmt = $pdo->prepare(
            'SELECT endpoint, count(*)::int AS total
             FROM incoming_requests'
            . $requestWhere
            . ' GROUP BY endpoint
                ORDER BY total DESC
                LIMIT 20'
        );
        $endpointsStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        $requests = $requestsStmt->fetch();
        $workflows = $workflowsStmt->fetch();
        $failed = $failedStmt->fetch();
        $queued = $queuedStmt->fetch();

        Response::json([
            'data' => [
                'requests_total' => (int) ($requests['total'] ?? 0),
                'requests_last_hour' => (int) ($requests['last_hour'] ?? 0),
                'requests_last_24h' => (int) ($requests['last_24h'] ?? 0),
                'workflows_active' => (int) ($workflows['active'] ?? 0),
                'failed_runs_24h' => (int) ($failed['failed'] ?? 0),
                'queued_runs' => (int) ($queued['queued'] ?? 0),
                'endpoints' => $endpointsStmt->fetchAll(),
            ],
        ]);
    }

    /**
     * `?room=<slug>` narrows the result set to one room. An unknown slug is a
     * client bug, not an empty room: answer 404 instead of silently returning
     * data from nowhere.
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

        return $room['id'];
    }

    /**
     * `?include=body` lets the workflow editor load a sample payload alongside
     * the list (one round trip instead of list → detail). Unknown values are
     * ignored so a stale client cannot break the query.
     *
     * @return list<string>
     */
    private function includeFields(Request $request): array
    {
        $raw = (string) ($request->query['include'] ?? '');
        if ($raw === '') {
            return [];
        }

        $allowed = ['body'];
        $requested = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw),
        );

        return array_values(array_intersect($allowed, $requested));
    }

    private function parseDate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return null;
        }
    }
}
