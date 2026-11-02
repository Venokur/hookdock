<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Services\WorkflowEngine;
use App\Support\JsonColumns;

final class WorkflowsController
{
    private const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains',
        'exists', 'not_exists', 'gt', 'gte', 'lt', 'lte', 'regex',
    ];

    private const ACTION_TYPES = ['forward', 'log', 'transform', 'delay'];

    /** Nesting depth of a condition tree. Deep trees are hard to reason about. */
    private const MAX_DEPTH = 5;

    /** Leaves in the whole tree, not per level. */
    private const MAX_CONDITIONS = 40;

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
            'INSERT INTO workflows (room_id, name, endpoint, enabled, conditions, actions, priority, stop_processing)
             SELECT room_id,
                    left(name, 140) || \' (копия)\',
                    endpoint,
                    false,
                    conditions,
                    actions,
                    priority,
                    stop_processing
               FROM workflows
              WHERE id = :id
             RETURNING *'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        $row = $this->present(JsonColumns::decode($row, ['conditions', 'actions']));

        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'created',
        ]);

        Response::json(['data' => $row], 201);
    }

    public function store(Request $request): void
    {
        Auth::requireUser($request, true);
        $workflow = $this->validated($request);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflows (room_id, name, endpoint, enabled, conditions, actions, priority, stop_processing)
             VALUES (:room_id, :name, :endpoint, :enabled, :conditions, :actions, :priority, :stop_processing)
             RETURNING *'
        );
        $stmt->execute([
            'room_id' => $workflow['room_id'],
            'name' => $workflow['name'],
            'endpoint' => $workflow['endpoint'],
            'enabled' => $workflow['enabled'] ? 'true' : 'false',
            'conditions' => $this->encode($workflow['conditions']),
            'actions' => $this->encode($workflow['actions']),
            'priority' => $workflow['priority'],
            'stop_processing' => $workflow['stop_processing'] ? 'true' : 'false',
        ]);

        $row = JsonColumns::decode($stmt->fetch(), ['conditions', 'actions']);
        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'created',
        ]);

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

        $workflow = $this->validated($request, $existing);

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
                 priority = :priority, stop_processing = :stop_processing,
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
            'priority' => $workflow['priority'],
            'stop_processing' => $workflow['stop_processing'] ? 'true' : 'false',
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
              RETURNING id, enabled, room_id'
        );
        $stmt->execute(['id' => (int) ($params['id'] ?? 0)]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Сценарий не найден', 404);
        }

        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'toggled',
        ]);

        Response::json(['data' => ['id' => (int) $row['id'], 'enabled' => $row['enabled']]]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request, true);

        $id = (int) ($params['id'] ?? 0);
        $stmt = Database::pdo()->prepare('DELETE FROM workflows WHERE id = :id RETURNING room_id');
        $stmt->execute(['id' => $id]);

        $deleted = $stmt->fetch();
        if ($deleted !== false) {
            Realtime::publish('workflow.changed', [
                'id' => $id,
                'room_id' => (int) $deleted['room_id'],
                'action' => 'deleted',
            ]);
        }

        Response::json(['ok' => true, 'deleted' => $deleted !== false]);
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

        return $row;
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

        $stmt = Database::pdo()->prepare('SELECT id, room_id, actions FROM workflows WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
     * @return array{room_id: int, name: string, endpoint: string, enabled: bool, conditions: array<string, mixed>, actions: list<array<string, mixed>>, priority: int, stop_processing: bool}
     */
    private function validated(Request $request, ?array $existing = null): array
    {
        $payload = $request->json() ?? [];

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

        $previous = [];
        if ($existing !== null) {
            $decodedExisting = $existing['actions'] ?? [];
            if (is_string($decodedExisting)) {
                $decodedExisting = json_decode($decodedExisting, true);
            }
            $previous = is_array($decodedExisting) ? array_values($decodedExisting) : [];
        }

        $normalizedActions = $this->validActions($actions, $previous);

        return [
            'room_id' => $roomId,
            'name' => $name,
            'endpoint' => $endpoint,
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'conditions' => $conditions,
            'actions' => $normalizedActions,
            'priority' => $priority,
            'stop_processing' => (bool) ($payload['stop_processing'] ?? false),
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
    private function validActions(array $actions, array $previous): array
    {
        if ($actions === []) {
            Response::error('Нужно хотя бы одно действие', 422);
        }
        if (count($actions) > 20) {
            Response::error('Допускается не более 20 действий', 422);
        }

        $normalizedActions = [];

        foreach ($actions as $index => $action) {
            if (!is_array($action)) {
                Response::error('Каждое действие должно быть объектом', 422);
            }

            $type = (string) ($action['type'] ?? '');
            if (!in_array($type, self::ACTION_TYPES, true)) {
                Response::error('Неподдерживаемый тип действия: ' . $type, 422);
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

                $normalizedActions[] = [
                    'type' => 'forward',
                    'url' => $url,
                    'method' => $method,
                    'payload' => $action['payload'] ?? 'body',
                    'headers' => $headers,
                    'secret' => $secret,
                ];
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

            $normalizedActions[] = [
                'type' => 'log',
                'message' => (string) ($action['message'] ?? 'Webhook matched workflow conditions'),
            ];
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
}
