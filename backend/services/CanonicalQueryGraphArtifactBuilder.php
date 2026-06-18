<?php

namespace app\services;

/**
 * Builds the initial canonical query graph artifact for the highest-risk
 * inventory contributor/campus/holdings/item barcode path.
 */
class CanonicalQueryGraphArtifactBuilder
{
    const ARTIFACT_VERSION = 2;

    const FOCUS_SLICE_KEY = 'inventory_contributor_campus_holdings_item_barcode';

    private const FOCUS_SQL_TABLES = [
        'inventory.loccampus__t' => [
            'entityKind' => 'base',
            'grain' => 'campus',
            'canonicalLabel' => 'Inventory Campuses',
        ],
        'inventory.contributor_name_type__t' => [
            'entityKind' => 'lookup',
            'grain' => 'contributor_name_type',
            'canonicalLabel' => 'Inventory Contributor Name Types',
        ],
        'inventory.holdings_record__t' => [
            'entityKind' => 'base',
            'grain' => 'holdings',
            'canonicalLabel' => 'Inventory Holdings',
        ],
        'inventory.holdings_record__t__holdings_items' => [
            'entityKind' => 'subtable',
            'grain' => 'holdings_item_snapshot',
            'canonicalLabel' => 'Inventory Holdings Items',
        ],
        'inventory.instance__t' => [
            'entityKind' => 'base',
            'grain' => 'instance',
            'canonicalLabel' => 'Inventory Instances',
        ],
        'inventory.instance__t__contributors' => [
            'entityKind' => 'subtable',
            'grain' => 'instance_contributor',
            'canonicalLabel' => 'Inventory Instance Contributors',
        ],
        'inventory.item__t' => [
            'entityKind' => 'base',
            'grain' => 'item',
            'canonicalLabel' => 'Inventory Items',
        ],
        'inventory.loclibrary__t' => [
            'entityKind' => 'base',
            'grain' => 'library',
            'canonicalLabel' => 'Inventory Libraries',
        ],
        'inventory.location__t' => [
            'entityKind' => 'base',
            'grain' => 'location',
            'canonicalLabel' => 'Inventory Locations',
        ],
    ];

    private const EXPLICIT_EDGE_OVERRIDES = [
        [
            'from' => 'inventory_holdings',
            'to' => 'inventory_items',
            'localColumn' => 'id',
            'targetColumn' => 'holdings_record_id',
            'edgeKind' => 'explicit_override',
            'joinCardinality' => 'one_to_many',
            'semanticRole' => 'holdings_to_items',
            'confidence' => 'high',
            'source' => 'override_map',
            'typeCompatibility' => 'cast_required',
            'castRule' => [
                'strategy' => 'cast_local_uuid_to_text',
                'castSide' => 'local',
                'localType' => 'uuid',
                'targetType' => 'text',
                'comparisonExample' => 'hr.id::text = ii.holdings_record_id',
            ],
        ],
        [
            'from' => 'inventory_instance__t__contributors',
            'to' => 'inventory_contributor_name_types',
            'localColumn' => 'contributors__contributor_name_type_id',
            'targetColumn' => 'id',
            'edgeKind' => 'explicit_override',
            'joinCardinality' => 'many_to_one',
            'semanticRole' => 'contributor_name_type_lookup',
            'confidence' => 'high',
            'source' => 'override_map',
            'typeCompatibility' => 'assumed_compatible',
        ],
        [
            'from' => 'inventory_items',
            'to' => 'inventory_locations',
            'localColumn' => 'effective_location_id',
            'targetColumn' => 'id',
            'edgeKind' => 'explicit_override',
            'joinCardinality' => 'many_to_one',
            'semanticRole' => 'item_effective_location',
            'confidence' => 'high',
            'source' => 'override_map',
            'typeCompatibility' => 'cast_required',
            'castRule' => [
                'strategy' => 'cast_target_uuid_to_text',
                'castSide' => 'target',
                'localType' => 'text',
                'targetType' => 'uuid',
                'comparisonExample' => 'ii.effective_location_id = loc.id::text',
            ],
        ],
        [
            'from' => 'inventory_locations',
            'to' => 'inventory_libraries',
            'localColumn' => 'library_id',
            'targetColumn' => 'id',
            'edgeKind' => 'explicit_override',
            'joinCardinality' => 'many_to_one',
            'semanticRole' => 'location_to_library',
            'confidence' => 'high',
            'source' => 'override_map',
            'typeCompatibility' => 'assumed_compatible',
        ],
        [
            'from' => 'inventory_libraries',
            'to' => 'inventory_campuses',
            'localColumn' => 'campus_id',
            'targetColumn' => 'id',
            'edgeKind' => 'explicit_override',
            'joinCardinality' => 'many_to_one',
            'semanticRole' => 'library_to_campus',
            'confidence' => 'high',
            'source' => 'override_map',
            'typeCompatibility' => 'assumed_compatible',
        ],
    ];

