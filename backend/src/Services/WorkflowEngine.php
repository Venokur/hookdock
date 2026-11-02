<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\HttpClient;
use App\Support\JsonPath;
use App\Support\Signature;
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
     * Rules are ordered by `priority` (0 by default) and then by id, so a more
     * specific rule can be made to win without renaming or recreating anything.
     * The insert order is preserved in `workflow_runs`, which is also the order
     * the worker claims them in.
     *
     * @param array<string, mixed> $request incoming_requests row + decoded fields
     */
    public static function enqueue(array $request): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT id, endpoint FROM workflows
              WHERE enabled AND room_id = :room_id
              ORDER BY priority DESC, id'
        );
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
     * Puts a run back in the queue after a `delay` action.
     *
     * Deliberately separate from `reschedule()`: that one is retry backoff and
     * counts as an attempt, while a delay is normal operation and must not consume
     * `ROUTING_MAX_ATTEMPTS`. The attempt counter stays where it is, so the step log
     * keeps `action_index` semantics and the succeeded actions are still skipped.
     */
    private static function rescheduleIn(int $runId, int $seconds, string $message): void
    {
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
            'delay' => max(1, $seconds),
            'message' => mb_substr($message, 0, 2000),
            'id' => $runId,
        ]);
    }

    /**
     * Marks the other queued runs of one request as skipped.
     *
     * Called after a successful `stop_processing` rule. Only `pending` rows are
     * touched: a run another worker already claimed is executing and must not be
     * yanked out from under it. `$exceptId` is the run that just finished.
     */
    private static function skipPending(int $requestId, int $exceptId, string $message): int
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE workflow_runs
                SET status = 'skipped', message = :message, updated_at = now()
              WHERE request_id = :request_id
                AND id <> :except_id
                AND status = 'pending'
              RETURNING id"
        );
        $stmt->execute([
            'request_id' => $requestId,
            'except_id' => $exceptId,
            'message' => mb_substr($message, 0, 2000),
        ]);

        return count($stmt->fetchAll());
    }

    /**
     * Queues a fresh run of the same workflow for the same request.
     *
     * Used by a manual retry: the operator asks for this run again, so it becomes
     * a fresh row with its own attempt counter and step log. Resetting the failed
     * row instead would make `succeededSteps()` skip the actions that worked in
     * the previous attempt, which is the opposite of what "повторить" means.
     */
    public static function enqueueRun(int $workflowId, int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO workflow_runs (workflow_id, request_id, status)
             VALUES (:workflow_id, :request_id, 'pending')
             RETURNING id"
        );
        $stmt->execute(['workflow_id' => $workflowId, 'request_id' => $requestId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Takes due runs for one worker. `FOR UPDATE SKIP LOCKED` makes concurrent
     * workers partition the queue instead of blocking on each other.
     *
     * The order is `available_at, id` and the runs of one request were inserted in
     * priority order, so a `stop_processing` rule executes before the rules it is
     * meant to pre-empt. A delayed run sits behind everything already due, which
     * is exactly what a debounce needs.
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
        $conditions = self::decodeNode($workflow['conditions'] ?? '[]');
        $actions = self::decode((string) $workflow['actions']);

        if (!self::conditionsPass($conditions, $context)) {
            self::finish($runId, 'skipped', 'Условия не выполнены');

            return 'skipped';
        }

        $done = self::succeededSteps($runId);
        [$status, $message, $retry, $delay] = self::runActions($actions, $context, $runId, $attempt, $done);

        // A delay action pauses the run itself: the remaining actions belong to the
        // other side of the pause, so the row goes back to the queue with a due
        // time instead of being finished. The step was recorded as `success`, so
        // the next pass skips it and does not delay a second time.
        if ($delay > 0) {
            self::rescheduleIn($runId, $delay, $message);

            return 'pending';
        }

        if ($retry && $attempt < self::maxAttempts()) {
            self::reschedule($runId, $attempt, $message);

            return 'pending';
        }

        if ($retry) {
            $message .= ' · попыток: ' . $attempt;
        }

        // `stop_processing` turns a room's rules into "first match wins": the runs
        // were queued in priority order, so everything still pending for this
        // request belongs to a less specific rule. They are skipped rather than
        // deleted — the log keeps answering "why did this rule not run".
        //
        // Done before `finish()` so the count lands in this run's message: the row
        // is already written by then, and a message appended afterwards would only
        // exist in this local variable.
        if ($status === 'success' && self::isTrue($workflow['stop_processing'] ?? false)) {
            $stopped = self::skipPending(
                (int) $run['request_id'],
                $runId,
                'остановлено сценарием «' . mb_substr((string) $workflow['name'], 0, 120) . '»',
            );

            if ($stopped > 0) {
                $message .= ' · остановлено правил: ' . $stopped;
            }
        }

        self::finish($runId, $status, $message);

        // Only a terminal outcome is interesting to the operator: a rescheduled
        // attempt would otherwise raise an alert for every retry of one incident.
        $run['attempts'] = $attempt;
        $run['message'] = $message;

        try {
            if ($status === 'failed') {
                WorkflowAlerting::onTerminalFailure($workflow, $run, $request);
            } elseif ($status === 'success') {
                // `skipped` is not evidence either way: the rule never ran, so it
                // neither breaks nor repairs the streak.
                WorkflowAlerting::onSuccess((int) $workflow['id']);
            }
        } catch (Throwable $e) {
            // Alerting is a side channel: a broken counter or an unreachable alert
            // URL must never change the outcome that was already recorded.
            error_log('alerting failed for run #' . $runId . ': ' . $e->getMessage());
        }

        return $status;
    }

    /**
     * Answers "what would this rule do with this stored request" without doing it.
     *
     * The editor and the request card need this: a rule can only be checked today
     * by sending a real webhook and letting it hit the real target. Here the
     * condition tree is evaluated against the stored payload, each action is
     * rendered exactly as `runActions` would render it, and nothing is queued,
     * stored or sent — the result is a plain description.
     *
     * A `forward` reports the URL, method, headers and body it *would* send. The
     * URL is still checked by `UrlGuard`, because a preview that says "fine" for a
     * target the worker will refuse is worse than no preview.
     *
     * @param array<string, mixed> $request incoming_requests row with decoded jsonb
     * @param array<string, mixed> $conditions normalized tree
     * @param list<array<string, mixed>> $actions
     * @return array{matched: bool, steps: list<array<string, mixed>>}
     */
    public static function preview(array $request, array $conditions, array $actions): array
    {
        $context = self::context($request);
        $matched = self::conditionsPass(self::normalizeTree($conditions), $context);

        $steps = [];
        if (!$matched) {
            return ['matched' => false, 'steps' => $steps];
        }

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');
            $step = ['index' => $index, 'type' => $type];

            if ($type === 'log') {
                $steps[] = $step + [
                    'message' => Template::render((string) ($action['message'] ?? ''), $context),
                ];
                continue;
            }

            if ($type === 'delay') {
                $steps[] = $step + ['seconds' => (int) ($action['seconds'] ?? 0)];
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

                // A later `forward` with `payload: "transformed"` reads this, and a
                // preview of the forwarded body has to see the same data.
                $context['transformed'] = $result;
                $steps[] = $step + ['mapping' => $result];
                continue;
            }

            if ($type === 'forward') {
                $url = Template::render((string) ($action['url'] ?? ''), $context);
                $method = strtoupper((string) ($action['method'] ?? 'POST'));

                $payload = $action['payload'] ?? 'body';
                if (is_string($payload)) {
                    $payload = match ($payload) {
                        'body' => $context['body'] ?? [],
                        'transformed' => $context['transformed'] ?? [],
                        'raw' => (string) ($context['raw'] ?? ''),
                        default => Template::render($payload, $context),
                    };
                } elseif (is_array($payload)) {
                    $payload = self::renderDeep($payload, $context);
                }

                $body = is_string($payload)
                    ? $payload
                    : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $headers = ['Content-Type' => 'application/json'];
                foreach (is_array($action['headers'] ?? null) ? $action['headers'] : [] as $name => $value) {
                    $headers[(string) $name] = Template::render((string) $value, $context);
                }

                if ((string) ($action['secret'] ?? '') !== '') {
                    $headers[Signature::HEADER] = Signature::sign($body, (string) $action['secret']) . ' (не показан полностью)';
                }

                $guard = $url === '' ? ['allowed' => false, 'reason' => 'пустой URL'] : UrlGuard::check($url);

                $steps[] = $step + [
                    'url' => $url,
                    'method' => $method,
                    'headers' => $headers,
                    'body' => $body,
                    'allowed' => (bool) ($guard['allowed'] ?? false),
                    'reason' => $guard['reason'] ?? null,
                ];
                continue;
            }

            $steps[] = $step + ['message' => 'неизвестное действие'];
        }

        return ['matched' => true, 'steps' => $steps];
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
     * Evaluates a condition tree.
     *
     * The stored shape is `{"mode": "all"|"any", "rules": [...]}`, where a rule is
     * either a condition or another group. `all` is AND, `any` is OR, and nesting
     * is unbounded — a flat list cannot express "source = stripe AND (type = paid
     * OR type = refunded)". An empty group passes, because "no conditions" means
     * "match everything", which is also how a workflow without rules is stored.
     *
     * `mode` is only read for objects with `rules`: a bare condition is matched by
     * its `path`, so a caller can hand in a single node without wrapping it.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $context
     */
    public static function conditionsPass(array $node, array $context): bool
    {
        if (!isset($node['rules']) || !is_array($node['rules'])) {
            // A lone condition, not a group. This is what a caller writes in a
            // dry run and what the old flat format looked like after decoding.
            return $node === [] || self::evaluate($node, $context);
        }

        $rules = array_values(array_filter($node['rules'], 'is_array'));
        if ($rules === []) {
            return true;
        }

        $mode = strtolower((string) ($node['mode'] ?? 'all'));

        foreach ($rules as $rule) {
            $passed = self::conditionsPass($rule, $context);

            if ($mode === 'any' && $passed) {
                return true;
            }

            if ($mode !== 'any' && !$passed) {
                return false;
            }
        }

        // `all` fell through every rule without a failure; `any` found no match.
        return $mode !== 'any';
    }

    /**
     * Normalizes a stored condition tree.
     *
     * Older rows are a plain list (migration 010 rewrites them, but a row written
     * by an older API between the migration and the deploy could still be one),
     * so a list is read as an `all` group instead of being rejected.
     *
     * @return array<string, mixed>
     */
    public static function normalizeTree(mixed $conditions): array
    {
        if (!is_array($conditions)) {
            return ['mode' => 'all', 'rules' => []];
        }

        // `array_is_list` distinguishes `[...]` from `{"mode": ...}`.
        if (array_is_list($conditions)) {
            return ['mode' => 'all', 'rules' => array_values(array_filter($conditions, 'is_array'))];
        }

        $mode = strtolower((string) ($conditions['mode'] ?? 'all'));
        $rules = $conditions['rules'] ?? [];

        return [
            'mode' => $mode === 'any' ? 'any' : 'all',
            'rules' => is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [],
        ];
    }

    /**
     * Counts the leaf conditions in a tree, for the "N conditions" label and for
     * the validation limit — a nested group must not bypass `max 20`.
     *
     * @param array<string, mixed> $node
     */
    public static function conditionCount(array $node): int
    {
        if (!isset($node['rules']) || !is_array($node['rules'])) {
            return $node === [] ? 0 : 1;
        }

        $total = 0;
        foreach ($node['rules'] as $rule) {
            if (is_array($rule)) {
                $total += self::conditionCount($rule);
            }
        }

        return $total;
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
     * @return array{0: string, 1: string, 2: bool, 3: int} status, message, retry, delay in seconds
     */
    private static function runActions(array $actions, array &$context, int $runId, int $attempt, array $done): array
    {
        $messages = [];
        $failed = false;
        $retry = false;
        $delay = 0;

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');

            if (isset($done[$index]) && $type !== 'transform') {
                // Already performed by an earlier attempt: never repeat a side effect.
                // `transform` is excluded on purpose: it has no side effect, and a
                // retry has to rebuild `context['transformed']` — otherwise a later
                // `forward` with `payload: "transformed"` would send an empty body.
                // `delay` is skipped like any other side effect: a resumed run had
                // its pause already, and repeating it would restart the clock.
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

                if ($type === 'delay') {
                    $seconds = (int) ($action['seconds'] ?? 0);
                    if ($seconds < 1) {
                        $failed = true;
                        $message = 'задержка: не задано время';
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
                        continue;
                    }

                    // Recorded as success before the run is rescheduled, so the
                    // second pass takes the `$done` branch above and does not add
                    // another pause. The remaining actions run after the wait.
                    $message = sprintf('задержка: %d с', $seconds);
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, null, null, $started);
                    $delay = $seconds;
                    break;
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
                            // Anything else is a hand-written body from the editor.
                            // It is a template, so `{"id": "{{ body.id }}"}` works
                            // without an intermediate transform action.
                            default => Template::render($payload, $context),
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

                    // Outgoing HMAC. The receiver of a forward gets the same check
                    // this service performs on its own intake: the signature covers
                    // the body actually sent, so it cannot be replayed with a
                    // different payload. The secret lives in the action, not in the
                    // run log.
                    $secret = (string) ($action['secret'] ?? '');
                    if ($secret !== '') {
                        $headers[Signature::HEADER] = Signature::sign($body, $secret);
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

        return [$failed ? 'failed' : 'success', implode('; ', $messages) ?: 'Готово', $failed && $retry, $delay];
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

    /**
     * Decodes a stored condition tree into the `{mode, rules}` shape.
     *
     * @return array<string, mixed>
     */
    private static function decodeNode(mixed $json): array
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        return self::normalizeTree($json);
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }
}
