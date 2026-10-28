<?php

declare(strict_types=1);

/**
 * Realtime websocket daemon.
 *
 * Runs as its own container (docker compose service `ws`) on the same image as
 * `api`: `php /var/www/backend/bin/ws-server.php`.
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
use App\Services\Realtime;
use App\Support\WebSocketServer;

$server = new WebSocketServer(
    (string) Config::get('WS_HOST', '0.0.0.0'),
    Config::int('WS_PORT', 8081),
    Realtime::channel(),
);

$server->run();
