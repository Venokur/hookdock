<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\HttpClient;
use App\Support\UrlGuard;

/**
 * A room's chat destination: Slack incoming webhook or Telegram bot.
 *
 * A `forward` can already reach any URL, so the point of a channel is not "another
 * way to send an HTTP request". It is that the two services an operator actually
 * uses differ in shape, not just in address:
 *
 *   slack    — POST `{"text": …}` to a URL that *is* the credential;
 *   telegram — POST `{chat_id, text}` to `…/bot<token>/sendMessage`.
 *
 * Without this, every rule that wants a notification would carry a copy of the bot
 * token and the chat id inside `workflows.actions`, and rotating a leaked token
 * would mean editing every rule that mentioned it. Here a channel is one row and
 * a rule references it by id.
 *
 * Both credentials are secrets and are never returned by the API: reads expose
 * `has_url`, `url_hint` and `has_token` instead, the same rule as `rooms.secret`
 * and `actions[].secret`. A Slack webhook URL is a credential — anyone holding it
 * can post to the channel.
 */
final class NotificationChannel
{
    public const SLACK = 'slack';
    public const TELEGRAM = 'telegram';

    public const KINDS = [self::SLACK, self::TELEGRAM];

    /**
     * Slack truncates a message around 4000 characters and answers `400
     * invalid_payload` above it. Telegram's limit is 4096. Truncating below both is
     * the difference between "the notification arrived, shortened" and "the run
     * failed because the payload was long".
     */
    private const TEXT_LIMIT = 3500;

    /** Telegram's API; overridable for a self-hosted Bot API server. */
    private const DEFAULT_TELEGRAM_BASE = 'https://api.telegram.org';

