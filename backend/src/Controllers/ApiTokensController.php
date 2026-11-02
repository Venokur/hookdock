<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiToken;
use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;

/**
 * Long-lived API tokens, for the integrations a browser session cannot serve.
 *
 * A login JWT lives 12 hours and dies with `JWT_SECRET`; a nightly export or a CI
 * job needs a credential that survives both and can still be taken back. That is
 * this table.
 *
 * Admin-only, like `/api/users*`: a token is not "your own key", it is a way to
 * act as an account, and an editor who could mint an `api` token for themselves
 * would be an admin with extra steps. `Auth::requireAdmin` refuses a `scope: read`
 * token and a `viewer` role through the write gate it already passes.
 *
 * The plaintext is returned exactly once, in the `201`. Reads expose `prefix` —
 * enough to tell two keys apart in a list, useless as a credential.
 */
final class ApiTokensController
{
    /**
     * Tokens of the installation, newest first.
     *
     * `expired` and `revoked` are derived here rather than stored: a status column
     * would need a background job to stay true, and the answer is a comparison
     * either way.
     */
    public function index(Request $request): void
    {
        Auth::requireAdmin($request);

        $stmt = Database::pdo()->query(
            "SELECT t.id, t.user_id, u.email AS user_email, t.name, t.prefix, t.scope,
                    t.expires_at, t.last_used_at, t.revoked_at, t.created_at,
                    (t.revoked_at IS NOT NULL) AS revoked,
                    (t.revoked_at IS NULL AND t.expires_at IS NOT NULL AND t.expires_at <= now()) AS expired
               FROM api_tokens t
               JOIN users u ON u.id = t.user_id
              ORDER BY t.id DESC"
        );

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['revoked'] = self::isTrue($row['revoked']);
            $row['expired'] = self::isTrue($row['expired']);
        }
        unset($row);

