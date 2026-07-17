<?php

namespace app\services;

final class BuilderRelationshipCatalogService
{
    public static function relationshipId(string $fromTable, string $fromColumn, string $toTable, string $toColumn): string
    {
        return $fromTable . '.' . $fromColumn . '->' . $toTable . '.' . $toColumn;
    }

    public static function pairId(string $leftTable, string $rightTable): string
    {
        $tables = [$leftTable, $rightTable];
        sort($tables, SORT_STRING);
        return $tables[0] . '<->' . $tables[1];
    }

    public static function loadOverlay(string $path): array
    {
        if (!is_file($path)) {
            return ['version' => 1, 'relationships' => []];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : ['version' => 1, 'relationships' => []];
    }

    public static function build(array $legacyRelationships, array $mapping, array $columnsByTable, array $overlay): array
    {
        $relationshipsById = [];
        $warnings = [];

        foreach ($legacyRelationships as $legacyFrom => $relationshipSet) {
            $fromTable = $mapping[$legacyFrom] ?? (isset($columnsByTable[$legacyFrom]) ? $legacyFrom : null);
            foreach (($relationshipSet['parents'] ?? []) as $parent) {
                $legacyTo = $parent['parent_table'] ?? '';
                $toTable = $mapping[$legacyTo] ?? (isset($columnsByTable[$legacyTo]) ? $legacyTo : null);
                self::mergeCandidate($relationshipsById, $warnings, $columnsByTable, [
                    'from_table' => $fromTable,
                    'from_column' => $parent['local_column'] ?? null,
                    'to_table' => $toTable,
                    'to_column' => $parent['parent_column'] ?? null,
                    'label' => $parent['foreign_key'] ?? 'Generated relationship',
                    'default_requested' => false,
                    'source' => 'metadb',
                ]);
            }
        }

        foreach (($overlay['relationships'] ?? []) as $entry) {
            self::mergeCandidate($relationshipsById, $warnings, $columnsByTable, [
                'from_table' => $entry['fromTable'] ?? null,
                'from_column' => $entry['fromColumn'] ?? null,
                'to_table' => $entry['toTable'] ?? null,
                'to_column' => $entry['toColumn'] ?? null,
                'label' => $entry['label'] ?? 'Reviewed relationship',
                'default_requested' => !empty($entry['default']),
                'source' => 'overlay',
            ]);
        }

        return self::finalizeCatalog($relationshipsById, $warnings);
    }

    private static function mergeCandidate(array &$relationshipsById, array &$warnings, array $columnsByTable, array $candidate): void
    {
        $fromTable = $candidate['from_table'];
        $fromColumn = $candidate['from_column'];
        $toTable = $candidate['to_table'];
        $toColumn = $candidate['to_column'];

        if (!is_string($fromTable) || !is_string($fromColumn) || !is_string($toTable) || !is_string($toColumn)) {
            $warnings[] = 'Relationship is missing a physical table or column endpoint.';
            return;
        }
        if (!self::hasColumn($columnsByTable, $fromTable, $fromColumn)) {
            $warnings[] = $fromTable . '.' . $fromColumn . ' does not exist.';
            return;
        }
        if (!self::hasColumn($columnsByTable, $toTable, $toColumn)) {
            $warnings[] = $toTable . '.' . $toColumn . ' does not exist.';
            return;
        }

        $relationshipId = self::relationshipId($fromTable, $fromColumn, $toTable, $toColumn);
        $relationship = [
            'relationship_id' => $relationshipId,
            'pair_id' => self::pairId($fromTable, $toTable),
            'from_table' => $fromTable,
            'from_column' => $fromColumn,
            'to_table' => $toTable,
            'to_column' => $toColumn,
            'label' => (string)$candidate['label'],
            'default_requested' => !empty($candidate['default_requested']),
            'is_default' => false,
            'source' => (string)$candidate['source'],
        ];

        if (!isset($relationshipsById[$relationshipId]) || $relationship['source'] === 'overlay') {
            $relationshipsById[$relationshipId] = $relationship;
        }
    }

    private static function finalizeCatalog(array $relationshipsById, array $warnings): array
    {
        ksort($relationshipsById, SORT_STRING);
        $relationshipsByPair = [];
        foreach ($relationshipsById as $relationship) {
            $relationshipsByPair[$relationship['pair_id']][] = $relationship;
        }

        $defaultsByPair = [];
        foreach ($relationshipsByPair as $pairId => &$relationships) {
            usort($relationships, function (array $left, array $right): int {
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
            $requested = array_values(array_filter($relationships, function (array $relationship): bool {
                return $relationship['default_requested'] === true;
            }));
            if (count($requested) > 1) {
                $warnings[] = $pairId . ' declares more than one default relationship.';
            }
            $defaultId = !empty($requested)
                ? $requested[0]['relationship_id']
                : $relationships[0]['relationship_id'];
            $defaultsByPair[$pairId] = $defaultId;

            foreach ($relationships as &$relationship) {
                $relationship['is_default'] = $relationship['relationship_id'] === $defaultId;
                unset($relationship['default_requested']);
                $relationshipsById[$relationship['relationship_id']] = $relationship;
            }
            unset($relationship);

            usort($relationships, function (array $left, array $right): int {
                if ($left['is_default'] !== $right['is_default']) {
                    return $left['is_default'] ? -1 : 1;
                }
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
        }
        unset($relationships);

        ksort($relationshipsByPair, SORT_STRING);
        ksort($defaultsByPair, SORT_STRING);
        return [
            'relationships_by_id' => $relationshipsById,
            'relationships_by_pair' => $relationshipsByPair,
            'defaults_by_pair' => $defaultsByPair,
            'warnings' => $warnings,
        ];
    }

    private static function hasColumn(array $columnsByTable, string $table, string $column): bool
    {
        foreach (($columnsByTable[$table] ?? []) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }
}
