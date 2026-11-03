<?php

declare(strict_types=1);

use App\Controllers\AnalyticsController;
use App\Controllers\ApiTokensController;
use App\Controllers\AuthController;
use App\Controllers\AuditController;
use App\Controllers\BreakersController;
use App\Controllers\HealthController;
use App\Controllers\MetricsController;
use App\Controllers\RequestsController;
use App\Controllers\RoomsController;
use App\Controllers\RunsController;
use App\Controllers\UsersController;
use App\Controllers\WebhookController;
use App\Controllers\WorkflowsController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();

$analytics = new AnalyticsController();
$apiTokens = new ApiTokensController();
$auth = new AuthController();
$audit = new AuditController();
$breakers = new BreakersController();
$health = new HealthController();
$metrics = new MetricsController();
$requests = new RequestsController();
$rooms = new RoomsController();
$runs = new RunsController();
$users = new UsersController();
$workflows = new WorkflowsController();
$webhook = new WebhookController();

// Real health: database, worker heartbeat, queue backlog. Public on purpose — a
// probe has no credentials — and it carries no payloads or secrets.
$router->add('GET', '/api/health', fn (Request $r) => $health->show($r));

$router->add('POST', '/api/auth/login', fn (Request $r) => $auth->login($r));
$router->add('GET', '/api/auth/me', fn (Request $r) => $auth->me($r));
$router->add('POST', '/api/auth/logout', fn () => $auth->logout());
$router->add('GET', '/api/auth/config', fn () => $auth->config());
$router->add('POST', '/api/ws/ticket', fn (Request $r) => $auth->wsTicket($r));
$router->add('POST', '/api/auth/read-token', fn (Request $r) => $auth->readToken($r));
// Own password only: no admin role needed, but the current password is required,
// because a stolen token must not be a permanent account takeover.
$router->add('POST', '/api/auth/password', fn (Request $r) => $users->changePassword($r));

// Accounts. Every handler is admin-only (`Auth::requireAdmin`): an editor who could
// grant themselves `admin` would make the roles decorative.
$router->add('GET', '/api/users', fn (Request $r) => $users->index($r));
$router->add('POST', '/api/users', fn (Request $r) => $users->store($r));
$router->add('PUT', '/api/users/{id}', fn (Request $r, array $p) => $users->update($r, $p));
$router->add('DELETE', '/api/users/{id}', fn (Request $r, array $p) => $users->destroy($r, $p));

// Long-lived keys for scripts and CI. Admin-only like `/api/users*`: a token acts
// as its account, so minting one is a privilege grant rather than a preference.
// Revoking keeps the row — `last_used_at` is the answer to "was it used first".
$router->add('GET', '/api/tokens', fn (Request $r) => $apiTokens->index($r));
$router->add('POST', '/api/tokens', fn (Request $r) => $apiTokens->store($r));
$router->add('DELETE', '/api/tokens/{id}', fn (Request $r, array $p) => $apiTokens->destroy($r, $p));

// "Who changed what". Reading is open to every role; the journal is written by the
// mutating handlers themselves, so there is no write endpoint here.
$router->add('GET', '/api/audit', fn (Request $r) => $audit->index($r));

$router->add('GET', '/api/rooms', fn (Request $r) => $rooms->index($r));
$router->add('POST', '/api/rooms', fn (Request $r) => $rooms->store($r));
$router->add('GET', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->show($r, $p));
$router->add('PUT', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->update($r, $p));
$router->add('DELETE', '/api/rooms/{id}', fn (Request $r, array $p) => $rooms->destroy($r, $p));
$router->add('POST', '/api/rooms/{id}/secret', fn (Request $r, array $p) => $rooms->rotateSecret($r, $p));
$router->add('DELETE', '/api/rooms/{id}/secret', fn (Request $r, array $p) => $rooms->clearSecret($r, $p));

