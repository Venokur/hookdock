<?php

declare(strict_types=1);

/**
 * Routing worker.
 *
 * Runs as its own container (docker compose service `worker`) on the same image
 * as `api`: `php /var/www/backend/bin/worker.php`.
 *
 * The catcher only stores a request and records `pending` runs; this daemon
 * evaluates conditions and performs actions, so a slow forward target can never
 * delay the 202 the sender is waiting for.
 *
 * Polling is deliberate: the queue lives in Postgres behind a partial index, so an
 * empty claim is cheap, and a worker that is not polling cannot honour backoff.
 *
 * `--once` drains whatever is due and exits (used by tests and by
 * `docker compose run --rm worker php bin/worker.php --once`).
 *
 * Uses src/autoload.php instead of bootstrap.php on purpose — bootstrap installs
 * the HTTP exception handler that answers with JSON and calls exit().
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/autoload.php';

use App\Config;
use App\Database;
use App\Services\Heartbeat;
use App\Services\Realtime;
use App\Services\Retention;
use App\Services\WorkflowEngine;

$once = in_array('--once', $argv, true);
$workerId = (string) (gethostname() ?: 'worker') . ':' . getmypid();

function logLine(string $message): void
{
    fwrite(STDOUT, sprintf("[%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
}

$running = true;

// Docker sends SIGTERM on `compose down`. Without pcntl the flag is never set and
// the container is killed mid-run, which recoverStale() then repairs.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running): void {
            $running = false;
            logLine('stop requested, finishing the current run');
        });
    }
}

$stale = 0;
$attempts = 0;

// `api` owns migrations and this container starts with RUN_MIGRATIONS=0, so the
// new columns may not exist for the first second or two after a cold start.
// Waiting here beats crashing into the restart loop.
while (true) {
    try {
        $stale = WorkflowEngine::recoverStale(Config::int('ROUTING_STALE_MINUTES', 5));
        break;
    } catch (Throwable $e) {
        $attempts++;
        if ($attempts >= 60) {
            fwrite(STDERR, 'schema is not ready: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }

        sleep(1);
    }
}

if ($stale > 0) {
    logLine(sprintf('requeued %d stale run(s)', $stale));
}

logLine(sprintf(
    'worker %s started (max attempts %d, retry base %ds)',
    $workerId,
    WorkflowEngine::maxAttempts(),
    max(1, Config::int('ROUTING_RETRY_BASE', 1)),
));

// Retention is a slow, low-priority job: run it once at boot and then hourly. It is
// checked at the top of every loop pass (see below), so a continuously busy worker
// cannot starve it the way an idle-only branch would.
$lastPrune = 0;

/** Runs retention and logs only when it removed something. */
function pruneIfDue(int $lastPrune): int
{
    $interval = max(60, Config::int('RETENTION_INTERVAL_SECONDS', 3600));
    if (time() - $lastPrune < $interval) {
        return $lastPrune;
    }

    try {
        $removed = Retention::prune();
    } catch (Throwable $e) {
        logLine('retention failed: ' . $e->getMessage());

        return time();
    }

    $total = array_sum($removed);
    if ($total > 0) {
        logLine(sprintf(
            'retention: %d request(s), %d run(s), %d rate-limit window(s)',
            $removed['requests'],
            $removed['runs'],
            $removed['rate_limits'],
        ));
    }

    return time();
}

/**
 * Publishes the outcome of one run so open tabs refresh without polling. Payloads
 * stay thin (ids only) — the SPA re-reads what it shows.
 *
 * A scheduled run has no request, so `request.updated` is published only when one
 * exists; `run.updated` always is, because the run journal has a row either way.
 *
 * @param array<string, mixed> $run
 */
function announce(array $run, ?int $requestId, int $roomId, string $status): void
{
    Realtime::publish('run.updated', [
        'room_id' => $roomId,
        'run_id' => (int) $run['id'],
        'workflow_id' => (int) $run['workflow_id'],
        'status' => $status,
        'request_id' => $requestId,
    ]);

    if ($requestId === null) {
        return;
    }

    Realtime::publish('request.updated', [
        'id' => $requestId,
        'room_id' => $roomId,
        'run_id' => (int) $run['id'],
        'status' => $status,
    ]);
}

while ($running) {
    // Cheap and index-backed by the partial queue index: if nothing is due, this is
    // the only query the idle loop runs. Retention is checked on every pass, not only
    // in the idle branch — a busy worker would otherwise never get to it.
    $lastPrune = pruneIfDue($lastPrune);

    // Scheduled rules are fired from here rather than from a second process: one
    // loop that owns the queue also owns the clock, so a run can never be queued by
    // a scheduler that is down while the queue keeps draining. The call is a single
    // index probe when nothing is due, and `--once` fires them too so a test can
    // drain a due schedule without starting the daemon.
    try {
        $fired = WorkflowEngine::fireDueSchedules();
        if ($fired > 0) {
            logLine(sprintf('scheduler queued %d run(s)', $fired));
        }
    } catch (Throwable $e) {
        // A broken schedule must not stop webhook routing, which is the product.
        logLine('scheduler failed: ' . $e->getMessage());
    }

    // One upsert per pass with the number of runs just taken: an idle pass is
    // 250 ms, so `beat_at` tracks the process rather than the queue, and
    // `/api/health` can tell "idle" from "dead".
    $runs = WorkflowEngine::claim(1, $workerId);
    Heartbeat::beat($workerId, count($runs));

    if ($runs === []) {
        if ($once) {
            break;
        }

        usleep(250_000);
        continue;
    }

    foreach ($runs as $run) {
        // A scheduled or manual run has no request; `room_id` lives on the run for
        // exactly that reason, so the event and the log line never need a join.
        $requestId = $run['request_id'] === null ? null : (int) $run['request_id'];
        $roomId = (int) ($run['room_id'] ?? 0);

        try {
            $status = WorkflowEngine::execute($run);
        } catch (Throwable $e) {
            // A crash must not kill the daemon: the run stays `running` and
            // recoverStale() puts it back once the lease expires.
            logLine(sprintf('run #%d failed unexpectedly: %s', (int) $run['id'], $e->getMessage()));
            continue;
        }

        logLine(sprintf(
            'run #%d (%s, attempt %d) → %s',
            (int) $run['id'],
            $requestId === null
                ? 'no request / ' . (string) ($run['trigger_type'] ?? 'manual')
                : 'request #' . $requestId,
            (int) $run['attempts'],
            $status,
        ));

        announce($run, $requestId, $roomId, $status);
    }
}

logLine('worker stopped');
