<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Heartbeat;

/**
 * Health for a human and for a monitor.
 *
 * `/api/health` used to answer `{"status":"ok"}` unconditionally, which is worse
 * than no health check: it stays green while the worker is dead and every hook is
 * stuck in `pending`. This one actually looks at the three things that can be
 * broken independently — the database, the worker, and the queue it is not moving.
 *
 * The route stays public: an uptime probe has no credentials, and the answer
 * deliberately carries no payloads, no room names and no secrets. `503` is used
 * for "not ready" so a naive `curl --fail` or a compose healthcheck notices.
 *
 * A worker that never beat is not a failure on its own — a fresh volume is empty
 * and `api` is up before the worker's first loop. It only matters when there is
 * queued work waiting for it, which is exactly the state this endpoint exists to
 * catch.
 */
final class HealthController
{
    public function show(Request $request): void
    {
        $checks = [];
        $healthy = true;

        // 1. The database. Everything else depends on it, so a failure here short
        // circuits the rest instead of producing three derived errors.
        $pdo = null;
        try {
            $pdo = Database::pdo();
            $pdo->query('SELECT 1')->fetchColumn();
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $healthy = false;
            $checks['database'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // 2. The queue. Pending work by itself is normal; pending work whose oldest
        // row is older than the threshold means it is not being drained.
        $pending = 0;
        $stuck = false;
        if ($pdo !== null) {
            try {
                $stmt = $pdo->query(
                    "SELECT count(*) FILTER (WHERE status = 'pending')::int AS pending,
                            count(*) FILTER (WHERE status = 'running')::int  AS running,
                            COALESCE(max(EXTRACT(EPOCH FROM (now() - available_at)))
                                FILTER (WHERE status = 'pending'), 0)::int   AS oldest_pending_seconds
                       FROM workflow_runs
                      WHERE status IN ('pending', 'running')"
                );
                $queue = $stmt->fetch() ?: [];

                $pending = (int) ($queue['pending'] ?? 0);
                $backlog = max(0, (int) ($queue['oldest_pending_seconds'] ?? 0));
                $limit = max(30, Config::int('HEALTH_QUEUE_MAX_AGE', 300));
                $stuck = $pending > 0 && $backlog > $limit;

                $checks['queue'] = [
                    'status' => $stuck ? 'fail' : 'ok',
                    'pending' => $pending,
                    'running' => (int) ($queue['running'] ?? 0),
                    'oldest_pending_seconds' => $backlog,
                    'max_age_seconds' => $limit,
                ];
                $healthy = $healthy && !$stuck;
            } catch (\Throwable $e) {
                $healthy = false;
                $checks['queue'] = ['status' => 'fail', 'message' => $e->getMessage()];
            }
        }

        // 3. The worker. A queue nobody drains is the failure this check exists for.
        if ($pdo !== null) {
            try {
                $heartbeat = Heartbeat::status();
                $worried = !$heartbeat['alive'] && ($pending > 0 || $heartbeat['workers'] > 0);

                $checks['worker'] = [
                    'status' => $worried ? 'fail' : 'ok',
                    'workers' => $heartbeat['workers'],
                    'last_beat_at' => $heartbeat['last_beat_at'],
                    'stale_after_seconds' => $heartbeat['stale_after'],
                ];
                $healthy = $healthy && !$worried;
            } catch (\Throwable $e) {
                $healthy = false;
                $checks['worker'] = ['status' => 'fail', 'message' => $e->getMessage()];
            }
        }

        Response::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'time' => gmdate('c'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
