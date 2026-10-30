<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\HttpClient;
use App\Support\JsonPath;
use App\Support\Template;
use App\Support\UrlGuard;
use PDO;
use Throwable;

/**
 * Routes a stored webhook through the workflows of its room.
 *
 * The receiver must answer fast, so the work is split in three:
 *   enqueue()  – called inside the request, records one `pending` run per
 *                matching workflow (no conditions are evaluated yet);
 *   claim()    – the worker takes a batch of due runs with SKIP LOCKED, so two
 *                workers never execute the same run;
 *   execute()  – conditions and actions, with a per-action step log.
 *
 * A run is retried on transient failures only, and a retry replays the actions
 * that already succeeded, so a forward is never sent twice by a retry.
 */
final class WorkflowEngine
{
    /** Statuses that occupy the queue. */
    public const QUEUE = ['pending', 'running'];

    /**
     * Records the runs a stored request still has to go through.
     *
     * Conditions are deliberately not evaluated here: the payload is already in
     * the database, so the worker can decide, and a slow condition write would
     * delay the 202 the sender is waiting for.
     *
     * @param array<string, mixed> $request incoming_requests row + decoded fields
     */
    public static function enqueue(array $request): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT id, endpoint FROM workflows WHERE enabled AND room_id = :room_id ORDER BY id');
        $stmt->execute(['room_id' => (int) ($request['room_id'] ?? 0)]);

        $ids = [];
        foreach ($stmt->fetchAll() as $workflow) {
            if (self::endpointMatches((string) $workflow['endpoint'], (string) $request['endpoint'])) {
                $ids[] = (int) $workflow['id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        $values = [];
        $params = ['request_id' => (int) $request['id']];

        foreach ($ids as $index => $id) {
            $values[] = sprintf('(:workflow_%d, :request_id, \'pending\')', $index);
            $params['workflow_' . $index] = $id;
        }

        $insert = $pdo->prepare(
            'INSERT INTO workflow_runs (workflow_id, request_id, status) VALUES ' . implode(', ', $values)
        );
        $insert->execute($params);

        return count($ids);
    }

    /**
     * Takes due runs for one worker. `FOR UPDATE SKIP LOCKED` makes concurrent
     * workers partition the queue instead of blocking on each other.
     *
     * @return list<array<string, mixed>>
     */
    public static function claim(int $limit, string $workerId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "UPDATE workflow_runs
                SET status = 'running',
                    attempts = attempts + 1,
                    locked_at = now(),
                    locked_by = :worker,
                    updated_at = now()
              WHERE id IN (
                    SELECT id FROM workflow_runs
                     WHERE status = 'pending' AND available_at <= now()
                     ORDER BY available_at, id
                     LIMIT :limit
                     FOR UPDATE SKIP LOCKED
              )
              RETURNING id, workflow_id, request_id, attempts"
        );
        $stmt->bindValue(':worker', $workerId);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Runs one claimed run and records the outcome.
     *
     * @param array<string, mixed> $run row from claim()
     */
    public static function execute(array $run): string
    {
        $pdo = Database::pdo();
        $runId = (int) $run['id'];
        $attempt = (int) $run['attempts'];

        $request = self::loadRequest((int) $run['request_id']);
        if ($request === null) {
            // The request was deleted while queued: nothing to route.
            self::finish($runId, 'skipped', 'Запрос удалён до выполнения');

            return 'skipped';
        }

        $workflow = self::loadWorkflow((int) $run['workflow_id']);
        if ($workflow === null) {
            self::finish($runId, 'skipped', 'Сценарий удалён до выполнения');

            return 'skipped';
        }

        $context = self::context($request);
        $conditions = self::decode((string) $workflow['conditions']);
        $actions = self::decode((string) $workflow['actions']);

        if (!self::conditionsPass($conditions, $context)) {
            self::finish($runId, 'skipped', 'Условия не выполнены');

            return 'skipped';
        }

        $done = self::succeededSteps($runId);
        [$status, $message, $retry] = self::runActions($actions, $context, $runId, $attempt, $done);

        if ($retry && $attempt < self::maxAttempts()) {
            self::reschedule($runId, $attempt, $message);

            return 'pending';
        }

        if ($retry) {
            $message .= ' · попыток: ' . $attempt;
        }

        self::finish($runId, $status, $message);

        return $status;
    }

    /**
     * Returns runs whose worker died back to the queue.
     *
     * A `running` row older than the threshold belongs to a process that no
     * longer exists (container restart, OOM), so it would otherwise stay in the
     * queue forever.
     */
    public static function recoverStale(int $minutes = 5): int
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE workflow_runs
                SET status = 'pending', locked_at = NULL, locked_by = NULL, updated_at = now()
              WHERE status = 'running'
                AND locked_at < now() - make_interval(mins => :minutes)
              RETURNING id"
        );
        $stmt->bindValue(':minutes', max(1, $minutes), PDO::PARAM_INT);
        $stmt->execute();

        return count($stmt->fetchAll());
    }

