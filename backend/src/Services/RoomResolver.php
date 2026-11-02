<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * Resolves the room a public webhook path points at.
 *
 * The router cannot tell /hook/{room} from /hook/{endpoint} (both are one path
 * segment), so resolution happens here: a known room slug wins, anything else is
 * treated as an endpoint of the default room.
 *
 * The cached rows carry the delivery settings as well as the secret, because the
 * catcher needs all three before it writes anything: the secret to verify the
 * signature, `require_timestamp` to decide how, and `dedup_header` to build the
 * idempotency key. Loading them separately would be a second query per delivery.
 */
final class RoomResolver
{
    public const DEFAULT_SLUG = 'default';

    /**
     * @var array<string, array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}>|null
     */
    private static ?array $slugs = null;

    /**
     * @return array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}|null
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
     * @return array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, slug, secret, dedup_header, require_timestamp FROM rooms WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return self::row($row);
    }

    /**
     * @return array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}|null
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
     * @return array<string, array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}>
     */
    private static function slugs(): array
    {
        if (self::$slugs !== null) {
            return self::$slugs;
        }

        self::$slugs = [];
        foreach (Database::pdo()->query('SELECT id, slug, secret, dedup_header, require_timestamp FROM rooms') as $row) {
            self::$slugs[strtolower((string) $row['slug'])] = self::row($row);
        }

        return self::$slugs;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, slug: string, secret: ?string, dedup_header: ?string, require_timestamp: bool}
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'secret' => $row['secret'] === null ? null : (string) $row['secret'],
            'dedup_header' => $row['dedup_header'] === null ? null : (string) $row['dedup_header'],
            'require_timestamp' => self::isTrue($row['require_timestamp'] ?? false),
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
