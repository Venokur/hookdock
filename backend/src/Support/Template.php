<?php

declare(strict_types=1);

namespace App\Support;

final class Template
{
    /**
     * Replace `{{ body.data.id }}` style placeholders with values from the webhook context.
     *
     * @param array<string, mixed> $context
     */
    public static function render(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', static function (array $matches) use ($context): string {
            $result = JsonPath::resolve($context, $matches[1]);

            return $result['found'] ? self::stringify($result['value']) : '';
        }, $template) ?? $template;
    }

    public static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
