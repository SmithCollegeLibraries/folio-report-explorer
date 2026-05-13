<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

if (!file_exists($geminiServicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$geminiServicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
    }
}

Yii::$app = (object) [
    'params' => [],
];

require_once $geminiServicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertNotSameValue($left, $right, string $message): void
{
    if ($left === $right) {
        fwrite(STDERR, $message . "\nBoth values: " . var_export($left, true) . "\n");
        exit(1);
    }
}

$signatureBuilder = new ReflectionMethod(GeminiService::class, 'buildSemanticSqlComparisonSignature');
if (PHP_VERSION_ID < 80500) {
    $signatureBuilder->setAccessible(true);
}

$intentSql = <<<'SQL'
WITH scoped_instances AS (
  SELECT iin.id AS instance_id,
         COUNT(*) AS item_count
  FROM inventory.item__t ii
  JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id
  JOIN inventory.instance__t iin ON ih.instance_id = iin.id
  JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id
  JOIN inventory.loclibrary__t il ON ilo.library_id = il.id
  JOIN inventory.loccampus__t ic ON il.campus_id = ic.id
  WHERE ic.name ILIKE 'Smith College'
    AND il.name ILIKE '%Neilson Library%'
    AND ilo.name ILIKE '%Neilson Reference%'
  GROUP BY ih.instance_id, iin.id
)
SELECT SUM(scoped_instances.item_count * (EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER))) / NULLIF(SUM(scoped_instances.item_count), 0) AS average_age_years
FROM scoped_instances
LEFT JOIN inventory.instance__t__publication iip ON iip.id = scoped_instances.instance_id
WHERE iip.publication__date_of_publication IS NOT NULL
  AND iip.publication__date_of_publication ~ '^\d{4}'
SQL;

$legacyAlignedSql = <<<'SQL'
SELECT AVG(EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(pub.publication__date_of_publication FROM 1 FOR 4) AS INTEGER)) AS average_age_years
FROM inventory.item__t ii
JOIN inventory.holdings_record__t hr ON ii.holdings_record_id = hr.id
JOIN inventory.instance__t inst ON hr.instance_id = inst.id
JOIN inventory.instance__t__publication pub ON inst.id = pub.id
JOIN inventory.location__t loc ON ii.effective_location_id = loc.id
JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id
JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id
WHERE lib.name ILIKE '%Neilson Library%'
  AND loc.name ILIKE '%Neilson Reference%'
  AND LOWER(camp.name) = LOWER('Smith College')
  AND pub.publication__date_of_publication ~ '^\d{4}'
LIMIT 100;
SQL;

$legacyAlignedAliasVariantSql = <<<'SQL'
SELECT 
  AVG(EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(pub.publication__date_of_publication FROM 1 FOR 4) AS INTEGER)) AS average_item_age_years
FROM 
  inventory.item__t AS ii
  JOIN inventory.holdings_record__t AS hr ON ii.holdings_record_id = hr.id
  JOIN inventory.instance__t AS inst ON hr.instance_id = inst.id
  JOIN inventory.instance__t__publication AS pub ON pub.id = inst.id
  JOIN inventory.location__t AS loc ON ii.effective_location_id = loc.id
  JOIN inventory.loclibrary__t AS lib ON loc.library_id = lib.id
  JOIN inventory.loccampus__t AS camp ON lib.campus_id = camp.id
WHERE 
  lib.name ILIKE '%Neilson Library%'
  AND loc.name ILIKE '%Neilson Reference%'
  AND LOWER(camp.name) = LOWER('Smith College')
  AND pub.publication__date_of_publication ~ '^\d{4}'
LIMIT 100;
SQL;

$legacyAlignedCteVariantSql = <<<'SQL'
WITH item_ages AS (
  SELECT
    EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(pub.publication__date_of_publication FROM 1 FOR 4) AS INTEGER) AS age_years
  FROM
    inventory.item__t AS ii
    JOIN inventory.holdings_record__t AS hr ON ii.holdings_record_id = hr.id
    JOIN inventory.instance__t AS inst ON hr.instance_id = inst.id
    JOIN inventory.instance__t__publication AS pub ON pub.id = inst.id
    JOIN inventory.location__t AS loc ON ii.effective_location_id = loc.id
    JOIN inventory.loclibrary__t AS lib ON loc.library_id = lib.id
    JOIN inventory.loccampus__t AS camp ON lib.campus_id = camp.id
  WHERE
    lib.name ILIKE '%Neilson Library%'
    AND loc.name ILIKE '%Neilson Reference%'
    AND LOWER(camp.name) = LOWER('Smith College')
    AND pub.publication__date_of_publication ~ '^\d{4}'
)
SELECT
  AVG(age_years)::numeric(10,2) AS average_age_years
FROM
  item_ages
LIMIT 100;
SQL;

