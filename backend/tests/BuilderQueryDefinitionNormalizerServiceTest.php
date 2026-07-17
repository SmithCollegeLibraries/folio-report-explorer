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
            'pair_id' => 'inventory.item__t<->inventory.location__t',
            'from_table' => 'inventory.item__t',
            'from_column' => 'permanent_location_id',
            'to_table' => 'inventory.location__t',
            'to_column' => 'id',
            'is_default' => true,
        ],
    ],
    'defaults_by_pair' => [
        'inventory.item__t<->inventory.location__t' => 'inventory.item__t.permanent_location_id->inventory.location__t.id',
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

$reverseDefinition = $definition;
$reverseDefinition['tables'] = ['inventory.location__t', 'inventory.item__t'];
$reverseNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $reverseDefinition,
    $mapping,
    $catalog
);
expectNormalizer(
    $reverseNormalized['joins'][0]['from_table'] === 'inventory_locations'
        && $reverseNormalized['joins'][0]['from_column'] === 'id',
    'A reverse table order must orient the trusted predicate from the already-joined location table.'
);
expectNormalizer(
    $reverseNormalized['joins'][0]['to_table'] === 'inventory_items'
        && $reverseNormalized['joins'][0]['to_column'] === 'permanent_location_id',
    'A reverse table order must join the item table exactly once.'
);

foreach ([null, 'auto', []] as $defaultJoins) {
    $defaultDefinition = $definition;
    if ($defaultJoins === null) {
        unset($defaultDefinition['joins']);
    } else {
        $defaultDefinition['joins'] = $defaultJoins;
    }
    $defaultNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
        $defaultDefinition,
        $mapping,
        $catalog
    );
    expectNormalizer(
        count($defaultNormalized['joins']) === 1
            && $defaultNormalized['joins'][0]['from_column'] === 'permanent_location_id',
        'Omitted, auto, and empty canonical joins must resolve the server-owned default relationship.'
    );
}

$singleTable = $definition;
$singleTable['tables'] = ['inventory.item__t'];
$singleTable['joins'] = 'auto';
$singleNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $singleTable,
    $mapping,
    $catalog
);
expectNormalizer($singleNormalized['joins'] === [], 'A single canonical table must not require a relationship.');

$multiHopCatalog = [
    'relationships_by_id' => [
        'a.id->b.a_id' => [
            'relationship_id' => 'a.id->b.a_id',
            'pair_id' => 'a<->b',
            'from_table' => 'a',
            'from_column' => 'id',
            'to_table' => 'b',
            'to_column' => 'a_id',
            'is_default' => true,
        ],
        'b.id->c.b_id' => [
            'relationship_id' => 'b.id->c.b_id',
            'pair_id' => 'b<->c',
            'from_table' => 'b',
            'from_column' => 'id',
            'to_table' => 'c',
            'to_column' => 'b_id',
            'is_default' => true,
        ],
    ],
    'defaults_by_pair' => [
        'a<->b' => 'a.id->b.a_id',
        'b<->c' => 'b.id->c.b_id',
    ],
];
$multiHopDefinition = [
    'schemaIdentity' => 'ldlite',
    'tables' => ['c', 'a', 'b'],
    'columns' => [],
    'joins' => [
        ['relationship_id' => 'a.id->b.a_id'],
        ['relationship_id' => 'b.id->c.b_id'],
    ],
];
$multiHopNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $multiHopDefinition,
    ['a' => 'a_legacy', 'b' => 'b_legacy', 'c' => 'c_legacy'],
    $multiHopCatalog
);
expectNormalizer(
    $multiHopNormalized['joins'][0]['from_table'] === 'c_legacy'
        && $multiHopNormalized['joins'][0]['to_table'] === 'b_legacy'
        && $multiHopNormalized['joins'][1]['from_table'] === 'b_legacy'
        && $multiHopNormalized['joins'][1]['to_table'] === 'a_legacy',
    'Explicit multi-hop joins must be reordered and oriented from the already-joined table set.'
);

$unknownRelationship = $definition;
$unknownRelationship['joins'][0]['relationship_id'] = 'inventory.item__t.unknown->inventory.location__t.id';
expectNormalizerInvalidArgument(
    function () use ($unknownRelationship, $mapping, $catalog): void {
        BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($unknownRelationship, $mapping, $catalog);
    },
    'Unknown Builder relationship'
);

foreach ([null, 'invalid'] as $malformedJoins) {
    $malformed = $definition;
    $malformed['joins'] = $malformedJoins;
    expectNormalizerInvalidArgument(
        function () use ($malformed, $mapping, $catalog): void {
            BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($malformed, $mapping, $catalog);
        },
        'Canonical joins must be'
    );
}

$malformedEntry = $definition;
$malformedEntry['joins'] = ['not-an-object'];
expectNormalizerInvalidArgument(
    function () use ($malformedEntry, $mapping, $catalog): void {
        BuilderQueryDefinitionNormalizerService::normalizeWithCatalog($malformedEntry, $mapping, $catalog);
    },
    'relationship_id'
);

$disconnectedCatalog = $catalog;
$disconnectedDefinition = $definition;
$disconnectedDefinition['tables'][] = 'inventory.campus__t';
$disconnectedDefinition['joins'] = 'auto';
expectNormalizerInvalidArgument(
    function () use ($disconnectedDefinition, $mapping, $disconnectedCatalog): void {
        BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
            $disconnectedDefinition,
            $mapping + ['inventory.campus__t' => 'inventory_campuses'],
            $disconnectedCatalog
        );
    },
    'Cannot resolve reviewed Builder relationships'
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
