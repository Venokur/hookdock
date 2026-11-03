<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\NotificationChannel;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Services\WorkflowEngine;
use App\Services\WorkflowHistory;
use App\Support\Canonical;
use App\Support\Cron;
use App\Support\JsonColumns;
use PDO;

final class WorkflowsController
{
    private const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains',
        'exists', 'not_exists', 'gt', 'gte', 'lt', 'lte', 'regex',
    ];

    private const ACTION_TYPES = ['forward', 'log', 'transform', 'delay', 'extract', 'notify'];

    /**
     * Sources an `extract` action may read. `raw` is deliberately absent: a
     * non-JSON body has nothing to address by path, and `response.text` covers the
     * "match a substring of the answer" case through `contains`.
     */
    private const EXTRACT_SOURCES = ['body', 'query', 'headers', 'meta', 'transformed', 'extracted', 'response'];

    /** Nesting depth of a condition tree. Deep trees are hard to reason about. */
    private const MAX_DEPTH = 5;

    /** Leaves in the whole tree, not per level. */
    private const MAX_CONDITIONS = 40;

    /** One import is a room's rule set, not a bulk tool. */
    private const MAX_IMPORT = 50;

    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $roomId = $this->optionalRoomId($request);
        $where = $roomId === null ? '' : ' WHERE w.room_id = :room_id';

        $stmt = Database::pdo()->prepare(
            'SELECT w.*, lr.created_at AS last_run_at, lr.status AS last_run_status
             FROM workflows w
             LEFT JOIN LATERAL (
                 SELECT created_at, status FROM workflow_runs
                 WHERE workflow_id = w.id ORDER BY id DESC LIMIT 1
             ) lr ON TRUE' . $where . '
             ORDER BY w.priority DESC, w.id DESC'
        );
        $stmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        // `auto_paused` is derived, not stored twice: a manual pause and an
        // automatic one look identical in `enabled`, but the UI has to tell them
        // apart to explain itself. `present()` also normalizes the condition tree
        // and strips a forward's signature secret.
        $rows = array_map(
            fn (array $row): array => $this->present($row),
            JsonColumns::decodeMany($stmt->fetchAll(), ['conditions', 'actions'])
        );

        Response::json(['data' => $rows]);
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $stmt = Database::pdo()->prepare('SELECT * FROM workflows WHERE id = :id');
        $stmt->execute(['id' => (int) ($params['id'] ?? 0)]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        Response::json(['data' => $this->present(JsonColumns::decode($row, ['conditions', 'actions']))]);
    }

    /**
     * Copies a workflow into the same room, disabled.
     *
     * A copy is the safe way to experiment with a live rule: it starts paused, so
     * forgetting to disable it cannot double every forward. Steps and runs are not
     * copied — the new rule has no history yet, which is what makes it safe to
     * change and to delete.
     */
    public function duplicate(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflows (room_id, name, endpoint, enabled, conditions, actions, actions_version, priority, stop_processing, schedule_cron)
             SELECT room_id,
                    left(name, 140) || \' (копия)\',
                    endpoint,
                    false,
                    conditions,
                    actions,
                    1,
                    priority,
                    stop_processing,
                    schedule_cron
               FROM workflows
              WHERE id = :id
             RETURNING *'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        $decoded = JsonColumns::decode($row, ['conditions', 'actions']);

        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'created',
        ]);

        // A copy is a new rule, so it starts its own history at version 1 rather
        // than inheriting the source's.
        WorkflowHistory::snapshot($decoded, 'created', $request);

        $source = $this->find($id);
        Audit::record([
            'action' => 'workflow.duplicated',
            'object_type' => 'workflow',
            'object_id' => (int) $decoded['id'],
            'object_label' => (string) $decoded['name'],
            'room_id' => (int) $decoded['room_id'],
            'room_slug' => $this->roomSlug((int) $decoded['room_id']),
            'summary' => 'Создана копия сценария «' . ($source['name'] ?? '#' . $id) . '»',
            'changes' => ['enabled' => [null, false]],
        ], $request);

        Response::json(['data' => $this->present($decoded)], 201);
    }

    /**
     * Runs a rule once, right now, without waiting for a webhook or a slot.
     *
     * The run has no request, so the payload is empty and only `meta` describes the
     * trigger — which is exactly what a scheduled rule sees. That makes this the
     * way to test a scheduled rule without waiting for its next slot, and it leaves
     * `schedule_next_at` alone: a test must not move the real schedule.
     *
     * A paused rule can still be run: the operator is asking "does this work", and
     * refusing would leave them to unpause a rule that may be paused for a reason.
     */
    public function runNow(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);
        $workflow = $this->find($id);
        if ($workflow === null) {
            Response::error('Сценарий не найден', 404);
        }

        $trigger = trim((string) ($workflow['schedule_cron'] ?? '')) === '' ? 'manual' : 'schedule';
        $runId = WorkflowEngine::enqueueRun($id, null, (int) $workflow['room_id'], $trigger);

        Realtime::publish('run.updated', [
            'room_id' => (int) $workflow['room_id'],
            'run_id' => $runId,
            'workflow_id' => $id,
            'status' => 'pending',
            'request_id' => null,
        ]);

        Audit::record([
            'action' => 'workflow.run_now',
            'object_type' => 'workflow_run',
            'object_id' => $runId,
            'object_label' => '#' . $runId,
            'room_id' => (int) $workflow['room_id'],
            'room_slug' => $this->roomSlug((int) $workflow['room_id']),
            'summary' => 'Ручной запуск сценария «' . $workflow['name'] . '»',
            'changes' => [],
        ], $request);

        Response::json([
            'data' => ['id' => $runId, 'workflow_id' => $id, 'request_id' => null, 'queued' => 1],
        ], 202);
    }

    public function store(Request $request): void
    {        Auth::requireUser($request, true);
        $workflow = $this->validated($request->json() ?? []);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflows (room_id, name, endpoint, enabled, conditions, actions, actions_version, priority, stop_processing, schedule_cron, schedule_next_at)
             VALUES (:room_id, :name, :endpoint, :enabled, :conditions, :actions, :actions_version, :priority, :stop_processing, :schedule_cron, :schedule_next_at)
             RETURNING *'
        );
        $stmt->execute([
            'room_id' => $workflow['room_id'],
            'name' => $workflow['name'],
            'endpoint' => $workflow['endpoint'],
            'enabled' => $workflow['enabled'] ? 'true' : 'false',
            'conditions' => $this->encode($workflow['conditions']),
            'actions' => $this->encode($workflow['actions']),
            'actions_version' => $workflow['actions_version'],
            'priority' => $workflow['priority'],
            'stop_processing' => $workflow['stop_processing'] ? 'true' : 'false',
            'schedule_cron' => $workflow['schedule_cron'],
            'schedule_next_at' => $workflow['schedule_next_at'],
        ]);

        $row = JsonColumns::decode($stmt->fetch(), ['conditions', 'actions']);
        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'created',
        ]);

        WorkflowHistory::snapshot($row, 'created', $request);
        Audit::record([
            'action' => 'workflow.created',
            'object_type' => 'workflow',
            'object_id' => (int) $row['id'],
            'object_label' => (string) $row['name'],
            'room_id' => (int) $row['room_id'],
            'room_slug' => $this->roomSlug((int) $row['room_id']),
            'summary' => 'Создан сценарий «' . $row['name'] . '»',
            'changes' => [
                'endpoint' => [null, $row['endpoint']],
                'enabled' => [null, self::isTrue($row['enabled'] ?? false)],
                'priority' => [null, (int) $row['priority']],
            ],
        ], $request);

        Response::json(['data' => $this->present($row)], 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Сценарий не найден', 404);
        }

        $workflow = $this->validated($request->json() ?? [], $existing);

        // Moving a workflow between rooms would silently change which webhooks it
        // reacts to, so it needs its own explicit action rather than a side effect
        // of editing a name.
        if ($workflow['room_id'] !== (int) $existing['room_id']) {
            Response::error('Сценарий нельзя перенести в другую комнату', 422);
        }

        // Editing a rule and leaving it enabled is also the operator saying
        // "this is fixed": the failure streak and the automatic-pause note are
        // cleared, so the card stops claiming the rule is broken. The fragment is
        // a constant, only its presence depends on the payload.
        $reset = $workflow['enabled']
            ? ', consecutive_failures = 0, disabled_reason = NULL, disabled_at = NULL'
            : '';

        $stmt = Database::pdo()->prepare(
            'UPDATE workflows
             SET name = :name, endpoint = :endpoint, enabled = :enabled,
                 conditions = :conditions, actions = :actions,
                 actions_version = :actions_version,
                 priority = :priority, stop_processing = :stop_processing,
                 schedule_cron = :schedule_cron, schedule_next_at = :schedule_next_at,
                 updated_at = now()' . $reset . '
             WHERE id = :id
             RETURNING *'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $workflow['name'],
            'endpoint' => $workflow['endpoint'],
            'enabled' => $workflow['enabled'] ? 'true' : 'false',
            'conditions' => $this->encode($workflow['conditions']),
            'actions' => $this->encode($workflow['actions']),
            'actions_version' => $workflow['actions_version'],
            'priority' => $workflow['priority'],
            'stop_processing' => $workflow['stop_processing'] ? 'true' : 'false',
            'schedule_cron' => $workflow['schedule_cron'],
            'schedule_next_at' => $workflow['schedule_next_at'],
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        $decoded = JsonColumns::decode($row, ['conditions', 'actions']);
        Realtime::publish('workflow.changed', [
            'id' => (int) $decoded['id'],
            'room_id' => (int) $decoded['room_id'],
            'action' => 'updated',
        ]);

        WorkflowHistory::snapshot($decoded, 'update', $request);

        // The diff describes what the operator meant to change. Nested structures
        // are summarized rather than copied: a condition tree is reported as its
        // leaf count and joiner, an action list as its size, so a rename does not
        // paste the whole rule into the journal. Both sides went through
        // `redactActions()` first, so a signature secret cannot reach it.
        $changes = Audit::diff(
            $this->auditable($existing),
            $this->auditable($decoded),
            ['name', 'endpoint', 'enabled', 'conditions', 'actions', 'priority', 'stop_processing', 'actions_version']
        );

        Audit::record([
            'action' => 'workflow.updated',
            'object_type' => 'workflow',
            'object_id' => (int) $decoded['id'],
            'object_label' => (string) $decoded['name'],
            'room_id' => (int) $decoded['room_id'],
            'room_slug' => $this->roomSlug((int) $decoded['room_id']),
            'summary' => 'Изменён сценарий «' . $decoded['name'] . '»'
                . ($changes === [] ? ' (без изменений)' : ''),
            'changes' => $changes,
        ], $request);

        Response::json(['data' => $this->present($decoded)]);
    }

    public function toggle(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        // Resume/reset semantics: turning a rule back on is the operator saying
        // "this is fixed", so the streak and the automatic-pause note go away.
        // Pausing by hand is not a failure either, so the note is cleared too —
        // otherwise the card would claim a reason that no longer applies.
        $stmt = Database::pdo()->prepare(
            'UPDATE workflows
                SET enabled = NOT enabled,
                    consecutive_failures = CASE WHEN NOT enabled THEN 0 ELSE consecutive_failures END,
                    disabled_reason = NULL,
                    disabled_at = NULL,
                    updated_at = now()
              WHERE id = :id
              RETURNING id, enabled, room_id, schedule_cron'
        );
        $stmt->execute(['id' => (int) ($params['id'] ?? 0)]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        // Re-enabling a scheduled rule must not fire every slot it missed while
        // paused: the next run is computed from now, exactly like a fresh save.
        if (self::isTrue($row['enabled'] ?? false) && (string) ($row['schedule_cron'] ?? '') !== '') {
            $next = Cron::nextAfter(
                (string) $row['schedule_cron'],
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $advance = Database::pdo()->prepare(
                'UPDATE workflows SET schedule_next_at = :next WHERE id = :id'
            );
            $advance->execute(['next' => $next?->format('c'), 'id' => (int) $row['id']]);
        }

        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'toggled',
        ]);

        // A toggle is a configuration change like any other: it decides whether the
        // rule sends anything, so it belongs in the history and in the journal.
        $stored = $this->find((int) $row['id']);
        if ($stored !== null) {
            WorkflowHistory::snapshot($stored, 'toggle', $request);
            Audit::record([
                'action' => 'workflow.toggled',
                'object_type' => 'workflow',
                'object_id' => (int) $row['id'],
                'object_label' => (string) $stored['name'],
                'room_id' => (int) $row['room_id'],
                'room_slug' => $this->roomSlug((int) $row['room_id']),
                'summary' => (self::isTrue($row['enabled'] ?? false) ? 'Включён' : 'Выключен')
                    . ' сценарий «' . $stored['name'] . '»',
                'changes' => ['enabled' => [null, self::isTrue($row['enabled'] ?? false)]],
            ], $request);
        }

        Response::json(['data' => ['id' => (int) $row['id'], 'enabled' => $row['enabled']]]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);

        // Read before the delete: the journal has to name what was removed, and
        // after the row is gone there is nothing left to read.
        $existing = $this->find($id);

        $stmt = Database::pdo()->prepare('DELETE FROM workflows WHERE id = :id RETURNING room_id');
        $stmt->execute(['id' => $id]);

        $deleted = $stmt->fetch();
        if ($deleted !== false) {
            Realtime::publish('workflow.changed', [
                'id' => $id,
                'room_id' => (int) $deleted['room_id'],
                'action' => 'deleted',
            ]);

            Audit::record([
                'action' => 'workflow.deleted',
                'object_type' => 'workflow',
                'object_id' => $id,
                'object_label' => (string) ($existing['name'] ?? ''),
                'room_id' => (int) $deleted['room_id'],
                'room_slug' => $this->roomSlug((int) $deleted['room_id']),
                'summary' => 'Удалён сценарий «' . ($existing['name'] ?? '#' . $id) . '»',
                'changes' => $existing === null ? null : [
                    'endpoint' => [$existing['endpoint'], null],
                    'priority' => [(int) $existing['priority'], null],
                ],
            ], $request);
        }

        Response::json(['ok' => true, 'deleted' => $deleted !== false]);
    }

    /**
     * A room's scenarios as a portable document.
     *
     * Rules are the part of a room that is worth reusing: an operator who built a
     * Stripe router in staging should not rebuild it in production by hand, and a
     * configuration in a file is also the only way to review a change before it
     * goes live.
     *
     * Secrets are the exception. `rules[].actions[].secret` is stored as-is (an
     * HMAC cannot be verified against a hash) and never leaves the API, so the
     * export carries `signed: true` instead and the import reports how many
     * forwards it could not re-sign. An export that quietly dropped the signatures
     * would be worse: the imported rule would look correct and send unsigned
     * requests.
     */
    public function exportRoom(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $room = $this->requireRoom((int) ($params['id'] ?? 0));

        $stmt = Database::pdo()->prepare(
            'SELECT name, endpoint, enabled, conditions, actions, priority, stop_processing, schedule_cron
               FROM workflows
              WHERE room_id = :room_id
              ORDER BY priority DESC, id'
        );
        $stmt->execute(['room_id' => $room['id']]);

        $rules = [];
        foreach (JsonColumns::decodeMany($stmt->fetchAll(), ['conditions', 'actions']) as $row) {
            $rules[] = [
                'name' => (string) $row['name'],
                'endpoint' => (string) $row['endpoint'],
                'enabled' => self::isTrue($row['enabled'] ?? false),
                'priority' => (int) $row['priority'],
                'stop_processing' => self::isTrue($row['stop_processing'] ?? false),
                'schedule_cron' => $row['schedule_cron'],
                'conditions' => WorkflowEngine::normalizeTree($row['conditions'] ?? []),
                'actions' => $this->redactActions($row['actions'] ?? []),
            ];
        }

        $document = [
            'format' => 'hookdock.room',
            'version' => 1,
            'exported_at' => gmdate('c'),
            // The room block is informational: `import` ignores it unless asked,
            // because applying delivery settings while importing rules would change
            // how the receiving room accepts traffic without saying so.
            'room' => [
                'name' => (string) $room['name'],
                'slug' => (string) $room['slug'],
                'endpoint' => (string) $room['endpoint'],
                'dedup_header' => $room['dedup_header'],
                'require_timestamp' => self::isTrue($room['require_timestamp'] ?? false),
                'has_secret' => self::isTrue($room['has_secret'] ?? false),
            ],
            'workflows' => $rules,
        ];

        $name = 'hookdock-' . preg_replace('/[^a-z0-9-]+/i', '-', (string) $room['slug']) . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        exit;
    }

    /**
     * Creates the rules of an exported document in this room.
     *
     * All or nothing on purpose: a half-imported rule set is worse than a refused
     * one, because the operator cannot tell which half is missing. Every rule goes
     * through the same `validated()` path as the editor, so an imported document
     * cannot introduce a condition tree or an action the UI could not have made.
     *
     * A name that already exists gets a suffix instead of an error: importing the
     * same file twice is a normal way to check that nothing broke, and the second
     * import should not look like a failure. Nothing is deleted or overwritten —
     * a "replace" mode would be a data-loss button, not an import.
     */
    public function importRoom(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $room = $this->requireRoom((int) ($params['id'] ?? 0));
        $payload = $request->json() ?? [];

        $incoming = $payload['workflows'] ?? null;
        // Accept the document as-is and a bare `{"workflows": [...]}`: pasting only
        // the rules is what an operator does when they trimmed the file.
        if (!is_array($incoming)) {
            Response::error('Ожидается документ с полем workflows', 422);
        }
        if (count($incoming) > self::MAX_IMPORT) {
            Response::error('За один импорт можно создать не более ' . self::MAX_IMPORT . ' сценариев', 422);
        }

        $names = [];
        $stmt = Database::pdo()->prepare('SELECT name FROM workflows WHERE room_id = :room_id');
        $stmt->execute(['room_id' => $room['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
            $names[mb_strtolower((string) $existing)] = true;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        $created = [];
        $unsigned = 0;

        try {
            foreach (array_values($incoming) as $rule) {
                if (!is_array($rule)) {
                    Response::error('Каждый сценарий должен быть объектом', 422);
                }

                // `room_id` is taken from the URL, never from the document: an
                // imported file must not be able to write into another room.
                $rule['room_id'] = $room['id'];
                $rule['name'] = $this->uniqueName((string) ($rule['name'] ?? ''), $names);

                $workflow = $this->validated($rule);

                // A signature is a secret, and a secret is not in the file. The
                // export marks a signed forward with `signed: true` and no value, so
                // the import can only count them — that count is the operator's only
                // warning that the imported rule will send unsigned requests.
                //
                // Read from the document, not from `$workflow`: normalization drops
                // `signed` (it is a read-only view of a stored secret), so counting
                // after it would always answer zero.
                foreach (is_array($rule['actions'] ?? null) ? $rule['actions'] : [] as $documentAction) {
                    if (is_array($documentAction) && self::isTrue($documentAction['signed'] ?? false)) {
                        $unsigned++;
                    }
                }

                $insert = $pdo->prepare(
                    'INSERT INTO workflows
                        (room_id, name, endpoint, enabled, conditions, actions, actions_version, priority, stop_processing, schedule_cron, schedule_next_at)
                     VALUES
                        (:room_id, :name, :endpoint, :enabled, :conditions, :actions, 1, :priority, :stop_processing, :schedule_cron, :schedule_next_at)
                     RETURNING id, room_id, name, endpoint, enabled, conditions, actions, actions_version, priority, stop_processing, schedule_cron, schedule_next_at'
                );
                $insert->execute([
                    'room_id' => $workflow['room_id'],
                    'name' => $workflow['name'],
                    'endpoint' => $workflow['endpoint'],
                    'enabled' => $workflow['enabled'] ? 'true' : 'false',
                    'conditions' => $this->encode($workflow['conditions']),
                    'actions' => $this->encode($workflow['actions']),
                    'priority' => $workflow['priority'],
                    'stop_processing' => $workflow['stop_processing'] ? 'true' : 'false',
                    'schedule_cron' => $workflow['schedule_cron'],
                    'schedule_next_at' => $workflow['schedule_next_at'],
                ]);

                $row = $insert->fetch();
                $created[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
                $names[mb_strtolower((string) $row['name'])] = true;

                // Inside the transaction: a rolled-back import must not leave
                // history rows for rules that do not exist.
                WorkflowHistory::snapshot($row, 'import', $request);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        Realtime::publish('workflow.changed', [
            'id' => 0,
            'room_id' => $room['id'],
            'action' => 'imported',
        ]);

        Audit::record([
            'action' => 'workflow.imported',
            'object_type' => 'room',
            'object_id' => (int) $room['id'],
            'object_label' => (string) $room['name'],
            'room_id' => (int) $room['id'],
            'room_slug' => (string) $room['slug'],
            'summary' => 'Импортировано сценариев: ' . count($created),
            'changes' => [
                'created' => [0, count($created)],
                'secrets_not_imported' => [null, $unsigned],
            ],
        ], $request);

        Response::json([
            'data' => [
                'created' => count($created),
                'workflows' => $created,
                // Not an error: the file cannot contain a secret, and pretending
                // otherwise would make the import look complete when it is not.
                'secrets_not_imported' => $unsigned,
            ],
        ], 201);
    }

    /**
     * The saved configurations of one rule, newest first.
     *
     * Read-only and available to every role: seeing what changed is the point of
     * keeping the history, and an editor who cannot read it would go back to
     * guessing.
     */
    public function versions(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $id = (int) ($params['id'] ?? 0);
        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Сценарий не найден', 404);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, version, actions_version, name, endpoint, enabled, priority,
                    stop_processing, schedule_cron, actor_email, change, created_at
               FROM workflow_versions
              WHERE workflow_id = :workflow_id
              ORDER BY version DESC
              LIMIT 100'
        );
        $stmt->execute(['workflow_id' => $id]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['version'] = (int) $row['version'];
            $row['actions_version'] = (int) $row['actions_version'];
            $row['priority'] = (int) $row['priority'];
            $row['enabled'] = self::isTrue($row['enabled'] ?? false);
            $row['stop_processing'] = self::isTrue($row['stop_processing'] ?? false);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'meta' => [
                'workflow_id' => $id,
                'current_version' => (int) ($rows[0]['version'] ?? 0),
                'actions_version' => (int) $existing['actions_version'],
            ],
        ]);
    }

    /**
     * One saved configuration, as it would be restored.
     *
     * The snapshot goes through `present()`-style redaction so a version cannot be
     * the one endpoint that leaks a signature secret: the stored value is needed
     * for a rollback, not for a read.
     */
    public function version(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $id = (int) ($params['id'] ?? 0);
        $version = (int) ($params['version'] ?? 0);

        if ($this->find($id) === null) {
            Response::error('Сценарий не найден', 404);
        }

        $snapshot = WorkflowHistory::find($id, $version);
        if ($snapshot === null) {
            Response::error('Версия не найдена', 404);
        }

        $snapshot['id'] = (int) $snapshot['id'];
        $snapshot['workflow_id'] = (int) $snapshot['workflow_id'];
        $snapshot['version'] = (int) $snapshot['version'];
        $snapshot['conditions'] = WorkflowEngine::normalizeTree($snapshot['conditions'] ?? []);
        $snapshot['actions'] = $this->redactActions($snapshot['actions'] ?? []);

        Response::json(['data' => $snapshot]);
    }

    /**
     * Restores a saved configuration.
     *
     * A rollback is a new save, not a rewrite of history: the current state is
     * snapshotted first (so the rollback itself can be undone), and the restored
     * configuration becomes the newest version. Deleting the versions in between
     * would make "who put this back and when" unanswerable, which is the reason the
     * history exists.
     *
     * `actions_version` follows the same rule as a normal save: it only grows when
     * the action list actually changes, so restoring a version that differs only in
     * a name does not invalidate another tab's draft.
     */
    public function rollback(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);
        $version = (int) ($params['version'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Сценарий не найден', 404);
        }

        $snapshot = WorkflowHistory::find($id, $version);
        if ($snapshot === null) {
            Response::error('Версия не найдена', 404);
        }

        $actionsVersion = (int) $existing['actions_version'];
        if (!WorkflowHistory::sameActions($snapshot, $existing)) {
            $actionsVersion++;
        }

        // The snapshot carries the trigger as well, so a rollback restores a rule
        // that fired on a schedule as a scheduled rule. The next slot is computed
        // from now: replaying every slot since the version was saved would be a
        // burst, not a restore.
        $schedule = trim((string) ($snapshot['schedule_cron'] ?? ''));
        $schedule = $schedule === '' ? null : $schedule;
        $scheduleNextAt = null;
        if ($schedule !== null) {
            $next = Cron::nextAfter($schedule, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $scheduleNextAt = $next?->format('c');
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE workflows
                SET name = :name, endpoint = :endpoint, enabled = :enabled,
                    conditions = :conditions, actions = :actions,
                    actions_version = :actions_version, priority = :priority,
                    stop_processing = :stop_processing,
                    schedule_cron = :schedule_cron, schedule_next_at = :schedule_next_at,
                    updated_at = now()
              WHERE id = :id
              RETURNING *'
        );
        $stmt->execute([
            'id' => $id,
            'name' => (string) $snapshot['name'],
            'endpoint' => (string) $snapshot['endpoint'],
            'enabled' => self::isTrue($snapshot['enabled'] ?? false) ? 'true' : 'false',
            'conditions' => $this->encode(
                WorkflowEngine::normalizeTree($snapshot['conditions'] ?? [])
            ),
            'actions' => $this->encode(is_array($snapshot['actions'] ?? null) ? $snapshot['actions'] : []),
            'actions_version' => $actionsVersion,
            'priority' => (int) $snapshot['priority'],
            'stop_processing' => self::isTrue($snapshot['stop_processing'] ?? false) ? 'true' : 'false',
            'schedule_cron' => $schedule,
            'schedule_next_at' => $scheduleNextAt,
        ]);

        $row = JsonColumns::decode($stmt->fetch(), ['conditions', 'actions']);

        if (self::isTrue($row['enabled'] ?? false)) {
            // Restoring an enabled rule is also the operator saying "this should
            // work", so the failure streak of the replaced configuration goes away.
            $reset = Database::pdo()->prepare(
                'UPDATE workflows
                    SET consecutive_failures = 0, disabled_reason = NULL, disabled_at = NULL
                  WHERE id = :id'
            );
            $reset->execute(['id' => $id]);
            $row['consecutive_failures'] = 0;
            $row['disabled_reason'] = null;
            $row['disabled_at'] = null;
        }

        Realtime::publish('workflow.changed', [
            'id' => $id,
            'room_id' => (int) $row['room_id'],
            'action' => 'updated',
        ]);

        WorkflowHistory::snapshot($row, 'rollback', $request);

        Audit::record([
            'action' => 'workflow.rolled_back',
            'object_type' => 'workflow',
            'object_id' => $id,
            'object_label' => (string) $row['name'],
            'room_id' => (int) $row['room_id'],
            'room_slug' => $this->roomSlug((int) $row['room_id']),
            'summary' => 'Сценарий «' . $row['name'] . '» возвращён к версии ' . $version,
            'changes' => ['version' => [(int) $existing['actions_version'], $actionsVersion]],
        ], $request);

        Response::json([
            'data' => $this->present($row),
            'meta' => ['restored_from' => $version],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRoom(int $id): array
    {
        $room = RoomResolver::findById($id);
        if ($room === null) {
            Response::error('Комната не найдена', 404);
        }

        return $room;
    }

    /**
     * A name that does not collide within the room, so a repeated import cannot
     * fail on something the operator cannot see in the file.
     *
     * @param array<string, true> $taken lowercase names already in the room
     */
    private function uniqueName(string $name, array $taken): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Импортированный сценарий';
        }

        // The column is VARCHAR(160); the suffix has to fit inside it.
        $name = mb_substr($name, 0, 150);

        if (!isset($taken[mb_strtolower($name)])) {
            return $name;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $name . ' (' . $n . ')';
            if (!isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return mb_substr($name, 0, 140) . ' (' . bin2hex(random_bytes(3)) . ')';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $row = $this->withAutoPaused($row);
        $row['condition_count'] = WorkflowEngine::conditionCount(
            WorkflowEngine::normalizeTree($row['conditions'] ?? [])
        );
        $row['conditions'] = WorkflowEngine::normalizeTree($row['conditions'] ?? []);
        $row['actions'] = $this->redactActions($row['actions'] ?? []);

        // `trigger_type` is derived, not stored twice: `schedule_cron` is the switch,
        // and a second column would be one more thing to keep in sync.
        $schedule = trim((string) ($row['schedule_cron'] ?? ''));
        $row['schedule_cron'] = $schedule === '' ? null : $schedule;
        $row['trigger_type'] = $schedule === '' ? 'webhook' : 'schedule';
        $row['schedule_description'] = $schedule === '' ? null : Cron::describe($schedule);

        return $row;
    }

    /**
     * The shape a diff is allowed to see: secrets removed, trees and lists reduced
     * to something a human reads in one line.
     *
     * `Audit::diff` already summarizes arrays, but it summarizes an *unredacted*
     * one — an action carrying `secret` would be compared (and, on a mismatch,
     * journalled) with its value in it. Redacting first makes the credential
     * unable to reach the table at all, rather than relying on the shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function auditable(array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'enabled' => self::isTrue($row['enabled'] ?? false),
            // The normalized tree, not a leaf counter: `Audit::shape()` keeps a
            // small structure verbatim, so the entry can say which condition changed
            // instead of only that the count stayed the same.
            'conditions' => WorkflowEngine::normalizeTree($row['conditions'] ?? []),
            'actions' => Audit::redactActions(
                is_array($row['actions'] ?? null) ? array_values($row['actions']) : []
            ),
            'priority' => (int) ($row['priority'] ?? 0),
            'stop_processing' => self::isTrue($row['stop_processing'] ?? false),
            'schedule_cron' => trim((string) ($row['schedule_cron'] ?? '')) === ''
                ? null
                : (string) $row['schedule_cron'],
            'actions_version' => (int) ($row['actions_version'] ?? 1),
        ];
    }

    /**
     * The room slug for a journal entry, or null when the room is already gone.
     *
     * The value is copied into `audit_log` on purpose: the record has to stay
     * readable after the room is deleted, which a join could not do.
     */
    private function roomSlug(int $roomId): ?string
    {
        if ($roomId <= 0) {
            return null;
        }

        $room = RoomResolver::findById($roomId);

        return $room === null ? null : (string) $room['slug'];
    }

    /**
     * Replaces a stored signature secret with `signed: true`.
     *
     * The secret cannot be hashed (an HMAC is verified against the same value), so
     * it is stored as-is and must therefore never leave the API. The editor keeps
     * showing a filled field from `signed` and only overwrites the secret when the
     * operator types a new one — sending an empty `secret` for an already signed
     * action means "leave it alone", which is why `validated()` reads `signed` too.
     *
     * @param mixed $actions
     * @return list<array<string, mixed>>
     */
    private function redactActions(mixed $actions): array
    {
        if (!is_array($actions)) {
            return [];
        }

        $clean = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            if ((string) ($action['type'] ?? '') === 'forward' && (string) ($action['secret'] ?? '') !== '') {
                $action['signed'] = true;
            }
            unset($action['secret']);
            $clean[] = $action;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withAutoPaused(array $row): array
    {
        // `enabled` may arrive as a bool or as Postgres' 't'/'f' string depending
        // on the driver build, so the comparison is explicit.
        $row['auto_paused'] = !self::isTrue($row['enabled'] ?? false) && !empty($row['disabled_reason']);

        return $row;
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        // `*` rather than a short list: the version check and the "keep the stored
        // signature secret" rule both need the stored row, and a column added later
        // must not be forgotten here — a missing tag is easy to miss, a missing
        // field in this query is not.
        $stmt = Database::pdo()->prepare('SELECT * FROM workflows WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return JsonColumns::decode($row, ['conditions', 'actions']);
    }

    /**
     * @return int|null null when the client asks for every room
     */
    private function optionalRoomId(Request $request): ?int
    {
        $slug = trim((string) ($request->query['room'] ?? ''));
        if ($slug === '') {
            return null;
        }

        return $this->requireRoomId(['room' => $slug]);
    }

    /**
     * Resolves the target room from `room_id` or `room` (slug) and fails loudly
     * when it does not exist.
     *
     * @param array<string, mixed> $payload
     */
    private function requireRoomId(array $payload): int
    {
        $id = (int) ($payload['room_id'] ?? 0);
        if ($id > 0) {
            $room = RoomResolver::findById($id);
            if ($room === null) {
                Response::error('Комната не найдена', 422);
            }

            return $room['id'];
        }

        $slug = trim((string) ($payload['room'] ?? ''));
        if ($slug !== '') {
            $room = RoomResolver::findBySlug($slug);
            if ($room === null) {
                Response::error('Комната не найдена: ' . $slug, 422);
            }

            return $room['id'];
        }

        Response::error('Укажите комнату для сценария', 422);
    }

    /**
     * Validates a condition tree.
     *
     * The payload may send the new `{mode, rules}` object or the legacy flat list;
     * the list is wrapped as `mode: all`, so an older client keeps working and a
     * rule does not change meaning when the editor upgrades.
     *
     * @param mixed $input
     * @param int $depth
     * @return array<string, mixed>
     */
    private function validTree(mixed $input, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            Response::error('Условия вложены слишком глубоко (максимум ' . self::MAX_DEPTH . ')', 422);
        }

        if (!is_array($input)) {
            Response::error('Условия должны быть массивом или группой', 422);
        }

        // A list of conditions is the old shape: an implicit `all` group.
        if (array_is_list($input)) {
            return ['mode' => 'all', 'rules' => $this->validRules($input, $depth)];
        }

        // A lone condition — `{"path": …, "operator": …}` — is a group of one.
        // Without this it would read as a group with no rules, pass validation as
        // "no conditions" and store a branch that always runs: the worst possible
        // way for a branch to be wrong, and it looks saved.
        if (isset($input['path']) && !isset($input['rules'])) {
            return ['mode' => 'all', 'rules' => $this->validRules([$input], $depth)];
        }

        $mode = strtolower((string) ($input['mode'] ?? 'all'));
        if (!in_array($mode, ['all', 'any'], true)) {
            Response::error('Режим группы условий: all или any', 422);
        }

        $rules = $input['rules'] ?? [];
        if (!is_array($rules)) {
            Response::error('Правила группы должны быть массивом', 422);
        }

        return ['mode' => $mode, 'rules' => $this->validRules($rules, $depth)];
    }

    /**
     * @param array<mixed> $rules
     * @return list<array<string, mixed>>
     */
    private function validRules(array $rules, int $depth): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                Response::error('Каждое условие должно быть объектом', 422);
            }

            // A nested group has `rules` and no `path`; anything else is a leaf.
            if (isset($rule['rules']) && !isset($rule['path'])) {
                $normalized[] = $this->validTree($rule, $depth + 1);
                continue;
            }

            $path = trim((string) ($rule['path'] ?? ''));
            $operator = (string) ($rule['operator'] ?? 'equals');

            if ($path === '') {
                Response::error('У каждого условия должен быть путь к полю', 422);
            }
            if (!in_array($operator, self::OPERATORS, true)) {
                Response::error('Неподдерживаемый оператор условия: ' . $operator, 422);
            }

            $normalized[] = [
                'path' => $path,
                'operator' => $operator,
                'value' => $rule['value'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $existing the stored row when editing
     * @return array{room_id: int, name: string, endpoint: string, enabled: bool, conditions: array<string, mixed>, actions: list<array<string, mixed>>, actions_version: int, priority: int, stop_processing: bool}
     */
    private function validated(array $payload, ?array $existing = null): array
    {
        $roomId = $this->requireRoomId($payload);

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            Response::error('Укажите название сценария', 422);
        }

        $endpoint = trim((string) ($payload['endpoint'] ?? '*'));
        $endpoint = trim($endpoint, "/ \t\n\r\0\x0B");
        if ($endpoint === '') {
            $endpoint = '*';
        }
        if (mb_strlen($endpoint) > 160) {
            Response::error('Slug эндпоинта должен быть не длиннее 160 символов', 422);
        }

        // A rule fires either on a webhook or on a schedule, and `schedule_cron` is
        // the switch — a second boolean would be one more thing to keep in sync.
        // An empty value clears the schedule, which is how the editor turns a rule
        // back into a webhook one.
        //
        // An **omitted** key keeps the stored expression, like every other delivery
        // setting: a client that never knew about schedules must not turn a
        // scheduled rule into a webhook one by renaming it.
        $storedSchedule = trim((string) ($existing['schedule_cron'] ?? ''));
        $storedSchedule = $storedSchedule === '' ? null : $storedSchedule;

        if (array_key_exists('schedule_cron', $payload)) {
            $schedule = trim((string) ($payload['schedule_cron'] ?? ''));
            $schedule = $schedule === '' ? null : $schedule;
        } else {
            $schedule = $storedSchedule;
        }

        $scheduleNextAt = null;

        if ($schedule !== null) {
            // Recomputing an unchanged expression would move the slot on every
            // rename; the stored one is already correct (and if it is in the past,
            // the worker fires it once and moves on).
            if ($schedule === $storedSchedule && !empty($existing['schedule_next_at'])) {
                $scheduleNextAt = (string) $existing['schedule_next_at'];
            } else {
                if (!Cron::isValid($schedule)) {
                    Response::error(
                        'Расписание: нужно cron-выражение из 5 полей (минуты часы день месяц день недели), '
                        . 'например 0 9 * * 1-5',
                        422,
                    );
                }

                // A well-formed expression can still never match — `0 0 31 2 *` is the
                // classic. Refusing it here is better than storing a rule that sits in
                // the scheduler forever and never fires.
                $next = Cron::nextAfter($schedule, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                if ($next === null) {
                    Response::error('Расписание не сработает ни разу: проверьте день и месяц', 422);
                }

                $scheduleNextAt = $next->format('c');
            }
        }

        $conditions = $this->validTree($payload['conditions'] ?? []);
        $count = WorkflowEngine::conditionCount($conditions);
        if ($count > self::MAX_CONDITIONS) {
            Response::error('Допускается не более ' . self::MAX_CONDITIONS . ' условий', 422);
        }

        $priority = (int) ($payload['priority'] ?? 0);
        if ($priority < 0 || $priority > 1000) {
            Response::error('Приоритет: число от 0 до 1000', 422);
        }

        $actions = $payload['actions'] ?? [];
        if (!is_array($actions)) {
            Response::error('Действия должны быть массивом', 422);
        }

        // Optimistic locking on the action list only.
        //
        // Two tabs editing one rule used to end with the last save silently
        // replacing the other's action list — including a forward that was just
        // added. The editor sends the `actions_version` it loaded; any other version
        // is refused with `409` and the version currently stored, so the editor can
        // show "reload" instead of overwriting. A client that sends no version
        // (curl, older scripts) keeps working: requiring the field would break every
        // existing integration for a protection it never asked for.
        //
        // Only the actions are guarded: renaming a rule or changing its priority in
        // one tab must not invalidate a condition edit in another.
        $storedVersion = $existing === null ? null : (int) ($existing['actions_version'] ?? 1);
        $sentVersion = null;
        if (array_key_exists('actions_version', $payload) && $payload['actions_version'] !== null && $payload['actions_version'] !== '') {
            $sentVersion = (int) $payload['actions_version'];
        }

        $previous = [];
        if ($existing !== null) {
            // `find()` decodes the stored row, but an older caller may still hand in
            // a raw string: decoding is idempotent, so both shapes work.
            $decodedExisting = $existing['actions'] ?? [];
            if (is_string($decodedExisting)) {
                $decodedExisting = json_decode($decodedExisting, true);
            }
            $previous = is_array($decodedExisting) ? array_values($decodedExisting) : [];
        }

        $normalizedActions = $this->validActions($actions, $previous, $roomId);

        if ($storedVersion !== null && $sentVersion !== null && $sentVersion !== $storedVersion) {
            Response::error(
                'Сценарий изменён в другой вкладке (версия действий ' . $storedVersion . '). '
                . 'Перезагрузите его, чтобы не потерять чужие правки',
                409,
                ['actions_version' => $storedVersion],
            );
        }

        // A real change bumps the version; a save that leaves the action list as it
        // was must not, or merely opening the editor and pressing "save" would
        // reject the other tab's pending edit for no reason.
        //
        // Compared by canonical JSON, not by `!==`: `jsonb` does not preserve object
        // key order, so a decoded stored action and a freshly normalized one can be
        // equal data with different key order — and `!==` would report a change on
        // every save.
        $version = $storedVersion === null ? 1 : $storedVersion;
        if ($existing !== null && $this->fingerprint($normalizedActions) !== $this->fingerprint($previous)) {
            $version = $storedVersion + 1;
        }

        return [
            'room_id' => $roomId,
            'name' => $name,
            'endpoint' => $endpoint,
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'conditions' => $conditions,
            'actions' => $normalizedActions,
            'actions_version' => $version,
            'priority' => $priority,
            'stop_processing' => (bool) ($payload['stop_processing'] ?? false),
            'schedule_cron' => $schedule,
            'schedule_next_at' => $scheduleNextAt,
        ];
    }

    /**
     * Validates and normalizes the action list.
     *
     * @param array<mixed> $actions
     * @param list<array<string, mixed>> $previous stored actions, for the "keep the
     *        signature secret the client cannot see" rule
     * @return list<array<string, mixed>>
     */
    private function validActions(array $actions, array $previous, int $roomId): array
    {
        if ($actions === []) {
            Response::error('Нужно хотя бы одно действие', 422);
        }
        if (count($actions) > 20) {
            Response::error('Допускается не более 20 действий', 422);
        }

        $normalizedActions = [];
        // `when` is collected during the loop and attached at the end: every branch
        // below appends exactly one action and `continue`s, so the input index is
        // the output index, and re-attaching once keeps each normalizer free of a
        // repeated "and also the branch condition" line that a new action type
        // would forget.
        $whens = [];

        foreach ($actions as $index => $action) {
            if (!is_array($action)) {
                Response::error('Каждое действие должно быть объектом', 422);
            }

            $type = (string) ($action['type'] ?? '');
            if (!in_array($type, self::ACTION_TYPES, true)) {
                Response::error('Неподдерживаемый тип действия: ' . $type, 422);
            }

            // A per-action `when` is a condition tree like any other, so it goes
            // through the same validator. Validation here matters more than on the
            // rule itself: a branch with a typo would otherwise evaluate to "no
            // conditions" and run unconditionally.
            $when = null;
            if (array_key_exists('when', $action) && $action['when'] !== null && $action['when'] !== []) {
                $when = $this->validTree($action['when']);
                if (WorkflowEngine::conditionCount($when) > self::MAX_CONDITIONS) {
                    Response::error('У действия слишком много условий (максимум ' . self::MAX_CONDITIONS . ')', 422);
                }
            }
            $whens[$index] = $when;

            // A chat message. The channel holds the credential and the shape, so the
            // action carries only the id and the text: copying a bot token into every
            // rule would make rotating one a hunt through the action lists.
            if ($type === 'notify') {
                $channelId = (int) ($action['channel_id'] ?? 0);
                if ($channelId <= 0) {
                    Response::error('Уведомление: выберите канал', 422);
                }

                // Checked here, not only in the worker: a rule saved with an id that
                // does not exist would fail on the first delivery, and the operator
                // would find out from a failed run instead of from the form.
                $channel = NotificationChannel::find($channelId);
                if ($channel === null) {
                    Response::error('Уведомление: канал не найден', 422);
                }
                if ((int) $channel['room_id'] !== $roomId) {
                    // A rule cannot post into another room's chat: a typo in an id
                    // would otherwise become a cross-room leak of payload text.
                    Response::error('Уведомление: канал принадлежит другой комнате', 422);
                }

                $message = (string) ($action['message'] ?? '');
                if (trim($message) === '') {
                    Response::error('Уведомление: укажите текст сообщения', 422);
                }
                if (mb_strlen($message) > 2000) {
                    Response::error('Уведомление: текст не длиннее 2000 символов', 422);
                }

                $normalizedActions[] = [
                    'type' => 'notify',
                    'channel_id' => $channelId,
                    'message' => $message,
                ];
                continue;
            }

            if ($type === 'forward') {
                $url = trim((string) ($action['url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    Response::error('Для пересылки нужен корректный URL', 422);
                }
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    Response::error('URL пересылки должен использовать http или https', 422);
                }

                $method = strtoupper((string) ($action['method'] ?? 'POST'));
                if (!in_array($method, ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'], true)) {
                    Response::error('Неподдерживаемый HTTP-метод пересылки: ' . $method, 422);
                }

                $headers = [];
                foreach (is_array($action['headers'] ?? null) ? $action['headers'] : [] as $headerName => $headerValue) {
                    $headerName = trim((string) $headerName);
                    if ($headerName === '') {
                        continue;
                    }
                    if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $headerName) !== 1) {
                        Response::error('Недопустимое имя заголовка: ' . $headerName, 422);
                    }
                    $headers[$headerName] = (string) $headerValue;
                }

                // The signature secret is stored with the action because an HMAC
                // cannot be verified against a hash. It is never echoed back: the
                // API replaces it with `signed: true`, so a listing endpoint cannot
                // leak a credential the way `rooms.secret` is protected against.
                //
                // Because the client therefore never sees the value, an empty
                // `secret` on an action that already had one means "keep it" — an
                // edit of any other field must not silently unsign the forward.
                // Clearing it is its own explicit request (`clear_signature`).
                //
                // The old value is taken only from the action at the same index with
                // the same URL: reordering the forwards in the editor would otherwise
                // move one target's secret onto another target.
                $secret = trim((string) ($action['secret'] ?? ''));
                if ($secret === '') {
                    $was = $previous[$index] ?? null;
                    if (
                        is_array($was)
                        && (string) ($was['type'] ?? '') === 'forward'
                        && (string) ($was['url'] ?? '') === $url
                    ) {
                        $secret = (string) ($was['secret'] ?? '');
                    }
                }
                if (self::isTrue($action['clear_signature'] ?? false)) {
                    $secret = '';
                }

                // An action-level timeout, in seconds. Absent means "use
                // FORWARD_TIMEOUT", which is why the key is omitted rather than
                // stored as 0: 0 would have to be special-cased in two places.
                $timeout = null;
                if (array_key_exists('timeout', $action) && $action['timeout'] !== null && $action['timeout'] !== '') {
                    $timeout = (int) $action['timeout'];
                    if ($timeout < 1 || $timeout > 60) {
                        Response::error('Таймаут пересылки: от 1 до 60 секунд', 422);
                    }
                }

                $forward = [
                    'type' => 'forward',
                    'url' => $url,
                    'method' => $method,
                    'payload' => $action['payload'] ?? 'body',
                    'headers' => $headers,
                    'secret' => $secret,
                ];

                if ($timeout !== null) {
                    $forward['timeout'] = $timeout;
                }

                // Opt-in, because a recipient that verifies a body-only HMAC
                // rejects the `"<ts>.<body>"` form this flag produces.
                if (self::isTrue($action['sign_timestamp'] ?? false)) {
                    $forward['sign_timestamp'] = true;
                }

                $normalizedActions[] = $forward;
                continue;
            }

            if ($type === 'delay') {
                $seconds = (int) ($action['seconds'] ?? 0);
                if ($seconds < 1 || $seconds > 86400) {
                    Response::error('Задержка: от 1 до 86400 секунд', 422);
                }

                $normalizedActions[] = ['type' => 'delay', 'seconds' => $seconds];
                continue;
            }

            if ($type === 'transform') {
                $mapping = $action['mapping'] ?? [];
                if (!is_array($mapping)) {
                    Response::error('Маппинг трансформации должен быть объектом', 422);
                }

                $normalizedActions[] = ['type' => 'transform', 'mapping' => $mapping];
                continue;
            }

            if ($type === 'extract') {
                $source = (string) ($action['source'] ?? 'body');
                if (!in_array($source, self::EXTRACT_SOURCES, true)) {
                    Response::error('Извлечение: неизвестный источник ' . $source, 422);
                }

                $mapping = $action['mapping'] ?? [];
                if (!is_array($mapping)) {
                    Response::error('Маппинг извлечения должен быть объектом', 422);
                }

                // Values are paths, not values: a mapping of `{"id": "42"}` would be a
                // constant that looks like a field, so a number is refused instead of
                // silently reading a key named "42".
                $paths = [];
                foreach ($mapping as $key => $path) {
                    $key = trim((string) $key);
                    if ($key === '') {
                        continue;
                    }
                    if (!is_string($path) && !is_numeric($path)) {
                        Response::error('Извлечение: значение для «' . $key . '» должно быть путём', 422);
                    }
                    // An empty path is allowed and means "the whole source", which is
                    // how a response body is passed on as one object.
                    $paths[$key] = trim((string) $path);
                }

                if ($paths === []) {
                    Response::error('Извлечение: укажите хотя бы одно поле', 422);
                }

                if (count($paths) > 30) {
                    Response::error('Извлечение: не более 30 полей', 422);
                }

                $normalizedActions[] = ['type' => 'extract', 'source' => $source, 'mapping' => $paths];
                continue;
            }

            $normalizedActions[] = [
                'type' => 'log',
                'message' => (string) ($action['message'] ?? 'Webhook matched workflow conditions'),
            ];
        }

        // Attach the branch conditions collected above. `when` sits first in the
        // stored object so a reader of the raw jsonb sees the gate before the
        // action it guards.
        foreach ($whens as $index => $when) {
            if ($when === null || !isset($normalizedActions[$index])) {
                continue;
            }

            $normalizedActions[$index] = ['when' => $when] + $normalizedActions[$index];
        }

        return $normalizedActions;
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Order-independent representation of a decoded `jsonb` value.
     *
     * `jsonb` normalizes key order, so two saves of the same action list can decode
     * to arrays that compare unequal with `!==`. Sorting keys recursively gives a
     * string that is equal exactly when the data is.
     *
     * @param mixed $value
     */
    private function fingerprint(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->fingerprint($item);
            }
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
