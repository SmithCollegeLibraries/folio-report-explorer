<?php

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';

if (!file_exists($contractServicePath)) {
    fwrite(STDERR, "QueryFamilyContractService is missing at {$contractServicePath}\n");
    exit(1);
}

if (!file_exists($slotServicePath)) {
    fwrite(STDERR, "QueryFamilySlotService is missing at {$slotServicePath}\n");
    exit(1);
}

require_once $contractServicePath;
require_once $slotServicePath;

use app\services\QueryFamilySlotService;

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

$validation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'material_type' => 'Theses',
        'requested_outputs' => ['title', 'publication_date', 'barcode', 'instance_hrid'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(true, $validation['valid'] ?? null, 'Covered family slot payloads should validate cleanly.');

$normalizedPayload = $validation['normalizedPayload'] ?? [];
assertSameValue(
    ['barcode', 'instance_hrid', 'publication_date', 'title'],
    $normalizedPayload['slots']['requested_outputs'] ?? null,
    'Requested outputs should normalize deterministically.'
);
assertSameValue(
    'exact_phrase',
    $normalizedPayload['slots']['match_policy'] ?? null,
    'Exact-vs-fuzzy intent should stay explicit in the normalized slot payload.'
);
assertTrueValue(
    method_exists(QueryFamilySlotService::class, 'slotRequiresExplicitPromptEvidence'),
    'QueryFamilySlotService should expose a shared slot-policy helper for explicit prompt evidence rules.'
);
assertSameValue(
    true,
    QueryFamilySlotService::slotRequiresExplicitPromptEvidence('inventory_collection_age', 'location'),
    'Collection-age location scope should require explicit prompt evidence according to the checked-in family contract.'
);
assertSameValue(
    false,
    QueryFamilySlotService::slotRequiresExplicitPromptEvidence('inventory_collection_age', 'library'),
    'Collection-age library scope should remain recoverable from direct library wording rather than being marked explicit-only.'
);

$collectionAgeLocationOnlyValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'location' => 'Locked Stack',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);
assertSameValue(
    true,
    $collectionAgeLocationOnlyValidation['valid'] ?? null,
    'Collection-age payloads with an explicit location scope should validate without requiring a broader library slot.'
);

$collectionAgeUnscopedValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);
assertSameValue(
    false,
    $collectionAgeUnscopedValidation['valid'] ?? null,
    'Collection-age payloads should still require either a library scope or an explicit location scope.'
);

$intent = QueryFamilySlotService::toQueryIntent($normalizedPayload);

assertSameValue(
    [
        'inventory_campuses',
        'inventory_contributor_name_types',
        'inventory_holdings',
        'inventory_instance__t__contributors',
        'inventory_instances',
        'inventory_items',
        'inventory_libraries',
        'inventory_locations',
        'inventory_material_types',
    ],
    $intent['query']['tables'] ?? null,
    'Slot translation should select the covered canonical inventory tables plus the optional material-type lookup.'
);
assertSameValue(
    'ILIKE',
    $intent['query']['where'][0]['op'] ?? null,
    'Exact-phrase slot payloads should compile contributor and campus filters with case-insensitive exact matching.'
);
assertSameValue(
    'Smith College',
    $intent['query']['where'][0]['value'] ?? null,
    'Canonical campus filters should avoid wildcard broadening in slot translation.'
);
assertSameValue(
    'contributors__name',
    $intent['query']['where'][1]['column'] ?? null,
    'Contributor name filters should compile onto the contributor subtable rather than raw SQL joins.'
);
assertSameValue(
    'Smith College. Department of Biological Sciences.',
    $intent['query']['where'][1]['value'] ?? null,
    'Exact contributor slot filters should preserve the raw contributor name without wildcard broadening.'
);
assertSameValue(
    '%thesis%',
    $intent['query']['where'][3]['value'] ?? null,
    'Thesis-like material-type slot filters should preserve contains matching semantics for stored variants.'
);
assertSameValue(
    'auto',
    $intent['query']['joins'] ?? null,
    'Slot translation should keep join planning deterministic and server-owned.'
);

$outputValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'requested_outputs' => ['title', 'contributor_name', 'barcode'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(true, $outputValidation['valid'] ?? null, 'Covered family slot payloads should allow contributor_name as a supported output field.');

$outputIntent = QueryFamilySlotService::toQueryIntent($outputValidation['normalizedPayload'] ?? []);
assertSameValue(
    'inventory_instance__t__contributors',
    $outputIntent['query']['select'][1]['table'] ?? null,
    'Contributor name outputs should compile onto the contributor subtable rather than failing validation.'
);
assertSameValue(
    'contributors__name',
    $outputIntent['query']['select'][1]['column'] ?? null,
    'Contributor name outputs should select the contributor name column from the contributor subtable.'
);
assertSameValue(
    'contributor_name',
    $outputIntent['query']['select'][1]['alias'] ?? null,
    'Contributor name outputs should use a stable contributor_name alias in QueryIntent translation.'
);

$missingRequired = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'requested_outputs' => ['barcode'],
    ],
]);

assertSameValue(false, $missingRequired['valid'] ?? null, 'Missing required family slots should fail validation deterministically.');
assertSameValue(
    'slots.contributor_name',
    $missingRequired['errors'][0]['path'] ?? null,
    'Missing required slot errors should point at the missing family slot.'
);

$genericContributorPlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'other author',
        'requested_outputs' => ['barcode'],
    ],
]);

assertSameValue(false, $genericContributorPlaceholder['valid'] ?? null, 'Generic contributor labels should not satisfy the required contributor_name slot.');
assertSameValue(
    'slots.contributor_name',
    $genericContributorPlaceholder['errors'][0]['path'] ?? null,
    'Generic contributor placeholders should collapse back to the missing contributor_name slot.'
);
assertSameValue(
    'required',
    $genericContributorPlaceholder['errors'][0]['code'] ?? null,
    'Generic contributor placeholders should surface as a missing required slot rather than a usable contributor value.'
);

$genericMaterialTypePlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_top_items',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Josten Library',
        'material_type' => 'document type',
        'requested_outputs' => ['ranked_circulation_items'],
    ],
]);

assertSameValue(false, $genericMaterialTypePlaceholder['valid'] ?? null, 'Generic material-type labels should not satisfy the material_type slot.');
assertSameValue(
    'slots.material_type',
    $genericMaterialTypePlaceholder['errors'][0]['path'] ?? null,
    'Generic material-type placeholders should collapse back to the missing material_type slot when that slot is required.'
);
assertSameValue(
    'required',
    $genericMaterialTypePlaceholder['errors'][0]['code'] ?? null,
    'Generic material-type placeholders should surface as a missing required slot rather than a usable material-type value.'
);

$genericGroupingDimensionPlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'grouping dimension',
        'year_buckets' => ['2026', '2025', '2024', '2023'],
        'requested_outputs' => ['yearly_circulation_matrix'],
    ],
]);

assertSameValue(false, $genericGroupingDimensionPlaceholder['valid'] ?? null, 'Generic grouping-dimension labels should not satisfy the trend grouping_dimension slot.');
assertSameValue(
    'slots.grouping_dimension',
    $genericGroupingDimensionPlaceholder['errors'][0]['path'] ?? null,
    'Generic grouping-dimension placeholders should collapse back to the missing grouping_dimension slot when that slot is required.'
);
assertSameValue(
    'required',
    $genericGroupingDimensionPlaceholder['errors'][0]['code'] ?? null,
    'Generic grouping-dimension placeholders should surface as a missing required slot rather than a usable grouping value.'
);

