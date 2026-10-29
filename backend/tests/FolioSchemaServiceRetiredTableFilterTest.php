<?php

require_once __DIR__ . '/../services/FolioSchemaService.php';

use app\services\FolioSchemaService;

foreach (['discoveredColumns', 'discoveredMap'] as $cacheName) {
    $cache = new ReflectionProperty(FolioSchemaService::class, $cacheName);
    if (PHP_VERSION_ID < 80100) {
        $cache->setAccessible(true);
    }
    $cache->setValue(null, []);
}

function retiredTableFilterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$hintMap = new ReflectionMethod(FolioSchemaService::class, 'sanitizePromptHintMap');
$examples = new ReflectionMethod(FolioSchemaService::class, 'sanitizePromptExamples');
$patterns = new ReflectionMethod(FolioSchemaService::class, 'sanitizePromptDataPatterns');
if (PHP_VERSION_ID < 80100) {
    $hintMap->setAccessible(true);
    $examples->setAccessible(true);
    $patterns->setAccessible(true);
}

$safeHints = $hintMap->invoke(null, [
    'acrl_statistics' => 'Historical data in acrl_statistics',
    'retired table note' => 'Do not query acrl_statistics_retired.',
    'budget allocations' => 'Use report_expense_allocations for local budget data.',
]);
retiredTableFilterAssert(count($safeHints) === 1, 'Hints naming either retired table must be removed.');
retiredTableFilterAssert(isset($safeHints['budget allocations']), 'Unrelated allocation guidance must remain.');

$safeExamples = $examples->invoke(null, [
    ['question' => 'Show history', 'sql' => 'SELECT * FROM acrl_statistics'],
    ['question' => 'Show acrl_statistics by year', 'sql' => 'SELECT 1'],
    ['question' => 'Show history', 'sql' => 'SELECT * FROM acrl_statistics_retired'],
    ['question' => 'Show allocations', 'sql' => 'SELECT * FROM report_expense_allocations'],
]);
retiredTableFilterAssert(count($safeExamples) === 1, 'Examples naming either retired table in question or SQL must be dropped.');

$safePatterns = $patterns->invoke(null, [
    'acrl_statistics' => ['sampleValues' => ['category' => ['Books']]],
    'acrl_statistics_retired' => ['sampleValues' => ['category' => ['Books']]],
    'inventory.instance__t' => [
        'columnWarnings' => ['title' => 'Compare against acrl_statistics only.'],
        'sampleValues' => ['title' => ['Books', 'acrl_statistics_retired']],
        'preferredApproach' => ['Do not read acrl_statistics.'],
    ],
]);
retiredTableFilterAssert(count($safePatterns) === 1, 'Patterns keyed by either retired table must be removed.');
retiredTableFilterAssert(
    !isset($safePatterns['inventory.instance__t']['columnWarnings']['title'])
        && !isset($safePatterns['inventory.instance__t']['preferredApproach'][0]),
    'Pattern guidance naming a retired table must be removed.'
);
retiredTableFilterAssert(
    $safePatterns['inventory.instance__t']['sampleValues']['title'] === ['Books'],
    'Safe sample values must remain while stale table names are filtered.'
);

echo "FolioSchemaService retired-table filter test passed\n";
