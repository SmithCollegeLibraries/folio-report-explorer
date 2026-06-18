<?php

$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'QueryFamilySlotService' => $slotServicePath,
    'FolioSchemaService' => $schemaServicePath,
    'GeminiService' => $geminiServicePath,
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
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once $slotServicePath;
require_once $schemaServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nSQL: {$haystack}\n");
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nSQL: {$haystack}\n");
        exit(1);
    }
}

$normalizer = new ReflectionMethod(GeminiService::class, 'normalizeFormerCirculationNumericCasts');
$idCastNormalizer = new ReflectionMethod(GeminiService::class, 'normalizeIdCasts');

$sql = "SELECT CAST(COALESCE(NULLIF(REGEXP_REPLACE(itn.notes__note, '\\D', '', 'g'), ''), '0') AS INTEGER) AS former_circulation FROM inventory.item__t__notes itn";

$normalized = $normalizer->invoke(null, $sql);
$normalizedBySharedPath = $idCastNormalizer->invoke(null, $sql);

$publicationDateSql = "SELECT inst.publication__date_of_publication AS publication_date FROM inventory.instance__t inst";
$publicationDateNormalized = $idCastNormalizer->invoke(null, $publicationDateSql);

$libraryLocationSql = "SELECT ii.barcode FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id JOIN inventory.loclibrary__t il1 ON il1.id = il.library_id WHERE il1.name ILIKE 'Josten Library' AND il.name ILIKE 'Treasure Case'";
$libraryLocationNormalized = $idCastNormalizer->invoke(null, $libraryLocationSql);

$referenceAgeSql = "WITH reference_items AS ( SELECT ii.id, ii.status__date FROM inventory.item__t ii JOIN inventory.location__t loc ON ii.effective_location_id = loc.id JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id WHERE lib.name ILIKE '%Neilson%' AND ii.status__name ILIKE '%Reference%' AND ii.status__date IS NOT NULL ) SELECT AVG(EXTRACT(EPOCH FROM (CURRENT_DATE - ii.status__date)) / 86400) AS average_age_days FROM reference_items ii";
$referenceAgeNormalized = $idCastNormalizer->invoke(null, $referenceAgeSql);

$referenceMaterialTypeAgeSql = "WITH reference_items AS ( SELECT ii.id, ii.status__date FROM inventory.item__t ii JOIN inventory.location__t loc ON ii.effective_location_id = loc.id JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id JOIN inventory.material_type__t imt ON ii.material_type_id = imt.id WHERE LOWER(camp.name) = LOWER('Smith College') AND lib.name ILIKE '%Neilson%' AND LOWER(imt.name) = 'reference' AND ii.status__date IS NOT NULL ) SELECT AVG(EXTRACT(YEAR FROM AGE(CURRENT_DATE, ii.status__date))) AS average_age_years FROM reference_items ii LIMIT 100";
$referenceMaterialTypeAgeNormalized = $idCastNormalizer->invoke(null, $referenceMaterialTypeAgeSql);

$referenceMetadataAgeSql = "WITH neilson_reference_items AS ( SELECT ii.id, ii.metadata__created_date FROM inventory.item__t ii JOIN inventory.location__t loc ON ii.effective_location_id = loc.id JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id WHERE lib.name ILIKE '%Neilson%' AND LOWER(loc.name) ILIKE '%reference%' AND LOWER(camp.name) = LOWER('Smith College') AND ii.metadata__created_date IS NOT NULL ) SELECT AVG(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - metadata__created_date)) / (365.25 * 24 * 3600)) AS average_age_years FROM neilson_reference_items LIMIT 100";
$referenceMetadataAgeNormalized = $idCastNormalizer->invoke(null, $referenceMetadataAgeSql);

$standingOrderPaymentStatusSql = "SELECT po.po_number FROM orders.po_line__t AS pol JOIN orders.purchase_order__t AS po ON pol.purchase_order_id = po.id WHERE LOWER(pol.payment_status) = LOWER('Ongoing') AND po.workflow_status = 'Open'";
$standingOrderPaymentStatusNormalized = GeminiService::normalizeGeneratedSql($standingOrderPaymentStatusSql);

$standingOrderFormatSql = "SELECT po.po_number FROM orders.po_line__t AS pol JOIN orders.purchase_order__t AS po ON pol.purchase_order_id = po.id WHERE LOWER(pol.order_format) = LOWER('Ongoing') AND po.workflow_status = 'Open'";
$standingOrderFormatNormalized = GeminiService::normalizeGeneratedSql($standingOrderFormatSql);

