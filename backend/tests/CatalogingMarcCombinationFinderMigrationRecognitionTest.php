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
    return $method->invoke(null, new MarcCombinationRecognitionDb($definition), [[
        'filename' => '068_marc_field_combination_finder.sql',
        'checksum' => str_repeat('a', 64),
    ]], true);
}

$migrationDefinition = marcCombinationFixtureSeed();
$migrationRecognition = new ReflectionMethod(\app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) {
    $migrationRecognition->setAccessible(true);
}

marcCombinationRecognitionCase('068 dispatch recognizes its own canonical report fingerprint', function () use ($migrationDefinition, $migrationRecognition) {
    $db = new MarcCombinationRecognitionDb($migrationDefinition);
    marcCombinationRecognitionSame(true, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete($db, '068_marc_field_combination_finder.sql'), 'The report migration definition list delegates 068 to the combination seed fingerprint.');
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, $db, '068_marc_field_combination_finder.sql'), 'Migration 068 must be registered in per-file dispatch.');
    if (method_exists(\app\services\CatalogingReportMigrationRecognitionService::class, 'marcCombinationFinderReportAppearsComplete')) {
        marcCombinationRecognitionSame(true, \app\services\CatalogingReportMigrationRecognitionService::marcCombinationFinderReportAppearsComplete($db), 'Cataloging report recognition must expose the new report check.');
    } else {
        global $failures;
        $failures[] = 'Cataloging report recognition must expose marcCombinationFinderReportAppearsComplete().';
        fwrite(STDERR, "FAIL: Cataloging report recognition is missing the combination finder fingerprint check.\n");
    }
});

marcCombinationRecognitionCase('068 recognition accepts JSON columns as strings or decoded arrays', function () use ($migrationDefinition, $migrationRecognition) {
    $decoded = $migrationDefinition;
    $decoded['parameters'] = json_decode($decoded['parameters'], true);
    $decoded['execution_config'] = json_decode($decoded['execution_config'], true);
    $db = new MarcCombinationRecognitionDb($decoded);
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, $db, '068_marc_field_combination_finder.sql'), 'MySQL 8 decoded JSON columns must match the canonical seed.');
});

marcCombinationRecognitionCase('068 recognition rejects a drifted seed and accepts every other canonical field', function () use ($migrationDefinition, $migrationRecognition) {
    marcCombinationRecognitionSame(true, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($migrationDefinition), '068_marc_field_combination_finder.sql'), 'The canonical seed is recognized before drift checks.');
    foreach (['name' => 'Changed name', 'sql_template' => 'SELECT 1', 'parameters' => '[]', 'execution_config' => '{}', 'default_limit' => 100001] as $field => $value) {
        $definition = $migrationDefinition;
        $definition[$field] = $value;
        marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($definition), '068_marc_field_combination_finder.sql'), 'A changed ' . $field . ' must not recognize migration 068.');
        marcCombinationRecognitionSame(false, \app\services\CatalogingReportMigrationRecognitionService::migrationDefinitionAppearsComplete(new MarcCombinationRecognitionDb($definition), '068_marc_field_combination_finder.sql'), 'The report definition list rejects drift in ' . $field . '.');
    }
});

marcCombinationRecognitionCase('empty-ledger baseline only baselines a canonical 068 seed', function () use ($migrationDefinition) {
    $canonical = marcCombinationRecognitionBaseline($migrationDefinition);
    marcCombinationRecognitionSame(['068_marc_field_combination_finder.sql'], $canonical['baselined'] ?? null, 'A current schema with the canonical seed baselines 068.');

    $drifted = $migrationDefinition;
    $drifted['name'] = 'Changed name';
    $drift = marcCombinationRecognitionBaseline($drifted);
    marcCombinationRecognitionSame([], $drift['baselined'] ?? null, 'A noncanonical report row must not be marked as a complete baseline migration.');
    marcCombinationRecognitionSame(['068_marc_field_combination_finder.sql'], $drift['applied'] ?? null, 'A drifted row leaves 068 to be installed and then rejected by the guarded insert.');
});

marcCombinationRecognitionCase('unrelated migrations do not claim the combination report seed', function () use ($migrationDefinition, $migrationRecognition) {
    marcCombinationRecognitionSame(false, $migrationRecognition->invoke(null, new MarcCombinationRecognitionDb($migrationDefinition), '067_acrl_invites.sql'), 'Migration 067 recognition remains separate.');
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination migration recognition cases failed.\n");
    exit(1);
}

echo "PASS: MARC combination migration recognition\n";