    private const INFERENCE_STEM_SYNONYMS = [
        'holdings_record' => ['holdings'],
        'permanent_location' => ['location'],
        'temporary_location' => ['location'],
        'effective_location' => ['location'],
    ];

    /**
     * @param array $schemaTables folio_schema.json tables keyed by LDP1 table name
     * @param array $relationships folio_schema.json relationships keyed by LDP1 table name
     * @param array $tableMapping table_mapping_cache.json mapping keyed by LDP1 table name
     * @param array $subtables subtable_cache.json subtables keyed by schema-qualified SQL table name
     * @param array $semanticContext semantic_context artifact array
     * @param string|null $generatedAt
     * @return array
     */
    public static function build(
        array $schemaTables,
        array $relationships,
        array $tableMapping,
        array $subtables,
        array $semanticContext = [],
        ?string $generatedAt = null
    ): array {
        $reverseMapping = self::buildReverseMapping($tableMapping);
        $semanticTables = $semanticContext['tables'] ?? [];

        $entities = [];
        foreach (self::FOCUS_SQL_TABLES as $sqlTable => $definition) {
            $entity = self::buildEntity($sqlTable, $definition, $schemaTables, $tableMapping, $reverseMapping, $subtables, $semanticTables);
            if ($entity === null) {
                continue;
            }

            $entities[$entity['contractKey']] = $entity;
        }

        ksort($entities, SORT_STRING);

        $contractKeyToSqlTable = [];
        $sqlTableToContractKey = [];
        foreach ($entities as $contractKey => $entity) {
            $contractKeyToSqlTable[$contractKey] = $entity['sqlTable'];
            $sqlTableToContractKey[$entity['sqlTable']] = $contractKey;
        }

        ksort($contractKeyToSqlTable, SORT_STRING);
        ksort($sqlTableToContractKey, SORT_STRING);

        $edges = self::buildEdges($entities, $relationships, $tableMapping, $subtables, $reverseMapping);

        return [
            'metadata' => [
                'artifactVersion' => self::ARTIFACT_VERSION,
                'generatedAt' => $generatedAt ?: gmdate('c'),
                'focusSlice' => self::FOCUS_SLICE_KEY,
                'sourceCounts' => [
                    'schemaTables' => count($schemaTables),
                    'mappedTables' => count($tableMapping),
                    'subtables' => count($subtables),
                    'semanticTables' => count($semanticTables),
                    'focusEntities' => count($entities),
                    'focusEdges' => count($edges),
                ],
            ],
            'contractKeyToSqlTable' => $contractKeyToSqlTable,
            'sqlTableToContractKey' => $sqlTableToContractKey,
            'entities' => $entities,
            'edges' => $edges,
        ];
    }

