<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\RequestsController;
use App\Controllers\WebhookController;
use App\Controllers\WorkflowsController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();

$auth = new AuthController();
$requests = new RequestsController();
$workflows = new WorkflowsController();
$webhook = new WebhookController();

$router->add('GET', '/api/health', static fn (): never => Response::json(['status' => 'ok']));

$router->add('POST', '/api/auth/login', fn (Request $r) => $auth->login($r));
$router->add('GET', '/api/auth/me', fn (Request $r) => $auth->me($r));
$router->add('POST', '/api/auth/logout', fn () => $auth->logout());

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

// Public catcher: every HTTP method, any endpoint slug, no auth.
foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
    $router->add($method, '/api/webhook', fn (Request $r) => $webhook->receive($r, ['endpoint' => 'default']));
    $router->add($method, '/api/webhook/{endpoint}', fn (Request $r, array $p) => $webhook->receive($r, $p));
    $router->add($method, '/hook/{endpoint}', fn (Request $r, array $p) => $webhook->receive($r, $p));
}

$request = Request::capture();

if ($router->dispatch($request)) {
    exit;
}

// Anything outside /api that reaches PHP is treated as a webhook on an arbitrary path.
if (!str_starts_with($request->path, '/api/')) {
    $webhook->receive($request);
}

Response::error('Не найдено', 404);
