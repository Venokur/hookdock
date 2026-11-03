<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\NotificationChannel;

/**
 * A room's chat destinations: Slack and Telegram.
 *
 * Scoped to the room rather than to a workflow, because a channel is an
 * operational fact ("our ops chat"), not a property of one rule. Two rules that
 * post to the same chat share one channel, and rotating a leaked Slack URL is one
 * edit instead of a hunt through every action list.
 *
 * Secrets never leave: `url` and `bot_token` are stored as-is (a Slack webhook URL
 * is the credential, a bot token cannot be checked against a hash) and every read
 * exposes `has_url` / `url_hint` / `has_token` instead.
 */
final class NotificationChannelsController
{
    private const NAME_LIMIT = 120;

    public function index(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $room = $this->room((int) ($params['id'] ?? 0));

        Response::json([
            'data' => NotificationChannel::forRoom((int) $room['id']),
            'meta' => [
                'room_id' => (int) $room['id'],
                'room_slug' => (string) $room['slug'],
                'kinds' => NotificationChannel::KINDS,
            ],
        ]);
    }

    public function store(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $room = $this->room((int) ($params['id'] ?? 0));
        $payload = $request->json() ?? [];

        $data = $this->validated($payload, null, (int) $room['id']);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO notification_channels
                (room_id, name, kind, url, bot_token, chat_id, notify_on_failure, enabled)
             VALUES
                (:room_id, :name, :kind, :url, :bot_token, :chat_id, :notify_on_failure, :enabled)
             RETURNING *'
        );
        $stmt->execute([
            'room_id' => (int) $room['id'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'url' => $data['url'],
            'bot_token' => $data['bot_token'],
            'chat_id' => $data['chat_id'],
            // Postgres booleans need text params (PHP `false` is sent as `''`).
            'notify_on_failure' => $data['notify_on_failure'] ? 'true' : 'false',
            'enabled' => $data['enabled'] ? 'true' : 'false',
        ]);

        $row = $stmt->fetch();
        $presented = NotificationChannel::present($row);

        Audit::record([
            'action' => 'channel.created',
            'object_type' => 'notification_channel',
            'object_id' => $presented['id'],
            'object_label' => $presented['name'],
            'room_id' => (int) $room['id'],
            'room_slug' => (string) $room['slug'],
            'summary' => 'Добавлен канал уведомлений «' . $presented['name'] . '» (' . $presented['kind'] . ')',
            'changes' => [
                'name' => [null, $presented['name']],
                'kind' => [null, $presented['kind']],
                'notify_on_failure' => [null, $presented['notify_on_failure']],
                'enabled' => [null, $presented['enabled']],
            ],
        ], $request);

        Response::json(['data' => $presented], 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);
        $existing = $this->find($id);
        $payload = $request->json() ?? [];

        $data = $this->validated($payload, $existing, (int) $existing['room_id']);

        $stmt = Database::pdo()->prepare(
            'UPDATE notification_channels
                SET name = :name,
                    kind = :kind,
                    url = :url,
                    bot_token = :bot_token,
                    chat_id = :chat_id,
                    notify_on_failure = :notify_on_failure,
                    enabled = :enabled,
                    updated_at = now()
              WHERE id = :id
              RETURNING *'
        );
        $stmt->execute([
            'name' => $data['name'],
            'kind' => $data['kind'],
            'url' => $data['url'],
            'bot_token' => $data['bot_token'],
            'chat_id' => $data['chat_id'],
            'notify_on_failure' => $data['notify_on_failure'] ? 'true' : 'false',
            'enabled' => $data['enabled'] ? 'true' : 'false',
            'id' => $id,
        ]);

        $row = $stmt->fetch();
        $presented = NotificationChannel::present($row);
        $before = NotificationChannel::present($existing);

        // The diff is built from the presented rows, so a rotated token is journalled
        // as `has_token: false → true` and never as the value itself.
        $changes = [];
        foreach (['name', 'kind', 'notify_on_failure', 'enabled', 'has_url', 'has_token', 'chat_id'] as $field) {
            if ($before[$field] !== $presented[$field]) {
                $changes[$field] = [$before[$field], $presented[$field]];
            }
        }

        Audit::record([
            'action' => 'channel.updated',
            'object_type' => 'notification_channel',
            'object_id' => $id,
            'object_label' => $presented['name'],
            'room_id' => $presented['room_id'],
            'summary' => 'Изменён канал уведомлений «' . $presented['name'] . '»',
            'changes' => $changes,
        ], $request);

        Response::json(['data' => $presented]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);
        $existing = $this->find($id);

        $room = $this->room((int) $existing['room_id']);

        $stmt = Database::pdo()->prepare(
            'DELETE FROM notification_channels WHERE id = :id RETURNING id'
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->fetch() === false) {
            Response::error('Канал не найден', 404);
        }

        $presented = NotificationChannel::present($existing);

        Audit::record([
            'action' => 'channel.deleted',
            'object_type' => 'notification_channel',
            'object_id' => $id,
            'object_label' => $presented['name'],
            'room_id' => (int) $room['id'],
            'room_slug' => (string) $room['slug'],
            'summary' => 'Удалён канал уведомлений «' . $presented['name'] . '»',
            'changes' => [
                'name' => [$presented['name'], null],
                'kind' => [$presented['kind'], null],
            ],
        ], $request);

        Response::json(['ok' => true, 'deleted' => true]);
    }

