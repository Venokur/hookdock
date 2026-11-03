<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\Cron;
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
              WHERE enabled AND room_id = :room_id AND schedule_cron IS NULL
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
        $params = [
            'request_id' => (int) $request['id'],
            'room_id' => (int) ($request['room_id'] ?? 0),
        ];

        foreach ($ids as $index => $id) {
            $values[] = sprintf(
                "(:workflow_%d, :request_id, :room_id, 'webhook', 'pending')",
                $index,
            );
            $params['workflow_' . $index] = $id;
        }

        $insert = $pdo->prepare(
            'INSERT INTO workflow_runs (workflow_id, request_id, room_id, trigger_type, status) '
            . 'VALUES ' . implode(', ', $values)
        );
        $insert->execute($params);

        return count($ids);
    }

    /**
     * Puts a run back in the queue after a `delay` action or a breaker refusal.
     *
     * Deliberately separate from `reschedule()`: that one is retry backoff and
     * counts as an attempt, while a delay is normal operation and must not consume
     * `ROUTING_MAX_ATTEMPTS`. The attempt counter stays where it is, so the step log
     * keeps `action_index` semantics and the succeeded actions are still skipped.
     *
     * `$deferred` records that the reason was a refused target. It is what keeps a
     * permanently dead address from producing an endless `pending` row: the caller
     * counts those and gives up after `deferralLimit()`.
     */
    private static function rescheduleIn(int $runId, int $seconds, string $message, bool $deferred = false): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE workflow_runs
                SET status = 'pending',
                    available_at = now() + make_interval(secs => :delay),
                    deferrals = deferrals + :deferred,
                    locked_at = NULL,
                    locked_by = NULL,
                    message = :message,
                    updated_at = now()
              WHERE id = :id"
        );
        $stmt->execute([
            'delay' => max(1, $seconds),
            'deferred' => $deferred ? 1 : 0,
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
    public static function enqueueRun(
        int $workflowId,
        ?int $requestId,
        int $roomId,
        string $triggerType = 'webhook',
    ): int {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO workflow_runs (workflow_id, request_id, room_id, trigger_type, status)
             VALUES (:workflow_id, :request_id, :room_id, :trigger_type, 'pending')
             RETURNING id"
        );
        $stmt->execute([
            'workflow_id' => $workflowId,
            'request_id' => $requestId,
            'room_id' => $roomId,
            'trigger_type' => $triggerType,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Queues every scheduled rule whose slot has come.
     *
     * A scheduled fire is a run without a request: the rule was started by its own
     * schedule, not by a delivery, so there is no payload and `request_id` stays
     * NULL. `FOR UPDATE SKIP LOCKED` keeps two workers from firing the same slot.
     *
     * A slot that came and went while the worker was down is fired **once**: the
     * next slot is computed from *now*, not from the missed one. Catching up on
     * every missed minute would turn a five-minute outage into a burst of hundreds
     * of identical runs, and a periodic tick does not need every tick to exist.
     *
     * @return int number of runs queued
     */
    public static function fireDueSchedules(int $limit = 20): int
    {
        $pdo = Database::pdo();

        // The cheap check first: an installation without schedules must not pay a
        // transaction on every worker pass.
        $probe = $pdo->query(
            'SELECT 1 FROM workflows
              WHERE enabled AND schedule_cron IS NOT NULL AND schedule_next_at <= now()
              LIMIT 1'
        );

        if ($probe === false || $probe->fetchColumn() === false) {
            return 0;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queued = 0;

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, room_id, schedule_cron
                   FROM workflows
                  WHERE enabled AND schedule_cron IS NOT NULL AND schedule_next_at <= now()
                  ORDER BY schedule_next_at, id
                  LIMIT :limit
                  FOR UPDATE SKIP LOCKED'
            );
            $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            $due = $stmt->fetchAll();

            $insert = $pdo->prepare(
                "INSERT INTO workflow_runs (workflow_id, room_id, request_id, trigger_type, status)
                 VALUES (:workflow_id, :room_id, NULL, 'schedule', 'pending')"
            );
            $advance = $pdo->prepare('UPDATE workflows SET schedule_next_at = :next WHERE id = :id');

            foreach ($due as $workflow) {
                $insert->execute([
                    'workflow_id' => (int) $workflow['id'],
                    'room_id' => (int) $workflow['room_id'],
                ]);
                $queued++;

                $next = Cron::nextAfter((string) $workflow['schedule_cron'], $now);
                $advance->execute([
                    'next' => $next?->format('c'),
                    'id' => (int) $workflow['id'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $queued;
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
              RETURNING id, workflow_id, request_id, room_id, trigger_type, attempts, deferrals"
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

        $requestId = $run['request_id'] === null ? null : (int) $run['request_id'];
        $request = $requestId === null ? null : self::loadRequest($requestId);

        if ($requestId !== null && $request === null) {
            // The request was deleted while queued: nothing to route.
            self::finish($runId, 'skipped', 'Запрос удалён до выполнения');

            return 'skipped';
        }

        $workflow = self::loadWorkflow((int) $run['workflow_id']);
        if ($workflow === null) {
            self::finish($runId, 'skipped', 'Сценарий удалён до выполнения');

            return 'skipped';
        }

        // A run without a request was started by the rule itself (schedule or a
        // manual "run now"), so there is no payload. The trigger context keeps the
        // template paths defined instead of making every `{{ body.x }}` a fatal.
        $context = $request === null ? self::triggerContext($workflow) : self::context($request);
        $conditions = self::decodeNode($workflow['conditions'] ?? '[]');
        $actions = self::decode((string) $workflow['actions']);

        if (!self::conditionsPass($conditions, $context)) {
            self::finish($runId, 'skipped', 'Условия не выполнены');

            return 'skipped';
        }

        $done = self::succeededSteps($runId);

        // A retry skips a forward that already succeeded, so its answer is no longer
        // in memory — but a later `extract` may still read `response.…`. Seeding it
        // from the step log keeps the chain intact on the second attempt.
        if ($done !== []) {
            $context['response'] = self::lastResponse($runId);
        }

        [$status, $message, $retry, $delay, $defer, $breakerOutcomes] = self::runActions($actions, $context, $runId, $attempt, $done, (int) ($run['room_id'] ?? 0));

        // The breaker learns an outcome only once the run has no retry left. While
        // one is still coming the address has not been proven broken — counting
        // every attempt would open the breaker during a five-second blip. A
        // terminal verdict matters either way: a 4xx proves the target rejects us,
        // and a 5xx or timeout that survives the whole retry budget proves it is
        // down, which is exactly what the breaker exists to remember.
        //
        // Recorded before the branches below, because a `delay` or a deferral
        // returns early: a forward that already succeeded in this pass must still
        // close the breaker.
        $willRetry = $retry && $attempt < self::maxAttempts();
        if (!$willRetry) {
            foreach ($breakerOutcomes as [$address, $ok, $responseStatus, $error]) {
                CircuitBreaker::record((string) $address, (bool) $ok, $responseStatus, $error);
            }
        }

        // A delay action pauses the run itself: the remaining actions belong to the
        // other side of the pause, so the row goes back to the queue with a due
        // time instead of being finished. The step was recorded as `success`, so
        // the next pass skips it and does not delay a second time.
        if ($delay > 0) {
            self::rescheduleIn($runId, $delay, $message);

            return 'pending';
        }

        // An address the breaker refused is not this run's failure. The run goes
        // back in the queue for the moment one probe is allowed, without burning an
        // attempt — otherwise a cooldown longer than the retry budget would turn
        // every deferred forward into a failed run, which is exactly the outcome
        // the breaker is there to avoid.
        //
        // A run that is not an attempt also has no natural end, so the deferral
        // count is the end: after `deferralLimit()` cooldowns the address has had
        // every chance the breaker offers, and the run is finished as `skipped`
        // instead of sitting in the queue forever. `skipped` is honest — the target
        // was never called — and it keeps the workflow's failure streak untouched.
        if ($defer > 0 && !$failed) {
            $deferrals = (int) ($run['deferrals'] ?? 0);

            if ($deferrals >= self::deferralLimit()) {
                $message .= ' · попыток проверки: ' . ($deferrals + 1);
                self::finish($runId, 'skipped', $message);

                return 'skipped';
            }

            self::rescheduleIn($runId, $defer, $message, true);

            return 'pending';
        }

        if ($willRetry) {
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
        if ($requestId !== null && $status === 'success' && self::isTrue($workflow['stop_processing'] ?? false)) {
            $stopped = self::skipPending(
                $requestId,
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

            // A branch that would not run is described as such instead of being
            // silently omitted: the point of the dry run is to show what the rule
            // will do, and "this action is skipped because its condition is false"
            // is exactly what an operator is checking for.
            $when = $action['when'] ?? null;
            if (is_array($when) && $when !== [] && !self::conditionsPass(self::normalizeTree($when), $context)) {
                $steps[] = $step + ['skipped' => true, 'reason' => 'условие действия не выполнено'];
                continue;
            }

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

            if ($type === 'extract') {
                $sourceName = (string) ($action['source'] ?? 'body');
                $source = $sourceName === 'response'
                    ? ($context['response'] ?? [])
                    : ($context[$sourceName] ?? null);

                $mapping = is_array($action['mapping'] ?? null) ? $action['mapping'] : [];
                $result = [];

                foreach ($mapping as $key => $path) {
                    $resolved = JsonPath::resolveWithin($source, (string) $path);
                    if ($resolved['found']) {
                        $result[$key] = $resolved['value'];
                    }
                }

                $existing = is_array($context['extracted'] ?? null) ? $context['extracted'] : [];
                $context['extracted'] = array_merge($existing, $result);
                $steps[] = $step + ['source' => $sourceName, 'extracted' => $result];
                continue;
            }

            if ($type === 'notify') {
                $channelId = (int) ($action['channel_id'] ?? 0);
                $channel = $channelId > 0 ? NotificationChannel::find($channelId) : null;

                if ($channel === null) {
                    $steps[] = $step + ['error' => 'канал не найден', 'channel_id' => $channelId];
                    continue;
                }

                $address = NotificationChannel::address($channel);
                // `peek`, never `tryAcquire`: a preview that consumed the single
                // half-open probe would push the real attempt back by a whole
                // cooldown and the dashboard would start lying about the channel.
                $breaker = $address === ''
                    ? ['state' => 'closed', 'retry_in' => 0]
                    : CircuitBreaker::peek($address);

                $steps[] = $step + [
                    'channel_id' => $channelId,
                    'channel' => (string) $channel['name'],
                    'kind' => (string) $channel['kind'],
                    // A Slack webhook URL is a credential, so the address is
                    // described rather than printed: the preview is rendered in a
                    // browser and read over someone's shoulder.
                    'target' => NotificationChannel::describe($channel),
                    'enabled' => self::isTrue($channel['enabled'] ?? false),
                    'message' => Template::render((string) ($action['message'] ?? ''), $context),
                    'breaker' => $breaker['state'],
                    'breaker_retry_in' => (int) $breaker['retry_in'],
                ];
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
                        'extracted' => $context['extracted'] ?? [],
                        'response' => $context['response'] ?? [],
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
                    [$signedAt, $signature] = Signature::outgoing(
                        $body,
                        (string) $action['secret'],
                        self::isTrue($action['sign_timestamp'] ?? false),
                    );

                    if ($signedAt !== null) {
                        $headers[Signature::TIMESTAMP_HEADER] = $signedAt;
                    }

                    $headers[Signature::HEADER] = $signature . ' (не показан полностью)';
                }

                $timeout = self::actionTimeout($action);
                $guard = $url === '' ? ['allowed' => false, 'reason' => 'пустой URL'] : UrlGuard::check($url);
                $breaker = $url === '' ? ['allowed' => true, 'state' => 'closed', 'retry_in' => 0] : CircuitBreaker::peek($url);

                $steps[] = $step + [
                    'url' => $url,
                    'method' => $method,
                    'headers' => $headers,
                    'body' => $body,
                    'timeout' => $timeout,
                    'allowed' => (bool) ($guard['allowed'] ?? false),
                    'reason' => $guard['reason'] ?? null,
                    // A preview has to say that the worker would skip this target
                    // right now: describing a request that will not be attempted is
                    // worse than describing nothing.
                    'breaker' => $breaker['state'],
                    'breaker_retry_in' => (int) $breaker['retry_in'],
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
     * How many times one run may be deferred by the breaker before it gives up.
     *
     * Three cooldowns is enough to tell "the target is still down" from "the target
     * blinked", and it bounds the queue: without a limit, a dead address with a
     * short cooldown would keep one `pending` row alive for as long as the address
     * stayed dead.
     */
    public static function deferralLimit(): int
    {
        return max(1, Config::int('FORWARD_BREAKER_DEFERRALS', 3));
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
            // Filled by `extract` actions, and read by later steps as
            // `{{ extracted.order_id }}` — that is how a value travels from one
            // action to the next without a script.
            'extracted' => [],
            // The decoded body of the last successful forward. Seeded on a retry
            // from the journal (see `lastResponse()`), so an `extract` that reads an
            // API answer still sees it on the second attempt, when the forward
            // itself is skipped as already done.
            'response' => [],
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

    /**
     * The context of a run that has no stored request.
     *
     * A scheduled rule (or a manual "run now") has no payload, so every path a
     * template reads is empty except `meta`, which describes the trigger itself.
     * Conditions evaluated against this context simply fail — which is why the
     * editor hides them for a scheduled rule instead of pretending they mean
     * something.
     *
     * @param array<string, mixed> $workflow
     * @return array<string, mixed>
     */
    private static function triggerContext(array $workflow): array
    {
        return [
            'body' => [],
            'raw' => '',
            'headers' => [],
            'query' => [],
            'transformed' => [],
            'extracted' => [],
            'response' => [],
            'meta' => [
                'id' => null,
                'method' => 'SCHEDULE',
                'path' => '/schedule',
                'endpoint' => 'schedule',
                'ip' => null,
                'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'workflow' => (string) ($workflow['name'] ?? ''),
                'schedule' => (string) ($workflow['schedule_cron'] ?? ''),
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

        // A lone condition — `{"path": …, "operator": …}` — is a group of one, not a
        // group with no rules. Without this a per-action `when` written as a single
        // condition would read as "no conditions" and pass every time, which is the
        // worst possible way for a branch to be wrong.
        if (isset($conditions['path']) && !isset($conditions['rules'])) {
            return ['mode' => 'all', 'rules' => [$conditions]];
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
    private static function runActions(array $actions, array &$context, int $runId, int $attempt, array $done, int $roomId = 0): array
    {
        $messages = [];
        $failed = false;
        $retry = false;
        $delay = 0;
        // Seconds to wait before this run should try a target the breaker refused.
        // Separate from `$delay`: a `delay` action pauses the run on purpose, while
        // a deferred forward means "come back when the address may be up".
        $defer = 0;
        // [url, ok, status, error] per attempted forward. Applied to the breaker
        // only for the terminal verdict, so a retry is not counted as an incident.
        $breakerOutcomes = [];

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');

            if (isset($done[$index]) && $type !== 'transform' && $type !== 'extract') {
                // Already performed by an earlier attempt: never repeat a side effect.
                // `transform` and `extract` are excluded on purpose: they have no side
                // effect, and a retry has to rebuild `context['transformed']` /
                // `context['extracted']` — otherwise a later `forward` with
                // `payload: "transformed"` would send an empty body.
                // `delay` is skipped like any other side effect: a resumed run had
                // its pause already, and repeating it would restart the clock.
                $messages[] = 'повтор пропущен: ' . $type;
                continue;
            }

            // Per-action condition: `when` turns a flat list into a branch without a
            // second kind of control flow. `false` records a `skipped` step, so the
            // journal explains why the action did not run, and the rest of the list
            // continues — a skipped action is a decision, not a failure.
            $started = microtime(true);
            $when = $action['when'] ?? null;
            if (is_array($when) && $when !== [] && !self::conditionsPass(self::normalizeTree($when), $context)) {
                $message = 'пропущено: условие действия не выполнено';
                $messages[] = $message;
                self::recordStep($runId, $attempt, $index, $type, 'skipped', $message, null, null, $started);
                continue;
            }

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

                // `extract` reads one source and publishes its fields as
                // `{{ extracted.… }}` for the actions after it. Different from
                // `transform` in the two ways that matter for a chain of calls: the
                // paths are resolved **inside** the chosen source (so `event.type` is
                // that field of the API answer, not "the first place in the context
                // where it appears"), and the result survives the step that reads it
                // — `forward` can send `extracted` or a single `{{ extracted.id }}`.
                if ($type === 'extract') {
                    $sourceName = (string) ($action['source'] ?? 'body');
                    $source = $sourceName === 'response'
                        ? ($context['response'] ?? [])
                        : ($context[$sourceName] ?? null);

                    $mapping = is_array($action['mapping'] ?? null) ? $action['mapping'] : [];
                    $result = [];

                    foreach ($mapping as $key => $path) {
                        $resolved = JsonPath::resolveWithin($source, (string) $path);
                        if ($resolved['found']) {
                            $result[$key] = $resolved['value'];
                        }
                    }

                    // Merged, not replaced: two extracts in one rule usually take
                    // different fields of different answers, and the second one
                    // silently dropping the first one's value would look like a
                    // template that stopped working.
                    $existing = is_array($context['extracted'] ?? null) ? $context['extracted'] : [];
                    $context['extracted'] = array_merge($existing, $result);

                    $message = 'извлечено из ' . $sourceName . ': ' . count($result) . ' пол.';
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, null, null, $started);
                    continue;
                }

                // `notify` posts to a room's chat channel. It is not a `forward` with
                // a nicer form: the channel holds the credential and the shape (Slack
                // wants `{text}`, Telegram wants `{chat_id, text}` at a derived
                // address), so a rule references a channel by id and rotating a leaked
                // token stays one edit instead of a hunt through every action list.
                if ($type === 'notify') {
                    $channelId = (int) ($action['channel_id'] ?? 0);
                    $channel = $channelId > 0 ? NotificationChannel::find($channelId) : null;

                    if ($channel === null) {
                        $failed = true;
                        $message = 'уведомление: канал не найден (id ' . $channelId . ')';
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
                        continue;
                    }

                    // The channel belongs to a room, and a rule belongs to a room: a
                    // rule that could post into another room's chat would turn a
                    // typo in an id into a cross-room leak. Refused as terminal,
                    // because no retry can change which room the channel is in.
                    if ((int) $channel['room_id'] !== $roomId) {
                        $failed = true;
                        $message = 'уведомление: канал принадлежит другой комнате';
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, null, null, $started);
                        continue;
                    }

                    if (!self::isTrue($channel['enabled'] ?? false)) {
                        // A muted channel is a decision, not a failure: the operator
                        // switched it off, and failing the run would make a paused
                        // chat look like a broken scenario.
                        $message = 'уведомление пропущено: канал «' . (string) $channel['name'] . '» выключен';
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'skipped', $message, null, null, $started);
                        continue;
                    }

                    $text = Template::render((string) ($action['message'] ?? ''), $context);

                    // Same breaker as a forward, keyed by the address a message goes
                    // to: a chat that is down is down for every rule, and the refusal
                    // is a skip with a deferral rather than a failure of this rule.
                    $address = NotificationChannel::address($channel);
                    $breaker = $address === ''
                        ? ['allowed' => true, 'state' => 'closed', 'retry_in' => 0]
                        : CircuitBreaker::tryAcquire($address);

                    if (!$breaker['allowed']) {
                        $defer = max($defer, (int) $breaker['retry_in']);
                        $message = sprintf(
                            'уведомление отложено: адрес %s не отвечает (повтор через %d с)',
                            CircuitBreaker::address($address),
                            $breaker['retry_in'],
                        );
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'skipped', $message, null, null, $started);
                        continue;
                    }

                    $result = NotificationChannel::send($channel, $text);
                    NotificationChannel::touch($channelId, $result);

                    if (!$result['ok']) {
                        $failed = true;
                        $retry = $retry || HttpClient::retryable($result);
                        $message = sprintf(
                            'уведомление в %s → %s%s',
                            NotificationChannel::describe($channel),
                            $result['status'] !== 0 ? (string) $result['status'] : 'ошибка',
                            $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
                        );
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, $result['status'], $result['body'], $started);
                        $breakerOutcomes[] = [$address, false, $result['status'], $result['error']];
                        continue;
                    }

                    $message = sprintf(
                        'уведомление в %s → %d',
                        NotificationChannel::describe($channel),
                        $result['status'],
                    );
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, $result['status'], $result['body'], $started);
                    $breakerOutcomes[] = [$address, true, $result['status'], null];
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

                    // The address itself may already be known to be down. Asking the
                    // breaker before sending is what turns "every delivery pays for
                    // the same timeout" into "the first few do, the rest say why they
                    // did not". A refused attempt is `skipped`: the target is not
                    // this run's fault, so it must not count toward the workflow's
                    // failure streak (that streak pauses the rule, and a paused rule
                    // cannot recover with the endpoint).
                    $breaker = CircuitBreaker::tryAcquire($url);
                    if (!$breaker['allowed']) {
                        $defer = max($defer, (int) $breaker['retry_in']);
                        $message = sprintf(
                            'пересылка отложена: адрес %s не отвечает (повтор через %d с)',
                            CircuitBreaker::address($url),
                            $breaker['retry_in'],
                        );
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'skipped', $message, null, null, $started);
                        continue;
                    }

                    $payload = $action['payload'] ?? 'body';
                    if (is_string($payload)) {
                        $payload = match ($payload) {
                            'body' => $context['body'] ?? [],
                            'transformed' => $context['transformed'] ?? [],
                            'extracted' => $context['extracted'] ?? [],
                            'response' => $context['response'] ?? [],
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
                    //
                    // `sign_timestamp` is opt-in and off by default: a recipient
                    // that verifies a body-only HMAC would reject the `"<ts>.<body>"`
                    // form, so turning it on for every forward would break existing
                    // integrations the day this version ships.
                    $secret = (string) ($action['secret'] ?? '');
                    if ($secret !== '') {
                        [$signedAt, $signature] = Signature::outgoing(
                            $body,
                            $secret,
                            self::isTrue($action['sign_timestamp'] ?? false),
                        );

                        if ($signedAt !== null) {
                            $headers[Signature::TIMESTAMP_HEADER] = $signedAt;
                        }

                        $headers[Signature::HEADER] = $signature;
                    }

                    $result = HttpClient::send(
                        $method,
                        $url,
                        $headers,
                        $body,
                        $guard['addresses'],
                        self::actionTimeout($action),
                    );

                    if (!$result['ok']) {
                        $failed = true;
                        $retry = $retry || HttpClient::retryable($result);
                        // The breaker only hears about a terminal verdict, which is
                        // decided after the loop. Telling it here would open it on
                        // the first of three retries of one incident.
                        $message = sprintf(
                            'пересылка %s %s → %s%s',
                            $method,
                            $url,
                            $result['status'] !== 0 ? (string) $result['status'] : 'ошибка',
                            $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
                        );
                        $messages[] = $message;
                        self::recordStep($runId, $attempt, $index, $type, 'failed', $message, $result['status'], $result['body'], $started);
                        $breakerOutcomes[] = [$url, false, $result['status'], $result['error']];
                        continue;
                    }

                    $message = sprintf('пересылка %s %s → %d', $method, $url, $result['status']);
                    $messages[] = $message;
                    self::recordStep($runId, $attempt, $index, $type, 'success', $message, $result['status'], $result['body'], $started);

                    // The answer becomes `{{ response.… }}` for the steps after this
                    // one, and it is persisted so a retry can still read it after the
                    // forward itself is skipped (see `lastResponse()`).
                    $context['response'] = self::decodeAnswer((string) ($result['body'] ?? ''));
                    $breakerOutcomes[] = [$url, true, $result['status'], null];
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

        return [$failed ? 'failed' : 'success', implode('; ', $messages) ?: 'Готово', $failed && $retry, $delay, $defer, $breakerOutcomes];
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
     * The body of the last successful forward of this run, decoded.
     *
     * A retry skips a forward that already succeeded — which is the whole point —
     * but a later `extract` reading `response.order_id` then has nothing to read,
     * because the answer only lived in the previous attempt's memory. The step log
     * already stores that body, so the retry seeds the context from it instead of
     * producing an `extract: 0 fields` that looks like a broken template.
     *
     * @return array<mixed>
     */
    private static function lastResponse(int $runId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT response_body FROM workflow_run_steps
              WHERE run_id = :run_id AND type = 'forward' AND status = 'success'
                AND response_body IS NOT NULL AND response_body <> ''
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute(['run_id' => $runId]);

        $body = $stmt->fetchColumn();

        return $body === false ? [] : self::decodeAnswer((string) $body);
    }

    /**
     * Decodes an HTTP answer into an addressable structure.
     *
     * A JSON object or array is returned as is; anything else (an empty body, an
     * HTML error page, a bare string) lands under `text`, so `{{ response.text }}`
     * is always defined and a scalar answer does not silently resolve to nothing.
     *
     * @return array<mixed>
     */
    private static function decodeAnswer(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['text' => $body];
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

    /**
     * Per-action forward timeout, or null for the global `FORWARD_TIMEOUT`.
     *
     * Kept as a helper because the preview and the worker must agree: a preview
     * that reports one number and a worker that uses another is worse than no
     * preview at all.
     *
     * @param array<string, mixed> $action
     */
    private static function actionTimeout(array $action): ?int
    {
        $value = $action['timeout'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (int) $value;

        return $seconds >= 1 ? min(60, $seconds) : null;
    }
}
