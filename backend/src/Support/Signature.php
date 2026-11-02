<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HMAC signature for incoming webhooks.
 *
 * A room with a secret only accepts requests carrying
 * `X-Hookdock-Signature: sha256=<hex>`, where the hex value is
 * `hash_hmac('sha256', $rawBody, $secret)`. The signature covers the body as it
 * arrived, so the stored payload and the signed payload cannot differ.
 *
 * The comparison is `hash_equals`, not `===`: a byte-by-byte comparison leaks
 * the length of the matching prefix through timing, which is enough to forge a
 * signature one byte at a time.
 */
final class Signature
{
    public const HEADER = 'X-Hookdock-Signature';
    public const PREFIX = 'sha256=';

    public static function sign(string $rawBody, string $secret): string
    {
        return self::PREFIX . hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Accepts the documented `sha256=<hex>` form and a bare hex digest: some
     * senders strip the prefix, and refusing them would look like a wrong secret.
     */
    public static function verify(?string $header, string $rawBody, string $secret): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $value = strtolower(trim($header));
        if (str_starts_with($value, self::PREFIX)) {
            $value = substr($value, strlen(self::PREFIX));
        }

        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $value);
    }

    /**
     * 192 bits of randomness, prefixed so a leaked value is recognisable as a
     * webhook secret rather than an API token.
     */
    public static function generate(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }
}