$intentLibraryOnlySql = <<<'SQL'
WITH scoped_instances AS (
  SELECT iin.id AS instance_id,
         COUNT(*) AS item_count
  FROM inventory.item__t ii
  JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id
  JOIN inventory.instance__t iin ON ih.instance_id = iin.id
  JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id
  JOIN inventory.loclibrary__t il ON ilo.library_id = il.id
  JOIN inventory.loccampus__t ic ON il.campus_id = ic.id
  WHERE ic.name ILIKE 'Smith College'
    AND il.name ILIKE '%Neilson Library%'
  GROUP BY ih.instance_id, iin.id
)
SELECT SUM(scoped_instances.item_count * (EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER))) / NULLIF(SUM(scoped_instances.item_count), 0) AS average_age_years
FROM scoped_instances
LEFT JOIN inventory.instance__t__publication iip ON iip.id = scoped_instances.instance_id
WHERE iip.publication__date_of_publication IS NOT NULL
  AND iip.publication__date_of_publication ~ '^\d{4}'
SQL;

$legacyAlignedLibraryOnlySql = <<<'SQL'
SELECT
  ROUND(AVG(EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(pub.publication__date_of_publication FROM 1 FOR 4) AS INT)), 2) AS average_item_age_in_years
FROM inventory.item__t ii
JOIN inventory.holdings_record__t hr ON ii.holdings_record_id = hr.id
JOIN inventory.instance__t inst ON hr.instance_id = inst.id
JOIN inventory.instance__t__publication pub ON inst.id = pub.id
JOIN inventory.location__t loc ON ii.effective_location_id = loc.id
JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id
JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id
WHERE LOWER(camp.name) = LOWER('Smith College')
  AND LOWER(lib.name) ILIKE LOWER('%Neilson Library%')
  AND pub.publication__date_of_publication ~ '^\d{4}'
  AND CAST(SUBSTRING(pub.publication__date_of_publication FROM 1 FOR 4) AS INT) <= EXTRACT(YEAR FROM CURRENT_DATE);
SQL;

$legacyDriftedSql = <<<'SQL'
WITH items_in_neilson_reference AS (
  SELECT
    ii.id,
    ii.metadata__created_date
  FROM
    inventory.item__t ii
    JOIN inventory.location__t loc ON ii.effective_location_id = loc.id
    JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id
    JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id
  WHERE
    LOWER(camp.name) = LOWER('Smith College')
    AND loc.name ILIKE '%Neilson%'
    AND loc.name ILIKE '%Reference%'
    AND ii.metadata__created_date IS NOT NULL
)
SELECT
  AVG(EXTRACT(EPOCH FROM (CURRENT_DATE - ii.metadata__created_date::date)) / 86400) AS average_age_days
FROM
  items_in_neilson_reference ii
LIMIT 100;
SQL;

$intentSignature = $signatureBuilder->invoke(null, $intentSql);
$legacyAlignedSignature = $signatureBuilder->invoke(null, $legacyAlignedSql);
$legacyAlignedAliasVariantSignature = $signatureBuilder->invoke(null, $legacyAlignedAliasVariantSql);
$legacyAlignedCteVariantSignature = $signatureBuilder->invoke(null, $legacyAlignedCteVariantSql);
$intentLibraryOnlySignature = $signatureBuilder->invoke(null, $intentLibraryOnlySql);
$legacyAlignedLibraryOnlySignature = $signatureBuilder->invoke(null, $legacyAlignedLibraryOnlySql);
$legacyDriftedSignature = $signatureBuilder->invoke(null, $legacyDriftedSql);

assertNotSameValue(
    null,
    $intentSignature,
    'Aligned deterministic collection-age SQL should produce a semantic comparison signature.'
);
assertSameValue(
    $intentSignature,
    $legacyAlignedSignature,
    'Semantically aligned deterministic and legacy collection-age SQL should share the same semantic comparison signature.'
);
assertSameValue(
  $intentSignature,
  $legacyAlignedAliasVariantSignature,
  'Aligned collection-age legacy SQL should share the same semantic comparison signature even when the aggregate alias name changes.'
);
assertSameValue(
  $intentSignature,
  $legacyAlignedCteVariantSignature,
  'Aligned collection-age legacy SQL should share the same semantic comparison signature even when the query is wrapped in a CTE and the outer aggregate shape differs.'
);
assertNotSameValue(
  null,
  $intentLibraryOnlySignature,
  'Library-only deterministic collection-age SQL should still produce a semantic comparison signature when the prompt explicitly scopes only to a library.'
);
assertSameValue(
  $intentLibraryOnlySignature,
  $legacyAlignedLibraryOnlySignature,
  'Semantically aligned library-only collection-age SQL should share the same semantic comparison signature without requiring a location predicate.'
);
assertNotSameValue(
    $intentSignature,
    $legacyDriftedSignature,
    'The old drifted legacy collection-age SQL should not share the same semantic comparison signature as the aligned deterministic SQL.'
);

fwrite(STDOUT, "GeminiService shadow semantic comparison test passed\n");