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

final class WorkflowsController
{
    private const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains',
        'exists', 'not_exists', 'gt', 'gte', 'lt', 'lte', 'regex',
    ];

    private const ACTION_TYPES = ['forward', 'log', 'transform'];

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
             ORDER BY w.id DESC'
        );
        $stmt->execute($roomId === null ? [] : ['room_id' => $roomId]);

        Response::json(['data' => JsonColumns::decodeMany($stmt->fetchAll(), ['conditions', 'actions'])]);
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

        Response::json(['data' => JsonColumns::decode($row, ['conditions', 'actions'])]);
    }

    public function store(Request $request): void
    {
        Auth::requireUser($request);
        $workflow = $this->validated($request);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflows (room_id, name, endpoint, enabled, conditions, actions)
             VALUES (:room_id, :name, :endpoint, :enabled, :conditions, :actions)
             RETURNING *'
        );
        $stmt->execute([
            'room_id' => $workflow['room_id'],
            'name' => $workflow['name'],
            'endpoint' => $workflow['endpoint'],
            'enabled' => $workflow['enabled'] ? 'true' : 'false',
            'conditions' => $this->encode($workflow['conditions']),
            'actions' => $this->encode($workflow['actions']),
        ]);

        $row = JsonColumns::decode($stmt->fetch(), ['conditions', 'actions']);
        Realtime::publish('workflow.changed', [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'action' => 'created',
        ]);

        Response::json(['data' => $row], 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Сценарий не найден', 404);
        }

        $workflow = $this->validated($request);

        // Moving a workflow between rooms would silently change which webhooks it
        // reacts to, so it needs its own explicit action rather than a side effect
        // of editing a name.
        if ($workflow['room_id'] !== (int) $existing['room_id']) {
            Response::error('Сценарий нельзя перенести в другую комнату', 422);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE workflows
             SET name = :name, endpoint = :endpoint, enabled = :enabled,
                 conditions = :conditions, actions = :actions, updated_at = now()
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

        Response::json(['data' => $decoded]);
    }

    public function toggle(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $stmt = Database::pdo()->prepare(
            'UPDATE workflows SET enabled = NOT enabled, updated_at = now()
             WHERE id = :id RETURNING id, enabled, room_id'
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
        Auth::requireUser($request);

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
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT id, room_id FROM workflows WHERE id = :id');
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
     * @return array{room_id: int, name: string, endpoint: string, enabled: bool, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
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

        $conditions = $payload['conditions'] ?? [];
        if (!is_array($conditions)) {
            Response::error('Условия должны быть массивом', 422);
        }
        if (count($conditions) > 20) {
            Response::error('Допускается не более 20 условий', 422);
        }

        $normalizedConditions = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                Response::error('Каждое условие должно быть объектом', 422);
            }

            $path = trim((string) ($condition['path'] ?? ''));
            $operator = (string) ($condition['operator'] ?? 'equals');

            if ($path === '') {
                Response::error('У каждого условия должен быть путь к полю', 422);
            }
            if (!in_array($operator, self::OPERATORS, true)) {
                Response::error('Неподдерживаемый оператор условия: ' . $operator, 422);
            }

            $normalizedConditions[] = [
                'path' => $path,
                'operator' => $operator,
                'value' => $condition['value'] ?? null,
            ];
        }

        $actions = $payload['actions'] ?? [];
        if (!is_array($actions) || $actions === []) {
            Response::error('Нужно хотя бы одно действие', 422);
        }
        if (count($actions) > 20) {
            Response::error('Допускается не более 20 действий', 422);
        }

        $normalizedActions = [];
        foreach ($actions as $action) {
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

                $normalizedActions[] = [
                    'type' => 'forward',
                    'url' => $url,
                    'method' => $method,
                    'payload' => $action['payload'] ?? 'body',
                    'headers' => is_array($action['headers'] ?? null) ? $action['headers'] : [],
                ];
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

        return [
            'room_id' => $roomId,
            'name' => $name,
            'endpoint' => $endpoint,
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'conditions' => $normalizedConditions,
            'actions' => $normalizedActions,
        ];
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