    private static function buildEntity(
        string $sqlTable,
        array $definition,
        array $schemaTables,
        array $tableMapping,
        array $reverseMapping,
        array $subtables,
        array $semanticTables
    ): ?array {
        $contractKey = self::resolveContractKey($sqlTable, $schemaTables, $tableMapping, $reverseMapping);
        if ($contractKey === null) {
            return null;
        }

        $isSubtable = isset($subtables[$sqlTable]);
        $ldp1Table = $reverseMapping[$sqlTable] ?? null;
        $parentContractKey = null;
        $columns = [];

        if ($isSubtable) {
            $columns = self::normalizeColumns($subtables[$sqlTable]['columns'] ?? []);
            $parentSqlTable = (string)($subtables[$sqlTable]['parent'] ?? '');
            if ($parentSqlTable !== '') {
                $parentContractKey = self::resolveContractKey($parentSqlTable, $schemaTables, $tableMapping, $reverseMapping);
            }
        } elseif ($ldp1Table !== null && isset($schemaTables[$ldp1Table]['columns'])) {
            $columns = self::normalizeColumns($schemaTables[$ldp1Table]['columns']);
        } elseif (isset($schemaTables[$sqlTable]['columns'])) {
            $columns = self::normalizeColumns($schemaTables[$sqlTable]['columns']);
        }

        $entity = [
            'contractKey' => $contractKey,
            'sqlTable' => $sqlTable,
            'ldp1Table' => $ldp1Table,
            'entityKind' => (string)$definition['entityKind'],
            'grain' => (string)$definition['grain'],
            'canonicalLabel' => (string)$definition['canonicalLabel'],
            'columns' => $columns,
            'semanticHints' => self::normalizeSemanticHints($semanticTables[$sqlTable] ?? []),
        ];

        if ($parentContractKey !== null) {
            $entity['parentContractKey'] = $parentContractKey;
        }

        return $entity;
    }

    private static function buildEdges(
        array $entities,
        array $relationships,
        array $tableMapping,
        array $subtables,
        array $reverseMapping
    ): array {
        $edges = [];

        foreach ($entities as $contractKey => $entity) {
            if (($entity['entityKind'] ?? '') === 'subtable') {
                $sqlTable = $entity['sqlTable'] ?? '';
                $parentSqlTable = $subtables[$sqlTable]['parent'] ?? null;
                if (!is_string($parentSqlTable) || $parentSqlTable === '') {
                    continue;
                }

                $parentContractKey = self::resolveContractKey($parentSqlTable, [], $tableMapping, $reverseMapping);
                if ($parentContractKey === null || !isset($entities[$parentContractKey])) {
                    continue;
                }

                $edgeKey = $contractKey . '.id->' . $parentContractKey . '.id';
                $edges[$edgeKey] = self::normalizeEdge([
                    'key' => $edgeKey,
                    'from' => $contractKey,
                    'to' => $parentContractKey,
                    'localColumn' => 'id',
                    'targetColumn' => 'id',
                    'edgeKind' => 'subtable_parent',
                    'joinCardinality' => 'many_to_one',
                    'semanticRole' => 'subtable_to_parent',
                    'confidence' => 'high',
                    'source' => 'subtable_cache',
                ], $entities);

                continue;
            }

            $ldp1Table = $entity['ldp1Table'] ?? null;
            if (!is_string($ldp1Table) || $ldp1Table === '') {
                continue;
            }

            foreach (($relationships[$ldp1Table]['parents'] ?? []) as $parentRelationship) {
                $parentLdp1Table = (string)($parentRelationship['parent_table'] ?? '');
                if ($parentLdp1Table === '') {
                    continue;
                }

                $parentSqlTable = $tableMapping[$parentLdp1Table] ?? $parentLdp1Table;
                $parentContractKey = self::resolveContractKey($parentSqlTable, [], $tableMapping, $reverseMapping);
                if ($parentContractKey === null || !isset($entities[$parentContractKey])) {
                    continue;
                }

                $localColumn = trim((string)($parentRelationship['local_column'] ?? ''));
                $targetColumn = trim((string)($parentRelationship['parent_column'] ?? ''));
                if ($localColumn === '' || $targetColumn === '') {
                    continue;
                }

                $edgeKey = $contractKey . '.' . $localColumn . '->' . $parentContractKey . '.' . $targetColumn;
                $edges[$edgeKey] = self::normalizeEdge([
                    'key' => $edgeKey,
                    'from' => $contractKey,
                    'to' => $parentContractKey,
                    'localColumn' => $localColumn,
                    'targetColumn' => $targetColumn,
                    'edgeKind' => 'foreign_key',
                    'joinCardinality' => 'many_to_one',
                    'semanticRole' => 'foreign_key_reference',
                    'confidence' => 'high',
                    'source' => 'folio_schema',
                    'foreignKey' => trim((string)($parentRelationship['foreign_key'] ?? '')),
                ], $entities);
            }
        }

        foreach (self::EXPLICIT_EDGE_OVERRIDES as $override) {
            $edge = self::normalizeEdge($override, $entities);
            $edges[$edge['key']] = $edge;
        }

        foreach (self::buildConventionInferredEdges($entities, $edges) as $edge) {
            $edges[$edge['key']] = $edge;
        }

        ksort($edges, SORT_STRING);
        return array_values($edges);
    }