    public static function maxAttempts(): int
    {
        return max(1, Config::int('ROUTING_MAX_ATTEMPTS', 3));
    }

    /**
     * Exponential backoff, counted from the first attempt (1s → 2s → 4s).
     */
    public static function retryDelay(int $attempt): int
    {
        $base = max(1, Config::int('ROUTING_RETRY_BASE', 1));

        return $base * (2 ** max(0, $attempt - 1));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadRequest(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM incoming_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row = \App\Support\JsonColumns::decode($row, ['query', 'headers', 'body']);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadWorkflow(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM workflows WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function context(array $request): array
    {
        return [
            'body' => $request['body'] ?? [],
            'raw' => (string) ($request['raw_body'] ?? ''),
            'headers' => $request['headers'] ?? [],
            'query' => $request['query'] ?? [],
            'transformed' => [],
            'meta' => [
                'id' => $request['id'] ?? null,
                'method' => $request['method'] ?? null,
                'path' => $request['path'] ?? null,
                'endpoint' => $request['endpoint'] ?? null,
                'ip' => $request['ip'] ?? null,
                'received_at' => $request['received_at'] ?? null,
            ],
        ];
    }

    private static function endpointMatches(string $pattern, string $actual): bool
    {
        return $pattern === '*' || strcasecmp($pattern, $actual) === 0;
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed> $context
     */
    private static function conditionsPass(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            if (!self::evaluate($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $context
     */
    private static function evaluate(array $condition, array $context): bool
    {
        $operator = (string) ($condition['operator'] ?? 'equals');
        $resolved = JsonPath::resolve($context, (string) ($condition['path'] ?? ''));

        if ($operator === 'exists') {
            return $resolved['found'];
        }

        if ($operator === 'not_exists') {
            return !$resolved['found'];
        }

        if (!$resolved['found']) {
            return false;
        }

        return self::compare($resolved['value'], $condition['value'] ?? null, $operator);
    }

    private static function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($actual) || !is_numeric($expected)) {
                return false;
            }

            return match ($operator) {
                'gt' => (float) $actual > (float) $expected,
                'gte' => (float) $actual >= (float) $expected,
                'lt' => (float) $actual < (float) $expected,
                'lte' => (float) $actual <= (float) $expected,
            };
        }

        if ($operator === 'regex') {
            $pattern = '~' . str_replace('~', '\~', (string) $expected) . '~u';

            return is_scalar($actual) && @preg_match($pattern, Template::stringify($actual)) === 1;
        }

        if ($operator === 'contains' || $operator === 'not_contains') {
            $contains = is_array($actual)
                ? in_array($expected, $actual, false)
                : str_contains(Template::stringify($actual), Template::stringify($expected));

            return $operator === 'contains' ? $contains : !$contains;
        }

        $equal = self::looseEquals($actual, $expected);

        return $operator === 'equals' ? $equal : !$equal;
    }

    private static function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                === filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_array($actual) || is_array($expected)) {
            return json_encode($actual) === json_encode($expected);
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $context
     * @param array<int, true> $done action indexes that already succeeded in an earlier attempt
     * @return array{0: string, 1: string, 2: bool} status, message, whether a retry is warranted
     */
    private static function runActions(array $actions, array &$context, int $runId, int $attempt, array $done): array
    {
        $messages = [];
        $failed = false;
        $retry = false;

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');

            if (isset($done[$index]) && $type !== 'transform') {
                // Already performed by an earlier attempt: never repeat a side effect.
                // `transform` is excluded on purpose: it has no side effect, and a
                // retry has to rebuild `context['transformed']` — otherwise a later
                // `forward` with `payload: "transformed"` would send an empty body.
                $messages[] = 'повтор пропущен: ' . $type;
                continue;
            }

            $started = microtime(true);

            try {
                if ($type === 'log') {
                    $rendered = Template::render((string) ($action['message'] ?? ''), $context);
                    $message = 'лог: ' . ($rendered !== '' ? $rendered : 'совпадение');
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, null, null, $started);
                    continue;
                }

                if ($type === 'transform') {
                    $mapping = is_array($action['mapping'] ?? null) ? $action['mapping'] : [];
                    $result = [];

                    foreach ($mapping as $key => $path) {
                        $resolved = JsonPath::resolve($context, (string) $path);
                        if ($resolved['found']) {
                            $result[$key] = $resolved['value'];
                        }
                    }

                    $context['transformed'] = $result;
                    $message = 'трансформация: ' . count($result) . ' пол.';
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, null, null, $started);
                    continue;
                }

                if ($type === 'forward') {
                    $url = Template::render((string) ($action['url'] ?? ''), $context);
                    $method = strtoupper((string) ($action['method'] ?? 'POST'));

                    if ($url === '') {
                        $failed = true;
                        $message = 'пересылка: пустой URL';
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
                        continue;
                    }

                    // Checked before the request, never after: the guard exists to
                    // stop the packet, not to log that it was sent. A refused URL is
                    // a configuration error, so it is terminal (no retry).
                    $guard = UrlGuard::check($url);
                    if (!$guard['allowed']) {
                        $failed = true;
                        $message = 'пересылка запрещена: ' . ($guard['reason'] ?? 'адрес не разрешён');
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
                        continue;
                    }

                    $payload = $action['payload'] ?? 'body';
                    if (is_string($payload)) {
                        $payload = match ($payload) {
                            'body' => $context['body'] ?? [],
                            'transformed' => $context['transformed'] ?? [],
                            'raw' => (string) ($context['raw'] ?? ''),
                            default => $payload,
                        };
                    } elseif (is_array($payload)) {
                        $payload = self::renderDeep($payload, $context);
                    }

                    $body = is_string($payload)
                        ? $payload
                        : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $headers = ['Content-Type' => 'application/json'];
                    $custom = is_array($action['headers'] ?? null) ? $action['headers'] : [];
                    foreach ($custom as $headerName => $headerValue) {
                        $headers[(string) $headerName] = Template::render((string) $headerValue, $context);
                    }

                    $result = HttpClient::send($method, $url, $headers, $body, $guard['addresses']);

                    if (!$result['ok']) {
                        $failed = true;
                        $retry = $retry || HttpClient::retryable($result);
                        $message = sprintf(
                            'пересылка %s %s → %s%s',
                            $method,
                            $url,
                            $result['status'] !== 0 ? (string) $result['status'] : 'ошибка',
                            $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
                        );
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, $result['status'], $result['body'], $started);
                        continue;
                    }

                    $message = sprintf('пересылка %s %s → %d', $method, $url, $result['status']);
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, $result['status'], $result['body'], $started);
                    continue;
                }

                $failed = true;
                $message = 'неизвестное действие: ' . $type;
                $messages[] = $message;
                self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
            } catch (Throwable $e) {
                $failed = true;
                $message = 'ошибка действия: ' . $e->getMessage();
                $messages[] = $message;
                self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
            }
        }

