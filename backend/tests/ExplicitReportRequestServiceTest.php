<?php

$servicePath = __DIR__ . '/../services/ExplicitReportRequestService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "ExplicitReportRequestService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\ExplicitReportRequestService;

function explicitAssertTrue($actual, string $message): void
{
    if ($actual !== true) {
        fwrite(STDERR, $message . "\nExpected: true\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function explicitAssertFalse($actual, string $message): void
{
    if ($actual !== false) {
        fwrite(STDERR, $message . "\nExpected: false\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function explicitAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$request = ExplicitReportRequestService::extract(
    'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.'
);
explicitAssertTrue($request['applicable'], 'Named instance numbers and requested fields should create an explicit request.');
explicitAssertSame(['in0001', 'in0002'], $request['identifiers']['instance_hrid'], 'Comma-separated instance HRIDs must preserve order.');
explicitAssertSame(['title', 'barcode', 'publication_date'], $request['requestedFields'], 'Supported output phrases must be normalized in prompt order.');
explicitAssertSame(20, $request['limit'], 'An explicit limit must be extracted.');
explicitAssertSame(
    "EXPLICIT REPORT VALUES — preserve exactly:\ninstance_hrid: [\"in0001\",\"in0002\"]\nrequested_fields: [\"title\",\"barcode\",\"publication_date\"]\nlimit: 20\nDo not broaden, replace, or infer additional identifiers.",
    ExplicitReportRequestService::buildGuidance($request),
    'Server-authored guidance must preserve the extracted values verbatim.'
);

$newlineRequest = ExplicitReportRequestService::extract("For instance HRIDs:\nin0002\nin0001\nin0002\nshow title.");
explicitAssertSame(['in0002', 'in0001'], $newlineRequest['identifiers']['instance_hrid'], 'Newline-separated duplicate HRIDs must remain first-seen ordered.');

$barcodeRequest = ExplicitReportRequestService::extract('For barcodes "32101012345678", "32101012345679", show title and barcode.');
explicitAssertSame(['32101012345678', '32101012345679'], $barcodeRequest['identifiers']['item_barcode'], 'Quoted barcodes must be extracted only after the barcode anchor.');

$uuidRequest = ExplicitReportRequestService::extract('For instance ID 550e8400-e29b-41d4-a716-446655440000 and item ID "550e8400-e29b-41d4-a716-446655440001", show title.');
explicitAssertSame(['550e8400-e29b-41d4-a716-446655440000'], $uuidRequest['identifiers']['instance_id'], 'UUID instance identifiers require an explicit instance ID label.');
explicitAssertSame(['550e8400-e29b-41d4-a716-446655440001'], $uuidRequest['identifiers']['item_id'], 'UUID item identifiers require an explicit item ID label.');

$pluralUuidRequest = ExplicitReportRequestService::extract('For instance IDs 550e8400-e29b-41d4-a716-446655440000 and item IDs 550e8400-e29b-41d4-a716-446655440001, show title.');
explicitAssertSame(['550e8400-e29b-41d4-a716-446655440000'], $pluralUuidRequest['identifiers']['instance_id'], 'Plural instance ID anchors must extract explicit UUIDs.');
explicitAssertSame(['550e8400-e29b-41d4-a716-446655440001'], $pluralUuidRequest['identifiers']['item_id'], 'Plural item ID anchors must extract explicit UUIDs.');

$uppercaseUuid = 'ABCDEFAB-CDEF-CDEF-CDEF-ABCDEFABCDEF';
$uppercaseUuidRequest = ExplicitReportRequestService::extract('For instance ID ' . $uppercaseUuid . ', show title.');
explicitAssertSame([$uppercaseUuid], $uppercaseUuidRequest['identifiers']['instance_id'], 'Explicit UUID spelling and case must be preserved exactly.');

$alphabeticBarcode = ExplicitReportRequestService::extract('For barcode ABCD-EFGH, show title.');
explicitAssertSame(['ABCD-EFGH'], $alphabeticBarcode['identifiers']['item_barcode'], 'Explicitly labelled alphabetic barcodes must be preserved.');
explicitAssertSame(['title'], $alphabeticBarcode['requestedFields'], 'Identifier labels must not become requested output fields.');

$identifierOnlyPrompt = ExplicitReportRequestService::extract('For barcode 32101012345678, show title.');
explicitAssertSame(['title'], $identifierOnlyPrompt['requestedFields'], 'Fields must be extracted only from output-request language.');

$fieldBeforeIdentifier = ExplicitReportRequestService::extract('Show title for barcode ABCD-EFGH.');
explicitAssertSame(['title'], $fieldBeforeIdentifier['requestedFields'], 'Output fields must stop before an identifier filter phrase.');

$filteredByIdentifier = ExplicitReportRequestService::extract('Show title filtered by barcode ABCD-EFGH.');
explicitAssertSame(['title'], $filteredByIdentifier['requestedFields'], 'Output fields must stop before a filtered-by identifier phrase.');

$overflowPrompt = 'For instance numbers ' . implode(', ', array_map(function ($number): string {
    return 'in' . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
}, range(1, 501))) . ', show title.';
$overflowRequest = ExplicitReportRequestService::extract($overflowPrompt);
explicitAssertTrue($overflowRequest['needsClarification'], 'More than 500 explicit identifiers must request clarification.');
explicitAssertSame(500, count($overflowRequest['identifiers']['instance_hrid'] ?? []), 'Overflow handling must cap retained identifiers at 500.');

$ordinaryNumbers = ExplicitReportRequestService::extract('Show spending for 2024 with a $20.00 threshold and top 10 results.');
explicitAssertFalse($ordinaryNumbers['applicable'], 'Years, currency, and unlabeled numeric tokens must not become identifiers.');
explicitAssertSame([], $ordinaryNumbers['identifiers'], 'Unlabeled numeric tokens must not produce identifiers.');

$candidate = ExplicitReportRequestService::validateCandidate(
    "SELECT inst.hrid AS instance_hrid, inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002') LIMIT 20",
    $request
);
explicitAssertTrue($candidate['valid'], 'A candidate retaining every explicit identifier and supported output should validate.');

$missingIdentifier = ExplicitReportRequestService::validateCandidate(
    "SELECT inst.hrid AS instance_hrid, inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001') LIMIT 20",
    $request
);
explicitAssertSame(['in0002'], $missingIdentifier['missingIdentifiers'], 'Omitted explicit identifiers must be reported.');

$missingField = ExplicitReportRequestService::validateCandidate(
    "SELECT inst.hrid AS instance_hrid, inst.title, item.barcode FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002') LIMIT 20",
    $request
);
explicitAssertSame(['publication_date'], $missingField['missingFields'], 'Omitted supported output fields must be reported.');

$unboundIdentifier = ExplicitReportRequestService::validateCandidate(
    "SELECT 'in0001' AS note, inst.title FROM inventory.instance__t inst WHERE inst.title = 'unrelated' LIMIT 20",
    ExplicitReportRequestService::extract('For instance number in0001, show title. Limit 20.')
);
explicitAssertFalse($unboundIdentifier['valid'], 'An identifier literal outside its supported filter column must not satisfy validation.');
explicitAssertSame(['in0001'], $unboundIdentifier['missingIdentifiers'], 'Identifier validation must require the matching entity filter.');

$lowercaseUuidCandidate = ExplicitReportRequestService::validateCandidate(
    "SELECT inst.title FROM inventory.instance__t inst WHERE inst.id = 'abcdefab-cdef-cdef-cdef-abcdefabcdef'",
    $uppercaseUuidRequest
);
explicitAssertFalse($lowercaseUuidCandidate['valid'], 'A changed UUID case must not satisfy exact-preservation validation.');

foreach ([
    "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid <> 'in0001'",
    "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid >= 'in0001'",
] as $nonMembershipSql) {
    $nonMembership = ExplicitReportRequestService::validateCandidate(
        $nonMembershipSql,
        ExplicitReportRequestService::extract('For instance number in0001, show title.')
    );
    explicitAssertFalse($nonMembership['valid'], 'Exclusion and range predicates must not satisfy explicit identifier membership.');
}

$cteIdentifier = ExplicitReportRequestService::validateCandidate(
    "WITH filtered AS (SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001') SELECT filtered.title FROM filtered",
    ExplicitReportRequestService::extract('For instance number in0001, show title.')
);
explicitAssertTrue($cteIdentifier['valid'], 'A positive identifier predicate inside a CTE must satisfy validation.');

$unqualifiedIdentifier = ExplicitReportRequestService::validateCandidate(
    "SELECT title FROM inventory.instance__t WHERE hrid = 'in0001'",
    ExplicitReportRequestService::extract('For instance number in0001, show title.')
);
explicitAssertTrue($unqualifiedIdentifier['valid'], 'An unqualified identifier predicate on one unambiguous relation must satisfy validation.');

fwrite(STDOUT, "ExplicitReportRequestService test passed\n");
