<?php

namespace app\services;

/**
 * Projects configured report identifiers and emits portable RFC-4180 CSV rows.
 */
final class FolioIdentifierCsvService
{
    /**
     * Return the configured row value only when it is a canonical UUID value.
     *
     * @param array $row
     * @param array $config
     * @return string|null
     */
    public static function project(array $row, array $config): ?string
    {
        $sourceColumn = $config['sourceColumn'] ?? null;
        if (!is_string($sourceColumn) || $sourceColumn === '' || !array_key_exists($sourceColumn, $row)) {
            return null;
        }

        $value = trim((string) $row[$sourceColumn]);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * Encode a CSV record without depending on PHP 8.1's fputcsv EOL argument.
     *
     * @param array $fields
     * @return string
     */
    public static function encodeRow(array $fields): string
    {
        $encoded = array_map(function ($value) {
            $value = (string) $value;
            if (strpbrk($value, ",\"\r\n") !== false) {
                return '"' . str_replace('"', '""', $value) . '"';
            }
            return $value;
        }, $fields);

        return implode(',', $encoded) . "\r\n";
    }
}
