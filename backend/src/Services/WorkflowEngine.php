<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Support\HttpClient;
use App\Support\JsonPath;
use App\Support\Template;
use Throwable;

/**
 * Evaluates enabled workflows against a freshly stored webhook request.
 * Failures are recorded per workflow and never bubble up to the receiver.
 */
final class WorkflowEngine
{
    /**
     * @param array<string, mixed> $request incoming_requests row + decoded fields
     * @return list<array<string, mixed>> created workflow_runs
     */
    public static function process(array $request): array
    {
        $pdo = Database::pdo();
        $context = self::context($request);

        $workflows = $pdo->query('SELECT * FROM workflows WHERE enabled ORDER BY id')->fetchAll();

        $runs = [];
        foreach ($workflows as $workflow) {
            $endpoint = (string) $workflow['endpoint'];
            if (!self::endpointMatches($endpoint, (string) $request['endpoint'])) {
                continue;
            }

            $conditions = self::decode((string) $workflow['conditions']);
            $actions = self::decode((string) $workflow['actions']);

            if (!self::conditionsPass($conditions, $context)) {
                $runs[] = self::recordRun((int) $workflow['id'], (int) $request['id'], 'skipped', 'Условия не выполнены');
                continue;
            }

            [$status, $message] = self::runActions($actions, $context);
            $runs[] = self::recordRun((int) $workflow['id'], (int) $request['id'], $status, $message);
        }

        return $runs;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function context(array $request): array
    {
        return [
            'body' => $request['body'] ?? [],
            'raw' => (string) ($request['raw_body'] ?? ''),
            'headers' => $request['headers'] ?? [],
            'query' => $request['query'] ?? [],
            'transformed' => [],
            'meta' => [
                'id' => $request['id'] ?? null,
                'method' => $request['method'] ?? null,
                'path' => $request['path'] ?? null,
                'endpoint' => $request['endpoint'] ?? null,
                'ip' => $request['ip'] ?? null,
                'received_at' => $request['received_at'] ?? null,
            ],
        ];
    }

    private static function endpointMatches(string $pattern, string $actual): bool
    {
        return $pattern === '*' || strcasecmp($pattern, $actual) === 0;
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed> $context
     */
    private static function conditionsPass(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            if (!self::evaluate($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $context
     */
    private static function evaluate(array $condition, array $context): bool
    {
        $operator = (string) ($condition['operator'] ?? 'equals');
        $resolved = JsonPath::resolve($context, (string) ($condition['path'] ?? ''));

        if ($operator === 'exists') {
            return $resolved['found'];
        }

        if ($operator === 'not_exists') {
            return !$resolved['found'];
        }

        if (!$resolved['found']) {
            return false;
        }

        return self::compare($resolved['value'], $condition['value'] ?? null, $operator);
    }

    private static function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($actual) || !is_numeric($expected)) {
                return false;
            }

            return match ($operator) {
                'gt' => (float) $actual > (float) $expected,
                'gte' => (float) $actual >= (float) $expected,
                'lt' => (float) $actual < (float) $expected,
                'lte' => (float) $actual <= (float) $expected,
            };
        }

        if ($operator === 'regex') {
            $pattern = '~' . str_replace('~', '\~', (string) $expected) . '~u';

            return is_scalar($actual) && @preg_match($pattern, Template::stringify($actual)) === 1;
        }

        if ($operator === 'contains' || $operator === 'not_contains') {
            $contains = is_array($actual)
                ? in_array($expected, $actual, false)
                : str_contains(Template::stringify($actual), Template::stringify($expected));

            return $operator === 'contains' ? $contains : !$contains;
        }

        $equal = self::looseEquals($actual, $expected);

        return $operator === 'equals' ? $equal : !$equal;
    }

    private static function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                === filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_array($actual) || is_array($expected)) {
            return json_encode($actual) === json_encode($expected);
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $context
     * @return array{0: string, 1: string} status and message
     */
    private static function runActions(array $actions, array &$context): array
    {
        $messages = [];
        $failed = false;

        foreach ($actions as $action) {
            try {
                $type = (string) ($action['type'] ?? '');

                if ($type === 'log') {
                    $rendered = Template::render((string) ($action['message'] ?? ''), $context);
                    $messages[] = 'лог: ' . ($rendered !== '' ? $rendered : 'совпадение');
                    continue;
                }

                if ($type === 'transform') {
                    $mapping = is_array($action['mapping'] ?? null) ? $action['mapping'] : [];
                    $result = [];

                    foreach ($mapping as $key => $path) {
                        $resolved = JsonPath::resolve($context, (string) $path);
                        if ($resolved['found']) {
                            $result[$key] = $resolved['value'];
                        }
                    }

                    $context['transformed'] = $result;
                    $messages[] = 'трансформация: ' . count($result) . ' пол.';
                    continue;
                }

                if ($type === 'forward') {
                    $url = Template::render((string) ($action['url'] ?? ''), $context);
                    $method = strtoupper((string) ($action['method'] ?? 'POST'));

                    if ($url === '') {
                        $failed = true;
                        $messages[] = 'пересылка: пустой URL';
                        continue;
                    }

                    $payload = $action['payload'] ?? 'body';
                    if (is_string($payload)) {
                        $payload = match ($payload) {
                            'body' => $context['body'] ?? [],
                            'transformed' => $context['transformed'] ?? [],
                            'raw' => (string) ($context['raw'] ?? ''),
                            default => $payload,
                        };
                    } elseif (is_array($payload)) {
                        $payload = self::renderDeep($payload, $context);
                    }

                    $body = is_string($payload)
                        ? $payload
                        : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $headers = ['Content-Type' => 'application/json'];
                    $custom = is_array($action['headers'] ?? null) ? $action['headers'] : [];
                    foreach ($custom as $headerName => $headerValue) {
                        $headers[(string) $headerName] = Template::render((string) $headerValue, $context);
                    }

                    $result = HttpClient::send($method, $url, $headers, $body);
                    if (!$result['ok']) {
                        $failed = true;
                        $messages[] = sprintf(
                            'пересылка %s %s → %s%s',
                            $method,
                            $url,
                            $result['status'] !== 0 ? (string) $result['status'] : 'ошибка',
                            $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
                        );
                        continue;
                    }

                    $messages[] = sprintf('пересылка %s %s → %d', $method, $url, $result['status']);
                    continue;
                }

                $failed = true;
                $messages[] = 'неизвестное действие: ' . $type;
            } catch (Throwable $e) {
                $failed = true;
                $messages[] = 'ошибка действия: ' . $e->getMessage();
            }
        }

        return [$failed ? 'failed' : 'success', implode('; ', $messages) ?: 'Готово'];
    }

    /**
     * Renders templates inside action payloads; full-string placeholders keep their type.
     *
     * @param array<string, mixed> $context
     */
    private static function renderDeep(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^\{\{\s*([^}]+?)\s*\}\}$/', $value, $matches)) {
                $resolved = JsonPath::resolve($context, $matches[1]);

                return $resolved['found'] ? $resolved['value'] : null;
            }

            return Template::render($value, $context);
        }

        if (is_array($value)) {
            $rendered = [];
            foreach ($value as $key => $item) {
                $rendered[$key] = self::renderDeep($item, $context);
            }

            return $rendered;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function recordRun(int $workflowId, int $requestId, string $status, string $message): array
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO workflow_runs (workflow_id, request_id, status, message)
             VALUES (:workflow_id, :request_id, :status, :message)
             RETURNING id, workflow_id, request_id, status, message, created_at'
        );
        $stmt->execute([
            'workflow_id' => $workflowId,
            'request_id' => $requestId,
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
        ]);

        return $stmt->fetch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
