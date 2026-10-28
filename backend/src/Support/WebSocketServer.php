<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Jwt;
use App\Config;
use App\Database;
use PDO;
use Throwable;

/**
 * Minimal RFC6455 websocket server (text frames only).
 *
 * One process owns the socket and multiplexes every client through
 * stream_select(). Realtime events are read from Postgres LISTEN, so the PHP-FPM
 * API keeps its short-lived request/response model.
 */
final class WebSocketServer
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_FRAME = 65536;
    private const PING_INTERVAL = 25;
    private const IDLE_TIMEOUT = 75;
    private const HANDSHAKE_TIMEOUT = 15;
    private const TICK_MICROSECONDS = 200_000;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array<string, mixed>> */
    private array $clients = [];

    private ?PDO $listener = null;

    private bool $running = true;

    private int $nextId = 1;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $channel,
    ) {
    }

    public function run(): void
    {
        $this->listen();

        $context = stream_context_create([
            'socket' => ['backlog' => 64, 'so_reuseaddr' => true],
        ]);

        $server = @stream_socket_server(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            fwrite(STDERR, sprintf("[ws] cannot listen on %s:%d (%s)\n", $this->host, $this->port, $error ?: (string) $errno));

            return;
        }

        stream_set_blocking($server, false);
        $this->server = $server;

        $this->log(sprintf('listening on %s:%d, listening for NOTIFY %s', $this->host, $this->port, $this->channel));

        while ($this->running) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                $read[] = $client['stream'];
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS);

            if ($ready === false) {
                // Interrupted by a signal: the next select() picks up where we left.
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->accept();

                    continue;
                }

                $this->readFrom($stream);
            }

            $this->pumpNotifications();
            $this->heartbeat();
        }
    }

    /**
     * Opens a dedicated LISTEN connection (the shared PDO is request-scoped in FPM).
     */
    private function listen(): void
    {
        $attempts = 0;

        while (true) {
            try {
                $pdo = Database::connect();
                $pdo->query('LISTEN ' . $this->quoteIdentifier($this->channel));
                $this->listener = $pdo;
                $this->log('subscribed to ' . $this->channel);

                return;
            } catch (Throwable $e) {
                $attempts++;
                if ($attempts >= 40) {
                    throw new \RuntimeException('Database is not reachable for LISTEN: ' . $e->getMessage(), 0, $e);
                }

                sleep(1);
            }
        }
    }

    private function accept(): void
    {
        $stream = @stream_socket_accept($this->server, 0);
        if ($stream === false) {
            return;
        }

        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);

        $id = $this->nextId++;
        $this->clients[$id] = [
            'id' => $id,
            'stream' => $stream,
            'state' => 'handshake',
            'buffer' => '',
            'fragments' => '',
            'fragmentOpcode' => null,
            'lastSeen' => time(),
            'lastPing' => 0,
            'peer' => (string) (@stream_socket_get_name($stream, true) ?: 'unknown'),
        ];
    }

    private function readFrom($stream): void
    {
        $id = $this->idOf($stream);
        if ($id === null) {
            return;
        }

        $chunk = @fread($stream, 16384);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $this->disconnect($id);

            return;
        }

        if ($chunk === '') {
            return;
        }

        $this->clients[$id]['lastSeen'] = time();
        $this->clients[$id]['buffer'] .= $chunk;

        if ($this->clients[$id]['state'] === 'handshake') {
            $this->completeHandshake($id);

            return;
        }

        $this->consumeFrames($id);
    }

    private function completeHandshake(int $id): void
    {
        $buffer = $this->clients[$id]['buffer'];
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            // Header block is capped so a silent client cannot grow memory.
            if (strlen($buffer) > 16384) {
                $this->disconnect($id);
            }

            return;
        }

        $head = substr($buffer, 0, $end);
        $this->clients[$id]['buffer'] = substr($buffer, $end + 4);

        $lines = preg_split("/\r\n/", $head) ?: [];
        $requestLine = (string) array_shift($lines);
        $headers = [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if (!preg_match('#^GET\s+(\S+)#', $requestLine, $matches)) {
            $this->reject($id, 400, 'Bad Request');

            return;
        }

        $target = $matches[1];
        $path = (string) (parse_url($target, PHP_URL_PATH) ?: '/');
        parse_str((string) (parse_url($target, PHP_URL_QUERY) ?: ''), $query);

        if ($path !== '/api/ws') {
            $this->reject($id, 404, 'Not Found');

            return;
        }

        $ticket = (string) ($query['ticket'] ?? '');
        $claims = $ticket === '' ? null : Jwt::decode($ticket);
        if ($claims === null || ($claims['scope'] ?? '') !== 'ws') {
            $this->reject($id, 401, 'Unauthorized');

            return;
        }

        $key = $headers['sec-websocket-key'] ?? '';
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connection = strtolower($headers['connection'] ?? '');
        $version = $headers['sec-websocket-version'] ?? '';

        if (
            $key === ''
            || !preg_match('/^[A-Za-z0-9+\/=]{16,64}$/', $key)
            || $upgrade !== 'websocket'
            || !str_contains($connection, 'upgrade')
            || $version !== '13'
        ) {
            $this->reject($id, 426, 'Upgrade Required', "Sec-WebSocket-Version: 13\r\n");

            return;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        if (!$this->write($id, $response)) {
            $this->disconnect($id);

            return;
        }

        $this->clients[$id]['state'] = 'open';
        $this->clients[$id]['user_id'] = (int) ($claims['sub'] ?? 0);

        $this->log(sprintf('client #%d connected (%s)', $id, $this->clients[$id]['peer']));

        $this->send($id, 'hello', ['connection_id' => $id, 'channel' => $this->channel]);

        if ($this->clients[$id]['buffer'] !== '') {
            $this->consumeFrames($id);
        }
    }

    private function consumeFrames(int $id): void
    {
        while (isset($this->clients[$id]) && $this->clients[$id]['state'] === 'open') {
            $buffer = $this->clients[$id]['buffer'];

            // Need at least the two-byte base header.
            if (strlen($buffer) < 2) {
                return;
            }

            $first = ord($buffer[0]);
            $second = ord($buffer[1]);

            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0f;
            $masked = ($second & 0x80) !== 0;
            $length = $second & 0x7f;
            $offset = 2;

            if ($length === 126) {
                if (strlen($buffer) < 4) {
                    return;
                }
                $length = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($buffer) < 10) {
                    return;
                }
                $length = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }

            if ($length > self::MAX_FRAME) {
                $this->closeWith($id, 1009, 'Message too big');

                return;
            }

            if (!$masked) {
                // Browsers always mask; an unmasked frame is a protocol violation.
                $this->closeWith($id, 1002, 'Unmasked frame');

                return;
            }

            if (strlen($buffer) < $offset + 4 + $length) {
                return;
            }

            $mask = substr($buffer, $offset, 4);
            $payload = substr($buffer, $offset + 4, $length);
            $this->clients[$id]['buffer'] = substr($buffer, $offset + 4 + $length);

            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }

            switch ($opcode) {
                case 0x0: // continuation
                    if ($this->clients[$id]['fragmentOpcode'] === null) {
                        $this->closeWith($id, 1002, 'Unexpected continuation');

                        return;
                    }
                    $this->clients[$id]['fragments'] .= $decoded;
                    if (strlen($this->clients[$id]['fragments']) > self::MAX_FRAME) {
                        $this->closeWith($id, 1009, 'Message too big');

                        return;
                    }
                    if ($fin) {
                        $complete = $this->clients[$id]['fragments'];
                        $this->clients[$id]['fragments'] = '';
                        $this->clients[$id]['fragmentOpcode'] = null;

                        if (!$this->onMessage($id, $complete)) {
                            return;
                        }
                    }
                    break;

                case 0x1: // text
                case 0x2: // binary
                    if (!$fin) {
                        $this->clients[$id]['fragments'] = $decoded;
                        $this->clients[$id]['fragmentOpcode'] = $opcode;
                        break;
                    }
                    if (!$this->onMessage($id, $decoded)) {
                        return;
                    }
                    break;

                case 0x8: // close
                    $this->closeWith($id, 1000, 'Bye');

                    return;

                case 0x9: // ping
                    $this->write($id, $this->frame($decoded, 0xA));
                    break;

                case 0xA: // pong
                    break;

                default:
                    $this->closeWith($id, 1002, 'Unsupported opcode');

                    return;
            }
        }
    }

    /**
     * Clients may send a "ping" text message to keep the connection warm; any
     * other payload is ignored (this channel is server -> client only).
     */
    private function onMessage(int $id, string $payload): bool
    {
        $trimmed = trim($payload);

        if ($trimmed === '') {
            return true;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && ($decoded['type'] ?? '') === 'ping') {
            return $this->send($id, 'pong', ['at' => gmdate('Y-m-d\TH:i:s\Z')]);
        }

        return true;
    }

    private function pumpNotifications(): void
    {
        if ($this->listener === null) {
            return;
        }

        try {
            while (true) {
                $row = $this->listener->pgsqlGetNotify(PDO::FETCH_ASSOC, 0);

                if ($row === false || $row === null) {
                    return;
                }

                // PHP 8.1+ returns ['message' => channel, 'pid' => …, 'payload' => …].
                // Without a payload there is nothing to forward: 'message' is the channel
                // name, so never broadcast it.
                $payload = (string) ($row['payload'] ?? '');
                if ($payload === '') {
                    continue;
                }

                $this->broadcastRaw($payload);
            }
        } catch (Throwable $e) {
            $this->log('LISTEN lost, reconnecting: ' . $e->getMessage());
            $this->listener = null;
            try {
                $this->listen();
            } catch (Throwable $fatal) {
                $this->log('reconnect failed: ' . $fatal->getMessage());
            }
        }
    }

    private function heartbeat(): void
    {
        $now = time();

        foreach (array_keys($this->clients) as $id) {
            $client = $this->clients[$id] ?? null;
            if ($client === null) {
                continue;
            }

            // Never send frames to a socket that has not answered the handshake
            // yet: a ping byte before "HTTP/1.1 101" breaks the upgrade.
            if ($client['state'] !== 'open') {
                if ($now - (int) $client['lastSeen'] > self::HANDSHAKE_TIMEOUT) {
                    $this->disconnect($id);
                }

                continue;
            }

            if ($now - (int) $client['lastSeen'] > self::IDLE_TIMEOUT) {
                $this->closeWith($id, 1001, 'Idle timeout');
                continue;
            }

            if ((int) $client['lastPing'] + self::PING_INTERVAL > $now) {
                continue;
            }

            $this->clients[$id]['lastPing'] = $now;
            $this->write($id, $this->frame('', 0x9));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(int $id, string $type, array $data): bool
    {
        $envelope = [
            'v' => 1,
            'type' => $type,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];

        return $this->write($id, $this->frame((string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0x1));
    }

    private function broadcastRaw(string $payload): void
    {
        $frame = $this->frame($payload, 0x1);
        $delivered = 0;

        foreach (array_keys($this->clients) as $id) {
            if (($this->clients[$id]['state'] ?? '') !== 'open') {
                continue;
            }

            if ($this->write($id, $frame)) {
                $delivered++;
            }
        }

        if ($delivered > 0) {
            $this->log(sprintf('delivered event to %d client(s)', $delivered));
        }
    }

    private function frame(string $payload, int $opcode): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length < 126) {
            $head .= chr($length);
        } elseif ($length < 65536) {
            $head .= chr(126) . pack('n', $length);
        } else {
            $head .= chr(127) . pack('J', $length);
        }

        return $head . $payload;
    }

    private function write(int $id, string $bytes): bool
    {
        if (!isset($this->clients[$id])) {
            return false;
        }

        $stream = $this->clients[$id]['stream'];
        $total = strlen($bytes);
        $written = 0;

        while ($written < $total) {
            $chunk = @fwrite($stream, substr($bytes, $written, 8192));

            if ($chunk === false || $chunk === 0) {
                $this->disconnect($id);

                return false;
            }

            $written += $chunk;
        }

        return true;
    }

    private function closeWith(int $id, int $code, string $reason): void
    {
        if (isset($this->clients[$id])) {
            $this->write($id, $this->frame(pack('n', $code) . $reason, 0x8));
        }

        $this->disconnect($id);
    }

    private function reject(int $id, int $status, string $reason, string $extraHeaders = ''): void
    {
        $body = $reason;
        $response = sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n%s\r\n%s",
            $status,
            $reason,
            strlen($body),
            $extraHeaders,
            $body,
        );

        $this->write($id, $response);
        $this->disconnect($id);
    }

    private function disconnect(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $client = $this->clients[$id];
        unset($this->clients[$id]);

        @fclose($client['stream']);

        $this->log(sprintf('client #%d disconnected', $id));
    }

    private function idOf($stream): ?int
    {
        foreach ($this->clients as $id => $client) {
            if ($client['stream'] === $stream) {
                return (int) $id;
            }
        }

        return null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, sprintf("[%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
    }
}