    private static function normalizeEdge(array $edge, array $entities): array
    {
        $from = trim((string)($edge['from'] ?? ''));
        $to = trim((string)($edge['to'] ?? ''));
        $localColumn = trim((string)($edge['localColumn'] ?? ''));
        $targetColumn = trim((string)($edge['targetColumn'] ?? ''));
        $key = trim((string)($edge['key'] ?? ''));

        if ($key === '' && $from !== '' && $to !== '' && $localColumn !== '' && $targetColumn !== '') {
            $key = $from . '.' . $localColumn . '->' . $to . '.' . $targetColumn;
        }

        $localType = self::findEntityColumnType($entities, $from, $localColumn);
        $targetType = self::findEntityColumnType($entities, $to, $targetColumn);
        $castRule = is_array($edge['castRule'] ?? null) ? $edge['castRule'] : null;
        if ($castRule !== null) {
            ksort($castRule, SORT_STRING);
        }

        $normalized = [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'localColumn' => $localColumn,
            'targetColumn' => $targetColumn,
            'edgeKind' => trim((string)($edge['edgeKind'] ?? 'foreign_key')),
            'joinCardinality' => trim((string)($edge['joinCardinality'] ?? 'many_to_one')),
            'semanticRole' => trim((string)($edge['semanticRole'] ?? 'reference')),
            'confidence' => trim((string)($edge['confidence'] ?? 'medium')),
            'supportsDeterministicCompilation' => trim((string)($edge['confidence'] ?? 'medium')) === 'high',
            'source' => trim((string)($edge['source'] ?? 'unknown')),
            'typeCompatibility' => trim((string)($edge['typeCompatibility'] ?? self::inferTypeCompatibility($localType, $targetType, $castRule))),
            'localType' => $localType,
            'targetType' => $targetType,
        ];

        if ($castRule !== null) {
            $normalized['castRule'] = $castRule;
        }

        $foreignKey = trim((string)($edge['foreignKey'] ?? ''));
        if ($foreignKey !== '') {
            $normalized['foreignKey'] = $foreignKey;
        }

        if (!empty($edge['inferenceBasis']) && is_array($edge['inferenceBasis'])) {
            $inferenceBasis = $edge['inferenceBasis'];
            ksort($inferenceBasis, SORT_STRING);
            $normalized['inferenceBasis'] = $inferenceBasis;
        }

        return $normalized;
    }