assertContainsText(
    'AS BIGINT) AS former_circulation',
    $normalized,
    'Historic circulation note extraction should cast to BIGINT so large migrated counts do not overflow INTEGER.'
);
assertNotContainsText(
    'AS INTEGER) AS former_circulation',
    $normalized,
    'Historic circulation note extraction should no longer use INTEGER casts.'
);
assertContainsText(
    'AS BIGINT) AS former_circulation',
    $normalizedBySharedPath,
    'Shared SQL normalization should rewrite former circulation note casts to BIGINT.'
);
assertNotContainsText(
    'AS INTEGER) AS former_circulation',
    $normalizedBySharedPath,
    'Shared SQL normalization should not leave former circulation note casts at INTEGER.'
);
assertContainsText(
    'inst.dates__date1 AS publication_date',
    $publicationDateNormalized,
    'Shared SQL normalization should rewrite drifted instance publication-date columns to dates__date1.'
);
assertNotContainsText(
    'inst.publication__date_of_publication',
    $publicationDateNormalized,
    'Shared SQL normalization should not leave invalid parent-table publication__date_of_publication references in place.'
);
assertContainsText(
    "il1.name ILIKE '%Josten Library%'",
    $libraryLocationNormalized,
    'Shared SQL normalization should widen bare library-name ILIKE filters to wildcard contains matches.'
);
assertContainsText(
    "il.name ILIKE '%Treasure Case%'",
    $libraryLocationNormalized,
    'Shared SQL normalization should widen bare location-name ILIKE filters to wildcard contains matches.'
);
assertNotContainsText(
    "il1.name ILIKE 'Josten Library'",
    $libraryLocationNormalized,
    'Shared SQL normalization should not leave bare library-name ILIKE literals in place.'
);
assertNotContainsText(
    "il.name ILIKE 'Treasure Case'",
    $libraryLocationNormalized,
    'Shared SQL normalization should not leave bare location-name ILIKE literals in place.'
);
assertContainsText(
    "LOWER(po.order_type) = LOWER('Ongoing')",
    $standingOrderPaymentStatusNormalized,
    'Shared SQL normalization should map standing-order payment_status filters to purchase_order__t.order_type.'
);
assertNotContainsText(
    'pol.payment_status',
    $standingOrderPaymentStatusNormalized,
    'Shared SQL normalization should remove incorrect standing-order payment_status filters.'
);
assertContainsText(
    "LOWER(po.order_type) = LOWER('Ongoing')",
    $standingOrderFormatNormalized,
    'Shared SQL normalization should map standing-order order_format filters to purchase_order__t.order_type.'
);
assertNotContainsText(
    'pol.order_format) = LOWER(\'Ongoing\')',
    $standingOrderFormatNormalized,
    'Shared SQL normalization should remove incorrect standing-order order_format filters.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t ref_hr ON ref_hr.id = ii.holdings_record_id',
    $referenceAgeNormalized,
    'Reference-collection age normalization should join through holdings so item age can use instance publication data.'
);
assertContainsText(
    'JOIN inventory.instance__t ref_inst ON ref_inst.id = ref_hr.instance_id',
    $referenceAgeNormalized,
    'Reference-collection age normalization should join the instance record before deriving item age.'
);
assertContainsText(
    'LEFT JOIN inventory.instance__t__publication ref_ip ON ref_ip.id = ref_inst.id',
    $referenceAgeNormalized,
    'Reference-collection age normalization should use the instance publication subtable for bibliographic age.'
);
assertContainsText(
    "loc.name ILIKE '%Reference%'",
    $referenceAgeNormalized,
    'Shared SQL normalization should treat reference collection as a location filter rather than an item status filter.'
);
assertContainsText(
    "ref_ip.publication__date_of_publication ~ '^\\d{4}'",
    $referenceAgeNormalized,
    'Reference-collection age normalization should keep only publication dates that begin with a four-digit year.'
);
assertContainsText(
    'SUBSTRING(ii.publication_date FROM 1 FOR 4)',
    $referenceAgeNormalized,
    'Reference-collection age normalization should derive age from the projected publication year.'
);
assertNotContainsText(
    'ii.status__date',
    $referenceAgeNormalized,
    'Shared SQL normalization should not leave item status__date in reference-collection age queries.'
);
assertNotContainsText(
    'ref_inst.cataloged_date',
    $referenceAgeNormalized,
    'Reference-collection age normalization should not fall back to instance cataloged_date for bibliographic age.'
);
assertNotContainsText(
    "ii.status__name ILIKE '%Reference%'",
    $referenceAgeNormalized,
    'Shared SQL normalization should not leave item status filters for reference-collection queries.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t ref_hr ON ref_hr.id = ii.holdings_record_id',
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should join through holdings so item age can use instance publication data.'
);
assertContainsText(
    'JOIN inventory.instance__t ref_inst ON ref_inst.id = ref_hr.instance_id',
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should join the instance record before deriving item age.'
);
assertContainsText(
    'LEFT JOIN inventory.instance__t__publication ref_ip ON ref_ip.id = ref_inst.id',
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should use the instance publication subtable for bibliographic age.'
);
assertContainsText(
    "loc.name ILIKE '%Reference%'",
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should treat reference as a location filter even when Gemini drifts to material type.'
);
assertContainsText(
    "ref_ip.publication__date_of_publication ~ '^\\d{4}'",
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should keep only publication dates that begin with a four-digit year.'
);
assertContainsText(
    'CAST(SUBSTRING(ii.publication_date FROM 1 FOR 4) AS INTEGER)',
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should compute average age from the projected publication year.'
);
assertNotContainsText(
    "LOWER(imt.name) = 'reference'",
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should not leave material_type reference filters in place for reference collections.'
);
assertNotContainsText(
    'ii.status__date',
    $referenceMaterialTypeAgeNormalized,
    'Reference-collection age normalization should not leave item status__date in material-type reference age queries.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t ref_hr ON ref_hr.id = ii.holdings_record_id',
    $referenceMetadataAgeNormalized,
    'Reference-collection age normalization should join through holdings when Gemini uses item metadata dates.'
);
assertContainsText(
    'LEFT JOIN inventory.instance__t__publication ref_ip ON ref_ip.id = ref_inst.id',
    $referenceMetadataAgeNormalized,
    'Reference-collection age normalization should use the instance publication subtable when Gemini uses item metadata dates.'
);
assertContainsText(
    'SUBSTRING(publication_date FROM 1 FOR 4)',
    $referenceMetadataAgeNormalized,
    'Reference-collection age normalization should derive age from the projected publication year even when the outer query references an unqualified column.'
);
assertNotContainsText(
    'metadata__created_date',
    $referenceMetadataAgeNormalized,
    'Reference-collection age normalization should not leave item metadata__created_date in collection age queries.'
);

fwrite(STDOUT, "GeminiService SQL normalization test passed\n");
