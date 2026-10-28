<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\WorkflowEngine;

final class WebhookController
{
    /**
     * Catch-all receiver: stores every method/path, then runs matching workflows.
     */
    public function receive(Request $request, array $params = []): void
    {
        $endpoint = (string) ($params['endpoint'] ?? $this->endpointFromPath($request->path));
        $endpoint = mb_substr(trim($endpoint, '/'), 0, 160);
        if ($endpoint === '') {
            $endpoint = 'default';
        }

        $raw = $request->rawBody;
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        $row = [
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
                (endpoint, method, path, query, headers, body, raw_body, content_type, body_bytes, ip)
             VALUES
                (:endpoint, :method, :path, :query, :headers, :body, :raw_body, :content_type, :body_bytes, :ip)
             RETURNING id, received_at'
        );
        $stmt->execute([
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

        $runs = WorkflowEngine::process($row);

        Response::json([
            'ok' => true,
            'id' => $row['id'],
            'endpoint' => $row['endpoint'],
            'received_at' => $row['received_at'],
            'workflow_runs' => count($runs),
        ], 202);
    }

    private function endpointFromPath(string $path): string
    {
        foreach (['/api/webhook/', '/hook/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return trim($path, '/');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
