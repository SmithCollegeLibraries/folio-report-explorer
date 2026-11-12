<?php
require_once __DIR__ . '/support/GiftReportsMigrationFixture.php';
require_once __DIR__ . '/../services/CatalogingReportMigrationRecognitionService.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
require_once __DIR__ . '/../services/MigrationService.php';

function giftRecognitionAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

final class GiftRecognitionDatabase
{
    public $definitions;
    public $queries = [];

    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function createCommand($sql = '', array $params = [])
    {
        $this->queries[] = [$sql, $params];
        return new class($this, $params) {
            private $database;
            private $params;

            public function __construct($database, array $params)
            {
                $this->database = $database;
                $this->params = $params;
            }

            public function queryOne()
            {
                $slug = $this->params[':slug'] ?? null;
                return $this->database->definitions[$slug] ?? false;
            }
        };
    }
}

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80100) {
    $recognize->setAccessible(true);
}
$filename = '070_gift_reports.sql';
$canonical = giftReportsMigrationDefinitions();
$emptyDb = new GiftRecognitionDatabase([]);
giftRecognitionAssert(!$recognize->invoke(null, $emptyDb, $filename), 'Do not recognize migration 070 when neither report row exists.');
$oneRowDb = new GiftRecognitionDatabase(['gift-items-one-time' => $canonical['gift-items-one-time']]);
giftRecognitionAssert(!$recognize->invoke(null, $oneRowDb, $filename), 'Do not recognize migration 070 when only one canonical row exists.');

$canonicalRows = $canonical;
$canonicalRows['gift-items-one-time']['composite_config'] = null;
$canonicalRows['gift-ongoing']['composite_config'] = null;
$canonicalDb = new GiftRecognitionDatabase($canonicalRows);
giftRecognitionAssert($recognize->invoke(null, $canonicalDb, $filename), 'Recognize migration 070 only when both canonical rows exist.');
foreach (['string', 'decoded-array', 'double-encoded'] as $form) {
    $represented = $canonicalRows;
    foreach ($represented as $slug => $definition) {
        if ($form === 'decoded-array') {
            $definition['parameters'] = json_decode($definition['parameters'], true);
        } elseif ($form === 'double-encoded') {
            $definition['parameters'] = json_encode($definition['parameters']);
        }
        $represented[$slug] = $definition;
    }
    giftRecognitionAssert(
        $recognize->invoke(null, new GiftRecognitionDatabase($represented), $filename),
        'Accept canonical parameters in ' . $form . ' form.'
    );

    $changed = $represented;
    foreach ($changed as $slug => $definition) {
        $parameters = $definition['parameters'];
        if ($form === 'string') {
            $parameters = json_decode($parameters, true);
        } elseif ($form === 'double-encoded') {
            $parameters = json_decode(json_decode($parameters, true), true);
        }
        $parameters[0]['default'] = 'changed';
        $encoded = json_encode($parameters);
        $definition['parameters'] = $form === 'decoded-array'
            ? $parameters
            : ($form === 'double-encoded' ? json_encode($encoded) : $encoded);
        $changed[$slug] = $definition;
    }
    giftRecognitionAssert(
        !$recognize->invoke(null, new GiftRecognitionDatabase($changed), $filename),
        'Reject changed parameters in ' . $form . ' form.'
    );
}

foreach ([
    'name' => 'Changed name',
    'description' => 'Changed description',
    'help_text' => 'Changed help',
    'category' => 'other',
    'sql_template' => 'SELECT 1',
    'parameters' => '[]',
    'data_source' => 'local',
    'execution_config' => '{}',
    'composite_config' => '{}',
    'default_limit' => 100001,
    'is_active' => 0,
    'created_by' => 'ai',
] as $field => $value) {
    $drifted = $canonical;
    $drifted['gift-ongoing'][$field] = $value;
    giftRecognitionAssert(!$recognize->invoke(null, new GiftRecognitionDatabase($drifted), $filename), 'Reject migration 070 when canonical field ' . $field . ' has drifted.');
}

$migrationFile = ['filename' => $filename, 'path' => __DIR__ . '/../../mysql/migrations/' . $filename, 'checksum' => str_repeat('a', 64)];
$canonicalBaseline = app\services\AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($canonicalDb, [$migrationFile], true);
giftRecognitionAssert(($canonicalBaseline['baselined'] ?? []) === [$filename] && empty($canonicalBaseline['applied']), 'Baseline canonical 070 rows from an empty ledger.');
foreach ([
    $emptyDb,
    $oneRowDb,
    new GiftRecognitionDatabase(['gift-items-one-time' => array_merge($canonical['gift-items-one-time'], ['name' => 'Drift'])]),
] as $database) {
    $baseline = app\services\AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($database, [$migrationFile], true);
    giftRecognitionAssert(($baseline['applied'] ?? []) === [$filename] && empty($baseline['baselined']), 'Plan migration 070 to repair missing or drifted rows.');
}

echo "PASS: Gift report migration recognition and baseline\n";
