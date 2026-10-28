<?php

declare(strict_types=1);

namespace App\Support;

final class HttpClient
{
    /**
     * Fire-and-forget JSON request used by the `forward` action.
     *
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, error: ?string}
     */
    public static function send(string $method, string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                    $status = (int) $matches[1];
                }
            }
        }

        $error = $response === false
            ? (error_get_last()['message'] ?? 'request failed')
            : null;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $error,
        ];
    }
}
