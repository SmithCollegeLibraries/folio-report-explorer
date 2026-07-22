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

fwrite(STDOUT, "ExplicitReportRequestService test passed\n");
