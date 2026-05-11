<?php

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$servicePath = __DIR__ . '/../services/GeminiService.php';

if (!file_exists($contractServicePath)) {
    fwrite(STDERR, "QueryFamilyContractService is missing at {$contractServicePath}\n");
    exit(1);
}

if (!file_exists($servicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$servicePath}\n");
    exit(1);
}

require_once $contractServicePath;
require_once $servicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$familyResolver = new ReflectionMethod(GeminiService::class, 'resolvePromptQueryFamily');
$promptBuilder = new ReflectionMethod(GeminiService::class, 'buildQueryFamilySlotSystemPrompt');

$corporateBodyPrompt = 'Create a list of materials with the document type "Theses" and other author "Smith College. Department of Biological Sciences." Include title, publication date, barcode and instance number.';

assertSameValue(
    null,
    $familyResolver->invoke(null, $corporateBodyPrompt, null),
    'Contributor names containing "College" should not count as campus scope when the prompt otherwise lacks a campus, library, location, or holdings constraint.'
);

$resolvedWithCampusContext = $familyResolver->invoke(null, $corporateBodyPrompt, 'Smith College');
assertSameValue(
    'inventory_contributor_campus_item_barcode',
    $resolvedWithCampusContext['familyKey'] ?? null,
    'Home-campus context should allow covered contributor/barcode prompts onto the family-slot path even when the prompt omits an explicit campus phrase.'
);

$resolvedWithExplicitScope = $familyResolver->invoke(
    null,
    'Show barcodes and titles for items in Neilson Library with other author "Smith College. Department of Biological Sciences."',
    null
);
assertSameValue(
    'inventory_contributor_campus_item_barcode',
    $resolvedWithExplicitScope['familyKey'] ?? null,
    'Explicit library or holdings scope in the prompt should activate the covered family without relying on home-campus context.'
);

$collectionAgePrompt = 'What is the average age of the Neilson Reference collection?';
$resolvedCollectionAge = $familyResolver->invoke(null, $collectionAgePrompt, 'Smith College');
assertSameValue(
    'inventory_collection_age',
    $resolvedCollectionAge['familyKey'] ?? null,
    'Library or collection-scoped age prompts should route onto the deterministic collection-age family.'
);

$referenceCollectionPrompt = 'What is the average age of the reference collection in Neilson Library?';
$resolvedReferenceCollection = $familyResolver->invoke(null, $referenceCollectionPrompt, 'Smith College');
assertSameValue(
    'inventory_collection_age',
    $resolvedReferenceCollection['familyKey'] ?? null,
    'Reference-collection age prompts should still route onto the deterministic collection-age family when the library and collection phrases are reversed.'
);

$locationListingPrompt = 'List of materials in Josten Library in the Treasure Case location. Include title, author, pub date, barcode and instance number.';
$resolvedLocationListing = $familyResolver->invoke(null, $locationListingPrompt, 'Smith College');
assertSameValue(
    'inventory_library_location_listing',
    $resolvedLocationListing['familyKey'] ?? null,
    'Library/location inventory listing prompts with title, author, publication date, barcode, and instance number outputs should resolve onto a deterministic inventory listing family instead of falling through generic builder conversion.'
);

$locationCodeOnlyListingPrompt = 'List of materials in location code SJTR. Include title, author, pub date, barcode and instance number.';
$resolvedLocationCodeOnlyListing = $familyResolver->invoke(null, $locationCodeOnlyListingPrompt, 'Smith College');
assertSameValue(
    'inventory_library_location_listing',
    $resolvedLocationCodeOnlyListing['familyKey'] ?? null,
    'Location-code inventory listing prompts without an explicit library should still resolve onto the deterministic inventory listing family so the request path can clarify for the missing library.'
);