    /**
     * Sends a probe message so "does this work" is answered before a real incident.
     *
     * A channel that has never been used looks identical to one whose token is
     * wrong, and the moment you find out is the moment a rule failed. The content is
     * deliberately boring and carries no payload: this is a connectivity check, not
     * a notification.
     */
    public function test(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);
        $channel = $this->find($id);

        $result = NotificationChannel::send(
            $channel,
            sprintf(
                "Hookdock: проверка канала «%s». Если вы это видите, уведомления из комнаты «%s» дойдут.",
                (string) $channel['name'],
                $this->room((int) $channel['room_id'])['name'],
            ),
        );

        NotificationChannel::touch($id, $result);

        $ok = (bool) ($result['ok'] ?? false);

        Audit::record([
            'action' => 'channel.tested',
            'object_type' => 'notification_channel',
            'object_id' => $id,
            'object_label' => (string) $channel['name'],
            'room_id' => (int) $channel['room_id'],
            'summary' => $ok
                ? 'Проверка канала «' . $channel['name'] . '» прошла'
                : 'Проверка канала «' . $channel['name'] . '» не удалась',
        ], $request);

        // A failed probe is a `200` with `ok: false`, not a `502`: the request to
        // *Hookdock* succeeded, the channel did not, and a proxy error code would
        // hide the message the operator needs to read.
        Response::json([
            'ok' => $ok,
            'status' => (int) ($result['status'] ?? 0),
            'error' => $result['error'] ?? null,
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing update: lets a blank secret mean
     *        "keep the stored one", exactly like `actions[].secret`
     * @param int $roomId the room the channel belongs to (a new channel has no row)
     * @return array{name: string, kind: string, url: ?string, bot_token: ?string, chat_id: ?string, notify_on_failure: bool, enabled: bool}
     */
    private function validated(array $payload, ?array $existing, int $roomId): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            Response::error('Укажите название канала', 422);
        }
        if (mb_strlen($name) > self::NAME_LIMIT) {
            Response::error('Название канала должно быть не длиннее ' . self::NAME_LIMIT . ' символов', 422);
        }

        // An update that does not name the type means "the one it already has", not
        // "slack": defaulting would turn `PUT {name}` on a Telegram channel into a
        // refused type change.
        $defaultKind = $existing === null
            ? NotificationChannel::SLACK
            : (string) ($existing['kind'] ?? NotificationChannel::SLACK);
        $kind = strtolower(trim((string) ($payload['kind'] ?? $defaultKind)));

        if (!in_array($kind, NotificationChannel::KINDS, true)) {
            Response::error('Неизвестный тип канала: допустимы slack и telegram', 422);
        }

        // The type is fixed after creation: Slack keeps a webhook URL and Telegram
        // keeps a bot token, so switching would leave the other field behind as a
        // stale credential nobody can see, and the editor's form (which shows one
        // set of fields) would silently keep posting through the old one.
        if ($existing !== null && (string) ($existing['kind'] ?? '') !== $kind) {
            Response::error('Тип канала нельзя изменить: создайте новый канал', 422);
        }

        $this->assertNameFree($roomId, $name, (int) ($existing['id'] ?? 0));

        $url = null;
        $botToken = null;
        $chatId = null;

        if ($kind === NotificationChannel::SLACK) {
            // The client never receives the stored URL, so an empty field means
            // "keep it" — otherwise editing the name would silently break the
            // channel. Rotating is typing a new value.
            $url = trim((string) ($payload['url'] ?? ''));
            if ($url === '') {
                $url = (string) ($existing['url'] ?? '');
            }

            // A channel with no credential is a channel that cannot post, so it is
            // not a state the API accepts: it would look configured in the editor
            // and fail every run that used it. Rotating replaces the value, and
            // removing one means deleting the channel.
            if ($url === '') {
                Response::error('Для канала Slack нужен URL входящего вебхука', 422);
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                Response::error('URL вебхука должен быть корректным адресом', 422);
            }
            if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                Response::error('URL вебхука должен использовать http или https', 422);
            }

            // `UrlGuard` is *not* applied here, for the same reason a `forward` is
            // not: the same address may be refused on one installation and allowed
            // on another (`FORWARD_ALLOW_INTERNAL`), so the verdict belongs at send
            // time. The test endpoint reports it, which is the moment it matters.

            $url = '' === $url ? null : $url;
        } else {
            $botToken = trim((string) ($payload['bot_token'] ?? ''));
            if ($botToken === '') {
                $botToken = (string) ($existing['bot_token'] ?? '');
            }

            if ($botToken === '') {
                Response::error('Для канала Telegram нужен токен бота', 422);
            }
            // @BotFather issues `<digits>:<35ish chars>`; refusing an obviously wrong
            // value here is cheaper than a 401 at the first real incident.
            if (preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $botToken) !== 1) {
                Response::error('Токен бота выглядит неверно: ожидается <id>:<ключ> от @BotFather', 422);
            }

