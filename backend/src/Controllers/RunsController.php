<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\RoomResolver;

/**
 * The run journal as a page of its own.
 *
 * Until now a run was only reachable through the request it belonged to: to answer
 * "what failed in the last hour" the operator had to open stored webhooks one by
 * one. This lists runs directly, which is the question actually asked when
 * routing is broken — the request is context, not the subject.
 *
 * Read-only on purpose. Retry stays on `/api/runs/{id}/retry` where it already is,
 * and nothing here changes a run.
 */
final class RunsController
{
    /** `workflow_runs.status` values, for validation and for the filter's options. */
    private const STATUSES = ['pending', 'running', 'success', 'failed', 'skipped'];

    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 25)));

        $where = [];
        $params = [];

        $slug = trim((string) ($request->query['room'] ?? ''));
        if ($slug !== '') {
            $room = RoomResolver::findBySlug($slug);
            if ($room === null) {
                Response::error('Комната не найдена: ' . $slug, 404);
            }
            $where[] = 'i.room_id = :room_id';
            $params['room_id'] = $room['id'];
        }

        $status = strtolower(trim((string) ($request->query['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            if (!in_array($status, self::STATUSES, true)) {
                Response::error('Неизвестный статус запуска: ' . $status, 422);
            }
            $where[] = 'wr.status = :status';
            $params['status'] = $status;
        }

        $workflowId = (int) ($request->query['workflow_id'] ?? 0);
        if ($workflowId > 0) {
            $where[] = 'wr.workflow_id = :workflow_id';
            $params['workflow_id'] = $workflowId;
        }

        $q = trim((string) ($request->query['q'] ?? ''));
        if ($q !== '') {
            // Same escaping rule as the request log: `%` and `_` are LIKE
            // metacharacters, and backslash is Postgres' default escape character.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(wr.message ILIKE :q_message OR w.name ILIKE :q_workflow OR i.path ILIKE :q_path)';
            $params['q_message'] = $like;
            $params['q_workflow'] = $like;
            $params['q_path'] = $like;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare(
            'SELECT count(*)
               FROM workflow_runs wr
               JOIN workflows w ON w.id = wr.workflow_id
               JOIN incoming_requests i ON i.id = wr.request_id' . $clause
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT wr.id, wr.workflow_id, wr.request_id, wr.status, wr.message, wr.attempts,
                    wr.created_at, wr.updated_at, wr.available_at,
                    w.name AS workflow_name, w.priority,
                    i.room_id, i.method, i.path, i.endpoint,
                    stats.steps
               FROM workflow_runs wr
               JOIN workflows w ON w.id = wr.workflow_id
               JOIN incoming_requests i ON i.id = wr.request_id
               LEFT JOIN LATERAL (
                   SELECT count(*)::int AS steps FROM workflow_run_steps
                    WHERE run_id = wr.id
               ) stats ON TRUE' . $clause . '
              ORDER BY wr.id DESC
              LIMIT :limit OFFSET :offset'
        );
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
                'statuses' => self::STATUSES,
            ],
        ]);
    }
}