$slotPrompt = $promptBuilder->invoke(null, 'inventory_contributor_campus_item_barcode', 'Smith College');
assertContainsText(
    'Supported match policies: ["case_insensitive_contains","exact_phrase"]',
    $slotPrompt,
    'Family slot prompt should expose the supported match-policy contract to the model.'
);
assertContainsText(
    "If the prompt does not name another campus explicitly, set slots.campus to 'Smith College'.",
    $slotPrompt,
    'Family slot prompt should preserve the deterministic campus default rule.'
);
assertContainsText(
    'Choose exact_phrase when the prompt uses quotation marks or wording such as named, listed as, or called for a contributor or other named entity; otherwise use case_insensitive_contains.',
    $slotPrompt,
    'Family slot prompt should explicitly describe the deterministic exact-name policy introduced for named-entity prompts.'
);

$collectionAgeSlotPrompt = $promptBuilder->invoke(null, 'inventory_collection_age', 'Smith College');
assertContainsText(
    '"familyKey": "inventory_collection_age"',
    $collectionAgeSlotPrompt,
    'Collection-age family slot prompts should render the requested family key in the extraction contract.'
);
assertContainsText(
    'Supported slots: ["campus","library","location","age_basis","aggregation","unit"]',
    $collectionAgeSlotPrompt,
    'Collection-age family slot prompts should expose the supported age-family slots to the model.'
);
assertContainsText(
    'Allowed outputs: ["average_age_years"]',
    $collectionAgeSlotPrompt,
    'Collection-age family slot prompts should constrain the output contract to the supported age metric.'
);

$trendMatrixPrompt = 'Show circulation numbers for 2026, 2025, 2024, and 2023 by primary call number class in Neilson Library.';
$resolvedTrendMatrix = $familyResolver->invoke(null, $trendMatrixPrompt, 'Smith College');
assertSameValue(
    'circulation_trends_matrix',
    $resolvedTrendMatrix['familyKey'] ?? null,
    'Fully specified yearly circulation trend prompts with an explicit library scope should route onto the deterministic trend-matrix family.'
);

$topCirculatingPrompt = 'Show me the top 10 circulating books at Josten Library.';
$resolvedTopCirculating = $familyResolver->invoke(null, $topCirculatingPrompt, 'Smith College');
assertSameValue(
    'circulation_top_items',
    $resolvedTopCirculating['familyKey'] ?? null,
    'Top-circulating book prompts with an explicit library scope should route onto a deterministic circulation_top_items family instead of falling through generic builder conversion.'
);

$topCirculatingDvdsPrompt = 'Show me the top 10 circulating DVDs at Josten Library.';
$resolvedTopCirculatingDvds = $familyResolver->invoke(null, $topCirculatingDvdsPrompt, 'Smith College');
assertSameValue(
    'circulation_top_items',
    $resolvedTopCirculatingDvds['familyKey'] ?? null,
    'Top-circulating prompts should stay on the deterministic circulation_top_items family for non-book material types too when an explicit library scope is present.'
);

$ambiguousTrendPrompt = 'Show circulation numbers for 2026, 2025, 2024, and 2023 by primary call number class with previous circulation data.';
assertSameValue(
    null,
    $familyResolver->invoke(null, $ambiguousTrendPrompt, 'Smith College'),
    'Trend prompts that still depend on clarification-first ambiguity handling should not bypass the deterministic clarification gate via direct family selection.'
);

$trendSlotPrompt = $promptBuilder->invoke(null, 'circulation_trends_matrix', 'Smith College');
assertContainsText(
    '"familyKey": "circulation_trends_matrix"',
    $trendSlotPrompt,
    'Trend-matrix family slot prompts should render the requested family key in the extraction contract.'
);
assertContainsText(
    'Supported slots: ["campus","library","location","grouping_dimension","year_buckets","circulation_source_policy"]',
    $trendSlotPrompt,
    'Trend-matrix family slot prompts should expose the supported trend-family slots to the model.'
);
assertContainsText(
    '"year_buckets": ["required years"]',
    $trendSlotPrompt,
    'Trend-matrix family slot prompts should require year_buckets as an ordered array of requested years rather than a freeform string.'
);
assertContainsText(
    'Allowed outputs: ["yearly_circulation_matrix"]',
    $trendSlotPrompt,
    'Trend-matrix family slot prompts should constrain the output contract to the first supported matrix metric.'
);

fwrite(STDOUT, "GeminiService query family selection test passed\n");