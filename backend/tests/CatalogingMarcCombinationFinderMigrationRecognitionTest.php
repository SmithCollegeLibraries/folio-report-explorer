<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/CatalogingReportMigrationRecognitionService.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
if (is_file(__DIR__ . '/../services/CatalogingMarcCombinationFinderService.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';
}

$failures = [];

final class MarcCombinationRecognitionDb
{
    public $definition;
    public $queries = [];
    public function __construct(array $definition) { $this->definition = $definition; }
    public function createCommand(string $sql, array $params = []): object
    {
        $this->queries[] = [$sql, $params];
        return new class($this, $params) {
            private $db;
            private $params;
            public function __construct($db, array $params) { $this->db = $db; $this->params = $params; }
            public function queryOne() { return ($this->params[':slug'] ?? null) === 'marc-field-combination-finder' ? $this->db->definition : null; }
            public function queryScalar() { return 0; }
        };
    }
}

function marcCombinationRecognitionCase(string $name, callable $callback): void
{
    global $failures;
    $before = count($failures);
    try {
        $callback();
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . get_class($error) . ': ' . $error->getMessage();
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS: {$name}\n");
    }
}

function marcCombinationRecognitionSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        global $failures;
        $failures[] = $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function marcCombinationRecognitionBaseline(array $definition): array
{
    $method = new ReflectionMethod(\app\services\AcrlQuestionAssignmentMigrationRecognitionService::class, 'baselineCurrentSchema');
    if (PHP_VERSION_ID < 80500) {
        $method->setAccessible(true);
    }
    return $method->invoke(null, new MarcCombinationRecognitionDb($definition), [
        ['filename' => '068_marc_field_combination_finder.sql', 'checksum' => str_repeat('a', 64)],
        ['filename' => '069_marc_combination_finder_record_scopes.sql', 'checksum' => str_repeat('b', 64)],
    ], true);
}

$legacyDefinition = marcCombinationFixtureSeed(__DIR__ . '/../../mysql/migrations/068_marc_field_combination_finder.sql');
$migrationDefinition = marcCombinationFixtureSeed();
$migrationRecognition = new ReflectionMethod(\app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) {
    $migrationRecognition->setAccessible(true);
}

marcCombinationRecognitionCase('068 recognizes its historical and current report definitions while 069 requires the current definition', function () use ($legacyDefinition, $migrationDefinition, $migrationRecognition) {
    foreach ([$legacyDefinition, $migrationDefinition] as $definition) {
        $db = new MarcCombinationRecognitionDb($definition);
        marcCombinationRecognitionSame(true, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete($db, '068_marc_field_combination_finder.sql'), 'Migration 068 accepts its historical definition and the 069 replacement.');
        marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, $db, '068_marc_field_combination_finder.sql'), 'Migration 068 must remain recognized after 069.');
    }
    $currentDb = new MarcCombinationRecognitionDb($migrationDefinition);
    $legacyDb = new MarcCombinationRecognitionDb($legacyDefinition);
    marcCombinationRecognitionSame(true, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete($currentDb, '069_marc_combination_finder_record_scopes.sql'), 'The report migration definition list recognizes 069.');
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, $currentDb, '069_marc_combination_finder_record_scopes.sql'), 'Migration 069 is registered in per-file dispatch.');
    marcCombinationRecognitionSame(false, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete($legacyDb, '069_marc_combination_finder_record_scopes.sql'), 'Migration 069 does not recognize the historical 068 definition.');
    marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, $legacyDb, '069_marc_combination_finder_record_scopes.sql'), 'The 068 definition must not mark 069 as applied.');
    if (method_exists(\app\services\CatalogingReportMigrationRecognitionService::class, 'marcCombinationFinderReportAppearsComplete')) {
        marcCombinationRecognitionSame(true, \app\services\CatalogingReportMigrationRecognitionService::marcCombinationFinderReportAppearsComplete($currentDb), 'Runtime report recognition must expose the current report check.');
        marcCombinationRecognitionSame(false, \app\services\CatalogingReportMigrationRecognitionService::marcCombinationFinderReportAppearsComplete($legacyDb), 'Runtime recognition accepts only the 069 definition.');
    } else {
        global $failures;
        $failures[] = 'Cataloging report recognition must expose marcCombinationFinderReportAppearsComplete().';
        fwrite(STDERR, "FAIL: Cataloging report recognition is missing the combination finder fingerprint check.\n");
    }
});

