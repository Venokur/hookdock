<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;

final class Jwt
{
    /**
     * @param array<string, mixed> $claims
     */
    public static function issue(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + Config::int('JWT_TTL', 43200),
        ]);

        $header = self::encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = self::encode($payload);
        $signature = self::sign($header . '.' . $body);

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * @return array<string, mixed>|null claims, or null when invalid/expired
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;

        if (!hash_equals(self::sign($header . '.' . $body), $signature)) {
            return null;
        }

        $claims = json_decode(self::decodeSegment($body), true);
        if (!is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private static function sign(string $data): string
    {
        $secret = Config::get('JWT_SECRET', 'dev-only-change-me');

        return self::base64UrlEncode(hash_hmac('sha256', $data, (string) $secret, true));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return self::base64UrlEncode(json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private static function decodeSegment(string $segment): string
    {
        $padded = str_pad(strtr($segment, '-_', '+/'), (int) (ceil(strlen($segment) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
