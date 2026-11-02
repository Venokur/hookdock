<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;

/**
 * Long-lived API tokens for scripts and integrations.
 *
 * The 12h login JWT is a browser session, not a credential a nightly job can
 * hold: it expires at an arbitrary hour and rotating `JWT_SECRET` invalidates
 * every one of them at once. `POST /api/auth/read-token` is the other half of the
 * story — a read-only convenience, not something an integration authenticates
 * with forever.
 *
 * A token is therefore a row, not a signature:
 *
 *   - only the SHA-256 is stored, so a database dump is not a set of credentials;
 *   - `prefix` is the lookup key. It is not a secret (it is listed on the access
 *     page) and it means the request finds its row with one indexed lookup
 *     instead of hashing every stored token;
 *   - the plaintext is returned exactly once, at issue time, like a room's HMAC
 *     secret;
 *   - revocation and expiry are `UPDATE`s, which is the point: a JWT cannot be
 *     taken back before it expires, and that is the gap this closes.
 *
 * The scope travels with the row, so a token issued as `read` stays read-only
 * even though the account behind it may write.
 */
final class ApiToken
{
    /**
     * Versions the *shape* of the plaintext, not the credential. A future format
     * can be recognised and rejected by an old deployment instead of being read
     * as a malformed one.
     */
    private const PREFIX = 'hd_';

    /** Bytes of entropy in the secret part (48 hex characters). */
    private const SECRET_BYTES = 24;

    /** How long a `last_used_at` write is trusted, in seconds. */
    private const TOUCH_INTERVAL = 60;

    /**
     * Builds a fresh token. Nothing is stored here — the caller inserts the row,
     * so a token that cannot be saved is never handed to anybody.
     *
     * @return array{plain: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $prefix = self::PREFIX . bin2hex(random_bytes(6));
        $plain = $prefix . bin2hex(random_bytes(self::SECRET_BYTES));

        return [
            'plain' => $plain,
            'prefix' => $prefix,
            'hash' => self::hash($plain),
        ];
    }

    /**
     * The row behind a presented token, or null when it is unknown, revoked or
     * expired. The user is looked up by the caller: this answers "which token",
     * not "which person".
     *
     * @return array{id: int, user_id: int, scope: string}|null
     */
    public static function resolve(string $plain): ?array
    {
        $plain = trim($plain);

        // Cheap shape check before touching the database: a JWT or a garbage
        // string must not cost a query on every request that carries one.
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) <= strlen(self::PREFIX)) {
            return null;
        }

        $prefix = substr($plain, 0, strlen(self::PREFIX) + 12);

        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, token_hash, scope, expires_at, revoked_at
               FROM api_tokens
              WHERE prefix = :prefix'
        );
        $stmt->execute(['prefix' => $prefix]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Constant-time: a timing oracle on a 48-character secret is far-fetched
        // over HTTP, but comparing a secret with `===` is the kind of shortcut that
        // gets copied somewhere it matters.
        if (!hash_equals((string) $row['token_hash'], self::hash($plain))) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            return null;
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
            return null;
        }

        self::touch((int) $row['id']);

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'scope' => (string) $row['scope'],
        ];
    }

    /**
     * Records use, at most once a minute per token.
     *
     * A write on every API request would double the cost of the request path to
     * answer a question nobody asks at second resolution; the point of the column
     * is "was this key used since we last looked", not an audit trail.
     */
    private static function touch(int $id): void
    {
        try {
            // The interval is interpolated from a class constant, never from input:
            // native prepared statements make Postgres infer the type of a bare
            // `secs => :interval` and it is easy to get that wrong for no benefit.
            $stmt = Database::pdo()->prepare(
                'UPDATE api_tokens
                    SET last_used_at = now()
                  WHERE id = :id
                    AND (last_used_at IS NULL
                         OR last_used_at < now() - make_interval(secs => ' . (int) self::TOUCH_INTERVAL . '))'
            );
            $stmt->execute(['id' => $id]);
        } catch (\PDOException $e) {
            // A token that authenticated is authenticated. Failing the request
            // because the usage timestamp could not be written would turn a
            // bookkeeping column into an outage.
            error_log('api_tokens touch failed: ' . $e->getMessage());
        }
    }

    /**
     * What is stored. The plaintext is never written anywhere.
     */
    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
