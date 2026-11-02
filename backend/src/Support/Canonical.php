<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Order-independent representation of a decoded `jsonb` value.
 *
 * `jsonb` does not preserve object key order, so two saves of the same action list
 * can decode to arrays that compare unequal with `!==` while carrying identical
 * data. Sorting keys recursively gives a string that is equal exactly when the data
 * is — which is what "did this save change anything" needs to answer.
 */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return (string) json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }

        return $value;
    }
}