    private static function buildConventionInferredEdges(array $entities, array $existingEdges): array
    {
        $existingKeys = array_fill_keys(array_keys($existingEdges), true);
        $inferredEdges = [];

        foreach ($entities as $fromContractKey => $entity) {
            foreach (($entity['columns'] ?? []) as $column) {
                $columnName = trim((string)($column['name'] ?? ''));
                if ($columnName === '' || $columnName === 'id' || substr($columnName, -3) !== '_id') {
                    continue;
                }

                $targetContractKey = self::inferTargetContractKeyFromColumn($columnName, $fromContractKey, $entities);
                if ($targetContractKey === null || !isset($entities[$targetContractKey])) {
                    continue;
                }

                $edge = self::normalizeEdge([
                    'from' => $fromContractKey,
                    'to' => $targetContractKey,
                    'localColumn' => $columnName,
                    'targetColumn' => 'id',
                    'edgeKind' => 'inferred_convention',
                    'joinCardinality' => 'many_to_one',
                    'semanticRole' => self::buildInferredSemanticRole($fromContractKey, $targetContractKey),
                    'confidence' => 'medium',
                    'source' => 'naming_convention',
                    'inferenceBasis' => [
                        'columnSuffix' => $columnName,
                        'matchedStem' => self::extractInferenceStem($columnName),
                        'targetContractKey' => $targetContractKey,
                    ],
                ], $entities);

                if (isset($existingKeys[$edge['key']])) {
                    continue;
                }

                $existingKeys[$edge['key']] = true;
                $inferredEdges[$edge['key']] = $edge;
            }
        }

        ksort($inferredEdges, SORT_STRING);
        return array_values($inferredEdges);
    }

    private static function inferTargetContractKeyFromColumn(string $columnName, string $fromContractKey, array $entities): ?string
    {
        $stem = self::extractInferenceStem($columnName);
        if ($stem === '') {
            return null;
        }

        $candidateStems = [$stem];
        if (isset(self::INFERENCE_STEM_SYNONYMS[$stem])) {
            $candidateStems = array_merge($candidateStems, self::INFERENCE_STEM_SYNONYMS[$stem]);
        }

        $parts = explode('_', $stem);
        if (count($parts) > 1) {
            $candidateStems[] = end($parts);
        }

        $candidateStems = self::sortedUniqueStrings($candidateStems);

        $matches = [];
        foreach ($entities as $contractKey => $entity) {
            if ($contractKey === $fromContractKey) {
                continue;
            }

            $signatures = self::buildEntityInferenceSignatures($contractKey, $entity);
            foreach ($candidateStems as $candidateStem) {
                if (isset($signatures[$candidateStem])) {
                    $matches[$contractKey] = true;
                }
            }
        }

        $matchedContractKeys = array_keys($matches);
        sort($matchedContractKeys, SORT_STRING);

        return count($matchedContractKeys) === 1 ? $matchedContractKeys[0] : null;
    }

    private static function extractInferenceStem(string $columnName): string
    {
        $columnName = trim($columnName);
        if ($columnName === '' || substr($columnName, -3) !== '_id') {
            return '';
        }

        $parts = explode('__', $columnName);
        $leaf = trim((string)end($parts));
        if ($leaf === '') {
            return '';
        }

        return substr($leaf, 0, -3);
    }

    private static function buildEntityInferenceSignatures(string $contractKey, array $entity): array
    {
        $signatures = [];

        $grain = trim((string)($entity['grain'] ?? ''));
        if ($grain !== '') {
            $signatures[$grain] = true;
        }

        $tail = $contractKey;
        if (strpos($tail, 'inventory_') === 0) {
            $tail = substr($tail, strlen('inventory_'));
        }
        $signatures[$tail] = true;

        $singularTail = self::singularizePhrase($tail);
        if ($singularTail !== '') {
            $signatures[$singularTail] = true;
        }

        $singularGrain = self::singularizePhrase($grain);
        if ($singularGrain !== '') {
            $signatures[$singularGrain] = true;
        }

        return $signatures;
    }