$libraryExactMatch = QueryFamilySlotService::resolveSlotMatch('library', 'Josten Library', 'exact_phrase');
assertSameValue(
    'ILIKE',
    $libraryExactMatch['op'] ?? null,
    'Library slot matching should continue using ILIKE semantics.'
);
assertSameValue(
    '%Josten Library%',
    $libraryExactMatch['value'] ?? null,
    'Library slot matching should preserve contains semantics even when the prompt looks exact, because stored names carry campus prefixes.'
);

$locationExactMatch = QueryFamilySlotService::resolveSlotMatch('location', 'Treasure Case', 'exact_phrase');
assertSameValue(
    '%Treasure Case%',
    $locationExactMatch['value'] ?? null,
    'Location slot matching should preserve contains semantics even when the prompt looks exact, because stored names carry campus prefixes and naming variants.'
);

$locationCodeExactMatch = QueryFamilySlotService::resolveSlotMatch('location_code', 'sjtrf', 'exact_phrase');
assertSameValue(
    'ILIKE',
    $locationCodeExactMatch['op'] ?? null,
    'Location-code slot matching should keep case-insensitive exact semantics.'
);
assertSameValue(
    'SJTRF',
    $locationCodeExactMatch['value'] ?? null,
    'Location-code slot matching should normalize listing prompts onto uppercase stored codes.'
);

$multiLocationCodeExactMatch = QueryFamilySlotService::resolveSlotMatch('location_code', 'sjtr, sjtrf', 'exact_phrase');
assertSameValue(
    'IN',
    $multiLocationCodeExactMatch['op'] ?? null,
    'Multiple location-code filters should compile to exact IN semantics.'
);
assertSameValue(
    'SJTR,SJTRF',
    $multiLocationCodeExactMatch['value'] ?? null,
    'Multiple location-code filters should normalize to an uppercase comma-delimited code list.'
);

$listingLocationCodeValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'location_code' => 'sjtrf',
        'requested_outputs' => ['title', 'barcode', 'instance_number'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(false, $listingLocationCodeValidation['valid'] ?? null, 'Single location-code listing payloads should still require a library to disambiguate scope.');
assertSameValue(
    'slots.library',
    $listingLocationCodeValidation['errors'][0]['path'] ?? null,
    'Single location-code listing payloads should fall back to the missing library slot when no library is supplied.'
);
assertSameValue(
    'required',
    $listingLocationCodeValidation['errors'][0]['code'] ?? null,
    'Single location-code listing payloads should clarify for a missing library rather than compile directly.'
);

$listingOnlyHoldingWithoutLibraryValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'location' => 'SC Rare Book Collection Reference',
        'only_holding_location' => true,
        'requested_outputs' => ['title'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(
    true,
    $listingOnlyHoldingWithoutLibraryValidation['valid'] ?? null,
    'Only-holding location listing prompts should validate when location scope is explicit and library is omitted.'
);
assertSameValue(
    false,
    array_key_exists('library', $listingOnlyHoldingWithoutLibraryValidation['normalizedPayload']['slots'] ?? []),
    'Library scope should no longer be mandatory for explicit only-holding location prompts.'
);
assertSameValue(
    true,
    $listingOnlyHoldingWithoutLibraryValidation['normalizedPayload']['slots']['only_holding_location'] ?? null,
    'Only-holding intent should remain explicit in normalized slot output.'
);

$listingMultiLocationCodeValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'location_code' => 'sjtr, sjtrf',
        'requested_outputs' => ['title', 'barcode', 'instance_number'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(true, $listingMultiLocationCodeValidation['valid'] ?? null, 'Multi-code listing payloads should still validate without a library when the explicit code set scopes the report.');
assertSameValue(
    'sjtr, sjtrf',
    $listingMultiLocationCodeValidation['normalizedPayload']['slots']['location_code'] ?? null,
    'Multi-code listing payloads should preserve the explicit location_code scope through validation.'
);

$genericListingLibraryPlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'library' => 'specific library',
        'requested_outputs' => ['title', 'barcode'],
    ],
]);

