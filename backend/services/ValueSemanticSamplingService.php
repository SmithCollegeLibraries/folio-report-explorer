<?php

namespace app\services;

/**
 * Applies deterministic prompt-facing budgets to sampled semantic values.
 */
class ValueSemanticSamplingService
{
    const DEFAULT_PROMPT_VALUE_LIMIT = 8;
    const HIGH_VALUE_LOOKUP_PROMPT_VALUE_LIMIT = 20;

    private const HIGH_VALUE_LOOKUP_COLUMNS = [
        'inventory.call_number_type__t.name' => true,
        'inventory.holdings_type__t.name' => true,
        'inventory.instance_format__t.code' => true,
        'inventory.instance_format__t.name' => true,
        'inventory.instance_status__t.code' => true,
        'inventory.instance_status__t.name' => true,
        'inventory.instance_type__t.code' => true,
        'inventory.instance_type__t.name' => true,
        'inventory.loan_type__t.name' => true,
        'inventory.location__t.code' => true,
        'inventory.location__t.name' => true,
        'inventory.loccampus__t.code' => true,
        'inventory.loccampus__t.name' => true,
        'inventory.locinstitution__t.code' => true,
        'inventory.locinstitution__t.name' => true,
        'inventory.loclibrary__t.code' => true,
        'inventory.loclibrary__t.name' => true,
        'inventory.material_type__t.name' => true,
        'users.groups__t.group' => true,
    ];

    public static function describeColumn(string $tableName, string $columnName, array $sampleValues): array
    {
        $normalizedValues = self::normalizeValues($sampleValues);
        $columnKey = self::buildColumnKey($tableName, $columnName);
        $isHighValueLookup = isset(self::HIGH_VALUE_LOOKUP_COLUMNS[$columnKey]);

        return [
            'kind' => $isHighValueLookup ? 'high_value_lookup' : 'compact',
            'promptValueLimit' => $isHighValueLookup
                ? self::HIGH_VALUE_LOOKUP_PROMPT_VALUE_LIMIT
                : self::DEFAULT_PROMPT_VALUE_LIMIT,
            'sampleValueCount' => count($normalizedValues),
        ];
    }

    public static function selectPromptValues(string $tableName, string $columnName, array $sampleValues): array
    {
        $normalizedValues = self::normalizeValues($sampleValues);
        $metadata = self::describeColumn($tableName, $columnName, $normalizedValues);

        return array_slice($normalizedValues, 0, (int)$metadata['promptValueLimit']);
    }

    private static function normalizeValues(array $sampleValues): array
    {
        $normalized = [];
        foreach ($sampleValues as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private static function buildColumnKey(string $tableName, string $columnName): string
    {
        return trim($tableName) . '.' . trim($columnName);
    }
}