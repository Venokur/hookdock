<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;
use App\Database;
use Throwable;

/**
 * Fixed-window rate limiter backed by Postgres.
 *
 * A single-row-per-window counter is enough here and needs no extra infrastructure:
 * the deployment already has Postgres, and a rate limit that only exists per
 * container would reset on every `compose up`.
 *
 * Fails open on purpose. A broken limiter must not lock the operator out of their
 * own dashboard, and this is a brute-force mitigation, not the only control —
 * passwords stay bcrypt-hashed and JWTs stay signed.
 */
final class RateLimiter
{
    /**
     * Counts one attempt and reports whether it is still within the limit.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function hit(string $bucket, string $subject, int $limit, int $windowSeconds): array
    {
        if ($limit <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $window = max(1, $windowSeconds);
        $now = time();

        // Align the window so a burst cannot reset it on every request.
        $start = intdiv($now, $window) * $window;

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO rate_limits (bucket, subject, window_start, hits)
                 VALUES (:bucket, :subject, to_timestamp(:start), 1)
                 ON CONFLICT (bucket, subject, window_start)
                 DO UPDATE SET hits = rate_limits.hits + 1
                 RETURNING hits'
            );
            $stmt->execute([
                'bucket' => $bucket,
                'subject' => $subject,
                'start' => $start,
            ]);

            $hits = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[rate-limit] ' . $e->getMessage());

            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        return [
            'allowed' => $hits <= $limit,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => max(1, $start + $window - $now),
        ];
    }

    public static function limit(string $key, int $default): int
    {
        return max(0, Config::int($key, $default));
    }

    public static function window(string $key, int $default): int
    {
        return max(1, Config::int($key, $default));
    }

    /**
     * Drops windows that can no longer match. Called from the worker, because the
     * request path should not pay for maintenance.
     */
    public static function prune(int $olderThanSeconds = 86400): int
    {
        try {
            $stmt = Database::pdo()->prepare(
                'DELETE FROM rate_limits WHERE window_start < now() - make_interval(secs => :seconds)'
            );
            $stmt->bindValue(':seconds', max(60, $olderThanSeconds), \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[rate-limit] prune failed: ' . $e->getMessage());

            return 0;
        }
    }
}