            // Omitted keeps the stored chat, like the token and the switches: a
            // rename must not require the caller to repeat a value it never
            // received (the read does return `chat_id`, but a script may only know
            // the id). An explicit empty value is still refused below, because a
            // Telegram channel without a chat cannot post.
            $chatId = array_key_exists('chat_id', $payload)
                ? trim((string) $payload['chat_id'])
                : trim((string) ($existing['chat_id'] ?? ''));

            if ($chatId === '') {
                Response::error('Для канала Telegram нужен chat_id', 422);
            }
            if (preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{3,})$/', $chatId) !== 1) {
                Response::error('chat_id: ожидается число (например -1001234567890) или @username', 422);
            }

            $botToken = '' === $botToken ? null : $botToken;
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'url' => $url === '' ? null : $url,
            'bot_token' => $botToken === '' ? null : $botToken,
            'chat_id' => $chatId,
            // Omitted keeps the current value, like `enabled`: the edit form sends
            // the switch, but a rename through curl must not quietly stop the
            // channel from reporting failures.
            'notify_on_failure' => array_key_exists('notify_on_failure', $payload)
                ? $this->isTrue($payload['notify_on_failure'])
                : (bool) ($existing === null ? false : NotificationChannel::present($existing)['notify_on_failure']),
            // Omitted keeps the current value: a rename form that does not show the
            // switch must not silently re-enable a channel someone muted.
            'enabled' => array_key_exists('enabled', $payload)
                ? $this->isTrue($payload['enabled'])
                : (bool) ($existing === null ? true : NotificationChannel::present($existing)['enabled']),
        ];
    }

    /**
     * One name per room, case-insensitively. The editor picks a channel from a
     * dropdown, and two entries called "Ops" make that dropdown unanswerable.
     */
    private function assertNameFree(int $roomId, string $name, int $exceptId): void
    {
        if ($roomId <= 0) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id FROM notification_channels
              WHERE room_id = :room_id AND lower(name) = lower(:name) AND id <> :id'
        );
        $stmt->execute(['room_id' => $roomId, 'name' => $name, 'id' => $exceptId]);

        if ($stmt->fetchColumn() !== false) {
            Response::error('В комнате уже есть канал с таким названием', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function room(int $roomId): array
    {
        if ($roomId <= 0) {
            Response::error('Комната не найдена', 404);
        }

        $stmt = Database::pdo()->prepare('SELECT id, name, slug FROM rooms WHERE id = :id');
        $stmt->execute(['id' => $roomId]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Комната не найдена', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        $row = $id > 0 ? NotificationChannel::find($id) : null;

        if ($row === null) {
            Response::error('Канал не найден', 404);
        }

        return $row;
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