        return [$failed ? 'failed' : 'success', implode('; ', $messages) ?: 'Готово', $failed && $retry];
    }

    /**
     * Renders templates inside action payloads; full-string placeholders keep their type.
     *
     * @param array<string, mixed> $context
     */
    private static function renderDeep(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^\{\{\s*([^}]+?)\s*\}\}$/', $value, $matches)) {
                $resolved = JsonPath::resolve($context, $matches[1]);

                return $resolved['found'] ? $resolved['value'] : null;
            }

            return Template::render($value, $context);
        }

        if (is_array($value)) {
            $rendered = [];
            foreach ($value as $key => $item) {
                $rendered[$key] = self::renderDeep($item, $context);
            }

            return $rendered;
        }

        return $value;
    }

    /**
     * Action indexes that succeeded in an earlier attempt of this run.
     *
     * @return array<int, true>
     */
    private static function succeededSteps(int $runId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT DISTINCT action_index FROM workflow_run_steps
              WHERE run_id = :run_id AND status = 'success'"
        );
        $stmt->execute(['run_id' => $runId]);

        $done = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $index) {
            $done[(int) $index] = true;
        }

        return $done;
    }

    private static function recordStep(
        int $runId,
        int $attempt,
        int $index,
        string $type,
        string $status,
        string $message,
        ?int $responseStatus,
        ?string $responseBody,
        float $started,
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflow_run_steps
                (run_id, attempt, action_index, type, status, message, response_status, response_body, duration_ms)
             VALUES
                (:run_id, :attempt, :action_index, :type, :status, :message, :response_status, :response_body, :duration_ms)'
        );
        $stmt->execute([
            'run_id' => $runId,
            'attempt' => $attempt,
            'action_index' => $index,
            'type' => $type,
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
            'response_status' => $responseStatus,
            'response_body' => $responseBody === null ? null : mb_substr($responseBody, 0, 4000),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private static function reschedule(int $runId, int $attempt, string $message): void
    {
        $delay = self::retryDelay($attempt);

        $stmt = Database::pdo()->prepare(
            "UPDATE workflow_runs
                SET status = 'pending',
                    available_at = now() + make_interval(secs => :delay),
                    locked_at = NULL,
                    locked_by = NULL,
                    message = :message,
                    updated_at = now()
              WHERE id = :id"
        );
        $stmt->execute([
            'delay' => $delay,
            'message' => mb_substr($message, 0, 2000),
            'id' => $runId,
        ]);
    }

    private static function finish(int $runId, string $status, string $message): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE workflow_runs
                SET status = :status, message = :message, locked_at = NULL, locked_by = NULL, updated_at = now()
              WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
            'id' => $runId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
