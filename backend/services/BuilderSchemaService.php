<?php

namespace app\services;

require_once __DIR__ . '/BuilderRelationshipCatalogService.php';
require_once __DIR__ . '/FolioSchemaService.php';

final class BuilderSchemaService
{
    /** @var array|null Request-local canonical relationship catalog. */
    private static $catalog = null;

    public static function getTables(?array $filter = null): array
    {
        $legacyTables = FolioSchemaService::getTables();
        foreach ($legacyTables as $legacyName => $summary) {
            $physicalName = (string)($summary['sql_name'] ?? $legacyName);
            $isLocal = ($summary['domain'] ?? null) === 'local' || ($summary['source'] ?? null) === 'local';
            if (!$isLocal && strpos($physicalName, '.') === false && $physicalName === $legacyName) {
                \Yii::warning(
                    'Omitting unverified Builder table mapping for ' . $legacyName . '.',
                    'builder.schema'
                );
            }
        }

        return self::projectTables($legacyTables, $filter);
    }

    public static function getTable(string $physicalName): ?array
    {
        $resolvedPhysicalName = self::physicalNameFor($physicalName);
        if ($resolvedPhysicalName === null) {
            return null;
        }

        $legacyName = self::legacyNameFor($resolvedPhysicalName) ?? $resolvedPhysicalName;
        $legacyDetail = FolioSchemaService::getTable($legacyName);
        if ($legacyDetail === null) {
            return null;
        }

        return self::projectTable($legacyDetail, self::catalog());
    }

    public static function findShortestPath(string $from, string $to): ?array
    {
        $from = self::physicalNameFor($from);
        $to = self::physicalNameFor($to);
        if ($from === null || $to === null) {
            return null;
        }
        if ($from === $to) {
            return self::formatPath([], $from);
        }

        $catalog = self::catalog();
        $adjacency = self::buildAdjacency($catalog);
        $queue = [[$from, [], [$from]]];
        $visited = [$from => true];

        while (!empty($queue)) {
            list($current, $path, $chain) = array_shift($queue);
            foreach (($adjacency[$current] ?? []) as $edge) {
                $neighbour = $edge['neighbour'];
                if (isset($visited[$neighbour])) {
                    continue;
                }
                $newPath = array_merge($path, [$edge['relationship']]);
                $newChain = array_merge($chain, [$neighbour]);
                if ($neighbour === $to) {
                    return self::formatPath($newPath, $from, $newChain);
                }
                $visited[$neighbour] = true;
                $queue[] = [$neighbour, $newPath, $newChain];
            }
        }

        return null;
    }

    public static function findAllPaths(string $from, string $to, int $maxDepth = 6): array
    {
        $from = self::physicalNameFor($from);
        $to = self::physicalNameFor($to);
        if ($from === null || $to === null) {
            return [];
        }

        return self::findAllPathsInCatalog(self::catalog(), $from, $to, $maxDepth);
    }

