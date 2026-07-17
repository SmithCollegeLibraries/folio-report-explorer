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

        if (!isset($definition['tables']) || !is_array($definition['tables'])) {
            throw new \InvalidArgumentException('Canonical tables must be an array.');
        }

        $canonicalTables = array_values($definition['tables']);
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

        $joinInputExists = array_key_exists('joins', $definition);
        $joinInput = $joinInputExists ? $definition['joins'] : 'auto';
        $useDefaults = !$joinInputExists || $joinInput === 'auto' || $joinInput === [];
        if (!$useDefaults && !is_array($joinInput)) {
            throw new \InvalidArgumentException(
                'Canonical joins must be "auto", an empty array, or an array of relationship_id selections.'
            );
        }

        $relationships = $useDefaults
            ? self::defaultRelationships($catalog, $canonicalTables)
            : self::selectedRelationships($joinInput, $catalog, $canonicalTables);
        $normalized['joins'] = self::orientRelationships(
            $relationships,
            $canonicalTables,
            $physicalToLegacyMap
        );

        unset($normalized['schemaIdentity']);
        return $normalized;
    }

    private static function normalizeTable(string $table, array $physicalToLegacyMap): string
    {
        return (string)($physicalToLegacyMap[$table] ?? $table);
    }

    private static function selectedRelationships(
        array $joins,
        array $catalog,
        array $canonicalTables
    ): array {
        $relationships = [];
        foreach ($joins as $join) {
            if (!is_array($join) || !isset($join['relationship_id'])
                || !is_string($join['relationship_id']) || $join['relationship_id'] === '') {
                throw new \InvalidArgumentException(
                    'Each canonical join must contain a relationship_id.'
                );
            }

            $relationshipId = $join['relationship_id'];
            $relationship = $catalog['relationships_by_id'][$relationshipId] ?? null;
            if (!is_array($relationship)) {
                throw new \InvalidArgumentException(
                    "Unknown Builder relationship: {$relationshipId}"
                );
            }
            self::assertSelectedEndpoints($relationship, $relationshipId, $canonicalTables);
            $relationship['join_type'] = strtoupper(trim((string)($join['join_type'] ?? 'JOIN')))
                === 'LEFT JOIN' ? 'LEFT JOIN' : 'JOIN';
            $relationships[] = $relationship;
        }
        return $relationships;
    }

    private static function defaultRelationships(array $catalog, array $canonicalTables): array
    {
        if (count($canonicalTables) <= 1) {
            return [];
        }

        $relationshipsByPair = [];
        foreach (($catalog['relationships_by_id'] ?? []) as $relationshipId => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $fromTable = (string)($relationship['from_table'] ?? '');
            $toTable = (string)($relationship['to_table'] ?? '');
            if (!in_array($fromTable, $canonicalTables, true)
                || !in_array($toTable, $canonicalTables, true)) {
                continue;
            }
            $pairId = (string)($relationship['pair_id'] ?? self::pairId($fromTable, $toTable));
            $relationship['relationship_id'] = (string)($relationship['relationship_id'] ?? $relationshipId);
            $relationshipsByPair[$pairId][] = $relationship;
        }

        $defaults = [];
        foreach ($relationshipsByPair as $pairId => $relationships) {
            usort($relationships, function (array $left, array $right): int {
                if (!empty($left['is_default']) !== !empty($right['is_default'])) {
                    return !empty($left['is_default']) ? -1 : 1;
                }
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
            $defaultId = $catalog['defaults_by_pair'][$pairId] ?? null;
            $selected = null;
            foreach ($relationships as $relationship) {
                if ($defaultId !== null && $relationship['relationship_id'] === $defaultId) {
                    $selected = $relationship;
                    break;
                }
            }
            $selected = $selected ?? $relationships[0];
            $selected['join_type'] = 'JOIN';
            $defaults[] = $selected;
        }

        return $defaults;
    }

    private static function orientRelationships(
        array $relationships,
        array $canonicalTables,
        array $physicalToLegacyMap
    ): array {
        if (count($canonicalTables) <= 1) {
            return [];
        }

        $joined = [$canonicalTables[0] => true];
        $remaining = array_values($relationships);
        $normalized = [];

        while (count($joined) < count(array_unique($canonicalTables))) {
            $progress = false;
            foreach ($remaining as $index => $relationship) {
                $fromTable = (string)($relationship['from_table'] ?? '');
                $toTable = (string)($relationship['to_table'] ?? '');
                $fromJoined = isset($joined[$fromTable]);
                $toJoined = isset($joined[$toTable]);
                if ($fromJoined === $toJoined) {
                    continue;
                }

                if ($fromJoined) {
                    $oriented = [
                        'from_table' => $fromTable,
                        'from_column' => (string)($relationship['from_column'] ?? ''),
                        'to_table' => $toTable,
                        'to_column' => (string)($relationship['to_column'] ?? ''),
                    ];
                    $joined[$toTable] = true;
                } else {
                    $oriented = [
                        'from_table' => $toTable,
                        'from_column' => (string)($relationship['to_column'] ?? ''),
                        'to_table' => $fromTable,
                        'to_column' => (string)($relationship['from_column'] ?? ''),
                    ];
                    $joined[$fromTable] = true;
                }

                $oriented['from_table'] = self::normalizeTable(
                    $oriented['from_table'],
                    $physicalToLegacyMap
                );
                $oriented['to_table'] = self::normalizeTable(
                    $oriented['to_table'],
                    $physicalToLegacyMap
                );
                $oriented['join_type'] = ($relationship['join_type'] ?? 'JOIN') === 'LEFT JOIN'
                    ? 'LEFT JOIN' : 'JOIN';
                $normalized[] = $oriented;
                unset($remaining[$index]);
                $progress = true;
            }

            if (!$progress) {
                throw new \InvalidArgumentException(
                    'Cannot resolve reviewed Builder relationships for all selected canonical tables.'
                );
            }
        }

        return $normalized;
    }

    private static function assertSelectedEndpoints(
        array $relationship,
        string $relationshipId,
        array $canonicalTables
    ): void {
        $fromTable = (string)($relationship['from_table'] ?? '');
        $toTable = (string)($relationship['to_table'] ?? '');
        if (!in_array($fromTable, $canonicalTables, true)
            || !in_array($toTable, $canonicalTables, true)) {
            throw new \InvalidArgumentException(
                "Both endpoints for Builder relationship {$relationshipId} must be included in tables."
            );
        }
    }

    private static function pairId(string $leftTable, string $rightTable): string
    {
        $tables = [$leftTable, $rightTable];
        sort($tables, SORT_STRING);
        return $tables[0] . '<->' . $tables[1];
    }
}