assertSameValue(false, $genericListingLibraryPlaceholder['valid'] ?? null, 'Generic library placeholders should not satisfy the required library slot for library/location listing prompts.');
assertSameValue(
    'slots.library',
    $genericListingLibraryPlaceholder['errors'][0]['path'] ?? null,
    'Generic listing-library placeholders should collapse back to the missing library slot.'
);
assertSameValue(
    'required',
    $genericListingLibraryPlaceholder['errors'][0]['code'] ?? null,
    'Generic listing-library placeholders should surface as a missing required slot rather than a usable library value.'
);

$emptyListingLibraryValue = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'library' => '   ',
        'requested_outputs' => ['title', 'barcode'],
    ],
]);

assertSameValue(false, $emptyListingLibraryValue['valid'] ?? null, 'Empty listing-library slot values should not validate as usable scope.');
assertSameValue(
    'slots.library',
    $emptyListingLibraryValue['errors'][0]['path'] ?? null,
    'Empty listing-library slot values should collapse back to the missing library slot.'
);
assertSameValue(
    'required',
    $emptyListingLibraryValue['errors'][0]['code'] ?? null,
    'Empty listing-library slot values should surface as a missing required slot instead of a hard type error.'
);

$genericListingLocationCodePlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'library' => 'Josten Library',
        'location_code' => 'location code',
        'requested_outputs' => ['title', 'barcode'],
    ],
]);

assertSameValue(true, $genericListingLocationCodePlaceholder['valid'] ?? null, 'Optional listing-family location-code placeholders should be dropped rather than invalidating the broader library-scoped prompt.');
assertSameValue(
    false,
    array_key_exists('location_code', $genericListingLocationCodePlaceholder['normalizedPayload']['slots'] ?? []),
    'Optional listing-family location-code placeholders should not survive normalization as usable filters.'
);

$collectionLocationMatch = QueryFamilySlotService::resolveSlotMatch('location', 'Reference collection', 'case_insensitive_contains');
assertSameValue(
    '%Reference collection%',
    $collectionLocationMatch['value'] ?? null,
    'Collection-age location slot matching should preserve the explicit Reference collection phrase so deterministic location filters do not widen to a generic Reference match.'
);

$trendValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => [2026, '2025', 2024, '2023'],
        'circulation_source_policy' => 'current_loans_only',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(true, $trendValidation['valid'] ?? null, 'Trend-matrix family slot payloads should validate when they provide year_buckets as an ordered year array.');
assertSameValue(
    ['2026', '2025', '2024', '2023'],
    $trendValidation['normalizedPayload']['slots']['year_buckets'] ?? null,
    'Trend-matrix year buckets should normalize to ordered year strings without losing the requested sequence.'
);

$invalidTrendValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => '2026,2025,2024,2023',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(false, $invalidTrendValidation['valid'] ?? null, 'Trend-matrix family slot payloads should reject year_buckets when they arrive as a freeform string instead of a year array.');
assertSameValue(
    'slots.year_buckets',
    $invalidTrendValidation['errors'][0]['path'] ?? null,
    'Invalid trend year-bucket payloads should point at the year_buckets slot.'
);

$genericTrendYearBucketsPlaceholder = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => ['required years'],
        'requested_outputs' => ['yearly_circulation_matrix'],
    ],
]);

assertSameValue(false, $genericTrendYearBucketsPlaceholder['valid'] ?? null, 'Generic trend year-bucket labels should not satisfy the required year_buckets slot.');
assertSameValue(
    'slots.year_buckets',
    $genericTrendYearBucketsPlaceholder['errors'][0]['path'] ?? null,
    'Generic trend year-bucket placeholders should collapse back to the missing year_buckets slot when that slot is required.'
);
assertSameValue(
    'required',
    $genericTrendYearBucketsPlaceholder['errors'][0]['code'] ?? null,
    'Generic trend year-bucket placeholders should surface as a missing required slot rather than a usable timeframe value.'
);

fwrite(STDOUT, "QueryFamilySlotService test passed\n");
