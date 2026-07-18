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
        self::assertNecessaryRelationshipTree($relationships, $canonicalTables);
        $oriented = self::orientRelationships(
            $relationships,
            $canonicalTables,
            $physicalToLegacyMap
        );
        $internalTables = $canonicalTables;
        foreach ($oriented['intermediary_tables'] as $table) {
            if (!in_array($table, $internalTables, true)) {
                $internalTables[] = $table;
            }
        }
        $normalized['tables'] = array_map(
            function ($table) use ($physicalToLegacyMap): string {
                return self::normalizeTable((string)$table, $physicalToLegacyMap);
            },
            $internalTables
        );
        $normalized['joins'] = $oriented['joins'];

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
            $relationship['relationship_id'] = (string)($relationship['relationship_id'] ?? $relationshipId);
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
            if ($fromTable === '' || $toTable === '') {
                continue;
            }
            $pairId = (string)($relationship['pair_id'] ?? self::pairId($fromTable, $toTable));
            $relationship['relationship_id'] = (string)($relationship['relationship_id'] ?? $relationshipId);
            $relationshipsByPair[$pairId][] = $relationship;
        }

        $defaultsById = [];
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
            $defaultsById[$selected['relationship_id']] = $selected;
        }

        $adjacency = self::buildAdjacency($defaultsById);
        $treeNodes = [(string)$canonicalTables[0] => true];
        $treeRelationships = [];
        foreach (array_slice($canonicalTables, 1) as $target) {
            $target = (string)$target;
            if (isset($treeNodes[$target])) {
                continue;
            }
            $path = self::findPathToTree($target, $treeNodes, $adjacency);
            if ($path === null) {
                throw new \InvalidArgumentException(
                    'Cannot resolve reviewed Builder relationships for all selected canonical tables.'
                );
            }
            foreach ($path as $relationship) {
                $relationshipId = (string)$relationship['relationship_id'];
                $treeRelationships[$relationshipId] = $relationship;
                $treeNodes[(string)$relationship['from_table']] = true;
                $treeNodes[(string)$relationship['to_table']] = true;
            }
        }

        return array_values($treeRelationships);
    }

    private static function orientRelationships(
        array $relationships,
        array $canonicalTables,
        array $physicalToLegacyMap
    ): array {
        if (count($canonicalTables) <= 1) {
            return ['joins' => [], 'intermediary_tables' => []];
        }

        $joined = [$canonicalTables[0] => true];
        $remaining = array_values($relationships);
        usort($remaining, function (array $left, array $right): int {
            return strcmp(
                (string)($left['relationship_id'] ?? ''),
                (string)($right['relationship_id'] ?? '')
            );
        });
        $normalized = [];
        $intermediaryTables = [];

        while (!empty($remaining)) {
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
                    $newTable = $toTable;
                } else {
                    $oriented = [
                        'from_table' => $toTable,
                        'from_column' => (string)($relationship['to_column'] ?? ''),
                        'to_table' => $fromTable,
                        'to_column' => (string)($relationship['from_column'] ?? ''),
                    ];
                    $joined[$fromTable] = true;
                    $newTable = $fromTable;
                }

                if (!in_array($newTable, $canonicalTables, true)
                    && !in_array($newTable, $intermediaryTables, true)) {
                    $intermediaryTables[] = $newTable;
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

        return ['joins' => $normalized, 'intermediary_tables' => $intermediaryTables];
    }

    private static function assertNecessaryRelationshipTree(
        array $relationships,
        array $canonicalTables
    ): void {
        $selectedTables = array_values(array_unique(array_map('strval', $canonicalTables)));
        if (count($selectedTables) <= 1) {
            if (!empty($relationships)) {
                throw new \InvalidArgumentException(
                    'Single-table canonical definitions cannot include relationships.'
                );
            }
            return;
        }

        $adjacency = [];
        $nodes = [];
        foreach ($relationships as $relationship) {
            $fromTable = (string)($relationship['from_table'] ?? '');
            $toTable = (string)($relationship['to_table'] ?? '');
            if ($fromTable === '' || $toTable === '') {
                throw new \InvalidArgumentException(
                    'Reviewed Builder relationships must contain catalog table endpoints.'
                );
            }
            $nodes[$fromTable] = true;
            $nodes[$toTable] = true;
            $adjacency[$fromTable][] = $toTable;
            $adjacency[$toTable][] = $fromTable;
        }

        foreach ($selectedTables as $table) {
            if (!isset($nodes[$table])) {
                throw new \InvalidArgumentException(
                    'Cannot resolve reviewed Builder relationships for all selected canonical tables.'
                );
            }
        }

        $visited = [];
        $queue = [$selectedTables[0]];
        while (!empty($queue)) {
            $table = array_shift($queue);
            if (isset($visited[$table])) {
                continue;
            }
            $visited[$table] = true;
            foreach (($adjacency[$table] ?? []) as $neighbour) {
                if (!isset($visited[$neighbour])) {
                    $queue[] = $neighbour;
                }
            }
        }
        if (count($visited) !== count($nodes)
            || count($relationships) !== count($nodes) - 1) {
            throw new \InvalidArgumentException(
                'Cannot resolve reviewed Builder relationships as one necessary connected path tree.'
            );
        }

        $selectedLookup = array_fill_keys($selectedTables, true);
        foreach ($nodes as $table => $_unused) {
            if (!isset($selectedLookup[$table]) && count($adjacency[$table] ?? []) < 2) {
                throw new \InvalidArgumentException(
                    "Unselected Builder relationship endpoint {$table} is not necessary to connect selected tables."
                );
            }
        }
    }

    private static function buildAdjacency(array $relationshipsById): array
    {
        $adjacency = [];
        foreach ($relationshipsById as $relationship) {
            $fromTable = (string)$relationship['from_table'];
            $toTable = (string)$relationship['to_table'];
            $adjacency[$fromTable][] = ['table' => $toTable, 'relationship' => $relationship];
            $adjacency[$toTable][] = ['table' => $fromTable, 'relationship' => $relationship];
        }
        foreach ($adjacency as &$edges) {
            usort($edges, function (array $left, array $right): int {
                $tableComparison = strcmp($left['table'], $right['table']);
                return $tableComparison !== 0 ? $tableComparison : strcmp(
                    $left['relationship']['relationship_id'],
                    $right['relationship']['relationship_id']
                );
            });
        }
        unset($edges);
        return $adjacency;
    }

    private static function findPathToTree(
        string $target,
        array $treeNodes,
        array $adjacency
    ): ?array {
        $queue = [[$target, []]];
        $visited = [$target => true];
        while (!empty($queue)) {
            list($table, $path) = array_shift($queue);
            foreach (($adjacency[$table] ?? []) as $edge) {
                $neighbour = $edge['table'];
                if (isset($visited[$neighbour])) {
                    continue;
                }
                $nextPath = array_merge($path, [$edge['relationship']]);
                if (isset($treeNodes[$neighbour])) {
                    return $nextPath;
                }
                $visited[$neighbour] = true;
                $queue[] = [$neighbour, $nextPath];
            }
        }
        return null;
    }

    private static function pairId(string $leftTable, string $rightTable): string
    {
        $tables = [$leftTable, $rightTable];
        sort($tables, SORT_STRING);
        return $tables[0] . '<->' . $tables[1];
    }
}
