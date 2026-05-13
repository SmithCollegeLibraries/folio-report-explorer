<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilySchemaManifestService' => $manifestServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

$temporaryManifestPath = tempnam(sys_get_temp_dir(), 'fre-manifest-');
if ($temporaryManifestPath === false) {
    fwrite(STDERR, "Failed to create temporary manifest file.\n");
    exit(1);
}

$brokenManifest = [
    'metadata' => [
        'artifactVersion' => 1,
        'generatedAt' => '2026-05-12T00:00:00+00:00',
        'familyCount' => 1,
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
                ['table' => 'inventory_instance__t__publication', 'column' => 'publication__nonexistent', 'type' => 'text'],
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

file_put_contents($temporaryManifestPath, json_encode($brokenManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

register_shutdown_function(static function () use ($temporaryManifestPath): void {
    if (file_exists($temporaryManifestPath)) {
        unlink($temporaryManifestPath);
    }
});

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            global $temporaryManifestPath;

            if ($alias === '@app/data/canonical_query_graph.json') {
                return __DIR__ . '/../data/canonical_query_graph.json';
            }
            if ($alias === '@app/data/query_family_contracts.json') {
                return __DIR__ . '/../data/query_family_contracts.json';
            }
            if ($alias === '@app/data/query_family_schema_manifests.json') {
                return $temporaryManifestPath;
            }
            if ($alias === '@app/data/table_mapping_cache.json') {
                return __DIR__ . '/../data/table_mapping_cache.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return __DIR__ . '/../data/column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return __DIR__ . '/../data/subtable_cache.json';
            }
            if ($alias === '@app/data/semantic_context.json') {
                return __DIR__ . '/../data/semantic_context.json';
            }

            return $alias;
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'defaultQueryLimit' => 100,
        'maxQueryRows' => 1000,
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $contractServicePath;
require_once $slotServicePath;
require_once $manifestServicePath;
require_once $compilerServicePath;

use app\services\QueryFamilyCompilerService;

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

assertThrowsRuntimeException(
    function (): void {
        QueryFamilyCompilerService::compileToQueryDefinition([
            'familyKey' => 'inventory_collection_age',
            'slots' => [
                'campus' => 'Smith College',
                'library' => 'Neilson Library',
                'location' => 'Reference',
                'requested_outputs' => ['average_age_years'],
                'match_policy' => 'exact_phrase',
            ],
        ]);
    },
    'schema_manifest_drift: Missing required column inventory_instance__t__publication.publication__nonexistent',
    'The query family compiler should validate the schema manifest before compiling and fail closed on drift.'
);

fwrite(STDOUT, "QueryFamilyCompiler schema manifest guard test passed\n");