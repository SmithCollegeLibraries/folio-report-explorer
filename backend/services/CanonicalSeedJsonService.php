<?php

namespace app\services;

/** Normalizes report seed JSON as returned by supported database drivers. */
final class CanonicalSeedJsonService
{
    public static function normalize($value): ?array
    {
        if (is_string($value)) {
            $value = self::decode($value);
            if (is_string($value)) {
                $value = self::decode($value);
            }
        }

        return is_array($value) ? self::normalizeArray($value) : null;
    }

    public static function matchesFingerprint($value, string $expectedHash): bool
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return false;
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && hash_equals($expectedHash, hash('sha256', $encoded));
    }

    private static function decode(string $json)
    {
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function normalizeArray(array $value): array
    {
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? self::normalizeArray($item) : $item;
        }
        return $value;
    }
}
