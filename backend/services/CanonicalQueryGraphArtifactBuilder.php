<?php

namespace app\services;

class CanonicalQueryGraphArtifactBuilder
{
    const ARTIFACT_VERSION = 2;
    const FOCUS_SLICE_KEY = 'deterministic_query_family_core';

    public static function build(
        array $schemaTables,
        array $relationships,
        array $tableMapping,
        array $subtables,
        array $semanticContext = [],
        ?string $generatedAt = null
    ): array {
        $semanticTables = is_array($semanticContext['tables'] ?? null) ? $semanticContext['tables'] : [];

        $entities = [
            'circulation_loans' => [
                'contractKey' => 'circulation_loans',
                'sqlTable' => 'circulation.loan__t',
                'entityKind' => 'base',
            ],
            'inventory_campuses' => [
                'contractKey' => 'inventory_campuses',
                'sqlTable' => 'inventory.loccampus__t',
                'entityKind' => 'base',
            ],
            'inventory_contributor_name_types' => [
                'contractKey' => 'inventory_contributor_name_types',
                'sqlTable' => 'inventory.contributor_name_type__t',
                'entityKind' => 'lookup',
            ],
            'inventory_holdings' => [
                'contractKey' => 'inventory_holdings',
                'sqlTable' => 'inventory.holdings_record__t',
                'entityKind' => 'base',
            ],
            'inventory_instance__t__contributors' => [
                'contractKey' => 'inventory_instance__t__contributors',
                'sqlTable' => 'inventory.instance__t__contributors',
                'entityKind' => 'subtable',
                'parentContractKey' => 'inventory_instances',
            ],
            'inventory_instance__t__publication' => [
                'contractKey' => 'inventory_instance__t__publication',
                'sqlTable' => 'inventory.instance__t__publication',
                'entityKind' => 'subtable',
                'parentContractKey' => 'inventory_instances',
            ],
            'inventory_instances' => [
                'contractKey' => 'inventory_instances',
                'sqlTable' => 'inventory.instance__t',
                'entityKind' => 'base',
            ],
            'inventory_items' => [
                'contractKey' => 'inventory_items',
                'sqlTable' => 'inventory.item__t',
                'entityKind' => 'base',
            ],
            'inventory_libraries' => [
                'contractKey' => 'inventory_libraries',
                'sqlTable' => 'inventory.loclibrary__t',
                'entityKind' => 'base',
            ],
            'inventory_locations' => [
                'contractKey' => 'inventory_locations',
                'sqlTable' => 'inventory.location__t',
                'entityKind' => 'base',
            ],
            'inventory_material_types' => [
                'contractKey' => 'inventory_material_types',
                'sqlTable' => 'inventory.material_type__t',
                'entityKind' => 'lookup',
            ],
        ];

        $contractKeyToSqlTable = [];
        $sqlTableToContractKey = [];
        foreach ($entities as $contractKey => $entity) {
            $contractKeyToSqlTable[$contractKey] = $entity['sqlTable'];
            $sqlTableToContractKey[$entity['sqlTable']] = $contractKey;
        }

        ksort($contractKeyToSqlTable, SORT_STRING);
        ksort($sqlTableToContractKey, SORT_STRING);

        $edges = [
            [
                'key' => 'circulation_loans.item_effective_location_id_at_check_out->inventory_locations.id',
                'from' => 'circulation_loans',
                'to' => 'inventory_locations',
                'localColumn' => 'item_effective_location_id_at_check_out',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'loan_checkout_location',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'exact',
            ],
            [
                'key' => 'circulation_loans.item_id->inventory_items.id',
                'from' => 'circulation_loans',
                'to' => 'inventory_items',
                'localColumn' => 'item_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'loan_to_item',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'exact',
            ],
            [
                'key' => 'inventory_instance__t__contributors.contributors__contributor_name_type_id->inventory_contributor_name_types.id',
                'from' => 'inventory_instance__t__contributors',
                'to' => 'inventory_contributor_name_types',
                'localColumn' => 'contributors__contributor_name_type_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'contributor_name_type_lookup',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'assumed_compatible',
            ],
            [
                'key' => 'inventory_holdings.instance_id->inventory_instances.id',
                'from' => 'inventory_holdings',
                'to' => 'inventory_instances',
                'localColumn' => 'instance_id',
                'targetColumn' => 'id',
                'edgeKind' => 'foreign_key',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'holdings_to_instance',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'assumed_compatible',
            ],
            [
                'key' => 'inventory_items.holdings_record_id->inventory_holdings.id',
                'from' => 'inventory_items',
                'to' => 'inventory_holdings',
                'localColumn' => 'holdings_record_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'item_to_holdings',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'cast_required',
            ],
            [
                'key' => 'inventory_items.effective_location_id->inventory_locations.id',
                'from' => 'inventory_items',
                'to' => 'inventory_locations',
                'localColumn' => 'effective_location_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'item_effective_location',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'cast_required',
            ],
            [
                'key' => 'inventory_locations.library_id->inventory_libraries.id',
                'from' => 'inventory_locations',
                'to' => 'inventory_libraries',
                'localColumn' => 'library_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'location_to_library',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'assumed_compatible',
            ],
            [
                'key' => 'inventory_libraries.campus_id->inventory_campuses.id',
                'from' => 'inventory_libraries',
                'to' => 'inventory_campuses',
                'localColumn' => 'campus_id',
                'targetColumn' => 'id',
                'edgeKind' => 'explicit_override',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'library_to_campus',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'assumed_compatible',
            ],
            [
                'key' => 'inventory_items.material_type_id->inventory_material_types.id',
                'from' => 'inventory_items',
                'to' => 'inventory_material_types',
                'localColumn' => 'material_type_id',
                'targetColumn' => 'id',
                'edgeKind' => 'foreign_key',
                'joinCardinality' => 'many_to_one',
                'semanticRole' => 'item_to_material_type',
                'confidence' => 'high',
                'supportsDeterministicCompilation' => true,
                'source' => 'query_family_slice',
                'typeCompatibility' => 'assumed_compatible',
            ],
        ];

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
}