$router->add('GET', '/api/stats', fn (Request $r) => $requests->stats($r));
// Throughput and latency over a window. `stats` is "how many right now"; this is
// "how did it behave", which needs a time axis and the step durations.
$router->add('GET', '/api/analytics', fn (Request $r) => $analytics->index($r));
// Prometheus exposition. Public like `/api/health` — a scrape config has no
// credentials — and counters only, never a payload or a forward address.
$router->add('GET', '/api/metrics', fn (Request $r) => $metrics->show($r));
// Before `/api/requests/{id}`: that pattern matches any single segment, so
// "export" would be read as a request id and answered with 404. Same reason
// `/api/endpoints` is its own route rather than `/api/requests/endpoints`.
$router->add('GET', '/api/endpoints', fn (Request $r) => $requests->endpoints($r));
$router->add('GET', '/api/requests', fn (Request $r) => $requests->index($r));
// Before `/api/requests/{id}`: that pattern matches any single segment, so
// "export" would be read as a request id and answered with 404.
$router->add('GET', '/api/requests/export', fn (Request $r) => $requests->export($r));
// Before `/api/requests/{id}` again: `bulk` is a literal segment. The list of
// ids is explicit, never the current filter, so one mis-click cannot delete a
// search result nobody counted.
$router->add('POST', '/api/requests/bulk', fn (Request $r) => $requests->bulk($r));
$router->add('GET', '/api/requests/{id}', fn (Request $r, array $p) => $requests->show($r, $p));
$router->add('DELETE', '/api/requests/{id}', fn (Request $r, array $p) => $requests->destroy($r, $p));
$router->add('POST', '/api/requests/{id}/replay', fn (Request $r, array $p) => $requests->replay($r, $p));
// Replays a *corrected copy*: the stored row stays the record of what the
// sender actually sent, so a run's step log never describes a payload that
// never arrived.
$router->add('POST', '/api/requests/{id}/redeliver', fn (Request $r, array $p) => $requests->redeliver($r, $p));
// Dry run: "what would routing do", without queueing or sending anything.
$router->add('POST', '/api/requests/{id}/preview', fn (Request $r, array $p) => $requests->preview($r, $p));
$router->add('GET', '/api/requests/{id}/body', fn (Request $r, array $p) => $requests->body($r, $p));
$router->add('POST', '/api/runs/{id}/retry', fn (Request $r, array $p) => $requests->retryRun($r, $p));
// The run journal as a page: "what failed in the last hour" without opening
// stored requests one by one. Read-only; retry stays where it already is.
$router->add('GET', '/api/runs', fn (Request $r) => $runs->index($r));

$router->add('GET', '/api/workflows', fn (Request $r) => $workflows->index($r));
$router->add('POST', '/api/workflows', fn (Request $r) => $workflows->store($r));
// Before `/api/workflows/{id}`: a literal segment would otherwise be read as an id.
$router->add('GET', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->show($r, $p));
$router->add('PUT', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->update($r, $p));
$router->add('DELETE', '/api/workflows/{id}', fn (Request $r, array $p) => $workflows->destroy($r, $p));
$router->add('POST', '/api/workflows/{id}/toggle', fn (Request $r, array $p) => $workflows->toggle($r, $p));
$router->add('POST', '/api/workflows/{id}/duplicate', fn (Request $r, array $p) => $workflows->duplicate($r, $p));
// A scheduled rule cannot be triggered by hand through a webhook, so "run now" is
// how it is tested without waiting for its next slot.
$router->add('POST', '/api/workflows/{id}/run', fn (Request $r, array $p) => $workflows->runNow($r, $p));
// Saved configurations of one rule. Reads are open to every role; a rollback is a
// write and passes the same `$write` flag as any other mutation.
$router->add('GET', '/api/workflows/{id}/versions', fn (Request $r, array $p) => $workflows->versions($r, $p));
$router->add('GET', '/api/workflows/{id}/versions/{version}', fn (Request $r, array $p) => $workflows->version($r, $p));
$router->add('POST', '/api/workflows/{id}/versions/{version}/restore', fn (Request $r, array $p) => $workflows->rollback($r, $p));
// A room's rules as a file, and the way back. Both live under /api/workflows so
// the collection and the spec keep one subject; the room is a path parameter
// because an export of "everything" is not the use case.
$router->add('GET', '/api/rooms/{id}/workflows/export', fn (Request $r, array $p) => $workflows->exportRoom($r, $p));
$router->add('POST', '/api/rooms/{id}/workflows/import', fn (Request $r, array $p) => $workflows->importRoom($r, $p));

// Forward targets the breaker is skipping. Read is open to a read token; clearing
// one is a mutating action, so it demands a full token.
$router->add('GET', '/api/breakers', fn (Request $r) => $breakers->index($r));
$router->add('POST', '/api/breakers/reset', fn (Request $r) => $breakers->reset($r));

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