    private static function singularizePhrase(string $phrase): string
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }

        $parts = explode('_', $phrase);
        $lastIndex = count($parts) - 1;
        $parts[$lastIndex] = self::singularizeWord($parts[$lastIndex]);

        return implode('_', $parts);
    }

    private static function singularizeWord(string $word): string
    {
        $word = trim($word);
        if ($word === '' || $word === 'holdings') {
            return $word;
        }

        if (substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }

        if (substr($word, -2) === 'es' && preg_match('/(ses|xes|zes|ches|shes)$/', $word) === 1) {
            return substr($word, 0, -2);
        }

        if (substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    private static function buildInferredSemanticRole(string $fromContractKey, string $toContractKey): string
    {
        $fromTail = strpos($fromContractKey, 'inventory_') === 0
            ? substr($fromContractKey, strlen('inventory_'))
            : $fromContractKey;
        $toTail = strpos($toContractKey, 'inventory_') === 0
            ? substr($toContractKey, strlen('inventory_'))
            : $toContractKey;

        return self::singularizePhrase($fromTail) . '_to_' . self::singularizePhrase($toTail);
    }

    private static function findEntityColumnType(array $entities, string $contractKey, string $columnName): ?string
    {
        if (!isset($entities[$contractKey]) || $columnName === '') {
            return null;
        }

        foreach (($entities[$contractKey]['columns'] ?? []) as $column) {
            if (($column['name'] ?? null) === $columnName) {
                $type = trim((string)($column['type'] ?? ''));
                return $type === '' ? null : $type;
            }
        }

        return null;
    }

    private static function inferTypeCompatibility(?string $localType, ?string $targetType, ?array $castRule): string
    {
        if ($castRule !== null) {
            return 'cast_required';
        }

        if ($localType === null || $targetType === null) {
            return 'unknown';
        }

        return $localType === $targetType ? 'exact' : 'assumed_compatible';
    }

    private static function normalizeColumns(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = trim((string)($column['name'] ?? ''));
            $type = trim((string)($column['type'] ?? ''));
            if ($name === '' || $type === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'type' => $type,
            ];
        }

        return $normalized;
    }

    private static function normalizeSemanticHints(array $semanticTable): array
    {
        $description = trim((string)($semanticTable['description'] ?? ''));
        $terms = self::sortedUniqueStrings($semanticTable['terms'] ?? []);
        $preferredApproach = self::sortedUniqueStrings($semanticTable['preferredApproach'] ?? []);
        $columnSemantics = [];

        foreach (($semanticTable['columnSemantics'] ?? []) as $columnName => $columnInfo) {
            $columnName = trim((string)$columnName);
            if ($columnName === '' || !is_array($columnInfo)) {
                continue;
            }

            $normalizedColumnInfo = [];
            foreach (['terms', 'warnings', 'sampleValues', 'derivedComments', 'derivedFrom'] as $field) {
                $values = self::sortedUniqueStrings($columnInfo[$field] ?? []);
                if (!empty($values)) {
                    $normalizedColumnInfo[$field] = $values;
                }
            }

            if (!empty($columnInfo['valueSemantics']) && is_array($columnInfo['valueSemantics'])) {
                $normalizedColumnInfo['valueSemantics'] = $columnInfo['valueSemantics'];
            }

            if (!empty($normalizedColumnInfo)) {
                ksort($normalizedColumnInfo, SORT_STRING);
                $columnSemantics[$columnName] = $normalizedColumnInfo;
            }
        }

        ksort($columnSemantics, SORT_STRING);

        return [
            'description' => $description,
            'terms' => $terms,
            'preferredApproach' => $preferredApproach,
            'columnSemantics' => $columnSemantics,
        ];
    }

    private static function buildReverseMapping(array $tableMapping): array
    {
        $reverseMapping = [];
        foreach ($tableMapping as $ldp1Table => $sqlTable) {
            $ldp1Table = trim((string)$ldp1Table);
            $sqlTable = trim((string)$sqlTable);
            if ($ldp1Table === '' || $sqlTable === '' || strpos($ldp1Table, '.') !== false) {
                continue;
            }

            if (!isset($reverseMapping[$sqlTable])) {
                $reverseMapping[$sqlTable] = $ldp1Table;
            }
        }

        return $reverseMapping;
    }

    private static function resolveContractKey(string $sqlTable, array $schemaTables, array $tableMapping, array $reverseMapping): ?string
    {
        $sqlTable = trim($sqlTable);
        if ($sqlTable === '') {
            return null;
        }

        if (isset($schemaTables[$sqlTable])) {
            return $sqlTable;
        }

        if (isset($reverseMapping[$sqlTable])) {
            return $reverseMapping[$sqlTable];
        }

        return strpos($sqlTable, '.') !== false ? str_replace('.', '_', $sqlTable) : $sqlTable;
    }

    private static function sortedUniqueStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }
}