    public static function findAllPathsInCatalog(
        array $catalog,
        string $from,
        string $to,
        int $maxDepth = 6
    ): array {
        if ($from === $to || $maxDepth < 1) {
            return [];
        }

        $adjacency = self::buildAdjacency($catalog);
        if (!isset($adjacency[$from]) || !isset($adjacency[$to])) {
            return [];
        }

        $paths = [];
        self::findPaths(
            $adjacency,
            $from,
            $to,
            $maxDepth,
            [],
            [$from],
            [$from => true],
            $paths
        );

        usort($paths, function (array $left, array $right): int {
            if ($left['hops'] !== $right['hops']) {
                return $left['hops'] < $right['hops'] ? -1 : 1;
            }
            foreach ($left['joins'] as $index => $leftJoin) {
                $rightJoin = $right['joins'][$index];
                if ($leftJoin['is_default'] !== $rightJoin['is_default']) {
                    return $leftJoin['is_default'] ? -1 : 1;
                }
                $comparison = strcmp($leftJoin['relationship_id'], $rightJoin['relationship_id']);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });

        return $paths;
    }

    public static function getRelationship(string $relationshipId): ?array
    {
        $catalog = self::catalog();
        return $catalog['relationships_by_id'][$relationshipId] ?? null;
    }

    public static function chooseDefaultRelationshipId(array $catalog, string $pairId): ?string
    {
        if (isset($catalog['defaults_by_pair'][$pairId])) {
            return (string)$catalog['defaults_by_pair'][$pairId];
        }

        $candidateIds = [];
        foreach (($catalog['relationships_by_id'] ?? []) as $relationship) {
            if (($relationship['pair_id'] ?? null) !== $pairId) {
                continue;
            }
            if (!empty($relationship['is_default'])) {
                return (string)$relationship['relationship_id'];
            }
            $candidateIds[] = (string)$relationship['relationship_id'];
        }
        sort($candidateIds, SORT_STRING);
        return $candidateIds[0] ?? null;
    }

    public static function legacyNameFor(string $physicalName): ?string
    {
        $mapping = self::physicalToLegacyMap();
        return $mapping[$physicalName] ?? null;
    }

    public static function physicalNameFor(string $input): ?string
    {
        $inputs = FolioSchemaService::getBuilderSchemaInputs();
        $mapping = $inputs['mapping'];
        if (isset($mapping[$input])) {
            return (string)$mapping[$input];
        }
        if (in_array($input, $mapping, true)) {
            return $input;
        }

        $tables = self::projectTables(FolioSchemaService::getTables(), [$input]);
        return isset($tables[$input]) ? $input : null;
    }

    public static function physicalToLegacyMap(): array
    {
        $inputs = FolioSchemaService::getBuilderSchemaInputs();
        $mapping = $inputs['mapping'];
        ksort($mapping, SORT_STRING);
        $result = [];
        foreach ($mapping as $legacyName => $physicalName) {
            if (!isset($result[$physicalName]) || $legacyName !== $physicalName) {
                $result[$physicalName] = $legacyName;
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $inputs = FolioSchemaService::getBuilderSchemaInputs();
        $overlay = BuilderRelationshipCatalogService::loadOverlay(
            \Yii::$app->params['builderRelationshipOverlayPath']
        );
        self::$catalog = BuilderRelationshipCatalogService::build(
            $inputs['legacy_relationships'],
            $inputs['mapping'],
            $inputs['columns_by_physical_table'],
            $overlay
        );
        foreach ((self::$catalog['warnings'] ?? []) as $warning) {
            \Yii::warning($warning, 'builder.relationship_catalog');
        }

        return self::$catalog;
    }

    public static function projectTables(array $legacyTables, ?array $filter): array
    {
        $result = [];
        foreach ($legacyTables as $legacyName => $summary) {
            $physicalName = (string)($summary['sql_name'] ?? $legacyName);
            $isLocal = ($summary['domain'] ?? null) === 'local' || ($summary['source'] ?? null) === 'local';
            $isPhysical = strpos($physicalName, '.') !== false;
            $isMapped = $physicalName !== $legacyName;
            if (!$isLocal && !$isPhysical && !$isMapped) {
                continue;
            }
            if ($filter !== null && !in_array($physicalName, $filter, true)) {
                continue;
            }
            $summary['name'] = $physicalName;
            $summary['sql_name'] = $physicalName;
            $summary['alias_name'] = $physicalName === $legacyName ? null : $legacyName;
            $result[$physicalName] = $summary;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public static function projectTable(array $legacyDetail, array $catalog): array
    {
        $legacyName = (string)($legacyDetail['name'] ?? '');
        $physicalName = (string)($legacyDetail['sql_name'] ?? $legacyName);
        $aliasName = $physicalName === $legacyName
            ? ($legacyDetail['alias_name'] ?? null)
            : $legacyName;
        $legacyDetail['name'] = $physicalName;
        $legacyDetail['sql_name'] = $physicalName;
        $legacyDetail['alias_name'] = $aliasName;
        $legacyDetail['relationships'] = ['parents' => [], 'children' => []];

        foreach (($catalog['relationships_by_id'] ?? []) as $relationship) {
            if (($relationship['from_table'] ?? null) === $physicalName) {
                $legacyDetail['relationships']['parents'][] = self::projectParentRelationship($relationship);
            }
            if (($relationship['to_table'] ?? null) === $physicalName) {
                $legacyDetail['relationships']['children'][] = self::projectChildRelationship($relationship);
            }
        }

        foreach (['parents', 'children'] as $direction) {
            usort($legacyDetail['relationships'][$direction], function (array $left, array $right): int {
                if ($left['is_default'] !== $right['is_default']) {
                    return $left['is_default'] ? -1 : 1;
                }
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
        }

        return $legacyDetail;
    }

    private static function buildAdjacency(array $catalog): array
    {
        $adjacency = [];
        foreach (($catalog['relationships_by_id'] ?? []) as $relationship) {
            $from = $relationship['from_table'];
            $to = $relationship['to_table'];
            $defaultId = self::chooseDefaultRelationshipId($catalog, $relationship['pair_id']);
            $relationship['is_default'] = $relationship['relationship_id'] === $defaultId;
            $adjacency[$from][] = ['neighbour' => $to, 'relationship' => $relationship];
            $adjacency[$to][] = ['neighbour' => $from, 'relationship' => $relationship];
        }

        foreach ($adjacency as &$edges) {
            usort($edges, function (array $left, array $right): int {
                $leftRelationship = $left['relationship'];
                $rightRelationship = $right['relationship'];
                if ($leftRelationship['is_default'] !== $rightRelationship['is_default']) {
                    return $leftRelationship['is_default'] ? -1 : 1;
                }
                return strcmp($leftRelationship['relationship_id'], $rightRelationship['relationship_id']);
            });
        }
        unset($edges);

        return $adjacency;
    }

    private static function findPaths(
        array $adjacency,
        string $current,
        string $target,
        int $maxDepth,
        array $relationships,
        array $chain,
        array $visited,
        array &$paths
    ): void {
        if (count($relationships) >= $maxDepth) {
            return;
        }

        foreach (($adjacency[$current] ?? []) as $edge) {
            $neighbour = $edge['neighbour'];
            if (isset($visited[$neighbour])) {
                continue;
            }

            $newRelationships = array_merge($relationships, [$edge['relationship']]);
            $newChain = array_merge($chain, [$neighbour]);
            if ($neighbour === $target) {
                $paths[] = self::formatPath($newRelationships, $chain[0], $newChain);
                continue;
            }

            $newVisited = $visited;
            $newVisited[$neighbour] = true;
            self::findPaths(
                $adjacency,
                $neighbour,
                $target,
                $maxDepth,
                $newRelationships,
                $newChain,
                $newVisited,
                $paths
            );
        }
    }

    private static function formatPath(array $relationships, string $start, ?array $chain = null): array
    {
        $chain = $chain ?? [$start];
        $joins = [];
        $sqlParts = [];
        foreach ($relationships as $index => $relationship) {
            $joins[] = array_merge($relationship, [
                'foreign_key' => $relationship['label'],
            ]);
            $joinedTable = $chain[$index + 1];
            $sqlParts[] = 'JOIN ' . $joinedTable
                . "\n  ON " . $relationship['to_table'] . '.' . $relationship['to_column']
                . ' = ' . $relationship['from_table'] . '.' . $relationship['from_column'];
        }

        return [
            'chain' => $chain,
            'hops' => count($relationships),
            'joins' => $joins,
            'sql_fragment' => implode("\n", $sqlParts),
        ];
    }

    private static function projectParentRelationship(array $relationship): array
    {
        return array_merge($relationship, [
            'parent_table' => $relationship['to_table'],
            'parent_column' => $relationship['to_column'],
            'local_column' => $relationship['from_column'],
            'foreign_key' => $relationship['label'],
        ]);
    }

    private static function projectChildRelationship(array $relationship): array
    {
        return array_merge($relationship, [
            'child_table' => $relationship['from_table'],
            'child_column' => $relationship['from_column'],
            'local_column' => $relationship['to_column'],
            'foreign_key' => $relationship['label'],
        ]);
    }
}
