<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CIDR matching for the catcher's per-room allowlist.
 *
 * The catcher is public by design, and an HMAC secret proves *what* sent a
 * payload, not *where* it came from. A signature secret can leak into a log; an
 * address range cannot be replayed from elsewhere. For an integration that runs
 * behind a fixed NAT ("only Stripe's published ranges may deliver here") the
 * allowlist is the right shape of the same protection.
 *
 * Everything is compared as **packed bytes** (`inet_pton`), never as text — the
 * same reason `UrlGuard` does it: `::1`, `0:0:0:0:0:0:0:1`, `::ffff:7f00:1` and
 * `::ffff:127.0.0.1` are four spellings of one address, and a string prefix
 * check would accept one of them and quietly reject the others.
 *
 * An entry may be a plain address (`203.0.113.7`) or a CIDR (`203.0.113.0/24`,
 * `2001:db8::/32`); a plain address is stored and compared as `/32` or `/128`.
 */
final class IpAllowlist
{
    /** How many entries a room may have before the list stops being a list. */
    public const MAX_ENTRIES = 64;

    /**
     * Normalizes one entry, or returns null when it is not an address or CIDR.
     *
     * The canonical form is what is stored, so `10.0.0.1/8` and `10.0.0.0/8`
     * cannot both sit in the list unmasked: only the network address is kept, and
     * the operator sees the same value they will be matched against.
     */
    public static function normalize(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        $address = trim((string) $address);
        $address = trim($address, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }

        $family = strlen($packed) === 4 ? 4 : 6;
        $max = $family === 4 ? 32 : 128;

        if ($bits === null || trim((string) $bits) === '') {
            return self::canonical($packed, $max);
        }

        $bits = trim((string) $bits);
        if (preg_match('/^\d{1,3}$/', $bits) !== 1) {
            return null;
        }

        $prefix = (int) $bits;
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        return self::canonical(self::maskAddress($packed, $prefix), $prefix);
    }

    /**
     * Validates a whole list. Returns the canonical entries, or a string naming
     * the first entry that is not acceptable — the caller turns it into a `422`
     * that repeats the offending value, because "неверный формат" in a list of 20
     * entries is not actionable.
     *
     * @param mixed $value
     * @return array{0: list<string>, 1: string|null}
     */
    public static function normalizeAll(mixed $value): array
    {
        if ($value === null) {
            return [[], null];
        }

        if (!is_array($value)) {
            return [[], 'Список адресов должен быть массивом строк'];
        }

        $list = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return [[], 'Список адресов должен быть массивом строк'];
            }

            $canonical = self::normalize($entry);
            if ($canonical === null) {
                return [[], 'Неверный адрес или маска: ' . mb_substr($entry, 0, 64)];
            }

            if (!in_array($canonical, $list, true)) {
                $list[] = $canonical;
            }
        }

        if (count($list) > self::MAX_ENTRIES) {
            return [[], 'Адресов в списке слишком много: не больше ' . self::MAX_ENTRIES];
        }

        return [$list, null];
    }

    /**
     * Whether an address is inside any entry of the list.
     *
     * An **empty list allows everything**: that is the behaviour of every room
     * that existed before the feature, and turning it into "deny all" on an
     * upgrade would silently stop every integration.
     *
     * A list that is non-empty but unparseable denies, because a broken guard
     * must not become an open door.
     *
     * @param list<string> $allowlist
     */
    public static function allows(array $allowlist, string $ip): bool
    {
        if ($allowlist === []) {
            return true;
        }

        $packed = @inet_pton(trim($ip, '[]'));
        if ($packed === false) {
            return false;
        }

        // An IPv4 delivery reaching an IPv6-only installation (or the other way
        // round) arrives in one of two forms depending on the listener: the raw
        // 4 bytes, or the same address embedded in `::ffff:a.b.c.d`. Checking both
        // spellings is what makes `0.0.0.0/0` mean "all v4 senders" instead of
        // "all v4 senders except the ones nginx mapped".
        $candidates = [$packed];
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $candidates[] = substr($packed, 12);
        } elseif (strlen($packed) === 4) {
            $candidates[] = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $packed;
        }

        foreach ($allowlist as $entry) {
            [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $network = @inet_pton(trim((string) $address, '[]'));
            if ($network === false) {
                continue;
            }

            $max = strlen($network) === 4 ? 32 : 128;
            $prefix = $bits === null ? $max : (int) $bits;

            foreach ($candidates as $candidate) {
                if (strlen($candidate) !== strlen($network)) {
                    continue;
                }

                if (self::maskAddress($candidate, $prefix) === self::maskAddress($network, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A human-readable reason for the refusal, built from what is configured.
     *
     * It names the sender's address, never the configured ranges: the person who
     * needs this message has just been told "no", and publishing the allowlist in
     * an unauthenticated 403 body would let anyone enumerate which ranges are
     * trusted and aim for one.
     *
     * @param list<string> $allowlist
     */
    public static function reason(array $allowlist, string $ip): string
    {
        return sprintf(
            'Адрес %s не входит в список разрешённых для этой комнаты (правил в списке: %d)',
            mb_substr($ip, 0, 64),
            count($allowlist),
        );
    }

    /**
     * Reads a `TEXT[]` column into a list.
     *
     * PDO/pgsql hands back the Postgres literal (`{10.0.0.0/8,203.0.113.7}`), not
     * an array, and a quoted entry would arrive with its quotes intact. The query
     * asks for `to_json(...)` instead where it can, but this accepts both shapes so
     * a caller never has to remember which one it used.
     *
     * @return list<string>
     */
    public static function fromColumn(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn ($entry): string => (string) $entry, $value));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $value = trim($value);

        if ($value[0] === '[') {
            $decoded = json_decode($value, true);

            return is_array($decoded)
                ? array_values(array_map(static fn ($entry): string => (string) $entry, $decoded))
                : [];
        }

        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $inner) as $entry) {
            $out[] = trim(trim($entry), '"');
        }

        return $out;
    }

    /**
     * Keeps only the first `$prefix` bits of an address.
     */
    private static function maskAddress(string $packed, int $prefix): string
    {
        $bytes = intdiv($prefix, 8);
        $rest = $prefix % 8;

        $masked = substr($packed, 0, $bytes);

        if ($bytes < strlen($packed)) {
            $byte = ord($packed[$bytes]);
            $byte &= 0xFF << (8 - $rest) & 0xFF;
            $masked .= chr($byte & 0xFF);
            $masked .= str_repeat("\x00", strlen($packed) - $bytes - 1);
        }

        return $masked;
    }

    private static function canonical(string $packed, int $prefix): string
    {
        $address = (string) inet_ntop($packed);

        return strlen($packed) === 4 ? $address . '/' . $prefix : $address . '/' . $prefix;
    }
}
