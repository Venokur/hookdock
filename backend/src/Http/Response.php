<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['error' => ['message' => $message] + $extra], $status);
    }
}
