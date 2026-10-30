<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

final class HttpClient
{
    /** Response bodies are kept for the run log; 4 KB is enough to see an error. */
    private const BODY_LIMIT = 4096;

    /**
     * JSON request used by the `forward` action.
     *
     * `$addresses` pins the connection to the addresses that `UrlGuard` already
     * approved. Without it the name is resolved twice — once by the guard, once by
     * curl — and a DNS-rebinding name can answer "public" the first time and
     * "127.0.0.1" the second. The guard's verdict is only meaningful if the request
     * goes to exactly the address it inspected.
     *
     * @param array<string, string> $headers
     * @param list<string> $addresses
     * @return array{ok: bool, status: int, error: ?string, body: string, duration_ms: int}
     */
    public static function send(string $method, string $url, array $headers, string $body, array $addresses = []): array
    {
        $timeout = max(1, Config::int('FORWARD_TIMEOUT', 8));

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $started = microtime(true);

        $handle = curl_init($url);
        if ($handle === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'cannot initialise the request',
                'body' => '',
                'duration_ms' => 0,
            ];
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            // A redirect would leave the pinned address and land on a target the
            // guard never saw, so it is surfaced as a failure instead of followed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS_STR => 'http,https',
        ];

        $pin = self::pin($url, $addresses);
        if ($pin !== null) {
            $options[CURLOPT_RESOLVE] = [$pin];
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $duration = (int) round((microtime(true) - $started) * 1000);

        $error = $response === false ? curl_error($handle) : null;
        curl_close($handle);

        if (is_string($response) && mb_strlen($response) > self::BODY_LIMIT) {
            $response = mb_substr($response, 0, self::BODY_LIMIT);
        }

        return [
            'ok' => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $error,
            'body' => is_string($response) ? $response : '',
            'duration_ms' => $duration,
        ];
    }

    /**
     * `CURLOPT_RESOLVE` entry that pins the URL's host:port to the vetted addresses.
     *
     * Literal-IP URLs need no pinning (there is nothing to resolve), and an empty
     * address list means the guard allowed everything (`FORWARD_ALLOW_INTERNAL`).
     *
     * @param list<string> $addresses
     */
    private static function pin(string $url, array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $host = (string) $parts['host'];
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // IPv6 literals must be bracketed or curl cannot parse the entry.
        $targets = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
            $addresses,
        );

        return $host . ':' . $port . ':' . implode(',', $targets);
    }

    /**
     * Whether a failed send is worth repeating.
     *
     * Transport failures, timeouts, throttling and upstream 5xx are transient;
     * other 4xx answers mean the request itself is wrong and a retry would only
     * repeat the same rejection.
     *
     * @param array{ok: bool, status: int, error: ?string} $result
     */
    public static function retryable(array $result): bool
    {
        if ($result['error'] !== null) {
            return true;
        }

        $status = (int) $result['status'];

        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }
}
