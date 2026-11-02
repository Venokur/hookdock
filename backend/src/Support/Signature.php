<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

/**
 * HMAC signature for incoming and outgoing webhooks.
 *
 * A room with a secret only accepts requests carrying
 * `X-Hookdock-Signature: sha256=<hex>`, where the hex value is
 * `hash_hmac('sha256', $rawBody, $secret)`. The signature covers the body as it
 * arrived, so the stored payload and the signed payload cannot differ.
 *
 * A body-only signature cannot tell a retry from a fresh delivery: whoever
 * captures one request can send it again and the room's workflows run twice. A
 * room can therefore also require `X-Hookdock-Timestamp: <unix seconds>`; the
 * signature then covers `"<ts>.<body>"`, and a timestamp outside
 * `SIGNATURE_TOLERANCE_SECONDS` is refused. The window is replay protection, not
 * clock sync — it has to absorb a sender's clock drift and a queued retry, so it
 * defaults to five minutes rather than seconds.
 *
 * The comparison is `hash_equals`, not `===`: a byte-by-byte comparison leaks
 * the length of the matching prefix through timing, which is enough to forge a
 * signature one byte at a time.
 */
final class Signature
{
    public const HEADER = 'X-Hookdock-Signature';
    public const TIMESTAMP_HEADER = 'X-Hookdock-Timestamp';
    public const PREFIX = 'sha256=';

    /** Default window, overridable with `SIGNATURE_TOLERANCE_SECONDS`. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * @param int|null $timestamp when given, the signature covers `"<ts>.<body>"`
     */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        return self::PREFIX . hash_hmac(
            'sha256',
            self::signedPayload($rawBody, $timestamp === null ? null : (string) $timestamp),
            $secret,
        );
    }

    /**
     * Accepts the documented `sha256=<hex>` form and a bare hex digest: some
     * senders strip the prefix, and refusing them would look like a wrong secret.
     *
     * When a timestamp header is present it is authoritative: the signature must
     * cover `"<ts>.<body>"` and the timestamp must be inside the window. Falling
     * back to the body-only form in that case would let a captured request be
     * replayed simply by keeping its timestamp, which defeats the point.
     *
     * @param string|null $timestamp raw header value, compared as text so the
     *        exact bytes the sender signed are used
     */
    public static function verify(
        ?string $header,
        string $rawBody,
        string $secret,
        ?string $timestamp = null,
        bool $requireTimestamp = false,
    ): bool {
        if ($header === null || $secret === '') {
            return false;
        }

        $digest = self::digest($header);
        if ($digest === null) {
            return false;
        }

        $ts = self::timestamp($timestamp);

        if ($ts !== null) {
            if (!self::fresh($ts)) {
                return false;
            }

            return hash_equals(
                hash_hmac('sha256', self::signedPayload($rawBody, $ts), $secret),
                $digest,
            );
        }

        if ($requireTimestamp) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $digest);
    }

    /**
     * Headers to attach to a signed forward.
     *
     * Returned as a pair rather than written into the array so the caller keeps
     * one place that builds the outgoing header list, and the preview renders the
     * same values the worker would send.
     *
     * @return array{0: ?string, 1: string} timestamp header (null = not used) and signature
     */
    public static function outgoing(string $rawBody, string $secret, bool $withTimestamp): array
    {
        if (!$withTimestamp) {
            return [null, self::sign($rawBody, $secret)];
        }

        $timestamp = (string) time();

        return [$timestamp, self::sign($rawBody, $secret, (int) $timestamp)];
    }

    /**
     * 192 bits of randomness, prefixed so a leaked value is recognisable as a
     * webhook secret rather than an API token.
     */
    public static function generate(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    public static function tolerance(): int
    {
        return max(1, Config::int('SIGNATURE_TOLERANCE_SECONDS', self::DEFAULT_TOLERANCE));
    }

    private static function signedPayload(string $rawBody, ?string $timestamp): string
    {
        return $timestamp === null ? $rawBody : $timestamp . '.' . $rawBody;
    }

    private static function digest(string $header): ?string
    {
        $value = strtolower(trim($header));
        if (str_starts_with($value, self::PREFIX)) {
            $value = substr($value, strlen(self::PREFIX));
        }

        return preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }

    /**
     * Digits only, returned verbatim: the sender signed the header value as a
     * string, and reformatting it (`+1699…`, `1699…0`) would compute a different
     * HMAC for a request that is in fact correct.
     */
    private static function timestamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return preg_match('/^[0-9]{1,12}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private static function fresh(string $timestamp): bool
    {
        return abs(time() - (int) $timestamp) <= self::tolerance();
    }
}
