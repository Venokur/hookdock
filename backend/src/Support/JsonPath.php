<?php

declare(strict_types=1);

namespace App\Support;

final class JsonPath
{
    /**
     * Context sources that a path may name explicitly.
     *
     * `extracted` holds the output of `extract` actions and `response` the last
     * HTTP answer a run received — both exist so a rule can pass a value from one
     * step to the next ("take the order id out of the API response, send it to
     * Slack") without a hand-written script.
     */
    public const SOURCES = ['body', 'query', 'headers', 'meta', 'transformed', 'raw', 'extracted', 'response'];

    /**
     * Implicit lookup order when a path has no source prefix.
     *
     * `extracted` comes first on purpose: an operator who explicitly extracted a
     * field is asking for *that* value, not for whatever the raw body happens to
     * contain under the same name. It is safe to put it at the front because the
     * source is new — no stored rule can already be addressing it.
     *
     * Everything after it keeps the order that existed before (`body`,
     * `transformed`, `query`, `headers`, `meta`): moving `transformed` ahead of
     * `body` would silently change which value an existing rule reads on a
     * delivery that has both, and `response` is appended rather than inserted for
     * the same reason.
     */
    private const LOOKUP_ORDER = ['extracted', 'body', 'transformed', 'query', 'headers', 'meta', 'response'];

    /**
     * Resolve a dot path such as `data.user.id` or `items[0].sku` against the webhook context.
     *
     * Sources may be addressed explicitly with a prefix: body, query, headers, meta, transformed,
     * raw, extracted, response. Without a prefix the lookup order is
     * extracted -> transformed -> body -> query -> headers -> meta -> response.
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

        $segments = self::segments($path);

        $first = $segments[0] ?? '';
        if (in_array($first, self::SOURCES, true)) {
            $value = $context[$first] ?? null;
            $rest = array_slice($segments, 1);

            return $rest === []
                ? ['found' => array_key_exists($first, $context), 'value' => $value]
                : self::walk($value, $rest);
        }

        foreach (self::LOOKUP_ORDER as $source) {
            $result = self::walk($context[$source] ?? null, $segments);
            if ($result['found']) {
                return $result;
            }
        }

        return ['found' => false, 'value' => null];
    }

    /**
     * Resolve a path **relative to a value**, ignoring the context.
     *
     * This is what `extract` needs: its mapping is written against the chosen
     * source (`body`, `response`, …), so `event.type` must mean that field of the
     * source and not "the first place in the whole context where it appears". An
     * empty path (or `.` / `$`) returns the source itself, which is how a whole
     * payload or a whole response body is forwarded.
     *
     * @return array{found: bool, value: mixed}
     */
    public static function resolveWithin(mixed $root, string $path): array
    {
        $path = trim($path);
        if ($path === '' || $path === '.' || $path === '$') {
            return ['found' => true, 'value' => $root];
        }

        return self::walk($root, self::segments($path));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $path = ltrim($path, '$');
        $path = ltrim($path, '.');
        $path = preg_replace('/\[(\d+)\]/', '.$1', $path) ?? $path;

        return explode('.', $path);
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
