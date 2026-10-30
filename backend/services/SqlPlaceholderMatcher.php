<?php

namespace app\services;

final class SqlPlaceholderMatcher
{
    public static function containsPlaceholder(string $sql, string $key): bool
    {
        $name = isset($key[0]) && $key[0] === ':' ? substr($key, 1) : $key;
        if ($name === '') {
            return false;
        }

        $pattern = '/(?<!:):' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/';
        return preg_match($pattern, $sql) === 1;
    }
}
