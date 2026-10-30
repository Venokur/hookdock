<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\RequestsController;
use App\Controllers\RoomsController;
use App\Controllers\WebhookController;
use App\Controllers\WorkflowsController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();

$auth = new AuthController();
$requests = new RequestsController();
$rooms = new RoomsController();
$workflows = new WorkflowsController();
$webhook = new WebhookController();

$router->add('GET', '/api/health', static fn (): never => Response::json(['status' => 'ok']));

$router->add('POST', '/api/auth/login', fn (Request $r) => $auth->login($r));
$router->add('GET', '/api/auth/me', fn (Request $r) => $auth->me($r));
$router->add('POST', '/api/auth/logout', fn () => $auth->logout());
$router->add('GET', '/api/auth/config', fn () => $auth->config());
$router->add('POST', '/api/ws/ticket', fn (Request $r) => $auth->wsTicket($r));

$router->add('GET', '/api/rooms', fn (Request $r) => $rooms->index($r));
$router->add('POST', '/api/rooms', fn (Request $r) => $rooms->store($r));
$router->add('GET', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->show($r, $p));
$router->add('PUT', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->update($r, $p));
$router->add('DELETE', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->destroy($r, $p));

$router->add('GET', '/api/stats', fn (Request $r) => $requests->stats($r));
$router->add('GET', '/api/requests', fn (Request $r) => $requests->index($r));
$router->add('GET', '/api/requests/{id}', fn (Request $r, array $p) => $requests->show($r, $p));
$router->add('DELETE', '/api/requests/{id}', fn (Request $r, array $p) => $requests->destroy($r, $p));

$router->add('GET', '/api/workflows', fn (Request $r) => $workflows->index($r));
$router->add('POST', '/api/workflows', fn (Request $r) => $workflows->store($r));
$router->add('GET', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->show($r, $p));
$router->add('PUT', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->update($r, $p));
$router->add('DELETE', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->destroy($r, $p));
$router->add('POST', '/api/workflows/{id}/toggle', fn (Request $r, array $p) => $workflows->toggle($r, $p));

$request = Request::capture();

// The catcher is claimed by prefix rather than by routes: a webhook path can be
// arbitrarily deep (`/hook/{room}/{endpoint}/a/b/c`), and a router pattern can
// only match a fixed number of segments. Every method is accepted and the path
// is resolved in the controller, because `/hook/{x}` is ambiguous on its own.
if (isWebhookPath($request->path)) {
    respondToCors($request);

    $webhook->receive($request);
}

if ($router->dispatch($request)) {
    exit;
}

// Anything outside /api that reaches PHP is treated as a webhook on an arbitrary path.
if (!str_starts_with($request->path, '/api/')) {
    $webhook->receive($request);
}

Response::error('Не найдено', 404);

/**
 * `/api/webhook…` and `/hook…` are the public catcher. `/api/webhookX` must not
 * be mistaken for it, so the match requires a segment boundary.
 */
function isWebhookPath(string $path): bool
{
    foreach (['/api/webhook', '/hook'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Browsers refuse to POST a payload to another origin without a preflight, so a
 * page on another port could not use the catcher at all. A real preflight is
 * answered here and never reaches the room resolver: it carries no body worth
 * storing. An `OPTIONS` webhook sent by a client (no
 * `Access-Control-Request-Method`) is still ingested like any other method.
 */
function respondToCors(Request $request): void
{
    header('Access-Control-Allow-Origin: *');
    header('Vary: Origin');

    if ($request->method !== 'OPTIONS' || $request->header('access-control-request-method') === null) {
        return;
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: ' . ($request->header('access-control-request-headers') ?? 'Content-Type'));
    header('Access-Control-Max-Age: 600');
    http_response_code(204);

    exit;
}

