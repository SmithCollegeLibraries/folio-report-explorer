<?php

$servicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "QueryFamilySchemaManifestService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

$serviceClass = 'app\\services\\QueryFamilySchemaManifestService';

if (!class_exists($serviceClass)) {
    fwrite(STDERR, "QueryFamilySchemaManifestService class was not loaded from {$servicePath}\n");
    exit(1);
}

function assertThrowsRuntimeException(callable $callback, string $expectedText, string $message): void
{
    try {
        $callback();
        fwrite(STDERR, $message . "\nExpected RuntimeException containing: {$expectedText}\n");
        exit(1);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), $expectedText) === false) {
            fwrite(STDERR, $message . "\nExpected text: {$expectedText}\nActual: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

$artifact = [
    'metadata' => [
        'artifactVersion' => 1,
    ],
    'families' => [
        'inventory_collection_age' => [
            'familyKey' => 'inventory_collection_age',
            'requiredEntities' => [
                'inventory_items',
                'inventory_holdings',
                'inventory_instances',
                'inventory_instance__t__publication',
                'inventory_locations',
                'inventory_libraries',
                'inventory_campuses',
            ],
            'requiredColumns' => [
                ['table' => 'inventory_items', 'column' => 'holdings_record_id', 'type' => 'uuid'],
                ['table' => 'inventory_items', 'column' => 'effective_location_id', 'type' => 'uuid'],
                ['table' => 'inventory_holdings', 'column' => 'instance_id', 'type' => 'uuid'],
                ['table' => 'inventory_instance__t__publication', 'column' => 'publication__date_of_publication', 'type' => 'text'],
                ['table' => 'inventory_locations', 'column' => 'library_id', 'type' => 'uuid'],
                ['table' => 'inventory_libraries', 'column' => 'campus_id', 'type' => 'uuid'],
                ['table' => 'inventory_campuses', 'column' => 'name', 'type' => 'text'],
            ],
            'requiredEdges' => [
                ['fromTable' => 'inventory_items', 'fromColumn' => 'holdings_record_id', 'toTable' => 'inventory_holdings', 'toColumn' => 'id'],
                ['fromTable' => 'inventory_holdings', 'fromColumn' => 'instance_id', 'toTable' => 'inventory_instances', 'toColumn' => 'id'],
                ['fromTable' => 'inventory_instances', 'fromColumn' => 'id', 'toTable' => 'inventory_instance__t__publication', 'toColumn' => 'id'],
                ['fromTable' => 'inventory_items', 'fromColumn' => 'effective_location_id', 'toTable' => 'inventory_locations', 'toColumn' => 'id'],
                ['fromTable' => 'inventory_locations', 'fromColumn' => 'library_id', 'toTable' => 'inventory_libraries', 'toColumn' => 'id'],
                ['fromTable' => 'inventory_libraries', 'fromColumn' => 'campus_id', 'toTable' => 'inventory_campuses', 'toColumn' => 'id'],
            ],
        ],
    ],
];

$graph = [
    'contractKeyToSqlTable' => [
        'inventory_items' => 'inventory.item__t',
        'inventory_holdings' => 'inventory.holdings_record__t',
        'inventory_instances' => 'inventory.instance__t',
        'inventory_instance__t__publication' => 'inventory.instance__t__publication',
        'inventory_locations' => 'inventory.location__t',
        'inventory_libraries' => 'inventory.loclibrary__t',
        'inventory_campuses' => 'inventory.loccampus__t',
    ],
    'sqlTableToContractKey' => [
        'inventory.item__t' => 'inventory_items',
        'inventory.holdings_record__t' => 'inventory_holdings',
        'inventory.instance__t' => 'inventory_instances',
        'inventory.instance__t__publication' => 'inventory_instance__t__publication',
        'inventory.location__t' => 'inventory_locations',
        'inventory.loclibrary__t' => 'inventory_libraries',
        'inventory.loccampus__t' => 'inventory_campuses',
    ],
    'edges' => [
        [
            'from' => 'inventory_items',
            'localColumn' => 'holdings_record_id',
            'to' => 'inventory_holdings',
            'targetColumn' => 'id',
            'supportsDeterministicCompilation' => true,
        ],
        [
            'from' => 'inventory_holdings',
            'localColumn' => 'instance_id',
            'to' => 'inventory_instances',
            'targetColumn' => 'id',
            'supportsDeterministicCompilation' => true,
        ],
        [
            'from' => 'inventory_items',
            'localColumn' => 'effective_location_id',
            'to' => 'inventory_locations',
            'targetColumn' => 'id',
            'supportsDeterministicCompilation' => true,
        ],
        [
            'from' => 'inventory_locations',
            'localColumn' => 'library_id',
            'to' => 'inventory_libraries',
            'targetColumn' => 'id',
            'supportsDeterministicCompilation' => true,
        ],
        [
            'from' => 'inventory_libraries',
            'localColumn' => 'campus_id',
            'to' => 'inventory_campuses',
            'targetColumn' => 'id',
            'supportsDeterministicCompilation' => true,
        ],
    ],
];

$columnCache = [
    'inventory.item__t' => [
        ['name' => 'id', 'type' => 'uuid'],
        ['name' => 'holdings_record_id', 'type' => 'uuid'],
        ['name' => 'effective_location_id', 'type' => 'uuid'],
    ],
    'inventory.holdings_record__t' => [
        ['name' => 'id', 'type' => 'uuid'],
        ['name' => 'instance_id', 'type' => 'uuid'],
    ],
    'inventory.instance__t' => [
        ['name' => 'id', 'type' => 'uuid'],
    ],
    'inventory.location__t' => [
        ['name' => 'id', 'type' => 'uuid'],
        ['name' => 'library_id', 'type' => 'uuid'],
    ],
    'inventory.loclibrary__t' => [
        ['name' => 'id', 'type' => 'uuid'],
        ['name' => 'campus_id', 'type' => 'uuid'],
        ['name' => 'name', 'type' => 'text'],
    ],
    'inventory.loccampus__t' => [
        ['name' => 'id', 'type' => 'uuid'],
        ['name' => 'name', 'type' => 'text'],
    ],
];

$subtableCache = [
    'inventory.instance__t__publication' => [
        'parent' => 'inventory.instance__t',
        'columns' => [
            ['name' => 'id', 'type' => 'uuid'],
            ['name' => 'publication__date_of_publication', 'type' => 'text'],
        ],
    ],
];

$serviceClass::validateFamilyReadyFromArtifacts(
    'inventory_collection_age',
    $artifact,
    $graph,
    $columnCache,
    $subtableCache
);

$missingColumnCache = $columnCache;
$missingSubtableCache = $subtableCache;
$missingSubtableCache['inventory.instance__t__publication']['columns'] = [
    ['name' => 'id', 'type' => 'uuid'],
];

assertThrowsRuntimeException(
    function () use ($serviceClass, $artifact, $graph, $missingColumnCache, $missingSubtableCache): void {
        $serviceClass::validateFamilyReadyFromArtifacts(
            'inventory_collection_age',
            $artifact,
            $graph,
            $missingColumnCache,
            $missingSubtableCache
        );
    },
    'schema_manifest_drift: Missing required column inventory_instance__t__publication.publication__date_of_publication',
    'Schema manifest validation should fail closed when a required column is missing from the live schema caches.'
);

$wrongTypeColumnCache = $columnCache;
$wrongTypeColumnCache['inventory.item__t'] = [
    ['name' => 'id', 'type' => 'uuid'],
    ['name' => 'holdings_record_id', 'type' => 'text'],
    ['name' => 'effective_location_id', 'type' => 'uuid'],
];

assertThrowsRuntimeException(
    function () use ($serviceClass, $artifact, $graph, $wrongTypeColumnCache, $subtableCache): void {
        $serviceClass::validateFamilyReadyFromArtifacts(
            'inventory_collection_age',
            $artifact,
            $graph,
            $wrongTypeColumnCache,
            $subtableCache
        );
    },
    'schema_manifest_drift: Expected inventory_items.holdings_record_id to have type uuid, found text',
    'Schema manifest validation should fail closed when a required column type drifts.'
);

$missingEdgeGraph = $graph;
array_pop($missingEdgeGraph['edges']);

assertThrowsRuntimeException(
    function () use ($serviceClass, $artifact, $missingEdgeGraph, $columnCache, $subtableCache): void {
        $serviceClass::validateFamilyReadyFromArtifacts(
            'inventory_collection_age',
            $artifact,
            $missingEdgeGraph,
            $columnCache,
            $subtableCache
        );
    },
    'schema_manifest_drift: Missing required deterministic edge inventory_libraries.campus_id <-> inventory_campuses.id',
    'Schema manifest validation should fail closed when a required deterministic join edge is absent.'
);

fwrite(STDOUT, "QueryFamilySchemaManifestService test passed\n");