        Response::json(['data' => $rows]);
    }

    /**
     * Issues a token.
     *
     * `name` is required because a list of six unnamed keys answers nothing when
     * one of them has to be revoked. `scope` defaults to `read`, deliberately: the
     * safer credential is the one you get when you do not ask.
     */
    public function store(Request $request): void
    {
        $actor = Auth::requireAdmin($request);
        $payload = $request->json() ?? [];

        $name = $this->validName($payload['name'] ?? '');
        $scope = $this->validScope($payload['scope'] ?? Auth::SCOPE_READ);
        $expiresAt = $this->validExpiry($payload['expires_at'] ?? null);

        // The owner is the account that issued it. A separate `user_id` in the body
        // would let an admin mint a token for somebody else, which sounds useful and
        // is exactly what makes "who used this key" unanswerable later.
        $token = ApiToken::generate();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO api_tokens (user_id, name, prefix, token_hash, scope, expires_at)
             VALUES (:user_id, :name, :prefix, :hash, :scope, :expires_at)
             RETURNING id, user_id, name, prefix, scope, expires_at, last_used_at,
                       revoked_at, created_at'
        );
        $stmt->execute([
            'user_id' => (int) $actor['id'],
            'name' => $name,
            'prefix' => $token['prefix'],
            'hash' => $token['hash'],
            'scope' => $scope,
            'expires_at' => $expiresAt,
        ]);

        $row = $stmt->fetch();
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['revoked'] = false;
        $row['expired'] = false;

        // The hash is never journalled and never returned: only the fact that a key
        // of a given scope and name exists, which is what "who created this" needs.
        Audit::record([
            'action' => 'token.created',
            'object_type' => 'api_token',
            'object_id' => $row['id'],
            'object_label' => $name,
            'summary' => 'Выпущен токен «' . $name . '» со scope ' . $scope,
            'changes' => [
                'name' => [null, $name],
                'scope' => [null, $scope],
                'expires_at' => [null, $row['expires_at']],
            ],
        ], $request);

        // Shown once, here. The list endpoint never returns it, so an operator who
        // closes this response has to issue a new token — same rule as a room's HMAC
        // secret, and for the same reason: a value the API can read back is a value
        // that leaks with a database dump or a browser history entry.
        $row['token'] = $token['plain'];

        Response::json(['data' => $row], 201);
    }

    /**
     * Revokes a token. The row is kept.
     *
     * Deleting it would erase the record that the key existed, and `last_used_at`
     * is the answer to "was this leaked key used before we noticed". A revoked row
     * cannot authenticate anything (`ApiToken::resolve` refuses it), so keeping it
     * costs nothing but makes the list readable.
     */
    public function destroy(Request $request, array $params): void
    {
        Auth::requireAdmin($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare(
            'UPDATE api_tokens
                SET revoked_at = now()
              WHERE id = :id
                AND revoked_at IS NULL
              RETURNING id, name, scope'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            // Either it never existed or it is already revoked. Both answer the
            // operator's question ("is this key still usable") the same way, but a
            // silent 200 for a typo would be a lie, so an id that is not there is a
            // 404 and a repeat revoke is a 200 no-op.
            $this->assertExists($id);
            Response::json(['ok' => true, 'revoked' => true, 'already' => true]);

            return;
        }

        Audit::record([
            'action' => 'token.revoked',
            'object_type' => 'api_token',
            'object_id' => (int) $row['id'],
            'object_label' => (string) $row['name'],
            'summary' => 'Отозван токен «' . $row['name'] . '»',
            'changes' => ['revoked' => [false, true]],
        ], $request);

        Response::json(['ok' => true, 'revoked' => true, 'already' => false]);
    }

    private function assertExists(int $id): void
    {
        if ($id <= 0) {
            Response::error('Токен не найден', 404);
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM api_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->fetchColumn() === false) {
            Response::error('Токен не найден', 404);
        }
    }

    private function validName(mixed $value): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            Response::error('Укажите название токена', 422);
        }
        if (mb_strlen($name) > 120) {
            Response::error('Название токена должно быть не длиннее 120 символов', 422);
        }

        return $name;
    }

    private function validScope(mixed $value): string
    {
        $scope = strtolower(trim((string) $value));

        if (!in_array($scope, [Auth::SCOPE_API, Auth::SCOPE_READ], true)) {
            Response::error('Неизвестный scope: допустимы api и read', 422);
        }

        return $scope;
    }

    /**
     * An absolute moment, or null for "does not expire".
     *
     * A duration ("30 days") was the alternative and is worse: re-running the same
     * request a week later would produce a differently-expiring key, and the
     * operator's intent is a date, not an interval.
     *
     * @param mixed $value
     */
    private function validExpiry(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);

        // A bare date is parsed on its own, not through `createFromFormat`, because
        // that fills every field the format omits from *now*: `2027-06-01` came out
        // as `2027-06-01 22:28:05`, so "end of that day" never applied and the key
        // appeared to expire at the minute it was issued.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'))
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $raw, new \DateTimeZone('UTC'));

            if ($parsed === false) {
                Response::error('Дата истечения: ожидается ISO-8601, например 2027-01-31T00:00:00Z', 422);
            }

            // "End of that day" is what an operator picking a date on a calendar
            // means; a key that dies at 00:00 of the chosen day is a day early.
            $parsed = $parsed->setTime(23, 59, 59);

            if ($parsed <= new \DateTimeImmutable()) {
                Response::error('Дата истечения уже прошла', 422);
            }

            return $parsed->format('c');
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $raw);

        if ($parsed === false) {
            Response::error('Дата истечения: ожидается ISO-8601, например 2027-01-31T00:00:00Z', 422);
        }

        // A timestamp in the past is refused rather than issued dead: it would look
        // like a working credential in the list until the first request it was tried
        // on.
        if ($parsed <= new \DateTimeImmutable()) {
            Response::error('Дата истечения уже прошла', 422);
        }

        return $parsed->format('c');
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }
}
