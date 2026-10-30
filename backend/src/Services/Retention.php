<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Support\RateLimiter;
use PDO;

/**
 * Time-based cleanup of the tables that grow with traffic.
 *
 * Nothing prunes a webhook log by itself, so an installation that receives a few
 * requests per second fills the volume in weeks. Retention is opt-in
 * (`RETENTION_DAYS=0` keeps everything, the safe default for an audit log) and runs
 * in the worker, never in the request path.
 *
 * Deletes are batched: one `DELETE` over millions of rows takes a long lock and
 * bloats the WAL, while batches keep the receiver responsive.
 */
final class Retention
{
    private const BATCH = 1000;

    /**
     * @return array{requests: int, runs: int, rate_limits: int}
     */
    public static function prune(): array
    {
        $deleted = ['requests' => 0, 'runs' => 0, 'rate_limits' => 0];

        $days = Config::int('RETENTION_DAYS', 0);
        if ($days > 0) {
            $deleted['requests'] = self::deleteRequests($days);
        }

        $deleted['rate_limits'] = RateLimiter::prune(86400);

        // Runs and steps of a deleted request go with it (ON DELETE CASCADE), but a
        // request kept for its log can still have runs older than the run window.
        $runDays = Config::int('RETENTION_RUN_DAYS', 0);
        if ($runDays > 0) {
            $deleted['runs'] = self::deleteRuns($runDays);
        }

        return $deleted;
    }

    /**
     * Removes requests received before the cutoff, in batches.
     *
     * A request that still has a `pending`/`running` run is skipped: deleting it
     * would cascade the queued run away, so the sender's delivery would be silently
     * dropped by housekeeping. It is picked up on a later pass, once routing is done.
     */
    private static function deleteRequests(int $days): int
    {
        $pdo = Database::pdo();
        $total = 0;

        $stmt = $pdo->prepare(
            "DELETE FROM incoming_requests
              WHERE id IN (
                    SELECT r.id FROM incoming_requests r
                     WHERE r.received_at < now() - make_interval(days => :days)
                       AND NOT EXISTS (
                             SELECT 1 FROM workflow_runs wr
                              WHERE wr.request_id = r.id
                                AND wr.status IN ('pending', 'running')
                       )
                     LIMIT :batch
              )"
        );

        do {
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->bindValue(':batch', self::BATCH, PDO::PARAM_INT);
            $stmt->execute();
            $affected = $stmt->rowCount();
            $total += $affected;
        } while ($affected === self::BATCH);

        return $total;
    }

    /**
     * Removes finished runs (and their steps) older than the cutoff. A run still in
     * the queue is never touched, no matter its age.
     */
    private static function deleteRuns(int $days): int
    {
        $pdo = Database::pdo();
        $total = 0;

        $stmt = $pdo->prepare(
            "DELETE FROM workflow_runs
              WHERE id IN (
                    SELECT id FROM workflow_runs
                     WHERE created_at < now() - make_interval(days => :days)
                       AND status NOT IN ('pending', 'running')
                     LIMIT :batch
              )"
        );

        do {
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->bindValue(':batch', self::BATCH, PDO::PARAM_INT);
            $stmt->execute();
            $affected = $stmt->rowCount();
            $total += $affected;
        } while ($affected === self::BATCH);

        return $total;
    }
}
