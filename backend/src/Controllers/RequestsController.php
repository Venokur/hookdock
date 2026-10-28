<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
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
            $where[] = "(r.path ILIKE :q_path OR r.endpoint ILIKE :q_endpoint OR COALESCE(r.raw_body, '') ILIKE :q_body)";
            $params['q_path'] = '%' . $q . '%';
            $params['q_endpoint'] = '%' . $q . '%';
            $params['q_body'] = '%' . $q . '%';
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

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT count(*) FROM incoming_requests r' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT r.id, r.endpoint, r.method, r.path, r.content_type, r.body_bytes, r.ip, r.received_at,
                       COALESCE(stats.runs, 0)::int AS run_count,
                       COALESCE(stats.failed, 0)::int AS failed_runs
                FROM incoming_requests r
                LEFT JOIN LATERAL (
                    SELECT count(*) AS runs, count(*) FILTER (WHERE wr.status = 'failed') AS failed
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

        Response::json([
            'data' => $stmt->fetchAll(),
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

        $runs = Database::pdo()->prepare(
            'SELECT wr.id, wr.workflow_id, wr.status, wr.message, wr.created_at, w.name AS workflow_name
             FROM workflow_runs wr
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE wr.request_id = :id
             ORDER BY wr.id'
        );
        $runs->execute(['id' => $id]);

        Response::json([
            'data' => [
                'request' => $row,
                'runs' => $runs->fetchAll(),
            ],
        ]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('DELETE FROM incoming_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);

        Response::json(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
    }

    public function stats(Request $request): void
    {
        Auth::requireUser($request);
        $pdo = Database::pdo();

        $requests = $pdo->query(
            "SELECT count(*)::int AS total,
                    count(*) FILTER (WHERE received_at >= now() - interval '1 hour')::int AS last_hour,
                    count(*) FILTER (WHERE received_at >= now() - interval '24 hours')::int AS last_24h
             FROM incoming_requests"
        )->fetch();

        $workflows = $pdo->query('SELECT count(*)::int AS active FROM workflows WHERE enabled')->fetch();
        $failed = $pdo->query(
            "SELECT count(*)::int AS failed FROM workflow_runs
             WHERE status = 'failed' AND created_at >= now() - interval '24 hours'"
        )->fetch();

        $endpoints = $pdo->query(
            'SELECT endpoint, count(*)::int AS total
             FROM incoming_requests
             GROUP BY endpoint
             ORDER BY total DESC
             LIMIT 20'
        )->fetchAll();

        Response::json([
            'data' => [
                'requests_total' => (int) ($requests['total'] ?? 0),
                'requests_last_hour' => (int) ($requests['last_hour'] ?? 0),
                'requests_last_24h' => (int) ($requests['last_24h'] ?? 0),
                'workflows_active' => (int) ($workflows['active'] ?? 0),
                'failed_runs_24h' => (int) ($failed['failed'] ?? 0),
                'endpoints' => $endpoints,
            ],
        ]);
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
