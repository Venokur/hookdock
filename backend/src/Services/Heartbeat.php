<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;

/**
 * Liveness of the routing workers, written by the worker and read by `/api/health`.
 *
 * Without this a dead worker is indistinguishable from an idle installation: the
 * queue simply does not move, every request keeps its `pending` runs and the SPA
 * looks exactly like a room where no rule matched. One row per worker, upserted
 * every loop pass, makes "no worker beat recently" an answer instead of a guess.
 */
final class Heartbeat
{
    /**
     * Records that this worker is alive.
     *
     * `claimed` accumulates rather than resets, so two workers of the same name
     * (a restarted container keeps its hostname) do not overwrite each other's
     * totals. A failure here must never stop routing, hence the try/catch.
     */
    public static function beat(string $workerId, int $claimed = 0): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO worker_heartbeats (worker_id, claimed, beat_at)
                      VALUES (:worker_id, :claimed, now())
                 ON CONFLICT (worker_id) DO UPDATE
                        SET beat_at = now(),
                            claimed = worker_heartbeats.claimed + EXCLUDED.claimed'
            );
            $stmt->execute(['worker_id' => $workerId, 'claimed' => $claimed]);
        } catch (\Throwable $e) {
            error_log('heartbeat failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array{alive: bool, workers: int, stale_after: int, last_beat_at: string|null, claimed: int}
     */
    public static function status(): array
    {
        // A worker beats on every loop pass, and an idle pass is 250 ms. A minute
        // of silence therefore means the process is gone, not that it is busy.
        $staleAfter = max(15, Config::int('WORKER_STALE_SECONDS', 60));

        $stmt = Database::pdo()->prepare(
            "SELECT count(*)::int                                   AS workers,
                    max(beat_at)                                    AS last_beat_at,
                    COALESCE(sum(claimed), 0)::int                  AS claimed,
                    count(*) FILTER (WHERE beat_at >= now() - make_interval(secs => :stale))::int AS alive
               FROM worker_heartbeats"
        );
        $stmt->execute(['stale' => $staleAfter]);
        $row = $stmt->fetch() ?: [];

        return [
            'alive' => (int) ($row['alive'] ?? 0) > 0,
            'workers' => (int) ($row['workers'] ?? 0),
            'stale_after' => $staleAfter,
            'last_beat_at' => $row['last_beat_at'] ?? null,
            'claimed' => (int) ($row['claimed'] ?? 0),
        ];
    }
}
