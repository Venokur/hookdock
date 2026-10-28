<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PDO/pgsql returns jsonb columns as JSON strings. The SPA expects real arrays/objects,
 * so decode them at the API boundary.
 */
final class JsonColumns
{
    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function decode(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                continue;
            }

            $decoded = json_decode($row[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$field] = $decoded;
            }
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public static function decodeMany(array $rows, array $fields): array
    {
        return array_map(static fn (array $row): array => self::decode($row, $fields), $rows);
    }
}
