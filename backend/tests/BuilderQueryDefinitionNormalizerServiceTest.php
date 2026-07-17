<?php

$normalizerPath = __DIR__ . '/../services/BuilderQueryDefinitionNormalizerService.php';

function expectNormalizer($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function expectNormalizerInvalidArgument(callable $callback, string $expectedText): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        expectNormalizer(
            strpos($e->getMessage(), $expectedText) !== false,
            "Expected exception containing '{$expectedText}', got '{$e->getMessage()}'."
        );
        return;
    }

    expectNormalizer(false, 'Expected InvalidArgumentException was not thrown.');
}

expectNormalizer(is_file($normalizerPath), 'BuilderQueryDefinitionNormalizerService is missing.');
require_once $normalizerPath;

use app\services\BuilderQueryDefinitionNormalizerService;

$catalog = [
    'relationships_by_id' => [
        'inventory.item__t.permanent_location_id->inventory.location__t.id' => [
            'relationship_id' => 'inventory.item__t.permanent_location_id->inventory.location__t.id',
            'from_table' => 'inventory.item__t',
            'from_column' => 'permanent_location_id',
            'to_table' => 'inventory.location__t',
            'to_column' => 'id',
        ],
    ],
];

$definition = [
    'schemaIdentity' => 'ldlite',
    'tables' => ['inventory.item__t', 'inventory.location__t'],
    'columns' => [['table' => 'inventory.item__t', 'column' => 'barcode']],
    'filters' => [['table' => 'inventory.location__t', 'column' => 'code', 'op' => '=', 'value' => 'main']],
    'joins' => [[
        'relationship_id' => 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        'join_type' => 'LEFT JOIN',
        'from_table' => 'client_controlled',
        'from_column' => 'client_controlled',
    ]],
    'groupBy' => [['table' => 'inventory.item__t', 'column' => 'barcode']],
    'having' => [['aggregate' => 'COUNT', 'table' => 'inventory.location__t', 'column' => 'id', 'op' => '>', 'value' => 1]],
    'orderBy' => [['table' => 'inventory.location__t', 'column' => 'code', 'dir' => 'ASC']],
    'limit' => 100,
];

$mapping = [
    'inventory.item__t' => 'inventory_items',
    'inventory.location__t' => 'inventory_locations',
];

$normalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($definition, $mapping, $catalog);

expectNormalizer($normalized['tables'] === ['inventory_items', 'inventory_locations'], 'Physical tables must normalize to legacy internal keys.');
expectNormalizer($normalized['columns'][0]['table'] === 'inventory_items', 'Column table must normalize.');
expectNormalizer($normalized['filters'][0]['table'] === 'inventory_locations', 'Filter table must normalize.');
expectNormalizer($normalized['groupBy'][0]['table'] === 'inventory_items', 'Group-by table must normalize.');
expectNormalizer($normalized['having'][0]['table'] === 'inventory_locations', 'Having table must normalize.');
expectNormalizer($normalized['orderBy'][0]['table'] === 'inventory_locations', 'Order-by table must normalize.');
expectNormalizer($normalized['joins'][0]['from_table'] === 'inventory_items', 'Join source table must come from the trusted catalog.');
expectNormalizer($normalized['joins'][0]['from_column'] === 'permanent_location_id', 'Trusted relationship ID must expand to the reviewed endpoint.');
expectNormalizer($normalized['joins'][0]['to_table'] === 'inventory_locations', 'Join target table must come from the trusted catalog.');
expectNormalizer($normalized['joins'][0]['to_column'] === 'id', 'Join target column must come from the trusted catalog.');
expectNormalizer($normalized['joins'][0]['join_type'] === 'LEFT JOIN', 'Join type must be preserved.');
expectNormalizer(!isset($normalized['schemaIdentity']), 'Internal definition must not retain schemaIdentity.');

$unknownRelationship = $definition;
$unknownRelationship['joins'][0]['relationship_id'] = 'inventory.item__t.unknown->inventory.location__t.id';
expectNormalizerInvalidArgument(
    function () use ($unknownRelationship, $mapping, $catalog): void {
        BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($unknownRelationship, $mapping, $catalog);
    },
    'Unknown Builder relationship'
);

$missingEndpoint = $definition;
$missingEndpoint['tables'] = ['inventory.item__t'];
expectNormalizerInvalidArgument(
    function () use ($missingEndpoint, $mapping, $catalog): void {
        BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($missingEndpoint, $mapping, $catalog);
    },
    'must be included in tables'
);

$unsupportedJoinType = $definition;
$unsupportedJoinType['joins'][0]['join_type'] = 'RIGHT JOIN';
$unsupportedNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $unsupportedJoinType,
    $mapping,
    $catalog
);
expectNormalizer($unsupportedNormalized['joins'][0]['join_type'] === 'JOIN', 'Unsupported join types must normalize to JOIN.');

$legacyDefinition = ['tables' => ['inventory_items'], 'joins' => 'auto'];
expectNormalizer(
    BuilderQueryDefinitionNormalizerService::normalize($legacyDefinition) === $legacyDefinition,
    'Definitions without LDLite identity must pass through unchanged.'
);

$controllerSource = (string)file_get_contents(__DIR__ . '/../controllers/FolioQueryController.php');
expectNormalizer(
    strpos($controllerSource, 'BuilderQueryDefinitionNormalizerService::normalize($body)') !== false,
    'The build endpoint must normalize canonical definitions before SQL generation.'
);

fwrite(STDOUT, "Builder query definition normalizer test passed\n");
