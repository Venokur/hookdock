<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\RoomResolver;
use App\Support\JsonColumns;

/**
 * "Who changed what" as a page.
 *
 * The journal itself is written by the mutating handlers (see `Services\Audit`);
 * this only lists it. Reading is open to every role — an editor who cannot see why
 * a rule changed is left guessing — while nothing here writes.
 *
 * The feed is installation-wide by default and narrowed with `?room=`, because the
 * two questions are different: "what happened in this room" while debugging, and
 * "what did this person do" across all of them. The room filter also matches the
 * copied `room_slug`, so the entries of a room that has since been deleted remain
 * findable — the `room_id` FK is `SET NULL` on purpose.
 */
final class AuditController
{
    /**
     * Actions the SPA offers as a filter; the API accepts any of them.
     *
     * This list is the dropdown, so an action missing here is an entry the operator
     * can neither name nor filter by — `workflow.run_now` and both `token.*` were
     * recorded with no way to select them. Keep it in step with every
     * `Audit::record()` call, and with `actionLabels` in `AuditView.vue`, which turns
     * these identifiers into Russian.
     */
    private const ACTIONS = [
        'room.created', 'room.updated', 'room.deleted',
        'room.secret_issued', 'room.secret_cleared',
        'workflow.created', 'workflow.updated', 'workflow.deleted',
        'workflow.toggled', 'workflow.duplicated', 'workflow.imported',
        'workflow.rolled_back', 'workflow.run_now',
        'request.deleted', 'request.replayed', 'run.retried',
        'request.redelivered', 'request.bulk_deleted', 'request.bulk_replayed',
        'breaker.reset',
        'channel.created', 'channel.updated', 'channel.deleted', 'channel.tested',
        'token.created', 'token.revoked',
        'user.created', 'user.updated', 'user.deleted', 'user.password_changed',
    ];

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
            // A deleted room still has a journal, and asking about it must not turn
            // into a 404: only the pointer is gone, the record is the point.
            $where[] = $room === null
                ? 'a.room_slug = :room_slug'
                : '(a.room_id = :room_id OR a.room_slug = :room_slug)';
            $params['room_slug'] = $slug;
            if ($room !== null) {
                $params['room_id'] = $room['id'];
            }
        }

        $action = trim((string) ($request->query['action'] ?? ''));
        if ($action !== '' && $action !== 'all') {
            if (preg_match('/^[a-z_]+\.[a-z_]+$/', $action) !== 1) {
                Response::error('Неизвестное действие: ' . $action, 422);
            }
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $objectType = trim((string) ($request->query['object_type'] ?? ''));
        if ($objectType !== '') {
            $where[] = 'a.object_type = :object_type';
            $params['object_type'] = $objectType;
        }

        $actor = trim((string) ($request->query['actor'] ?? ''));
        if ($actor !== '') {
            // Same escaping rule as the request log: `%` and `_` are LIKE
            // metacharacters and backslash is Postgres' default escape character.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $actor) . '%';
            $where[] = 'a.actor_email ILIKE :actor';
            $params['actor'] = $like;
        }

        $q = trim((string) ($request->query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(a.summary ILIKE :q_summary OR a.object_label ILIKE :q_label)';
            $params['q_summary'] = $like;
            $params['q_label'] = $like;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT count(*) FROM audit_log a' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT a.id, a.created_at, a.actor_id, a.actor_email, a.actor_role,
                    a.action, a.object_type, a.object_id, a.object_label,
                    a.room_id, a.room_slug, a.summary, a.changes, a.ip
               FROM audit_log a' . $clause . '
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = JsonColumns::decodeMany($stmt->fetchAll(), ['changes']);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
                'actions' => self::ACTIONS,
            ],
        ]);
    }
}
