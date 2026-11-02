<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Services\WorkflowEngine;
use App\Support\RateLimiter;
use App\Support\IpAllowlist;
use App\Support\Signature;

final class WebhookController
{
    /**
     * Catch-all receiver: resolves the room, stores the request, then runs that
     * room's matching workflows. Requests and workflows of other rooms are never
     * touched.
     */
    public function receive(Request $request, array $params = []): void
    {
        $this->enforceLimit($request);

        [$room, $endpoint] = $this->resolveTarget($request, $params);
        $this->verifySignature($request, $room);
        $this->enforceAllowlist($request, $room);

        $raw = $request->rawBody;
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        $row = [
            'room_id' => $room['id'],
            'endpoint' => $endpoint,
            'method' => $request->method,
            'path' => $request->path,
            'query' => $request->query,
            'headers' => $request->headers,
            'body' => is_array($decoded) ? $decoded : null,
            'raw_body' => $raw,
            'content_type' => $request->header('content-type'),
            'body_bytes' => strlen($raw),
            'ip' => $request->ip,
        ];

        // The unique index on `(room_id, dedup_key)` is the check: no preceding
        // SELECT, because two deliveries of one event can pass a SELECT at the
        // same time and both be treated as the first. The conflicting INSERT is
        // what makes "only one wins" atomic.
        $dedupKey = $this->dedupKey($request, $room);
        $duplicateOf = null;

        try {
            $saved = $this->store($row, $dedupKey, null);
        } catch (\PDOException $e) {
            if ($dedupKey === null || $e->getCode() !== '23505') {
                throw $e;
            }

            $duplicateOf = $this->findOriginalId($room, $dedupKey);

            // The original can vanish between the conflicting INSERT and this
            // SELECT (retention pruned it). Storing the delivery beats answering
            // 202 for a row nobody has.
            $saved = $this->store($row, null, $duplicateOf);
        }

        $row['id'] = (int) $saved['id'];
        $row['received_at'] = $saved['received_at'];
        $row['duplicate_of'] = $duplicateOf;

        // Realtime first: the list can show the row before routing has started.
        Realtime::publish('request.created', [
            'id' => $row['id'],
            'room_id' => $row['room_id'],
            'endpoint' => $row['endpoint'],
            'method' => $row['method'],
            'duplicate' => $duplicateOf !== null,
        ]);

        // A repeat is logged but never routed: the first delivery already ran the
        // room's workflows, and running them again is exactly the double side
        // effect deduplication exists to prevent.
        //
        // Routing itself happens in the `worker` service: the receiver only
        // records what has to be done, so a slow forward target cannot hold the
        // sender.
        $queued = $duplicateOf === null ? WorkflowEngine::enqueue($row) : 0;

        Response::json([
            'ok' => true,
            'id' => $row['id'],
            'room' => $room['slug'],
            'endpoint' => $row['endpoint'],
            'received_at' => $row['received_at'],
            'queued' => $queued,
            'duplicate' => $duplicateOf !== null,
            'duplicate_of' => $duplicateOf,
        ], 202);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: mixed, received_at: mixed}
     */
    private function store(array $row, ?string $dedupKey, ?int $duplicateOf): array
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO incoming_requests
                (room_id, endpoint, method, path, query, headers, body, raw_body,
                 content_type, body_bytes, ip, dedup_key, duplicate_of)
             VALUES
                (:room_id, :endpoint, :method, :path, :query, :headers, :body, :raw_body,
                 :content_type, :body_bytes, :ip, :dedup_key, :duplicate_of)
             RETURNING id, received_at'
        );
        $stmt->execute([
            'room_id' => $row['room_id'],
            'endpoint' => $row['endpoint'],
            'method' => $row['method'],
            'path' => $row['path'],
            'query' => $this->json($row['query']),
            'headers' => $this->json($row['headers']),
            'body' => $row['body'] === null ? null : $this->json($row['body']),
            'raw_body' => $row['raw_body'],
            'content_type' => $row['content_type'],
            'body_bytes' => $row['body_bytes'],
            'ip' => $row['ip'],
            'dedup_key' => $dedupKey,
            'duplicate_of' => $duplicateOf,
        ]);

        return $stmt->fetch();
    }

    /**
     * The delivery id, or null when the room does not deduplicate.
     *
     * A configured header that the sender did not send is not an error: the
     * delivery is stored and routed like any other. Rejecting it would make
     * turning the setting on a breaking change for every sender that has not
     * been updated yet.
     *
     * @param array<string, mixed> $room
     */
    private function dedupKey(Request $request, array $room): ?string
    {
        $header = trim((string) ($room['dedup_header'] ?? ''));
        if ($header === '') {
            return null;
        }

        $value = trim((string) $request->header($header));
        if ($value === '') {
            return null;
        }

        // The column is VARCHAR(200): truncating here keeps the stored key and
        // the compared key identical, which is what the unique index relies on.
        return mb_substr($value, 0, 200);
    }

    /**
     * @param array<string, mixed> $room
     */
    private function findOriginalId(array $room, string $dedupKey): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM incoming_requests WHERE room_id = :room_id AND dedup_key = :dedup_key'
        );
        $stmt->execute(['room_id' => $room['id'], 'dedup_key' => $dedupKey]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Per-address throttle for the public catcher.
     *
     * An unauthenticated endpoint that writes a row per request is a free disk-fill
     * and write-amplification vector. The limit is deliberately generous (a real
     * sender bursts) and off unless configured, because a busy integration should
     * not start dropping deliveries after an upgrade.
     */
    private function enforceLimit(Request $request): void
    {
        $limit = RateLimiter::limit('INGEST_MAX_PER_WINDOW', 0);
        if ($limit <= 0) {
            return;
        }

        $result = RateLimiter::hit(
            'ingest-ip',
            $request->ip,
            $limit,
            RateLimiter::window('INGEST_WINDOW_SECONDS', 60),
        );

        if ($result['allowed']) {
            return;
        }

        header('Retry-After: ' . $result['retry_after']);

        Response::error('Слишком много входящих запросов, повторите позже', 429);
    }

    /**
     * The room's IP allowlist, when it has one.
     *
     * Runs next to `verifySignature` and for the same reason: before the `INSERT`,
     * so a delivery from a refused address is neither stored nor queued. A room
     * without a list accepts everyone — that is what every room did before the
     * feature, and turning that into "deny all" on upgrade would stop every
     * integration at once.
     *
     * The signature is checked first on purpose: a forged request should be
     * answered with the reason it was forged, and telling a stranger "your address
     * is not allowed" when the payload was also unsigned would confirm which
     * addresses are close to being trusted.
     *
     * @param array<string, mixed> $room
     */
    private function enforceAllowlist(Request $request, array $room): void
    {
        $allowlist = IpAllowlist::fromColumn($room['ip_allowlist'] ?? null);
        if ($allowlist === []) {
            return;
        }

        if (IpAllowlist::allows($allowlist, $request->ip)) {
            return;
        }

        Response::error(IpAllowlist::reason($allowlist, $request->ip), 403);
    }

    /**
     * A room with a secret accepts only requests signed with it.
     *
     * The check runs before the row is written, so a forged request never reaches
     * the log and never queues a workflow. A room without a secret stays public:
     * the catcher is meant to be reachable from any sender.
     *
     * `require_timestamp` only has an effect together with a secret — there is
     * nothing to verify without one, and a room that has the flag but no secret
     * stays public rather than rejecting every delivery.
     *
     * @param array<string, mixed> $room
     */
    private function verifySignature(Request $request, array $room): void
    {
        $secret = (string) ($room['secret'] ?? '');
        if ($secret === '') {
            return;
        }

        $requireTimestamp = (bool) ($room['require_timestamp'] ?? false);

        if (Signature::verify(
            $request->header(Signature::HEADER),
            $request->rawBody,
            $secret,
            $request->header(Signature::TIMESTAMP_HEADER),
            $requireTimestamp,
        )) {
            return;
        }

        header('WWW-Authenticate: Signature realm="hookdock"');

        $message = 'Неверная подпись вебхука: проверьте секрет комнаты и заголовок ' . Signature::HEADER;

        if ($requireTimestamp) {
            $message .= '. Комната требует ' . Signature::TIMESTAMP_HEADER
                . ' (unix-время), подпись считается по "<timestamp>.<тело>" и окно '
                . Signature::tolerance() . ' с';
        }

        Response::error($message, 401);
    }

    /**
     * Resolves the room and the endpoint slug out of the request path.
     *
     * Supported shapes:
     *   /api/webhook/{room}/{endpoint}   /hook/{room}/{endpoint}
     *   /api/webhook/{room}              /hook/{room}          (endpoint = default)
     *   /api/webhook/{endpoint}          /hook/{endpoint}      (room = default)
     *
     * @param array<string, mixed> $params
     * @return array{0: array{id: int, slug: string}, 1: string}
     */
    private function resolveTarget(Request $request, array $params): array
    {
        $roomSlug = isset($params['room']) ? (string) $params['room'] : '';
        $endpoint = isset($params['endpoint']) ? (string) $params['endpoint'] : '';

        if ($roomSlug !== '') {
            $room = RoomResolver::findBySlug($roomSlug);
            if ($room === null) {
                Response::error('Комната не найдена: ' . $roomSlug, 404);
            }

            return [$room, $this->normalizeEndpoint($endpoint)];
        }

        $segments = $this->segments($request->path);

        // Two segments after the prefix: the first one must be a known room.
        if (count($segments) >= 2) {
            $room = RoomResolver::findBySlug($segments[0]);
            if ($room !== null) {
                return [$room, $this->normalizeEndpoint(implode('/', array_slice($segments, 1)))];
            }

            Response::error('Комната не найдена: ' . $segments[0], 404);
        }

        // One segment: either a room (endpoint = default) or an endpoint of the
        // default room. A known slug wins so /hook/default keeps working both ways.
        if (count($segments) === 1) {
            $room = RoomResolver::findBySlug($segments[0]);
            if ($room !== null) {
                return [$room, $endpoint !== '' ? $this->normalizeEndpoint($endpoint) : 'default'];
            }

            return [$this->requireDefaultRoom(), $this->normalizeEndpoint($segments[0])];
        }

        return [$this->requireDefaultRoom(), $this->normalizeEndpoint($endpoint)];
    }

    /**
     * @return array{id: int, slug: string}
     */
    private function requireDefaultRoom(): array
    {
        $room = RoomResolver::default();

        if ($room === null) {
            Response::error('Комната по умолчанию не настроена', 500);
        }

        return $room;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        foreach (['/api/webhook/', '/hook/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));

                return array_values(array_filter(explode('/', trim($rest, '/')), static fn (string $part): bool => $part !== ''));
            }
        }

        // A bare `/api/webhook` or `/hook` has no path of its own: it belongs to the
        // default room with the default endpoint, like `/hook/{room}` with no endpoint.
        foreach (['/api/webhook', '/hook'] as $bare) {
            if ($path === $bare) {
                return [];
            }
        }

        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $value = mb_substr(trim($endpoint, '/'), 0, 160);

        return $value === '' ? 'default' : $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
