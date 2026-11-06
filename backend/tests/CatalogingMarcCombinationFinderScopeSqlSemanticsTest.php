<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../services/CatalogingMarcLocationScopeService.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/../services/CatalogingMarcConditionValidator.php';
require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$failures = [];

final class MarcCombinationScopeSqliteCommand
{
    private $pdo;
    private $sql;
    private $params;

    public function __construct(PDO $pdo, string $sql, array $params)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne(): array
    {
        $table = preg_replace('/^marctab\./', '', (string) ($this->params[':table_name'] ?? ''));
        $statement = $this->pdo->prepare('SELECT name FROM marctab.sqlite_master WHERE type = :type AND name = :name');
        $statement->execute([':type' => 'table', ':name' => $table]);
        $found = $statement->fetchColumn();
        return ['to_regclass' => $found === false ? null : $table];
    }
}

final class MarcCombinationScopeSqliteDb
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function createCommand(string $sql, array $params = []): MarcCombinationScopeSqliteCommand
    {
        return new MarcCombinationScopeSqliteCommand($this->pdo, $sql, $params);
    }
}

function marcCombinationScopeSqliteAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function marcCombinationScopeSqliteRun(PDO $pdo, array $overrides = []): array
{
    $compiled = \app\services\CatalogingMarcCombinationFinderService::build(
        marcCombinationFixtureReport(),
        marcCombinationFixtureInputs($overrides),
        new MarcCombinationScopeSqliteDb($pdo)
    );
    $statement = $pdo->prepare(marcCombinationFixtureSqliteSql($compiled['sql']));
    $statement->execute($compiled['params']);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function marcCombinationScopeSqliteFind(array $rows, string $id): array
{
    foreach ($rows as $row) if (($row['Instance UUID'] ?? null) === $id) return $row;
    return [];
}

function marcCombinationScopeSqliteCase(string $name, callable $callback): void
{
    global $failures;
    try {
        $callback();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . get_class($error) . ': ' . $error->getMessage();
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('LEFT_FN', function ($value, $length) { return substr((string) $value, 0, (int) $length); }, 2);
$pdo->sqliteCreateFunction('regexp', function ($pattern, $value) {
    return preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/', (string) $value) === 1 ? 1 : 0;
}, 2);
$pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
$pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
$pdo->exec('CREATE TABLE inventory.location__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');
$pdo->exec('CREATE TABLE inventory.instance__t (id TEXT PRIMARY KEY, hrid TEXT, title TEXT, source TEXT, deleted BOOLEAN, discovery_suppress BOOLEAN, staff_suppress BOOLEAN)');
$pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT PRIMARY KEY, instance_id TEXT, permanent_location_id TEXT)');
$pdo->exec('CREATE TABLE inventory.item__t (id TEXT PRIMARY KEY, holdings_record_id TEXT, effective_location_id TEXT, permanent_location_id TEXT)');
$pdo->exec('CREATE TABLE inventory.instance__t__statistical_code_ids (id TEXT, statistical_code_ids TEXT)');
foreach (['563', '998', '650', '245'] as $tag) {
    $pdo->exec("CREATE TABLE marctab.mt{$tag} (instance_id TEXT, ind1 TEXT, ind2 TEXT, ord INTEGER, line INTEGER, sf TEXT, content TEXT)");
}
$pdo->exec("INSERT INTO inventory.location__t VALUES
    ('11111111-1111-4111-8111-111111111111', 'Main', 'MAIN'),
    ('22222222-2222-4222-8222-222222222222', 'Internet', 'ONLINE')");

$records = [
    ['items-primary', 'MARC', null, null, null, [['a', ' sm '], ['b', 'AM']], 'items'],
    ['items-no-location', 'MARC', null, 0, 0, [['a', 'SM']], 'no-location'],
    ['items-discovery', 'MARC', null, 1, 0, [['a', 'SM']], 'main'],
    ['items-staff', 'MARC', null, 0, 1, [['a', 'SM'], ['b', 'AM']], 'main'],
    ['items-marker-plus', 'MARC', null, 0, 0, [['a', 'SM'], ['b', 'AM']], 'main'],
    ['items-has-item', 'MARC', null, 0, 0, [['a', 'SM']], 'has-item'],
    ['scope-non-sm', 'MARC', null, 0, 0, [['a', 'AM']], 'main'],
    ['scope-non-marc', 'FOLIO', null, 0, 0, [['a', 'SM']], 'main'],
    ['scope-deleted', 'MARC', 1, 0, 0, [['a', 'SM']], 'main'],
    ['scope-delete-code', 'MARC', null, 0, 0, [['a', 'SM']], 'main'],
    ['scope-wrong-subfield', 'MARC', null, 0, 0, [['b', 'SM']], 'main'],
    ['noholdings-non-sm', 'MARC', null, 0, 0, [['a', 'AM']], 'none'],
    ['noholdings-non-marc', 'FOLIO', null, 0, 0, [['a', 'SM']], 'none'],
    ['noholdings-deleted', 'MARC', 1, 0, 0, [['a', 'SM']], 'none'],
    ['noholdings-delete-code', 'MARC', null, 0, 0, [['a', 'SM']], 'none'],
    ['noholdings-wrong-subfield', 'MARC', null, 0, 0, [['b', 'SM']], 'none'],
    ['scope-noholdings', 'MARC', null, 0, 0, [['a', 'SM']], 'none'],
    ['scope-noholdings-discovery', 'MARC', null, 1, 0, [['a', 'SM']], 'none'],
    ['scope-noholdings-staff', 'MARC', null, 0, 1, [['a', 'SM'], ['b', 'AM']], 'none'],
    ['scope-noholdings-marker-plus', 'MARC', null, 0, 0, [['a', 'SM'], ['b', 'AM']], 'none'],
    ['scope-has-holding', 'MARC', null, 0, 0, [['a', 'SM']], 'main'],
];
$instance = $pdo->prepare('INSERT INTO inventory.instance__t VALUES (?, ?, ?, ?, ?, ?, ?)');
$holding = $pdo->prepare('INSERT INTO inventory.holdings_record__t VALUES (?, ?, ?)');
$ownership = $pdo->prepare('INSERT INTO marctab.mt998 VALUES (?, NULL, NULL, 1, 1, ?, ?)');
foreach ($records as $record) {
    list($id, $source, $deleted, $discovery, $staff, $markers, $holdingKind) = $record;
    $instance->execute([$id, $id, $id, $source, $deleted, $discovery, $staff]);
    foreach ($markers as $marker) $ownership->execute([$id, $marker[0], $marker[1]]);
    if ($holdingKind === 'items') {
        foreach ([['main', '11111111-1111-4111-8111-111111111111'], ['internet', '22222222-2222-4222-8222-222222222222'], ['main-again', '11111111-1111-4111-8111-111111111111'], ['unknown', 'missing-location'], ['null', null]] as $location) {
            $holding->execute(['scope-' . $id . '-' . $location[0], $id, $location[1]]);
        }
    } elseif ($holdingKind === 'no-location') {
        $holding->execute(['scope-' . $id . '-unknown', $id, 'missing-location']);
        $holding->execute(['scope-' . $id . '-null', $id, null]);
    } elseif ($holdingKind === 'main') {
        $holding->execute(['scope-' . $id, $id, '11111111-1111-4111-8111-111111111111']);
    } elseif ($holdingKind === 'has-item') {
        $holding->execute(['scope-' . $id . '-first', $id, '11111111-1111-4111-8111-111111111111']);
        $holding->execute(['scope-' . $id . '-second', $id, '22222222-2222-4222-8222-222222222222']);
        $pdo->prepare('INSERT INTO inventory.item__t VALUES (?, ?, ?, ?)')->execute([
            'scope-item-' . $id, 'scope-' . $id . '-second', '22222222-2222-4222-8222-222222222222', '22222222-2222-4222-8222-222222222222',
        ]);
    }
    if ($id === 'scope-delete-code' || $id === 'noholdings-delete-code') {
        $pdo->prepare('INSERT INTO inventory.instance__t__statistical_code_ids VALUES (?, ?)')->execute([
            $id, '4921058f-8b68-43a7-a7d6-45f58c38aab9',
        ]);
    }
}
foreach (['items-primary', 'items-discovery', 'scope-noholdings'] as $id) {
    $pdo->prepare("INSERT INTO marctab.mt650 VALUES (?, NULL, NULL, 1, 1, 'a', 'Has subject')")->execute([$id]);
}
foreach (['items-primary', 'items-staff', 'scope-noholdings-staff'] as $id) {
    $pdo->prepare("INSERT INTO marctab.mt245 VALUES (?, NULL, NULL, 1, 1, 'a', 'the title')")->execute([$id]);
}

marcCombinationScopeSqliteCase('Smith scopes preserve structural, ownership, suppression, and MARC condition semantics', function () use ($pdo) {
    $expected = [
        'holdings_no_items' => ['items-discovery', 'items-marker-plus', 'items-no-location', 'items-primary', 'items-staff', 'scope-has-holding'],
        'no_holdings' => ['scope-noholdings', 'scope-noholdings-discovery', 'scope-noholdings-marker-plus', 'scope-noholdings-staff'],
    ];
    foreach ($expected as $basis => $ids) {
        $rows = marcCombinationScopeSqliteRun($pdo, ['locationBasis' => $basis, 'c1_marcTag' => '563', 'c1_occurrenceCondition' => 'missing']);
        $actual = array_column($rows, 'Instance UUID');
        sort($ids, SORT_STRING);
        sort($actual, SORT_STRING);
        marcCombinationScopeSqliteAssertSame($ids, $actual, $basis . ' selects only matching synthetic Smith records.');
        $has = marcCombinationScopeSqliteRun($pdo, ['locationBasis' => $basis, 'c1_marcTag' => '650']);
        $missing = marcCombinationScopeSqliteRun($pdo, ['locationBasis' => $basis, 'c1_marcTag' => '245', 'c1_occurrenceCondition' => 'missing']);
        $or = marcCombinationScopeSqliteRun($pdo, ['locationBasis' => $basis, 'combine' => 'any', 'c1_marcTag' => '650', 'c2_marcTag' => '245', 'c2_occurrenceCondition' => 'missing']);
        marcCombinationScopeSqliteAssertSame(true, in_array($basis === 'holdings_no_items' ? 'items-primary' : 'scope-noholdings', array_column($has, 'Instance UUID'), true), $basis . ' supports a has condition.');
        marcCombinationScopeSqliteAssertSame(true, in_array($basis === 'holdings_no_items' ? 'items-no-location' : 'scope-noholdings-discovery', array_column($missing, 'Instance UUID'), true), $basis . ' supports a missing condition.');
        marcCombinationScopeSqliteAssertSame(true, in_array($basis === 'holdings_no_items' ? 'items-no-location' : 'scope-noholdings-discovery', array_column($or, 'Instance UUID'), true), $basis . ' supports OR conditions.');
    }
    $primary = marcCombinationScopeSqliteFind(marcCombinationScopeSqliteRun($pdo, ['locationBasis' => 'holdings_no_items', 'c1_marcTag' => '563', 'c1_occurrenceCondition' => 'missing']), 'items-primary');
    marcCombinationScopeSqliteAssertSame('Internet [ONLINE]; Main [MAIN]', $primary['Selected Location(s)'] ?? null, 'Holding locations are distinct, sorted, and joined with semicolon-space.');
    $noLocation = marcCombinationScopeSqliteFind(marcCombinationScopeSqliteRun($pdo, ['locationBasis' => 'holdings_no_items', 'c1_marcTag' => '563', 'c1_occurrenceCondition' => 'missing']), 'items-no-location');
    marcCombinationScopeSqliteAssertSame(null, $noLocation['Selected Location(s)'] ?? null, 'Unknown locations do not exclude holdings or invent a label.');
    $noHoldings = marcCombinationScopeSqliteFind(marcCombinationScopeSqliteRun($pdo, ['locationBasis' => 'no_holdings', 'c1_marcTag' => '563', 'c1_occurrenceCondition' => 'missing']), 'scope-noholdings');
    marcCombinationScopeSqliteAssertSame(null, $noHoldings['Selected Location(s)'] ?? null, 'No-holdings results have NULL selected locations.');
    $tag998 = marcCombinationScopeSqliteRun($pdo, ['locationBasis' => 'no_holdings', 'c1_marcTag' => '998', 'c1_subfieldCode' => 'a', 'c1_contentRule' => 'contains', 'c1_searchValue' => 'SM']);
    marcCombinationScopeSqliteAssertSame(true, in_array('scope-noholdings', array_column($tag998, 'Instance UUID'), true), 'Tag 998 remains searchable as a condition.');
});

if ($failures) {
    fwrite(STDERR, 'FAIL: MARC combination record-scope SQLite semantics failed.\n');
    exit(1);
}
echo "PASS: MARC combination record-scope SQLite semantics\n";
