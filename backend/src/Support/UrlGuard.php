<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

/**
 * Guard for URLs that the `forward` action is allowed to call.
 *
 * The router runs inside the private Docker network, so an unrestricted forward is
 * a server-side request forgery primitive: anyone who can create a workflow could
 * point it at `http://api:9000`, at the database, or at the cloud metadata service
 * (`169.254.169.254`) and read the answer out of the run log.
 *
 * Loopback, link-local (which covers metadata), multicast and the unspecified
 * address are refused. RFC1918 and IPv6 ULA stay allowed on purpose: forwarding to
 * an internal service (`http://web/...`) is the main use case of the product.
 *
 * Addresses are compared as packed bytes (`inet_pton`), never as text. A textual
 * check is trivially bypassed: `::1`, `0:0:0:0:0:0:0:1`, `::ffff:7f00:1` and
 * `::ffff:127.0.0.1` are four spellings of loopback, and `ip6-localhost` reaches
 * it through `/etc/hosts` without any DNS record at all.
 */
final class UrlGuard
{
    /**
     * @return array{allowed: bool, reason: ?string, addresses: list<string>}
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return self::deny('некорректный URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::deny('разрешены только http и https');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === '') {
            return self::deny('некорректный URL');
        }

        if (in_array($host, self::blockedHosts(), true)) {
            return self::deny('адрес в списке запрещённых');
        }

        if (self::allowAll()) {
            return ['allowed' => true, 'reason' => null, 'addresses' => []];
        }

        // A literal IP needs no resolution; a name does, because
        // `http://169.254.169.254.nip.io` would otherwise slip through.
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isLiteral) {
            if (self::isForbidden($host)) {
                return self::deny('внутренний адрес запрещён');
            }

            return ['allowed' => true, 'reason' => null, 'addresses' => [$host]];
        }

        $addresses = self::resolve($host);

        // Fail closed. "Does not resolve, let the request fail on its own" is not a
        // guard: the name is resolved again at connect time, so a name that only
        // exists in `/etc/hosts` (or appears later) would sail past this check.
        if ($addresses === []) {
            return self::deny('хост не резолвится');
        }

        foreach ($addresses as $address) {
            if (self::isForbidden($address)) {
                return self::deny('внутренний адрес запрещён');
            }
        }

        return ['allowed' => true, 'reason' => null, 'addresses' => $addresses];
    }

    /**
     * @return array{allowed: false, reason: string, addresses: list<string>}
     */
    private static function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'addresses' => []];
    }

    /**
     * `FORWARD_ALLOW_INTERNAL=1` restores the old behaviour for a trusted
     * installation that genuinely needs to call loopback.
     */
    private static function allowAll(): bool
    {
        return Config::get('FORWARD_ALLOW_INTERNAL', '0') === '1';
    }

    /**
     * @return list<string>
     */
    private static function blockedHosts(): array
    {
        $raw = (string) Config::get('FORWARD_BLOCKED_HOSTS', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * Every address a name points at, over both families.
     *
     * `gethostbynamel()` is IPv4-only and goes through NSS, so it is the only one
     * that sees `/etc/hosts` (Docker puts service names and `ip6-localhost` there).
     * `dns_get_record()` adds AAAA, which gethostbynamel cannot return at all. The
     * union is what matters: a guard that checks only IPv4 misses an AAAA that the
     * client will happily connect to.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = [];

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[$address] = true;
            }
        }

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($host, $type);
            if (!is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                $address = (string) ($record[$key] ?? '');
                if ($address !== '') {
                    $addresses[$address] = true;
                }
            }
        }

        return array_keys($addresses);
    }

    /**
     * Whether a single address is off limits. Accepts any accepted spelling and
     * compares packed bytes.
     */
    public static function isForbidden(string $address): bool
    {
        // A zone id (`fe80::1%eth0`) is not part of the address itself.
        $address = (string) preg_replace('/%.*$/', '', trim($address, '[]'));

        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::forbiddenV4($packed);
        }

        // IPv4-mapped (`::ffff:a.b.c.d`) and the deprecated IPv4-compatible form
        // both tunnel an IPv4 address in the last four bytes; unwrap and re-check,
        // so `::ffff:7f00:1` cannot be a hideout for 127.0.0.1.
        $mapped = self::unwrapV4($packed);
        if ($mapped !== null) {
            return self::forbiddenV4($mapped);
        }

        return self::inV6($packed, '::1')
            || self::inV6($packed, '::')
            || self::inV6Range($packed, 'fe80::', 10)
            || self::inV6Range($packed, 'ff00::', 8);
    }

    /**
     * @param non-empty-string $packed 4 bytes
     */
    private static function forbiddenV4(string $packed): bool
    {
        // 0.0.0.0/8: "this network", includes the unspecified address.
        // 127.0.0.0/8: loopback, the service and its sidecars.
        // 169.254.0.0/16: link-local, where cloud metadata lives.
        // 224.0.0.0/4 and 255.255.255.255: multicast and broadcast.
        return self::inV4Range($packed, '0.0.0.0', 8)
            || self::inV4Range($packed, '127.0.0.0', 8)
            || self::inV4Range($packed, '169.254.0.0', 16)
            || self::inV4Range($packed, '224.0.0.0', 4)
            || $packed === "\xff\xff\xff\xff";
    }

    /**
     * @param non-empty-string $packed 16 bytes
     * @return non-empty-string|null the embedded IPv4 address, if any
     */
    private static function unwrapV4(string $packed): ?string
    {
        // ::ffff:0:0/96 and ::/96 both put the IPv4 address in the last four bytes.
        $firstTwelve = substr($packed, 0, 12);

        if ($firstTwelve === str_repeat("\0", 10) . "\xff\xff" || $firstTwelve === str_repeat("\0", 12)) {
            $last = substr($packed, 12, 4);

            // `::` and `::1` are handled as IPv6 before this is consulted.
            return $last === "\0\0\0\0" ? null : $last;
        }

        return null;
    }

    private static function inV4Range(string $packed, string $network, int $bits): bool
    {
        $base = @inet_pton($network);
        if ($base === false) {
            return false;
        }

        return self::samePrefix($packed, $base, $bits);
    }

    private static function inV6(string $packed, string $network): bool
    {
        $base = @inet_pton($network);

        return $base !== false && $packed === $base;
    }

    private static function inV6Range(string $packed, string $network, int $bits): bool
    {
        $base = @inet_pton($network);
        if ($base === false) {
            return false;
        }

        return self::samePrefix($packed, $base, $bits);
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
