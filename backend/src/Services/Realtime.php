<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use Throwable;

/**
 * Publishes realtime events to the websocket daemon.
 *
 * Transport is Postgres LISTEN/NOTIFY: the `api` container only runs NOTIFY and
 * bin/ws-server.php LISTENs, so no extra broker or table is needed.
 *
 * Delivery is best effort on purpose: a failed notification must never turn a
 * successfully stored webhook into an error response.
 */
final class Realtime
{
    /** Postgres rejects NOTIFY payloads above 8000 bytes. */
    private const MAX_PAYLOAD = 7500;

    private static ?bool $available = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function publish(string $type, array $data = []): void
    {
        if (self::$available === false) {
            return;
        }

        $envelope = [
            'v' => 1,
            'type' => $type,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD) {
            // Too big to travel through a notification: tell clients to resync.
            $encoded = (string) json_encode([
                'v' => 1,
                'type' => $type,
                'at' => $envelope['at'],
                'data' => ['resync' => true],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $stmt = Database::pdo()->prepare('SELECT pg_notify(:channel, :payload)');
            $stmt->execute([
                'channel' => self::channel(),
                'payload' => $encoded,
            ]);
            self::$available = true;
        } catch (Throwable $e) {
            self::$available = false;
            error_log('[realtime] publish failed: ' . $e->getMessage());
        }
    }

    public static function channel(): string
    {
        return (string) Config::get('WS_CHANNEL', 'hookdock_events');
    }
}
