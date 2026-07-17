<?php

namespace app\services;

require_once __DIR__ . '/BuilderSchemaService.php';

final class BuilderQueryDefinitionNormalizerService
{
    public static function normalize(array $definition): array
    {
        if (($definition['schemaIdentity'] ?? null) !== 'ldlite') {
            return $definition;
        }

        return self::normalizeWithCatalog(
            $definition,
            BuilderSchemaService::physicalToLegacyMap(),
            BuilderSchemaService::catalog()
        );
    }

    public static function normalizeWithCatalog(
        array $definition,
        array $physicalToLegacyMap,
        array $catalog
    ): array {
        if (($definition['schemaIdentity'] ?? null) !== 'ldlite') {
            return $definition;
        }

        $canonicalTables = array_values($definition['tables'] ?? []);
        $normalized = $definition;
        $normalized['tables'] = array_map(
            function ($table) use ($physicalToLegacyMap): string {
                return self::normalizeTable((string)$table, $physicalToLegacyMap);
            },
            $canonicalTables
        );

        foreach (['columns', 'filters', 'groupBy', 'having', 'orderBy'] as $property) {
            if (!isset($normalized[$property]) || !is_array($normalized[$property])) {
                continue;
            }
            foreach ($normalized[$property] as &$entry) {
                if (is_array($entry) && isset($entry['table'])) {
                    $entry['table'] = self::normalizeTable(
                        (string)$entry['table'],
                        $physicalToLegacyMap
                    );
                }
            }
            unset($entry);
        }

        if (isset($normalized['joins']) && is_array($normalized['joins'])) {
            $normalizedJoins = [];
            foreach ($normalized['joins'] as $join) {
                $relationshipId = is_array($join)
                    ? (string)($join['relationship_id'] ?? '')
                    : '';
                $relationship = $catalog['relationships_by_id'][$relationshipId] ?? null;
                if (!is_array($relationship)) {
                    throw new \InvalidArgumentException(
                        "Unknown Builder relationship: {$relationshipId}"
                    );
                }

                $fromTable = (string)($relationship['from_table'] ?? '');
                $toTable = (string)($relationship['to_table'] ?? '');
                if (!in_array($fromTable, $canonicalTables, true)
                    || !in_array($toTable, $canonicalTables, true)) {
                    throw new \InvalidArgumentException(
                        "Both endpoints for Builder relationship {$relationshipId} must be included in tables."
                    );
                }

                $requestedJoinType = strtoupper(trim((string)($join['join_type'] ?? 'JOIN')));
                $normalizedJoins[] = [
                    'from_table' => self::normalizeTable($fromTable, $physicalToLegacyMap),
                    'from_column' => (string)($relationship['from_column'] ?? ''),
                    'to_table' => self::normalizeTable($toTable, $physicalToLegacyMap),
                    'to_column' => (string)($relationship['to_column'] ?? ''),
                    'join_type' => $requestedJoinType === 'LEFT JOIN' ? 'LEFT JOIN' : 'JOIN',
                ];
            }
            $normalized['joins'] = $normalizedJoins;
        }

        unset($normalized['schemaIdentity']);
        return $normalized;
    }

    private static function normalizeTable(string $table, array $physicalToLegacyMap): string
    {
        return (string)($physicalToLegacyMap[$table] ?? $table);
    }
}
