<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Services\WorkflowEngine;
use App\Support\JsonColumns;

final class RequestsController
{
    /**
     * How many endpoints the filter shows before it asks the operator to search.
     * A room can receive on hundreds of paths, and the dropdown has to stay a
     * dropdown; the rest are reachable through `?q=` on `/api/endpoints`.
     */
    private const ENDPOINT_LIMIT = 15;

    /**
     * Upper bound on one bulk call. A page holds at most 100 requests, so 200
     * covers "select all on this page" plus a manual list, while a mistake still
     * cannot become a table-wide delete.
     */
    private const BULK_MAX = 200;

    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 25)));

        // The same filter set drives the list and the export: a download must
        // contain exactly the rows the operator is looking at, not "everything
        // in the room".
        [$clause, $params] = $this->filters($request);

        $include = $this->includeFields($request);
        // The workflow editor builds condition paths from a real payload, so it
        // asks for the heavy columns explicitly; the plain list stays lean.
        $extraColumns = in_array('body', $include, true) ? ', r.body, r.raw_body' : '';

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT count(*) FROM incoming_requests r' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT r.id, r.room_id, r.endpoint, r.method, r.path, r.content_type, r.body_bytes, r.ip, r.received_at,
                       r.duplicate_of, r.replayed_from,
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
            'SELECT wr.id, wr.workflow_id, wr.status, wr.message, wr.attempts, wr.deferrals,
                    wr.created_at, wr.updated_at,
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
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('DELETE FROM incoming_requests WHERE id = :id RETURNING room_id');
        $stmt->execute(['id' => $id]);

        $deleted = $stmt->fetch();
        if ($deleted !== false) {
            Realtime::publish('request.deleted', [
                'id' => $id,
                'room_id' => (int) $deleted['room_id'],
            ]);

            Audit::record([
                'action' => 'request.deleted',
                'object_type' => 'request',
                'object_id' => $id,
                'object_label' => '#' . $id,
                'room_id' => (int) $deleted['room_id'],
                'summary' => 'Удалён пойманный запрос #' . $id,
            ], $request);
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
            'SELECT count(*) FILTER (WHERE enabled)::int AS active,
                    count(*) FILTER (WHERE NOT enabled AND disabled_reason IS NOT NULL)::int AS auto_paused
             FROM workflows WHERE true'
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
                ORDER BY total DESC, endpoint
                LIMIT ' . self::ENDPOINT_LIMIT
        );
        $endpointsStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        // Total distinct endpoints: the filter has to say "показаны первые 15 из N",
        // and it must not ask for that with a second round trip.
        $endpointCountStmt = $pdo->prepare(
            'SELECT count(DISTINCT endpoint)::int AS total FROM incoming_requests' . $requestWhere
        );
        $endpointCountStmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

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
                'workflows_auto_paused' => (int) ($workflows['auto_paused'] ?? 0),
                'failed_runs_24h' => (int) ($failed['failed'] ?? 0),
                'queued_runs' => (int) ($queued['queued'] ?? 0),
                'endpoints' => $endpointsStmt->fetchAll(),
                'endpoints_total' => (int) ($endpointCountStmt->fetchColumn() ?? 0),
                'endpoints_limit' => self::ENDPOINT_LIMIT,
            ],
        ]);
    }

    /**
     * Endpoints of one room (or of the whole installation), for the log filter.
     *
     * `stats` answers with the most frequent few, which is what the closed filter
     * shows. This route exists for the rest: the operator types into the same
     * field and gets matches from every endpoint, not only from the visible page
     * — a room can receive on hundreds of paths, and a filter that can only pick
     * from the top 15 would make the other rows unreachable.
     *
     * `?q=` is a case-insensitive substring match, mirroring the room search. An
     * unknown room is a 404, exactly like `/api/requests?room=`.
     */
    public function endpoints(Request $request): void
    {
        Auth::requireUser($request);

        $roomId = $this->roomId($request);
        $q = trim((string) ($request->query['q'] ?? ''));

        $where = [];
        $params = [];

        if ($roomId !== null) {
            $where[] = 'room_id = :room_id';
            $params['room_id'] = $roomId;
        }

        if ($q !== '') {
            // `%` and `_` are LIKE wildcards: unescaped, a search for "%" would
            // return everything and look like the filter ignored the input.
            $where[] = 'endpoint ILIKE :q';
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }

        $limit = min(100, max(1, (int) ($request->query['limit'] ?? self::ENDPOINT_LIMIT)));

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // The LIMIT is interpolated, never bound: the value is clamped above.
        $stmt = Database::pdo()->prepare(
            'SELECT endpoint, count(*)::int AS total
               FROM incoming_requests' . $clause . '
              GROUP BY endpoint
              ORDER BY total DESC, endpoint
              LIMIT ' . $limit
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // `has_more` is what tells the SPA whether to offer "уточните запрос"
        // instead of silently showing a truncated list.
        $countStmt = Database::pdo()->prepare(
            'SELECT count(DISTINCT endpoint) FROM incoming_requests' . $clause
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        Response::json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'returned' => count($rows),
                'has_more' => $total > count($rows),
            ],
        ]);
    }

    /**
     * Re-runs the room's matching workflows for a stored request.
     *
     * This is the debugging path: a workflow was paused, wrong, or added after
     * the webhook arrived, and the operator needs the payload routed again. The
     * stored row is not duplicated — only new `pending` runs are queued, so the
     * log keeps one entry per delivery.
     */
    public function replay(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare(
            'SELECT id, room_id, endpoint FROM incoming_requests WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Запрос не найден', 404);
        }

        $queued = WorkflowEngine::enqueue($row);

        Realtime::publish('request.updated', [
            'id' => $id,
            'room_id' => (int) $row['room_id'],
        ]);

        Audit::record([
            'action' => 'request.replayed',
            'object_type' => 'request',
            'object_id' => $id,
            'object_label' => '#' . $id,
            'room_id' => (int) $row['room_id'],
            'summary' => 'Повторная маршрутизация запроса #' . $id . ', в очередь: ' . $queued,
            'changes' => ['queued' => [null, $queued]],
        ], $request);

        Response::json([
            'data' => ['id' => $id, 'queued' => $queued],
        ], 202);
    }

    /**
     * Routes a *corrected copy* of a stored delivery.
     *
     * `replay` answers "the rule was wrong, try the same payload again"; this
     * answers "the payload was wrong, would the rule have matched?". Mutating the
     * stored row would be the easy implementation and the wrong product: the log
     * is the evidence of what the sender actually sent, and every run step of
     * that request would then describe a payload that never arrived.
     *
     * The copy is a real row, so the whole pipeline (conditions, template
     * rendering, dry run, step log) works on it unchanged. It is marked with
     * `replayed_from` rather than `duplicate_of`, because the latter means "the
     * sender retried and we deliberately did not route it" — the opposite of what
     * happened here.
     */
    public function redeliver(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);
        $stmt = Database::pdo()->prepare('SELECT * FROM incoming_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $original = $stmt->fetch();

        if ($original === false) {
            Response::error('Запрос не найден', 404);
        }

        // `headers` is jsonb and PDO hands it back as a string. Merging a string
        // into `is_array(...)` silently produced an empty header map, so the copy
        // lost `content-type` and every rule that reads `headers.*` stopped
        // matching — the failure was invisible in the response.
        $original = JsonColumns::decode($original, ['query', 'headers', 'body']);

        $payload = $request->json() ?? [];
        $raw = $this->redeliveryBody($payload, $original);

        // The endpoint defaults to the original one: a corrected payload almost
        // always means "the same rule should have matched", and a different
        // endpoint would silently route to other rules.
        $endpoint = $this->redeliveryEndpoint($payload, $original);
        $headers = $this->redeliveryHeaders($payload, $original);
        $decoded = $raw === '' ? null : json_decode($raw, true);

        $contentType = (string) ($payload['content_type'] ?? $original['content_type'] ?? 'application/json');

        $row = [
            'room_id' => (int) $original['room_id'],
            'endpoint' => $endpoint,
            'method' => strtoupper((string) ($payload['method'] ?? $original['method'])),
            'path' => (string) ($payload['path'] ?? $original['path']),
            // Omitted query parameters are inherited like the body: a corrected
            // payload is still the same delivery, and dropping the query would
            // silently change which rule matches.
            'query' => is_array($payload['query'] ?? null)
                ? $payload['query']
                : (is_array($original['query'] ?? null) ? $original['query'] : []),
            'headers' => $headers,
            'body' => is_array($decoded) ? $decoded : null,
            'raw_body' => $raw,
            'content_type' => $contentType,
            'body_bytes' => strlen($raw),
            // The sender's address, not the operator's: this row stands in for a
            // delivery, so showing the dashboard's IP would make the log lie about
            // where the traffic came from.
            'ip' => (string) $original['ip'],
        ];

        $insert = Database::pdo()->prepare(
            'INSERT INTO incoming_requests
                (room_id, endpoint, method, path, query, headers, body, raw_body,
                 content_type, body_bytes, ip, replayed_from)
             VALUES
                (:room_id, :endpoint, :method, :path, :query, :headers, :body, :raw_body,
                 :content_type, :body_bytes, :ip, :replayed_from)
             RETURNING id, received_at'
        );
        $insert->execute([
            'room_id' => $row['room_id'],
            'endpoint' => $row['endpoint'],
            'method' => $row['method'],
            'path' => $row['path'],
            'query' => $this->json($row['query']),
            'headers' => $this->json($row['headers']),
            'body' => $row['body'] === null ? null : $this->json($row['body']),
            'raw_body' => $row['raw_body'],
            'content_type' => $row['content_type'],
            'body_bytes' => $row['body_bytes'],
            'ip' => $row['ip'],
            'replayed_from' => $id,
        ]);

        $saved = $insert->fetch();
        $row['id'] = (int) $saved['id'];

        Realtime::publish('request.created', [
            'id' => $row['id'],
            'room_id' => $row['room_id'],
            'endpoint' => $row['endpoint'],
            'method' => $row['method'],
            'duplicate' => false,
        ]);

        // No `dedup_key` on purpose: the room's dedup header would make the copy a
        // duplicate of itself and it would be stored without being routed. A
        // redelivery is a human action, not a sender retry, so the deduplication
        // rule does not apply to it.
        $queued = WorkflowEngine::enqueue($row);

        Audit::record([
            'action' => 'request.redelivered',
            'object_type' => 'request',
            'object_id' => $row['id'],
            'object_label' => '#' . $row['id'],
            'room_id' => $row['room_id'],
            'summary' => sprintf(
                'Запрос #%d отправлен заново с правками как #%d, в очередь: %d',
                $id,
                $row['id'],
                $queued,
            ),
            'changes' => ['replayed_from' => [null, $id], 'queued' => [null, $queued]],
        ], $request);

        Response::json([
            'data' => [
                'id' => $row['id'],
                'replayed_from' => $id,
                'received_at' => $saved['received_at'],
                'queued' => $queued,
            ],
        ], 202);
    }

    /**
     * Bulk delete / bulk replay over an explicit id list.
     *
     * Deliberately takes **ids**, not filters. "Delete everything matching this
     * search" turns one mis-click into an outage, and the log has no undo. The
     * client sends what the operator could see and had selected; the cap keeps a
     * script from turning one request into a table scan with a million deletes.
     *
     * A request whose run is still `pending`/`running` is skipped and reported
     * rather than deleted: removing it would cancel routing that is being done
     * right now, which is exactly what retention refuses to do silently.
     */
    public function bulk(Request $request): void
    {
        Auth::requireUser($request, true);

        $payload = $request->json() ?? [];
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if (!in_array($action, ['delete', 'replay'], true)) {
            Response::error('Действие: delete или replay', 422);
        }

        $ids = $this->bulkIds($payload['ids'] ?? null);
        if ($ids === []) {
            Response::error('Не выбрано ни одного запроса', 422);
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $pdo = Database::pdo();

        $find = $pdo->prepare(
            "SELECT r.id, r.room_id, r.endpoint,
                    EXISTS (
                        SELECT 1 FROM workflow_runs wr
                         WHERE wr.request_id = r.id AND wr.status IN ('pending', 'running')
                    ) AS in_flight
               FROM incoming_requests r
              WHERE r.id IN ({$placeholders})"
        );
        $find->execute($ids);
        $rows = $find->fetchAll();

        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['id']] = $row;
        }

        $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($found[$id])));

        $done = 0;
        $queued = 0;
        $skipped = [];
        $rooms = [];

        foreach ($found as $row) {
            $rooms[(int) $row['room_id']] = true;

            if (self::isTrue($row['in_flight'] ?? false)) {
                $skipped[] = ['id' => (int) $row['id'], 'reason' => 'Запуск ещё выполняется'];

                continue;
            }

            if ($action === 'replay') {
                $queued += WorkflowEngine::enqueue($row);
                $done++;

                continue;
            }

            $delete = $pdo->prepare('DELETE FROM incoming_requests WHERE id = :id');
            $delete->execute(['id' => (int) $row['id']]);
            $done++;
        }

        foreach (array_keys($rooms) as $roomId) {
            Realtime::publish($action === 'delete' ? 'request.deleted' : 'request.updated', [
                'id' => 0,
                'room_id' => $roomId,
                'bulk' => $done,
            ]);
        }

        // One journal entry for the batch, not one per row: clearing a page of
        // 100 requests would otherwise bury every other change in the feed.
        Audit::record([
            'action' => $action === 'delete' ? 'request.bulk_deleted' : 'request.bulk_replayed',
            'object_type' => 'request',
            'object_label' => $done . ' запросов',
            'summary' => $action === 'delete'
                ? sprintf('Удалено запросов: %d (пропущено: %d)', $done, count($skipped))
                : sprintf('Маршрутизировано повторно запросов: %d, в очередь: %d', $done, $queued),
            'changes' => ['count' => [null, $done], 'skipped' => [null, count($skipped)]],
        ], $request);

        Response::json([
            'data' => [
                'action' => $action,
                'count' => $done,
                'queued' => $queued,
                'skipped' => $skipped,
                'missing' => $missing,
            ],
        ]);
    }

    /**
     * Queues a fresh run of the same workflow for the same request.
     *
     * A manual retry is deliberately a new row: the failed one keeps its attempts
     * and step log as evidence, and the new run executes every action again
     * instead of skipping the ones that succeeded before the failure.
     */
    public function retryRun(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare(
            'SELECT wr.id, wr.status, wr.workflow_id, wr.request_id, i.room_id
               FROM workflow_runs wr
               JOIN incoming_requests i ON i.id = wr.request_id
              WHERE wr.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $run = $stmt->fetch();

        if ($run === false) {
            Response::error('Запуск не найден', 404);
        }

        if (in_array((string) $run['status'], WorkflowEngine::QUEUE, true)) {
            Response::error('Запуск ещё выполняется, повторите позже', 422);
        }

        $newId = WorkflowEngine::enqueueRun((int) $run['workflow_id'], (int) $run['request_id']);

        Realtime::publish('request.updated', [
            'id' => (int) $run['request_id'],
            'room_id' => (int) $run['room_id'],
        ]);

        Audit::record([
            'action' => 'run.retried',
            'object_type' => 'workflow_run',
            'object_id' => $newId,
            'object_label' => '#' . $newId,
            'room_id' => (int) $run['room_id'],
            'summary' => 'Повторный запуск сценария для запроса #' . $run['request_id'],
            'changes' => ['replaces' => [(int) $run['id'], $newId]],
        ], $request);

        Response::json([
            'data' => ['id' => $newId, 'request_id' => (int) $run['request_id'], 'queued' => 1],
        ], 202);
    }

    /**
     * Downloads the filtered log as a file.
     *
     * The SPA cannot use a plain link: the API is Bearer-authenticated and a
     * browser download does not send the header, so the client fetches the blob
     * and saves it. Rows are written as they are fetched, so the export is never
     * assembled as one string in PHP (libpq still buffers the result set).
     *
     * Formats: `json` (one document), `ndjson` (one row per line, easy to pipe)
     * and `csv` (semicolon-separated with a UTF-8 BOM, which is what Excel in a
     * Russian locale expects).
     */
    public function export(Request $request): void
    {
        Auth::requireUser($request);

        $format = strtolower(trim((string) ($request->query['format'] ?? 'json')));
        if (!in_array($format, ['json', 'ndjson', 'csv'], true)) {
            Response::error('Формат экспорта: json, ndjson или csv', 422);
        }

        [$clause, $params] = $this->filters($request);

        $stmt = Database::pdo()->prepare(
            'SELECT r.id, r.received_at, r.method, r.endpoint, r.path, r.ip, r.content_type,
                    r.body_bytes, r.query, r.headers, r.body, r.raw_body
               FROM incoming_requests r' . $clause . '
              ORDER BY r.received_at DESC, r.id DESC'
        );
        $stmt->execute($params);

        // The room slug is a query parameter, so it cannot go into a header
        // verbatim: quotes and newlines would let a caller shape the response.
        $room = (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($request->query['room'] ?? ''));
        $filename = sprintf(
            'hookdock-%s-%s.%s',
            $room === '' ? 'all' : $room,
            date('Ymd-His'),
            $format,
        );

        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Accel-Buffering: no');

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            if ($out === false) {
                Response::error('Не удалось начать экспорт', 500);
            }

            fputcsv($out, [
                'id', 'received_at', 'method', 'endpoint', 'path', 'ip',
                'content_type', 'body_bytes', 'query', 'headers', 'body', 'raw_body',
            ], ';');

            while (($row = $stmt->fetch()) !== false) {
                $row = JsonColumns::decode($row, ['query', 'headers', 'body']);
                fputcsv($out, [
                    $row['id'],
                    $row['received_at'],
                    $row['method'],
                    $row['endpoint'],
                    $row['path'],
                    $row['ip'],
                    $row['content_type'],
                    $row['body_bytes'],
                    $this->compactJson($row['query']),
                    $this->compactJson($row['headers']),
                    $this->compactJson($row['body']),
                    $row['raw_body'],
                ], ';');
            }

            fclose($out);
            exit;
        }

        if ($format === 'ndjson') {
            header('Content-Type: application/x-ndjson; charset=utf-8');

            while (($row = $stmt->fetch()) !== false) {
                echo $this->exportJson(JsonColumns::decode($row, ['query', 'headers', 'body'])), "\n";
            }

            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo '{"exported_at":', (string) json_encode(date('c')), ',"requests":[';

        $first = true;
        while (($row = $stmt->fetch()) !== false) {
            if (!$first) {
                echo ',';
            }
            $first = false;
            echo $this->exportJson(JsonColumns::decode($row, ['query', 'headers', 'body']));
        }

        echo ']}';
        exit;
    }

    /**
     * Answers "what would routing do with this request right now" without doing it.
     *
     * Two callers share this:
     *   - the request card asks with no body, and every enabled rule of the room is
     *     evaluated (which ones match, what each would send);
     *   - the rule editor asks with a draft `{conditions, actions}`, so a rule can be
     *     checked before it is saved.
     *
     * Nothing is queued and nothing leaves the process: the point of a dry run is
     * that a wrong URL or a wrong condition costs nothing. A `forward` still goes
     * through `UrlGuard`, because a preview that approves a target the worker will
     * refuse would be worse than no preview at all.
     */
    public function preview(Request $request, array $params): void
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
        $payload = $request->json() ?? [];

        // A draft wins over a stored rule: the editor has unsaved changes and must
        // be able to check them.
        if (isset($payload['conditions']) || isset($payload['actions'])) {
            $conditions = WorkflowEngine::normalizeTree($payload['conditions'] ?? []);
            $actions = is_array($payload['actions'] ?? null) ? array_values($payload['actions']) : [];

            if ($actions === []) {
                Response::error('Для проверки нужно хотя бы одно действие', 422);
            }

            $result = WorkflowEngine::preview($row, $conditions, $actions);

            Response::json([
                'data' => [
                    'request_id' => $id,
                    'mode' => 'draft',
                    'workflows' => [[
                        'workflow_id' => null,
                        'name' => 'Черновик',
                        'matched' => $result['matched'],
                        'steps' => $result['steps'],
                    ]],
                ],
            ]);
        }

        $workflowId = (int) ($payload['workflow_id'] ?? 0);

        // The endpoint rule is evaluated in PHP (`endpointMatches` is private), so the
        // filter happens after fetching the room's rules. A room has few rules; the
        // alternative is duplicating a wildcard rule in SQL.
        $rules = Database::pdo()->prepare(
            'SELECT id, name, conditions, actions, priority, endpoint
               FROM workflows
              WHERE room_id = :room_id AND enabled'
            . ($workflowId > 0 ? ' AND id = :id' : '')
            . ' ORDER BY priority DESC, id'
        );
        $rules->execute($workflowId > 0
            ? ['room_id' => (int) $row['room_id'], 'id' => $workflowId]
            : ['room_id' => (int) $row['room_id']]);

        $results = [];
        foreach (JsonColumns::decodeMany($rules->fetchAll(), ['conditions', 'actions']) as $rule) {
            // `*` matches every endpoint; anything else is an exact, case-insensitive
            // match. Mirrors `WorkflowEngine::endpointMatches`.
            $pattern = (string) ($rule['endpoint'] ?? '*');
            if ($pattern !== '*' && strcasecmp($pattern, (string) $row['endpoint']) !== 0) {
                continue;
            }

            $result = WorkflowEngine::preview(
                $row,
                WorkflowEngine::normalizeTree($rule['conditions'] ?? []),
                is_array($rule['actions'] ?? null) ? array_values($rule['actions']) : [],
            );

            $results[] = [
                'workflow_id' => (int) $rule['id'],
                'name' => (string) $rule['name'],
                'priority' => (int) $rule['priority'],
                'matched' => $result['matched'],
                'steps' => $result['steps'],
            ];
        }

        Response::json([
            'data' => [
                'request_id' => $id,
                'mode' => $workflowId > 0 ? 'workflow' : 'room',
                'workflows' => $results,
            ],
        ]);
    }

    /**
     * Streams the stored body back as a file.
     *
     * The detail card can copy a payload, but a 4 MB JSON is not something to paste:
     * the operator wants it in an editor. `raw_body` is returned byte for byte (the
     * original text, including a non-JSON payload), and the filename carries the
     * request id so two downloads do not overwrite each other.
     */
    public function body(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('SELECT raw_body, content_type, method FROM incoming_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Запрос не найден', 404);
        }

        $content = (string) ($row['raw_body'] ?? '');
        $extension = $this->extensionFor((string) ($row['content_type'] ?? ''));

        header('Cache-Control: no-store');
        header('Content-Type: ' . ($this->isJsonType((string) ($row['content_type'] ?? '')) ? 'application/json; charset=utf-8' : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="request-' . $id . '.' . $extension . '"');
        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    /**
     * The body of a redelivery: `body` wins over `raw_body`, and neither means
     * "send exactly what the sender sent".
     *
     * `body` is the normal case (the operator fixes a field in the JSON), but a
     * non-JSON delivery has no structured body at all, so `raw_body` is the only
     * way to correct one. Encoding an array here rather than passing it through
     * keeps `raw_body` and `body` consistent: the catcher stores both, and a row
     * whose jsonb and text disagree would render one thing and route another.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $original
     */
    private function redeliveryBody(array $payload, array $original): string
    {
        if (array_key_exists('body', $payload)) {
            $body = $payload['body'];

            // A string in `body` is a JSON string, not raw bytes; only `raw_body`
            // carries text verbatim. Treating it otherwise would store `"hello"`
            // with quotes as the payload.
            return $body === null ? '' : $this->json($body);
        }

        if (array_key_exists('raw_body', $payload)) {
            return (string) $payload['raw_body'];
        }

        return (string) ($original['raw_body'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $original
     */
    private function redeliveryEndpoint(array $payload, array $original): string
    {
        $endpoint = trim((string) ($payload['endpoint'] ?? ($original['endpoint'] ?? '')), '/');

        if ($endpoint === '') {
            $endpoint = (string) ($original['endpoint'] ?? 'default');
        }

        $endpoint = mb_substr($endpoint, 0, 160);

        return $endpoint === '' ? 'default' : $endpoint;
    }

    /**
     * Corrected headers are merged over the stored ones, not substituted for
     * them: an operator fixing `x-event-type` should not have to retype the whole
     * envelope, and a replacement would drop `user-agent` and friends that the
     * rules may read.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $original
     * @return array<string, string>
     */
    private function redeliveryHeaders(array $payload, array $original): array
    {
        $headers = is_array($original['headers'] ?? null) ? $original['headers'] : [];

        if (is_array($payload['headers'] ?? null)) {
            foreach ($payload['headers'] as $name => $value) {
                $key = strtolower(trim((string) $name));
                if ($key === '') {
                    continue;
                }
                $headers[$key] = is_scalar($value) ? (string) $value : $this->compactJson($value);
            }
        }

        return $headers;
    }

    /**
     * Validates the explicit id list of a bulk operation.
     *
     * A flat list, not "the filters": a filter is a query the operator was
     * looking at, and the rows it matches can change between the click and the
     * request. The cap bounds one call so a script cannot turn `bulk` into a
     * table-wide delete.
     *
     * @return list<int>
     */
    private function bulkIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        sort($ids);

        return array_slice($ids, 0, self::BULK_MAX);
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     */
    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isJsonType(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    private function extensionFor(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return match (true) {
            $this->isJsonType($type) => 'json',
            $type === 'application/x-www-form-urlencoded' => 'txt',
            $type === 'text/plain', $type === 'text/csv' => 'txt',
            $type === 'application/xml', $type === 'text/xml' => 'xml',
            str_contains($type, 'multipart/form-data') => 'txt',
            default => 'bin',
        };
    }

    /**
     * Builds the WHERE clause shared by the list, the counters and the export.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(Request $request): array
    {
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

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function exportJson(array $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function compactJson(mixed $value): string
    {
        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
