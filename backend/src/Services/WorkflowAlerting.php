<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\HttpClient;
use App\Support\UrlGuard;
use Throwable;

/**
 * Makes a failing workflow visible: counts terminal failures, pauses the rule
 * after too many in a row, and posts a notification to an operator webhook.
 *
 * The receiver answers `202` before any action runs, so a broken forward target
 * used to fail in silence — the sender is happy, the log is the only evidence,
 * and nobody opens the log until a customer complains. This is the missing
 * signal.
 *
 * Only *terminal* failures count: a retry that later succeeds is normal
 * operation, not an incident, and counting attempts would pause a workflow during
 * a five-second network blip.
 */
final class WorkflowAlerting
{
    /**
     * A successful run proves the rule works again, so the streak ends here.
     * Guarded by `consecutive_failures > 0` to keep the common case a no-op.
     */
    public static function onSuccess(int $workflowId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE workflows SET consecutive_failures = 0
              WHERE id = :id AND consecutive_failures > 0'
        );
        $stmt->execute(['id' => $workflowId]);
    }

    /**
     * Records one terminal failure, pauses the workflow at the threshold and
     * notifies the operator.
     *
     * @param array<string, mixed> $workflow workflows row (id, name, endpoint, enabled)
     * @param array<string, mixed> $run      workflow_runs row
     * @param array<string, mixed>|null $request incoming_requests row
     */
    public static function onTerminalFailure(array $workflow, array $run, ?array $request): void
    {
        $workflowId = (int) $workflow['id'];
        $threshold = max(0, Config::int('WORKFLOW_FAILURE_THRESHOLD', 3));

        $stmt = Database::pdo()->prepare(
            'UPDATE workflows
                SET consecutive_failures = consecutive_failures + 1
              WHERE id = :id
              RETURNING id, consecutive_failures, enabled, name, endpoint, room_id, last_alert_at'
        );
        $stmt->execute(['id' => $workflowId]);
        $row = $stmt->fetch();

        if ($row === false) {
            // Deleted while the run was in flight: nothing to report.
            return;
        }

        $failures = (int) $row['consecutive_failures'];
        // Postgres booleans arrive as PHP `true`/`false` with this driver build, but
        // `'f'` would cast to a truthy string, so it is normalised explicitly.
        $wasEnabled = self::flag($row['enabled'] ?? false);
        $paused = false;

        if ($threshold > 0 && $wasEnabled && $failures >= $threshold) {
            $reason = sprintf(
                'Автоматически выключен: %s подряд. Последняя ошибка: %s',
                self::failureCount($failures),
                self::shorten((string) ($run['message'] ?? '')),
            );

            // `AND enabled` keeps two workers from writing two different reasons
            // for the same outage; only the one that flipped it reports a pause.
            $pause = Database::pdo()->prepare(
                'UPDATE workflows
                    SET enabled = false, disabled_reason = :reason, disabled_at = now()
                  WHERE id = :id AND enabled
                  RETURNING id'
            );
            $pause->execute(['reason' => $reason, 'id' => $workflowId]);

            if ($pause->fetch() !== false) {
                $paused = true;
                Realtime::publish('workflow.changed', [
                    'id' => $workflowId,
                    'room_id' => (int) $row['room_id'],
                    'action' => 'toggled',
                ]);
            }
        }

        self::notify($row, $run, $request, $failures, $threshold, $paused);
    }

    /**
     * Posts the notification. Best effort by construction: an unreachable alert
     * target must never turn a failed run into a failed run *and* a broken worker.
     *
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $run
     * @param array<string, mixed>|null $request
     */
    private static function notify(
        array $workflow,
        array $run,
        ?array $request,
        int $failures,
        int $threshold,
        bool $paused,
    ): void {
        $url = trim((string) Config::get('ALERT_WEBHOOK_URL', ''));
        if ($url === '') {
            return;
        }

        // A permanently broken target would otherwise alert on every single
        // webhook. The pause notice ignores the cooldown: it is the one message
        // that explains why the rule stopped, and it happens once per streak.
        $cooldown = max(0, Config::int('ALERT_COOLDOWN_SECONDS', 900));
        $lastAlert = $workflow['last_alert_at'] ?? null;
        if (!$paused && $cooldown > 0 && $lastAlert !== null) {
            $elapsed = time() - (int) strtotime((string) $lastAlert);
            if ($elapsed < $cooldown) {
                return;
            }
        }

        $room = self::room((int) ($workflow['room_id'] ?? 0));
        $name = (string) ($workflow['name'] ?? '');
        $message = self::shorten((string) ($run['message'] ?? ''));

        $text = $paused
            ? sprintf(
                'Hookdock: сценарий «%s» выключен после %s подряд. Последняя ошибка: %s',
                $name,
                self::failureCountGenitive($failures),
                $message,
            )
            : sprintf(
                'Hookdock: сценарий «%s» упал (%s подряд). %s',
                $name,
                self::failureCount($failures),
                $message,
            );

        $payload = [
            // `text` so a Slack/Discord incoming webhook works as-is; the rest is
            // for anything that wants structure.
            'text' => $text,
            'event' => $paused ? 'workflow.paused' : 'workflow.failed',
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'workflow' => [
                'id' => (int) ($workflow['id'] ?? 0),
                'name' => $name,
                'endpoint' => (string) ($workflow['endpoint'] ?? '*'),
                'enabled' => !$paused,
            ],
            'room' => $room,
            'run' => [
                'id' => (int) ($run['id'] ?? 0),
                'attempts' => (int) ($run['attempts'] ?? 0),
                'message' => $message,
            ],
            'request' => $request === null ? null : [
                'id' => (int) $request['id'],
                'method' => (string) ($request['method'] ?? ''),
                'path' => (string) ($request['path'] ?? ''),
                'endpoint' => (string) ($request['endpoint'] ?? ''),
            ],
            'consecutive_failures' => $failures,
            'threshold' => $threshold,
            'disabled' => $paused,
        ];

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            // The alert URL comes from the installation's own environment, not from
            // a workflow, but it is still an outbound request: the same guard keeps
            // a misconfigured value from reaching cloud metadata.
            $guard = UrlGuard::check($url);
            if (!$guard['allowed']) {
                error_log('alert skipped: ' . ($guard['reason'] ?? 'URL not allowed'));

                return;
            }

            $result = HttpClient::send(
                'POST',
                $url,
                ['Content-Type' => 'application/json', 'X-Hookdock-Alert' => 'workflow'],
                $body,
                $guard['addresses'],
            );

            if (!$result['ok']) {
                // Not retried: the next failure (or the pause notice) carries the
                // same information, and a retry loop here would hide the outage.
                error_log(sprintf(
                    'alert delivery failed (status %d%s)',
                    $result['status'],
                    $result['error'] !== null ? ', ' . $result['error'] : '',
                ));

                return;
            }

            $mark = Database::pdo()->prepare('UPDATE workflows SET last_alert_at = now() WHERE id = :id');
            $mark->execute(['id' => (int) ($workflow['id'] ?? 0)]);
        } catch (Throwable $e) {
            error_log('alert failed: ' . $e->getMessage());
        }
    }

    /**
     * Room identity for the payload. The slug is what an operator recognises in a
     * chat message; the id keeps it usable without a second lookup.
     *
     * @return array{id: int, slug: string, name: string}
     */
    private static function room(int $roomId): array
    {
        if ($roomId <= 0) {
            return ['id' => 0, 'slug' => '', 'name' => ''];
        }

        $stmt = Database::pdo()->prepare('SELECT id, slug, name FROM rooms WHERE id = :id');
        $stmt->execute(['id' => $roomId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['id' => $roomId, 'slug' => '', 'name' => ''];
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
        ];
    }

    /** «3 ошибки подряд», «1 ошибка подряд», «5 ошибок подряд». */
    public static function failureCount(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $count . ' ошибка';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
            return $count . ' ошибки';
        }

        return $count . ' ошибок';
    }

    /**
     * The reason is read in a genitive phrase («после 3 ошибок подряд»), which needs
     * a different form than the nominative used on a card. Kept as a pair so both
     * phrases stay correct instead of one being appended to the other.
     */
    public static function failureCountGenitive(int $count): string
    {
        return $count % 10 === 1 && $count % 100 !== 11
            ? $count . ' ошибки'
            : $count . ' ошибок';
    }

    private static function shorten(string $message): string
    {
        $message = trim($message);

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    /**
     * Postgres booleans reach PHP as `true`/`false` here, but a driver that returns
     * `'t'`/`'f'` would make `'f'` truthy — so the value is normalised, not cast.
     */
    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }
}
