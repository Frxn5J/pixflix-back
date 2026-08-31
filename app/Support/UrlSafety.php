<?php

namespace App\Support;

final class UrlSafety
{
    public static function http(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    /** @return array<int, string> */
    public static function httpList(mixed $values): array
    {
        return array_values(array_filter(array_map(
            self::http(...),
            is_array($values) ? $values : [],
        )));
    }
}