marcCombinationRecognitionCase('068 and 069 recognition accept JSON columns as strings or decoded arrays', function () use ($legacyDefinition, $migrationDefinition, $migrationRecognition) {
    $legacy = $legacyDefinition;
    $legacy['parameters'] = json_decode($legacy['parameters'], true);
    $legacy['execution_config'] = json_decode($legacy['execution_config'], true);
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($legacy), '068_marc_field_combination_finder.sql'), 'Decoded legacy JSON matches migration 068.');
    $decoded = $migrationDefinition;
    $decoded['parameters'] = json_decode($decoded['parameters'], true);
    $decoded['execution_config'] = json_decode($decoded['execution_config'], true);
    $db = new MarcCombinationRecognitionDb($decoded);
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, $db, '069_marc_combination_finder_record_scopes.sql'), 'MySQL 8 decoded JSON columns must match migration 069.');
});

marcCombinationRecognitionCase('068 and 069 reject drift while accepting every field of their matching definition', function () use ($migrationDefinition, $migrationRecognition) {
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($migrationDefinition), '069_marc_combination_finder_record_scopes.sql'), 'The current seed is recognized before drift checks.');
    foreach (['name' => 'Changed name', 'sql_template' => 'SELECT 1', 'parameters' => '[]', 'execution_config' => '{}', 'default_limit' => 100001] as $field => $value) {
        $definition = $migrationDefinition;
        $definition[$field] = $value;
        marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($definition), '068_marc_field_combination_finder.sql'), 'A changed ' . $field . ' must not recognize migration 068.');
        marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($definition), '069_marc_combination_finder_record_scopes.sql'), 'A changed ' . $field . ' must not recognize migration 069.');
        marcCombinationRecognitionSame(false, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete(new MarcCombinationRecognitionDb($definition), '069_marc_combination_finder_record_scopes.sql'), 'The report definition list rejects 069 drift in ' . $field . '.');
    }
});

marcCombinationRecognitionCase('empty-ledger baseline recognizes 068 and 069 and rejects drift', function () use ($legacyDefinition, $migrationDefinition) {
    $legacy = marcCombinationRecognitionBaseline($legacyDefinition);
    marcCombinationRecognitionSame(['068_marc_field_combination_finder.sql'], $legacy['baselined'] ?? null, 'A current schema with the historical seed baselines 068.');
    marcCombinationRecognitionSame(['069_marc_combination_finder_record_scopes.sql'], $legacy['applied'] ?? null, 'A 068 database proceeds to install 069.');
    $canonical = marcCombinationRecognitionBaseline($migrationDefinition);
    marcCombinationRecognitionSame(['068_marc_field_combination_finder.sql', '069_marc_combination_finder_record_scopes.sql'], $canonical['baselined'] ?? null, 'A current schema with the 069 definition baselines both report migrations.');

    $drifted = $migrationDefinition;
    $drifted['name'] = 'Changed name';
    $drift = marcCombinationRecognitionBaseline($drifted);
    marcCombinationRecognitionSame([], $drift['baselined'] ?? null, 'A noncanonical report row must not be marked as a complete baseline migration.');
    marcCombinationRecognitionSame(['068_marc_field_combination_finder.sql', '069_marc_combination_finder_record_scopes.sql'], $drift['applied'] ?? null, 'A drifted row is not marked current and neither migration is baselined.');
});

marcCombinationRecognitionCase('unrelated migrations do not claim the combination report seed', function () use ($migrationDefinition, $migrationRecognition) {
    marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($migrationDefinition), '067_acrl_invites.sql'), 'Migration 067 recognition remains separate.');
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination migration recognition cases failed.\n");
    exit(1);
}

echo "PASS: MARC combination migration recognition\n";
