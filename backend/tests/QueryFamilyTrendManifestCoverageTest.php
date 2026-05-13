<?php

$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';

foreach ([
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilySchemaManifestService' => $manifestServicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/canonical_query_graph.json') {
                return __DIR__ . '/../data/canonical_query_graph.json';
            }
            if ($alias === '@app/data/query_family_schema_manifests.json') {
                return __DIR__ . '/../data/query_family_schema_manifests.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return __DIR__ . '/../data/column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return __DIR__ . '/../data/subtable_cache.json';
            }

            return $alias;
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [],
];

require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $manifestServicePath;

use app\services\CanonicalQueryGraphArtifactBuilder;
use app\services\CanonicalQueryGraphService;
use app\services\QueryFamilySchemaManifestService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertHasDeterministicEdge(array $artifact, string $from, string $fromColumn, string $to, string $toColumn, string $message): void
{
    foreach (($artifact['edges'] ?? []) as $edge) {
        if (!is_array($edge) || empty($edge['supportsDeterministicCompilation'])) {
            continue;
        }

        $forwardMatch = ($edge['from'] ?? null) === $from
            && ($edge['localColumn'] ?? null) === $fromColumn
            && ($edge['to'] ?? null) === $to
            && ($edge['targetColumn'] ?? null) === $toColumn;

        $reverseMatch = ($edge['from'] ?? null) === $to
            && ($edge['localColumn'] ?? null) === $toColumn
            && ($edge['to'] ?? null) === $from
            && ($edge['targetColumn'] ?? null) === $fromColumn;

        if ($forwardMatch || $reverseMatch) {
            return;
        }
    }

    fwrite(STDERR, $message . "\nMissing edge: {$from}.{$fromColumn} <-> {$to}.{$toColumn}\n");
    exit(1);
}

$checkedInArtifact = CanonicalQueryGraphService::loadArtifact();

assertSameValue(
    'circulation.loan__t',
    $checkedInArtifact['contractKeyToSqlTable']['circulation_loans'] ?? null,
    'The checked-in canonical query graph artifact should expose circulation_loans so the trend family can validate against a real deterministic graph.'
);

assertHasDeterministicEdge(
    $checkedInArtifact,
    'circulation_loans',
    'item_id',
    'inventory_items',
    'id',
    'The checked-in canonical query graph artifact should include the deterministic loans-to-items edge required by the trend family.'
);

assertHasDeterministicEdge(
    $checkedInArtifact,
    'circulation_loans',
    'item_effective_location_id_at_check_out',
    'inventory_locations',
    'id',
    'The checked-in canonical query graph artifact should include the deterministic loans-to-location edge required by the trend family.'
);

assertTrueValue(
    QueryFamilySchemaManifestService::hasManifest('circulation_trends_matrix'),
    'The schema-manifest artifact should include the circulation_trends_matrix family once its deterministic graph coverage is available.'
);

QueryFamilySchemaManifestService::validateFamilyReady('circulation_trends_matrix');

$rebuiltArtifact = CanonicalQueryGraphArtifactBuilder::build([], [], [], [], [], '2026-05-12T16:40:00+00:00');

assertSameValue(
    'circulation.loan__t',
    $rebuiltArtifact['contractKeyToSqlTable']['circulation_loans'] ?? null,
    'The canonical query graph artifact builder should regenerate circulation_loans coverage instead of relying on a one-off checked-in file edit.'
);

assertHasDeterministicEdge(
    $rebuiltArtifact,
    'circulation_loans',
    'item_id',
    'inventory_items',
    'id',
    'The canonical query graph artifact builder should regenerate the deterministic loans-to-items edge.'
);

assertHasDeterministicEdge(
    $rebuiltArtifact,
    'circulation_loans',
    'item_effective_location_id_at_check_out',
    'inventory_locations',
    'id',
    'The canonical query graph artifact builder should regenerate the deterministic loans-to-location edge.'
);

fwrite(STDOUT, "QueryFamily trend manifest coverage test passed\n");