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
 */
final class RoomResolver
{
    public const DEFAULT_SLUG = 'default';

    /** @var array<string, array{id: int, slug: string, secret: ?string}>|null */
    private static ?array $slugs = null;

    /**
     * @return array{id: int, slug: string, secret: ?string}|null
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
     * @return array{id: int, slug: string, secret: ?string}|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT id, slug, secret FROM rooms WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'secret' => $row['secret'] === null ? null : (string) $row['secret'],
        ];
    }

    /**
     * @return array{id: int, slug: string, secret: ?string}|null
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
     * @return array<string, array{id: int, slug: string, secret: ?string}>
     */
    private static function slugs(): array
    {
        if (self::$slugs !== null) {
            return self::$slugs;
        }

        self::$slugs = [];
        foreach (Database::pdo()->query('SELECT id, slug, secret FROM rooms') as $row) {
            self::$slugs[strtolower((string) $row['slug'])] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'secret' => $row['secret'] === null ? null : (string) $row['secret'],
            ];
        }

        return self::$slugs;
    }
}
