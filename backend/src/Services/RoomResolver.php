<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Support\IpAllowlist;

/**
 * Resolves the room a public webhook path points at.
 *
 * The router cannot tell /hook/{room} from /hook/{endpoint} (both are one path
 * segment), so resolution happens here: a known room slug wins, anything else is
 * treated as an endpoint of the default room.
 *
 * The cached rows carry the delivery settings as well as the secret, because the
 * catcher needs all of them before it writes anything: the secret to verify the
 * signature, `require_timestamp` to decide how, `dedup_header` to build the
 * idempotency key, and `ip_allowlist` to decide whether the sender may deliver at
 * all. Loading them separately would be a second query per delivery.
 */
final class RoomResolver
{
    public const DEFAULT_SLUG = 'default';

    /**
     * @var array<string, array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}>|null
     */
    private static ?array $slugs = null;

    /**
     * @return array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        return self::slugs()[$slug] ?? null;
    }

    /**
     * @return array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, slug, endpoint, secret, dedup_header, require_timestamp,
                    to_json(ip_allowlist) AS ip_allowlist,
                    (secret IS NOT NULL) AS has_secret
               FROM rooms WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return self::row($row);
    }

    /**
     * @return array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}|null
     */
    public static function default(): ?array
    {
        return self::findBySlug(self::DEFAULT_SLUG);
    }

    public static function forget(): void
    {
        self::$slugs = null;
    }

    /**
     * @return array<string, array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}>
     */
    private static function slugs(): array
    {
        if (self::$slugs !== null) {
            return self::$slugs;
        }

        self::$slugs = [];
        $rows = Database::pdo()->query(
            'SELECT id, name, slug, endpoint, secret, dedup_header, require_timestamp,
                    to_json(ip_allowlist) AS ip_allowlist,
                    (secret IS NOT NULL) AS has_secret
               FROM rooms'
        );
        foreach ($rows as $row) {
            self::$slugs[strtolower((string) $row['slug'])] = self::row($row);
        }

        return self::$slugs;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, slug: string, endpoint: string, secret: ?string, has_secret: bool, dedup_header: ?string, require_timestamp: bool, ip_allowlist: list<string>}
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) $row['slug'],
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'secret' => $row['secret'] === null ? null : (string) $row['secret'],
            'has_secret' => $row['secret'] !== null,
            'dedup_header' => $row['dedup_header'] === null ? null : (string) $row['dedup_header'],
            'require_timestamp' => self::isTrue($row['require_timestamp'] ?? false),
            'ip_allowlist' => IpAllowlist::fromColumn($row['ip_allowlist'] ?? null),
        ];
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true'], true);
    }
}
