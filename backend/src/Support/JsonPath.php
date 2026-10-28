<?php

declare(strict_types=1);

namespace App\Support;

final class JsonPath
{
    /**
     * Resolve a dot path such as `data.user.id` or `items[0].sku` against the webhook context.
     *
     * Sources may be addressed explicitly with a prefix: body, query, headers, meta, transformed, raw.
     * Without a prefix the lookup order is body -> transformed -> query -> headers -> meta.
     *
     * @param array<string, mixed> $context
     * @return array{found: bool, value: mixed}
     */
    public static function resolve(array $context, string $path): array
    {
        $path = trim($path);
        if ($path === '' || $path === '$') {
            return ['found' => true, 'value' => $context];
        }

        $path = ltrim($path, '$');
        $path = ltrim($path, '.');
        $path = preg_replace('/\[(\d+)\]/', '.$1', $path) ?? $path;

        $segments = explode('.', $path);
        $sources = ['body', 'query', 'headers', 'meta', 'transformed', 'raw'];

        $first = $segments[0] ?? '';
        if (in_array($first, $sources, true)) {
            $value = $context[$first] ?? null;
            $rest = array_slice($segments, 1);

            return $rest === []
                ? ['found' => array_key_exists($first, $context), 'value' => $value]
                : self::walk($value, $rest);
        }

        foreach (['body', 'transformed', 'query', 'headers', 'meta'] as $source) {
            $result = self::walk($context[$source] ?? null, $segments);
            if ($result['found']) {
                return $result;
            }
        }

        return ['found' => false, 'value' => null];
    }

    /**
     * @param list<string> $segments
     * @return array{found: bool, value: mixed}
     */
    private static function walk(mixed $value, array $segments): array
    {
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $value];
    }
}