    /**
     * @return list<array<string, mixed>>
     */
    public static function forRoom(int $roomId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM notification_channels WHERE room_id = :room_id ORDER BY id DESC'
        );
        $stmt->execute(['room_id' => $roomId]);

        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /**
     * Channels that asked to hear about a failing rule of this room.
     *
     * Only enabled rows: a channel the operator switched off must not keep posting,
     * and the flag is the switch an operator expects it to be.
     *
     * @return list<array<string, mixed>> raw rows (the sender needs the secrets)
     */
    public static function failureTargets(int $roomId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM notification_channels
              WHERE room_id = :room_id AND enabled AND notify_on_failure
              ORDER BY id'
        );
        $stmt->execute(['room_id' => $roomId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null raw row, with secrets — never send this to a client
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM notification_channels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Turns a stored row into what the API may show.
     *
     * `url` and `bot_token` are dropped entirely rather than blanked: a key that is
     * present but empty reads as "not configured" to a careless client. `has_url`
     * and `url_hint` answer the two questions the list actually has — "is this set"
     * and "which webhook is it".
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $url = (string) ($row['url'] ?? '');
        $token = (string) ($row['bot_token'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'room_id' => (int) ($row['room_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'kind' => (string) ($row['kind'] ?? ''),
            'has_url' => $url !== '',
            // Enough to tell two Slack webhooks apart in a list, useless as a
            // credential: the workspace host plus the channel-ish tail.
            'url_hint' => self::hint($url),
            'has_token' => $token !== '',
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'notify_on_failure' => self::flag($row['notify_on_failure'] ?? false),
            'enabled' => self::flag($row['enabled'] ?? false),
            'last_sent_at' => $row['last_sent_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * The address a message would be posted to.
     *
     * Telegram's address contains the bot token, so this is a credential too — it
     * is used for the request and for the breaker key, never for a read response.
     *
     * @param array<string, mixed> $channel
     */
    public static function address(array $channel): string
    {
        if ((string) ($channel['kind'] ?? '') === self::TELEGRAM) {
            $base = rtrim(trim((string) Config::get('TELEGRAM_API_BASE', self::DEFAULT_TELEGRAM_BASE)), '/');
            if ($base === '') {
                $base = self::DEFAULT_TELEGRAM_BASE;
            }

            return $base . '/bot' . (string) ($channel['bot_token'] ?? '') . '/sendMessage';
        }

        return trim((string) ($channel['url'] ?? ''));
    }

    /**
     * Sends one message.
     *
     * Never throws: a notification is a side effect of a run, and a broken chat must
     * not turn "the rule worked" into a worker exception. The caller decides what to
     * do with `ok` — the run records a step, `WorkflowAlerting` just logs.
     *
     * @param array<string, mixed> $channel raw row
     * @return array{ok: bool, status: int, error: ?string, body: string, duration_ms: int, address: string}
     */
    public static function send(array $channel, string $text): array
    {
        $address = self::address($channel);
        $text = self::shorten($text);

        $kind = (string) ($channel['kind'] ?? '');
        $body = $kind === self::TELEGRAM
            ? [
                'chat_id' => (string) ($channel['chat_id'] ?? ''),
                'text' => $text,
                // A link preview of an internal run URL is noise in a chat.
                'disable_web_page_preview' => true,
            ]
            : ['text' => $text];

        $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($address === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => $kind === self::TELEGRAM ? 'не задан токен бота' : 'не задан адрес вебхука',
                'body' => '',
                'duration_ms' => 0,
                'address' => '',
            ];
        }

        // The address comes from an operator but is still an outbound request: a
        // typo must not become an SSRF into cloud metadata, and Telegram's own host
        // has to pass the same check as a Slack URL. Refused without contacting.
        $guard = UrlGuard::check($address);
        if (!$guard['allowed']) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'адрес запрещён: ' . (string) ($guard['reason'] ?? 'не разрешён'),
                'body' => '',
                'duration_ms' => 0,
                'address' => $address,
            ];
        }

        $result = HttpClient::send(
            'POST',
            $address,
            ['Content-Type' => 'application/json'],
            $payload,
            $guard['addresses'],
        );

        return $result + ['address' => $address];
    }

    /**
     * Remembers the outcome so the channel's card can answer "does it work".
     *
     * Best effort: this runs after the message was (not) sent, and a failed
     * bookkeeping UPDATE must not fail the run or the test request.
     *
     * @param array<string, mixed> $result
     */
    public static function touch(int $channelId, array $result): void
    {
        try {
            $ok = (bool) ($result['ok'] ?? false);
            $error = $ok
                ? null
                : trim(sprintf(
                    '%s%s',
                    (string) ($result['error'] ?? ''),
                    (int) ($result['status'] ?? 0) > 0 ? ' (HTTP ' . (int) $result['status'] . ')' : '',
                ));

            // Two statements rather than one with `CASE WHEN :ok`: a failed send
            // must not move `last_sent_at`, and repeating a boolean parameter in one
            // native prepared statement is the kind of cleverness that breaks on a
            // driver upgrade for no benefit.
            $stmt = Database::pdo()->prepare(
                $ok
                    ? 'UPDATE notification_channels
                          SET last_sent_at = now(), last_error = NULL, updated_at = now()
                        WHERE id = :id'
                    : 'UPDATE notification_channels
                          SET last_error = :error
                        WHERE id = :id'
            );

            $stmt->execute($ok ? ['id' => $channelId] : ['error' => $error, 'id' => $channelId]);
        } catch (\Throwable $e) {
            error_log('channel bookkeeping failed: ' . $e->getMessage());
        }
    }

    /**
     * The message a run sends when the notification itself is the action.
     *
     * @param array<string, mixed> $channel
     */
    public static function describe(array $channel): string
    {
        $name = (string) ($channel['name'] ?? '');
        $kind = (string) ($channel['kind'] ?? '');

        return $kind === self::TELEGRAM
            ? 'Telegram «' . $name . '»'
            : 'Slack «' . $name . '»';
    }

    /**
     * `hooks.slack.com/…/T01/B02/xxxx` — the host and the first path segment, which
     * identifies the workspace without carrying the token part of the URL.
     */
    private static function hint(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $host = (string) $parts['host'];
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $head = $path === '' ? '' : '/' . explode('/', $path)[0];

        return $host . $head;
    }

    private static function shorten(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > self::TEXT_LIMIT
            ? mb_substr($text, 0, self::TEXT_LIMIT) . '…'
            : $text;
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }
}
