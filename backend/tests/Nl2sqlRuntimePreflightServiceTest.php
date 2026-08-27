<?php

$servicePath = __DIR__ . '/../services/Nl2sqlRuntimePreflightService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "Nl2sqlRuntimePreflightService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

$serviceClass = 'app\\services\\Nl2sqlRuntimePreflightService';

if (!class_exists($serviceClass)) {
    fwrite(STDERR, "Nl2sqlRuntimePreflightService class was not loaded from {$servicePath}\n");
    exit(1);
}

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

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

$artifactPaths = [
    'canonicalQueryGraph' => __DIR__ . '/../data/canonical_query_graph.json',
    'queryFamilyContracts' => __DIR__ . '/../data/query_family_contracts.json',
    'folioSchema' => __DIR__ . '/../data/folio_schema.json',
    'folioDerivedTables' => __DIR__ . '/../data/folio_derived_tables.json',
];

$legacyDefaultReport = $serviceClass::buildReport(
    [
        'nl2sqlIntentMode' => false,
        'nl2sqlPrimaryMode' => 'auto',
        'nl2sqlShadowMode' => false,
        'nl2sqlShadowUsers' => '',
        'nl2sqlShadowSamplePercent' => 100,
        'nl2sqlForceLegacy' => false,
    ],
    [],
    $artifactPaths
);

assertSameValue('warning', $legacyDefaultReport['status'] ?? null, 'Blank settings with legacy-default runtime flags should surface as a warning state.');
assertSameValue(false, $legacyDefaultReport['settings']['hasSettingsFile'] ?? true, 'The preflight report should note when settings.json is absent or empty.');
assertTrueValue(!empty($legacyDefaultReport['warnings']), 'The preflight report should emit warnings when deterministic runtime settings are not persisted.');
assertContainsText(
    'settings.json',
    implode(' ', $legacyDefaultReport['warnings'] ?? []),
    'The warning set should explain that runtime settings are falling back without persisted settings.json values.'
);
assertContainsText(
    'legacy defaults',
    implode(' ', $legacyDefaultReport['warnings'] ?? []),
    'The warning set should mention that the effective NL2SQL runtime flags still resolve to legacy defaults.'
);
assertSameValue(
    true,
    $legacyDefaultReport['effective']['nl2sqlTwoLaneEnabled'] ?? null,
    'Older direct callers must retain the default-on two-lane rollout.'
);
assertSameValue(
    true,
    $legacyDefaultReport['effective']['nl2sqlCoordinatorEnabled'] ?? null,
    'Older direct callers must receive the default-on Ask coordinator.'
);
assertTrueValue(!empty($legacyDefaultReport['artifacts']['canonicalQueryGraph']['hash'] ?? null), 'The preflight report should include canonical graph artifact hash metadata.');

$intentReadyReport = $serviceClass::buildReport(
    [
        'nl2sqlIntentMode' => true,
        'nl2sqlPrimaryMode' => 'intent',
        'nl2sqlShadowMode' => true,
        'nl2sqlShadowUsers' => 'all',
        'nl2sqlShadowSamplePercent' => 100,
        'nl2sqlForceLegacy' => false,
        'nl2sqlTwoLaneEnabled' => true,
        'nl2sqlCoordinatorEnabled' => true,
    ],
    [
        'nl2sql_intent_mode' => true,
        'nl2sql_primary_mode' => 'intent',
        'nl2sql_shadow_mode' => true,
        'nl2sql_shadow_users' => 'all',
        'nl2sql_shadow_sample_percent' => 100,
        'nl2sql_force_legacy' => false,
        'nl2sql_two_lane_enabled' => true,
        'nl2sql_coordinator_enabled' => true,
    ],
    $artifactPaths
);

assertSameValue('ok', $intentReadyReport['status'] ?? null, 'Intent-enabled persisted runtime settings should report a healthy parity state.');
assertSameValue(true, $intentReadyReport['settings']['hasSettingsFile'] ?? false, 'The preflight report should recognize persisted runtime settings.');
assertSameValue(true, $intentReadyReport['effective']['nl2sqlIntentMode'] ?? false, 'The preflight report should surface the effective intent-mode flag.');
assertSameValue('intent', $intentReadyReport['effective']['nl2sqlPrimaryMode'] ?? null, 'The preflight report should surface the effective primary mode.');
assertSameValue(false, $intentReadyReport['effective']['nl2sqlForceLegacy'] ?? true, 'The preflight report should surface the effective emergency rollback state.');
assertSameValue(true, $intentReadyReport['effective']['nl2sqlTwoLaneEnabled'], 'Two-lane routing should be visible in preflight.');
assertSameValue(true, $intentReadyReport['effective']['nl2sqlCoordinatorEnabled'], 'Ask coordinator routing should be visible in preflight.');
assertContainsText(
    'does not rewrite stored provenance',
    (string)($intentReadyReport['readinessNotes']['nl2sqlCoordinatorEnabled'] ?? ''),
    'Preflight should explain that the routing flag never rewrites stored provenance.'
);
assertSameValue([], $intentReadyReport['warnings'] ?? null, 'Healthy runtime parity should not emit warnings.');

$rollback = $serviceClass::buildReport(
    ['nl2sqlTwoLaneEnabled' => false, 'nl2sqlCoordinatorEnabled' => false],
    ['nl2sql_two_lane_enabled' => false, 'nl2sql_coordinator_enabled' => false],
    $artifactPaths
);
assertTrueValue(
    count(array_filter($rollback['warnings'], function ($warning) {
        return strpos($warning, 'Ask generation coordinator rollback') !== false;
    })) === 1,
    'Preflight should identify the Ask coordinator rollback path.'
);
assertTrueValue(
    count(array_filter($rollback['warnings'], function ($warning) {
        return strpos($warning, 'strict blocker rollback') !== false;
    })) === 1,
    'Preflight should identify the rollback path.'
);

fwrite(STDOUT, "Nl2sqlRuntimePreflightService test passed\n");
