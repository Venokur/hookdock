<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use PDO;

/**
 * Per-address circuit breaker for `forward` actions.
 *
 * `UrlGuard` answers "may this address be called", never "is it working". A dead
 * target therefore costs `ROUTING_MAX_ATTEMPTS` failed requests per delivery and
 * then a failed run; after `WORKFLOW_FAILURE_THRESHOLD` of those the workflow is
 * paused automatically. One broken endpoint takes down the rule that would have
 * recovered together with it, and every delivery keeps paying for the same
 * discovery.
 *
 * The breaker remembers the address instead of the rule: two scenarios forwarding
 * to the same endpoint share one verdict, and fixing the endpoint fixes both.
 *
 * Three states, derived rather than scheduled:
 *   closed   — normal. Failures are counted, a success resets them.
 *   open     — the address is known to be down. `tryAcquire()` refuses, the run
 *              records a `skipped` step and does not burn an attempt.
 *   half-open — `next_probe_at` has passed. Exactly one delivery is let through,
 *              and its outcome either closes the breaker or re-opens it.
 *
 * Nothing recovers the breaker except a real request: a background "ping the
 * target" job would be a second source of traffic, and a target that answers a
 * ping is not necessarily able to accept a webhook.
 *
 * A refused attempt is recorded as `skipped`, not `failed`, because `skipped`
 * changes nothing in the workflow's failure streak and sends no alert. Counting
 * a known-down target per delivery would pause the rule the breaker exists to
 * protect.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    /** Failures in a row before an address is trusted no more. */
    private const DEFAULT_THRESHOLD = 2;

    /** How long an open address is skipped before one probe is allowed. */
    private const DEFAULT_COOLDOWN = 60;

    /**
     * The breaker key: origin only, without path or query.
     *
     * One row per destination, not per webhook URL: `/hooks/a` and `/hooks/b` on a
     * host that is down are the same outage, and a rule-local key would let a
     * renamed path bypass the verdict.
     */
    public static function address(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            return $scheme . '://' . $host . ':' . (int) $parts['port'];
        }

        // Default ports are written out, because `https://x` and `https://x:443`
        // are the same destination and must share one verdict.
        $port = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * Whether a `forward` to this address may run now.
     *
     * `open` and `half_open` are both "refuse", but they differ in the state they
     * leave behind: the first delivery after the cooldown claims the probe, so two
     * deliveries cannot both probe and two screens cannot both see "recovering".
     *
     * @return array{allowed: bool, state: string, retry_in: int}
     */
    public static function tryAcquire(string $url): array
    {
        $address = self::address($url);
        if ($address === '') {
            // A URL without a host is handled by `UrlGuard`; the breaker has no
            // opinion and must not turn a configuration error into a skip.
            return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
        }

        $pdo = Database::pdo();
        // `FOR UPDATE` because claiming a half-open probe is a decision: without the
        // row lock two workers can both see `next_probe_at` in the past and both go
        // to a target that is still down, which is exactly what the cooldown is for.
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM forward_targets WHERE address = :address FOR UPDATE');
            $stmt->execute(['address' => $address]);
            $row = $stmt->fetch();

            if ($row === false) {
                $insert = $pdo->prepare(
                    "INSERT INTO forward_targets (address, state) VALUES (:address, 'closed')
                     ON CONFLICT (address) DO NOTHING"
                );
                $insert->execute(['address' => $address]);
                $pdo->commit();

                return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
            }

            $state = (string) $row['state'];
            if ($state === self::CLOSED) {
                $pdo->commit();

                return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
            }

            $nextProbe = $row['next_probe_at'] === null ? null : strtotime((string) $row['next_probe_at']);
            if ($nextProbe !== null && $nextProbe <= time()) {
                $claim = $pdo->prepare(
                    "UPDATE forward_targets
                        SET state = 'half_open', next_probe_at = now() + make_interval(secs => :cooldown),
                            updated_at = now()
                      WHERE address = :address"
                );
                $claim->execute(['cooldown' => self::cooldown(), 'address' => $address]);
                $pdo->commit();

                return ['allowed' => true, 'state' => self::HALF_OPEN, 'retry_in' => 0];
            }

            $retryIn = $nextProbe === null ? self::cooldown() : max(1, $nextProbe - time());
            $pdo->commit();

            return ['allowed' => false, 'state' => self::OPEN, 'retry_in' => $retryIn];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // The breaker is a guard rail, not the delivery itself: a broken table
            // must not stop every forward. Failing open here matches `RateLimiter`,
            // which also prefers "the limiter is broken" over "the product is down".
            error_log('circuit breaker unavailable: ' . $e->getMessage());

            return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
        }
    }

    /**
     * The same answer as `tryAcquire()`, without claiming anything.
     *
     * A preview must not consume the single half-open probe: a dry run would then
     * push the real probe back by a whole cooldown, and the operator would see a
     * target that is "about to be retried" for no reason.
     *
     * @return array{allowed: bool, state: string, retry_in: int}
     */
    public static function peek(string $url): array
    {
        $address = self::address($url);
        if ($address === '') {
            return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT state, next_probe_at FROM forward_targets WHERE address = :address'
            );
            $stmt->execute(['address' => $address]);
            $row = $stmt->fetch();

            if ($row === false || (string) $row['state'] === self::CLOSED) {
                return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
            }

            $nextProbe = $row['next_probe_at'] === null ? null : strtotime((string) $row['next_probe_at']);
            if ($nextProbe !== null && $nextProbe <= time()) {
                return ['allowed' => true, 'state' => self::HALF_OPEN, 'retry_in' => 0];
            }

            return [
                'allowed' => false,
                'state' => self::OPEN,
                'retry_in' => $nextProbe === null ? self::cooldown() : max(1, $nextProbe - time()),
            ];
        } catch (\Throwable $e) {
            error_log('circuit breaker unavailable: ' . $e->getMessage());

            return ['allowed' => true, 'state' => self::CLOSED, 'retry_in' => 0];
        }
    }

    /**
     * Records the outcome of an attempt the breaker allowed, which is any attempt:
     * a success closes the breaker, a terminal failure counts toward opening it.
     *
     * Only terminal failures are reported — the caller passes `success` and the
     * final verdict, so a retry that later succeeds never counts as an incident.
     */
    public static function record(string $url, bool $success, ?int $status = null, ?string $error = null): void
    {
        $address = self::address($url);
        if ($address === '') {
            return;
        }

        try {
            $pdo = Database::pdo();

            if ($success) {
                $stmt = $pdo->prepare(
                    "UPDATE forward_targets
                        SET state = 'closed', consecutive_failures = 0,
                            opened_at = NULL, next_probe_at = NULL,
                            last_status = :status, last_error = NULL, updated_at = now()
                      WHERE address = :address"
                );
                $stmt->execute(['status' => $status, 'address' => $address]);

                return;
            }

            $stmt = $pdo->prepare(
                "UPDATE forward_targets
                    SET consecutive_failures = consecutive_failures + 1,
                        last_status = :status,
                        last_error = :error,
                        last_failure_at = now(),
                        updated_at = now()
                  WHERE address = :address
                  RETURNING id, consecutive_failures"
            );
            $stmt->execute([
                'status' => $status,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'address' => $address,
            ]);
            $row = $stmt->fetch();

            if ($row === false) {
                // No row yet (the breaker was reset or this attempt predates it):
                // record the failure so the next delivery does not start at zero.
                $create = $pdo->prepare(
                    "INSERT INTO forward_targets
                        (address, consecutive_failures, last_status, last_error, last_failure_at)
                     VALUES (:address, 1, :status, :error, now())
                     ON CONFLICT (address) DO UPDATE
                        SET consecutive_failures = forward_targets.consecutive_failures + 1,
                            last_status = EXCLUDED.last_status,
                            last_error = EXCLUDED.last_error,
                            last_failure_at = now(),
                            updated_at = now()"
                );
                $create->execute([
                    'address' => $address,
                    'status' => $status,
                    'error' => $error === null ? null : mb_substr($error, 0, 500),
                ]);

                return;
            }

            $threshold = self::threshold();
            if ($threshold > 0 && (int) $row['consecutive_failures'] >= $threshold) {
                // `WHERE state <> 'open'` makes opening idempotent: several workers
                // failing at once produce one transition, not one per failure.
                $open = $pdo->prepare(
                    "UPDATE forward_targets
                        SET state = 'open', opened_at = COALESCE(opened_at, now()),
                            next_probe_at = now() + make_interval(secs => :cooldown),
                            updated_at = now()
                      WHERE address = :address AND state <> 'open'"
                );
                $open->execute(['cooldown' => self::cooldown(), 'address' => $address]);
            }
        } catch (\Throwable $e) {
            error_log('circuit breaker record failed: ' . $e->getMessage());
        }
    }

    /**
     * Every breaker row for the dashboard, newest trouble first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT address, state, consecutive_failures, opened_at, next_probe_at,
                    last_status, last_error, last_failure_at, updated_at,
                    GREATEST(0, EXTRACT(EPOCH FROM (next_probe_at - now())))::int AS retry_in
               FROM forward_targets
              ORDER BY (state <> 'closed') DESC, consecutive_failures DESC, address
              LIMIT 100"
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'address' => (string) $row['address'],
                'state' => (string) $row['state'],
                'consecutive_failures' => (int) $row['consecutive_failures'],
                'opened_at' => $row['opened_at'],
                'next_probe_at' => $row['next_probe_at'],
                'retry_in' => (int) $row['retry_in'],
                'last_status' => $row['last_status'] === null ? null : (int) $row['last_status'],
                'last_error' => $row['last_error'],
                'last_failure_at' => $row['last_failure_at'],
                'updated_at' => $row['updated_at'],
                'threshold' => self::threshold(),
                'cooldown_seconds' => self::cooldown(),
            ];
        }

        return $rows;
    }

    /**
     * Clears one address by hand: the operator says "I fixed it, try now".
     *
     * Deletes the row rather than setting `closed`, so the address starts from a
     * clean slate — a stale failure count would re-open the breaker on the very
     * next error, which is the state the operator just reported as fixed.
     */
    public static function reset(string $address): bool
    {
        $normalized = self::address($address);
        if ($normalized === '') {
            $normalized = trim($address);
        }

        $stmt = Database::pdo()->prepare('DELETE FROM forward_targets WHERE address = :address');
        $stmt->execute(['address' => $normalized]);

        return $stmt->rowCount() > 0;
    }

    public static function threshold(): int
    {
        return max(0, Config::int('FORWARD_BREAKER_THRESHOLD', self::DEFAULT_THRESHOLD));
    }

    public static function cooldown(): int
    {
        return max(1, Config::int('FORWARD_BREAKER_COOLDOWN', self::DEFAULT_COOLDOWN));
    }
}
