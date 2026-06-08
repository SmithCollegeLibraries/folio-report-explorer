<?php

$controllerPath = __DIR__ . '/../commands/ReferenceCacheController.php';

if (!file_exists($controllerPath)) {
    fwrite(STDERR, "ReferenceCacheController is missing at {$controllerPath}\n");
    exit(1);
}

$controller = (string)file_get_contents($controllerPath);

function assertInventoryAllowlistContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$expectedTables = [
    'inventory.item_damaged_status__t',
    'inventory.instance_relationship_type__t',
    'inventory.electronic_access_relationship__t',
    'inventory.ill_policy__t',
    'inventory.statistical_code_type__t',
    'inventory.statistical_code__t',
    'inventory.holdings_note_type__t',
    'inventory.item_note_type__t',
    'inventory.contributor_name_type__t',
    'inventory.mode_of_issuance__t',
    'inventory.subject_sources__t',
    'inventory.alternative_title_type__t',
    'inventory.nature_of_content_term__t',
    'inventory.instance_note_type__t',
    'inventory.contributor_type__t',
    'inventory.identifier_type__t',
];

foreach ($expectedTables as $table) {
    assertInventoryAllowlistContains(
        "'{$table}'",
        $controller,
        "{$table} should be enabled in the default reference-cache allowlist."
    );
}

fwrite(STDOUT, "Reference cache inventory allowlist test passed\n");
