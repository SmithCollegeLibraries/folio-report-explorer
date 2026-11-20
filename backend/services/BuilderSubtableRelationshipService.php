<?php

namespace app\services;

final class BuilderSubtableRelationshipService
{
    public static function columnsByTable(array $columnsByPhysicalTable, array $subtables): array
    {
        foreach ($subtables as $table => $info) {
            $columnsByPhysicalTable[$table] = $info['columns'] ?? [];
        }

        return $columnsByPhysicalTable;
    }

    public static function build(array $subtables, array $columnsByPhysicalTable): array
    {
        $columnsByPhysicalTable = self::columnsByTable($columnsByPhysicalTable, $subtables);
        $relationships = [];

        foreach ($subtables as $table => $info) {
            $parent = $info['parent'] ?? null;
            if (!is_string($parent)
                || !self::hasColumn($columnsByPhysicalTable, $table, 'id')
                || !self::hasColumn($columnsByPhysicalTable, $parent, 'id')) {
                continue;
            }

            $relationships[] = [
                'from_table' => $table,
                'from_column' => 'id',
                'to_table' => $parent,
                'to_column' => 'id',
                'label' => 'Parent record',
                'default_requested' => false,
                'source' => 'metadb',
            ];

            $arrayPrefix = $parent . '__';
            $arrayName = strpos($table, $arrayPrefix) === 0
                ? substr($table, strlen($arrayPrefix))
                : '';
            $schema = strpos($table, '.') !== false ? explode('.', $table, 2)[0] : null;
            if ($schema === null) {
                continue;
            }

            foreach (($info['columns'] ?? []) as $column) {
                $columnName = $column['name'] ?? null;
                $arrayColumnPrefix = $arrayName . '__';
                if (!is_string($columnName)
                    || $arrayName === ''
                    || strpos($columnName, $arrayColumnPrefix) !== 0
                    || substr($columnName, -3) !== '_id') {
                    continue;
                }

                $targetName = substr($columnName, strlen($arrayColumnPrefix), -3);
                if ($targetName === '') {
                    continue;
                }

                $target = $schema . '.' . $targetName . '__t';
                if (!self::hasColumn($columnsByPhysicalTable, $target, 'id')) {
                    continue;
                }

                $relationships[] = [
                    'from_table' => $table,
                    'from_column' => $columnName,
                    'to_table' => $target,
                    'to_column' => 'id',
                    'label' => 'Generated relationship',
                    'default_requested' => false,
                    'source' => 'metadb',
                ];
            }
        }

        return $relationships;
    }

    public static function appendToAdjacency(
        array $adjacency,
        array $subtables,
        array $columnsByPhysicalTable,
        array $tableMapping
    ): array
    {
        $legacyNamesByPhysicalTable = [];
        ksort($tableMapping, SORT_STRING);
        foreach ($tableMapping as $legacyName => $physicalName) {
            if (!isset($legacyNamesByPhysicalTable[$physicalName]) || $legacyName !== $physicalName) {
                $legacyNamesByPhysicalTable[$physicalName] = $legacyName;
            }
        }

        foreach (self::build($subtables, $columnsByPhysicalTable) as $relationship) {
            $fromTable = $legacyNamesByPhysicalTable[$relationship['from_table']]
                ?? $relationship['from_table'];
            $fromColumn = $relationship['from_column'];
            $toTable = $legacyNamesByPhysicalTable[$relationship['to_table']]
                ?? $relationship['to_table'];
            $toColumn = $relationship['to_column'];
            $label = $relationship['label'];

            $adjacency[$fromTable][] = [$fromTable, $fromColumn, $toTable, $toColumn, $label];
            $adjacency[$toTable][] = [$toTable, $toColumn, $fromTable, $fromColumn, $label];
        }

        return $adjacency;
    }

    private static function hasColumn(array $columnsByPhysicalTable, string $table, string $column): bool
    {
        foreach (($columnsByPhysicalTable[$table] ?? []) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
