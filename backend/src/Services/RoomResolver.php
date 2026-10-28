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

    /** @var array<string, int>|null */
    private static ?array $slugs = null;

    /**
     * @return array{id: int, slug: string}|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $ids = self::slugs();
        if (!isset($ids[$slug])) {
            return null;
        }

        return ['id' => $ids[$slug], 'slug' => $slug];
    }

    /**
     * @return array{id: int, slug: string}|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT id, slug FROM rooms WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'slug' => (string) $row['slug']];
    }

    /**
     * @return array{id: int, slug: string}|null
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
     * @return array<string, int>
     */
    private static function slugs(): array
    {
        if (self::$slugs !== null) {
            return self::$slugs;
        }

        self::$slugs = [];
        foreach (Database::pdo()->query('SELECT id, slug FROM rooms') as $row) {
            self::$slugs[strtolower((string) $row['slug'])] = (int) $row['id'];
        }

        return self::$slugs;
    }
}
