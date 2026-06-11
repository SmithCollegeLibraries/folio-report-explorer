<?php

namespace app\services;

/**
 * Shared text normalization for local FOLIO reference matching.
 */
class ReferenceTextNormalizerService
{
    private const CAMPUS_PREFIX_PATTERN = '/^(?:sc|ac|hc|mh|um|rp|yb)\s+/';

    public static function normalize(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', (string)$normalized);
        return trim((string)preg_replace('/\s+/', ' ', (string)$normalized));
    }

    public static function normalizeWithoutCampusPrefix(string $text): string
    {
        $normalized = self::normalize($text);
        $normalized = preg_replace(self::CAMPUS_PREFIX_PATTERN, '', $normalized, 1);
        return trim((string)$normalized);
    }

    /**
     * @return array<int, string>
     */
    public static function tokens(string $text, int $minimumLength = 2): array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return [];
        }

        $tokens = array_values(array_unique(array_filter(explode(' ', $normalized), function ($token) use ($minimumLength) {
            return strlen((string)$token) >= $minimumLength;
        })));
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    public static function key(string $text): string
    {
        $normalized = self::normalize($text);
        $normalized = preg_replace('/\s+/', '_', $normalized);
        return trim((string)$normalized, '_');
    }
}
