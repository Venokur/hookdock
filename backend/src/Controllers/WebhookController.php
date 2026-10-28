<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Realtime;
use App\Services\RoomResolver;
use App\Services\WorkflowEngine;

final class WebhookController
{
    /**
     * Catch-all receiver: resolves the room, stores the request, then runs that
     * room's matching workflows. Requests and workflows of other rooms are never
     * touched.
     */
    public function receive(Request $request, array $params = []): void
    {
        [$room, $endpoint] = $this->resolveTarget($request, $params);

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

        $stmt = Database::pdo()->prepare(
            'INSERT INTO incoming_requests
                (room_id, endpoint, method, path, query, headers, body, raw_body, content_type, body_bytes, ip)
             VALUES
                (:room_id, :endpoint, :method, :path, :query, :headers, :body, :raw_body, :content_type, :body_bytes, :ip)
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
        ]);

        $saved = $stmt->fetch();
        $row['id'] = (int) $saved['id'];
        $row['received_at'] = $saved['received_at'];

        // Realtime first: the list can show the row while routing is still running.
        Realtime::publish('request.created', [
            'id' => $row['id'],
            'room_id' => $row['room_id'],
            'endpoint' => $row['endpoint'],
            'method' => $row['method'],
        ]);

        $runs = WorkflowEngine::process($row);

        if ($runs !== []) {
            $failed = count(array_filter($runs, static fn (array $run): bool => ($run['status'] ?? '') === 'failed'));

            Realtime::publish('request.updated', [
                'id' => $row['id'],
                'room_id' => $row['room_id'],
                'run_count' => count($runs),
                'failed_runs' => $failed,
            ]);
        }

        Response::json([
            'ok' => true,
            'id' => $row['id'],
            'room' => $room['slug'],
            'endpoint' => $row['endpoint'],
            'received_at' => $row['received_at'],
            'workflow_runs' => count($runs),
        ], 202);
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
