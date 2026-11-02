<?php

declare(strict_types=1);

/**
 * Container healthcheck for `api` (CLI, no HTTP server in this container).
 *
 * The `api` container runs php-fpm, so there is nothing listening on port 80 to
 * curl — a healthcheck has to talk to the same services the API talks to. This is
 * the CLI twin of `/api/health`: database, worker heartbeat, queue backlog.
 *
 * A missing worker is only a failure when there is work to drain. On a cold start
 * `api` is up before the worker's first beat, and an empty installation has no
 * reason to be unhealthy — otherwise compose would restart a perfectly good
 * container during the first seconds of every boot.
 *
 * Exit codes are what Docker reads: 0 healthy, 1 unhealthy. `--verbose` prints
 * what was checked, which is what `docker inspect` shows for a failing container.
 */

require dirname(__DIR__) . '/src/autoload.php';

use App\Config;
use App\Database;
use App\Services\Heartbeat;

$verbose = in_array('--verbose', $argv, true);
$failures = [];
$notes = [];

try {
    Database::pdo()->query('SELECT 1')->fetchColumn();
    $notes[] = 'база доступна';
} catch (Throwable $e) {
    $failures[] = 'база недоступна: ' . $e->getMessage();
}

$pending = 0;
if ($failures === []) {
    try {
        $stmt = Database::pdo()->query(
            "SELECT count(*) FILTER (WHERE status = 'pending')::int AS pending,
                    max(EXTRACT(EPOCH FROM (now() - available_at)))
                        FILTER (WHERE status = 'pending')::int       AS oldest
               FROM workflow_runs
              WHERE status IN ('pending', 'running')"
        );
        $queue = $stmt->fetch() ?: [];
        $pending = (int) ($queue['pending'] ?? 0);
        $oldest = max(0, (int) ($queue['oldest'] ?? 0));
        $limit = max(30, Config::int('HEALTH_QUEUE_MAX_AGE', 300));

        if ($pending > 0 && $oldest > $limit) {
            $failures[] = sprintf(
                'очередь не движется: %d в ожидании, старейший %d с (порог %d с)',
                $pending,
                $oldest,
                $limit,
            );
        }

        $notes[] = sprintf('очередь: %d в ожидании', $pending);
    } catch (Throwable $e) {
        $failures[] = 'не удалось прочитать очередь: ' . $e->getMessage();
    }
}

if ($failures === [] && $pending > 0) {
    try {
        $workers = Heartbeat::status();
        if (!$workers['alive']) {
            $failures[] = sprintf(
                'есть работа, но нет живого воркера (последний удар: %s)',
                $workers['last_beat_at'] ?? 'никогда',
            );
        }

        $notes[] = sprintf('воркеров: %d', $workers['workers']);
    } catch (Throwable $e) {
        $failures[] = 'не удалось прочитать heartbeat: ' . $e->getMessage();
    }
}

if ($failures === []) {
    if ($verbose) {
        fwrite(STDOUT, 'ok · ' . implode(' · ', $notes) . "\n");
    }

    exit(0);
}

fwrite(STDERR, implode('; ', $failures) . PHP_EOL);

exit(1);
