<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../services/CatalogingMarcLocationScopeService.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
if (is_file(__DIR__ . '/../services/CatalogingMarcConditionValidator.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcConditionValidator.php';
}
if (is_file(__DIR__ . '/../services/CatalogingMarcCombinationFinderService.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$failures = [];

final class MarcCombinationSqliteCommand
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
        if (strpos($this->sql, 'to_regclass') === false) {
            throw new RuntimeException('Unexpected queryOne SQL: ' . $this->sql);
        }
        $table = (string) ($this->params[':table_name'] ?? '');
        $tableName = preg_replace('/^marctab\./', '', $table);
        $statement = $this->pdo->prepare('SELECT name FROM marctab.sqlite_master WHERE type = :type AND name = :name');
        $statement->execute([':type' => 'table', ':name' => $tableName]);
        $found = $statement->fetchColumn();
        return ['to_regclass' => $found === false ? null : $table];
    }

    public function queryAll(): array
    {
        $sql = marcCombinationFixtureSqliteSql($this->sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class MarcCombinationSqliteDb
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function createCommand(string $sql, array $params = []): MarcCombinationSqliteCommand
    {
        return new MarcCombinationSqliteCommand($this->pdo, $sql, $params);
    }
}

function marcCombinationSqliteAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function marcCombinationSqliteCase(string $name, callable $callback): void
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

function marcCombinationSqliteRun(PDO $pdo, array $overrides = []): array
{
    if (!class_exists('app\\services\\CatalogingMarcCombinationFinderService')) {
        throw new RuntimeException('CatalogingMarcCombinationFinderService is missing.');
    }
    $compiled = \app\services\CatalogingMarcCombinationFinderService::build(
        marcCombinationFixtureReport(),
        marcCombinationFixtureInputs($overrides),
        new MarcCombinationSqliteDb($pdo)
    );
    if (!is_string($compiled['sql'] ?? null) || !is_array($compiled['params'] ?? null)) {
        throw new RuntimeException('The compiler must return SQL and bindings before the truth table can execute.');
    }
    $sql = marcCombinationFixtureSqliteSql($compiled['sql']);
    $statement = $pdo->prepare($sql);
    $statement->execute($compiled['params']);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('LEFT_FN', function ($value, $length) { return substr((string) $value, 0, (int) $length); }, 2);
$pdo->sqliteCreateFunction('regexp', function ($pattern, $value) {
    return preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/', (string) $value) === 1 ? 1 : 0;
}, 2);
$pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
$pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
$pdo->exec('CREATE TABLE inventory.location__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');
$pdo->exec('CREATE TABLE inventory.instance__t (id TEXT PRIMARY KEY, hrid TEXT, title TEXT, source TEXT)');
$pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT PRIMARY KEY, instance_id TEXT)');
$pdo->exec('CREATE TABLE inventory.item__t (id TEXT PRIMARY KEY, holdings_record_id TEXT, effective_location_id TEXT, permanent_location_id TEXT)');
foreach (['655', '563'] as $tag) {
    $pdo->exec("CREATE TABLE marctab.mt{$tag} (instance_id TEXT, ind1 TEXT, ind2 TEXT, ord INTEGER, line INTEGER, sf TEXT, content TEXT)");
}
$pdo->exec("INSERT INTO inventory.location__t VALUES
    ('11111111-1111-4111-8111-111111111111', 'Main', 'MAIN'),
    ('22222222-2222-4222-8222-222222222222', 'Internet', 'ONLINE')");

$instances = [
    ['same-field', 'in0001', 'Alpha', 'MARC', '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
    ['separate-fields', 'in0002', 'Beta', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['missing-p', 'in0003', 'Gamma', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['outside', 'in0004', 'Delta', 'MARC', '22222222-2222-4222-8222-222222222222', '22222222-2222-4222-8222-222222222222'],
    ['folio', 'in0005', 'Epsilon', 'FOLIO', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['effective-only', 'in0006', 'Zeta', 'MARC', '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
    ['permanent-only', 'in0007', 'Eta', 'MARC', '22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111'],
    ['blank-content', 'in0008', 'Theta', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['long-content', 'in0009', 'Iota', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['same-title-z', 'in0011', 'Same title', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
    ['same-title-a', 'in0010', 'Same title', 'MARC', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
];
$insertInstance = $pdo->prepare('INSERT INTO inventory.instance__t VALUES (?, ?, ?, ?)');
$insertHolding = $pdo->prepare('INSERT INTO inventory.holdings_record__t VALUES (?, ?)');
$insertItem = $pdo->prepare('INSERT INTO inventory.item__t VALUES (?, ?, ?, ?)');
foreach ($instances as $index => $instance) {
    $insertInstance->execute(array_slice($instance, 0, 4));
    $holdingId = 'hold-' . $instance[0];
    $insertHolding->execute([$holdingId, $instance[0]]);
    $insertItem->execute(['item-' . $instance[0], $holdingId, $instance[4], $instance[5]]);
}
$insertMarc = $pdo->prepare('INSERT INTO marctab.mt655 VALUES (?, ?, ?, ?, ?, ?, ?)');
$marcRows = [
    ['same-field', '7', ' ', 1, 1, 'a', 'Fiction history'],
    ['same-field', '7', ' ', 2, 2, 'a', 'Fiction history'],
    ['separate-fields', '7', ' ', 1, 1, 'a', 'Fiction'],
    ['separate-fields', '7', ' ', 2, 2, 'a', 'History'],
    ['outside', '7', ' ', 1, 1, 'a', 'Fiction outside'],
    ['effective-only', '7', ' ', 1, 1, 'a', 'Fiction effective'],
    ['permanent-only', '7', ' ', 1, 1, 'a', 'Fiction permanent'],
    ['blank-content', '7', ' ', 1, 1, 'a', '   '],
    ['long-content', '7', ' ', 1, 1, 'a', str_repeat('x', 520) . 'TAIL'],
    ['same-title-z', '7', ' ', 1, 1, 'a', 'Fiction'],
    ['same-title-a', '7', ' ', 1, 1, 'a', 'Fiction'],
];
foreach ($marcRows as $row) {
    $insertMarc->execute($row);
}
$pdo->exec("INSERT INTO marctab.mt563 VALUES ('same-field', ' ', ' ', 1, 1, 'a', 'Box')");

marcCombinationSqliteCase('has P OR missing P returns every scoped MARC instance', function () use ($pdo) {
    $rows = marcCombinationSqliteRun($pdo, [
        'combine' => 'any', 'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
        'c2_marcTag' => '655', 'c2_occurrenceCondition' => 'missing', 'c2_contentRule' => 'contains', 'c2_searchValue' => 'Fiction',
    ]);
    $ids = array_column($rows, 'Instance UUID');
    marcCombinationSqliteAssertSame(8, count($ids), 'The truth table includes each scoped MARC instance once and excludes FOLIO and out-of-scope instances.');
    marcCombinationSqliteAssertSame(8, count(array_unique($ids)), 'OR results contain one row per instance.');
});

marcCombinationSqliteCase('has P AND missing P returns no scoped instance', function () use ($pdo) {
    $rows = marcCombinationSqliteRun($pdo, [
        'combine' => 'all', 'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
        'c2_marcTag' => '655', 'c2_occurrenceCondition' => 'missing', 'c2_contentRule' => 'contains', 'c2_searchValue' => 'Fiction',
    ]);
    marcCombinationSqliteAssertSame([], $rows, 'A condition and its exact complement cannot both be true.');
});

marcCombinationSqliteCase('two positive conditions can match the same physical occurrence or separate occurrences', function () use ($pdo) {
    $rows = marcCombinationSqliteRun($pdo, [
        'combine' => 'all', 'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
        'c2_marcTag' => '655', 'c2_contentRule' => 'contains', 'c2_searchValue' => 'History',
    ]);
    $ids = array_column($rows, 'Instance UUID');
    marcCombinationSqliteAssertSame(['same-field', 'separate-fields'], $ids, 'Each positive predicate is evaluated independently per instance.');
});

marcCombinationSqliteCase('OR output marks a failed condition as Not matched and reports slot satisfaction', function () use ($pdo) {
    $rows = marcCombinationSqliteRun($pdo, [
        'combine' => 'any', 'c1_contentRule' => 'any',
        'c2_marcTag' => '655', 'c2_contentRule' => 'contains', 'c2_searchValue' => 'History',
    ]);
    $separate = array_values(array_filter($rows, function ($row) { return $row['Instance UUID'] === 'separate-fields'; }));
    $separate = $separate[0] ?? [];
    marcCombinationSqliteAssertSame('Any condition', $separate['Match'] ?? null, 'The row labels OR matching as Any condition.');
    marcCombinationSqliteAssertSame('Fiction; History', $separate['Condition 1 result'] ?? null, 'Distinct contents are ordered and joined for the first condition.');
    marcCombinationSqliteAssertSame('History', $separate['Condition 2 result'] ?? null, 'The second condition returns only its matching content.');
    $fictionOnly = array_values(array_filter($rows, function ($row) { return $row['Instance UUID'] === 'same-title-a'; }));
    marcCombinationSqliteAssertSame('Not matched', $fictionOnly[0]['Condition 2 result'] ?? null, 'A condition that did not match is explicit under OR.');
});

marcCombinationSqliteCase('missing results, blank values, 500-character truncation, and title/HRID ordering are rendered', function () use ($pdo) {
    $missing = marcCombinationSqliteRun($pdo, [
        'combine' => 'all', 'c1_marcTag' => '563', 'c1_occurrenceCondition' => 'missing',
    ]);
    $gamma = array_values(array_filter($missing, function ($row) { return $row['Instance UUID'] === 'missing-p'; }));
    marcCombinationSqliteAssertSame('Missing', $gamma[0]['Condition 1 result'] ?? null, 'A satisfied missing condition renders Missing.');
    $blankRows = marcCombinationSqliteRun($pdo, [
        'c1_contentRule' => 'blank', 'c1_subfieldCode' => 'a',
    ]);
    $blank = array_values(array_filter($blankRows, function ($row) { return $row['Instance UUID'] === 'blank-content'; }));
    marcCombinationSqliteAssertSame('(blank)', $blank[0]['Condition 1 result'] ?? null, 'Whitespace-only matched content renders (blank).');
    $longRows = marcCombinationSqliteRun($pdo, ['c1_contentRule' => 'contains', 'c1_searchValue' => 'x']);
    $long = array_values(array_filter($longRows, function ($row) { return $row['Instance UUID'] === 'long-content'; }));
    $value = (string) ($long[0]['Condition 1 result'] ?? '');
    if (substr($value, -3) !== '…' || strlen($value) !== 503) {
        throw new RuntimeException('Long result cells must contain 500 characters followed by one ellipsis.');
    }
    $ordered = marcCombinationSqliteRun($pdo, ['c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction']);
    $sameTitle = array_values(array_filter($ordered, function ($row) { return $row['Title'] === 'Same title'; }));
    marcCombinationSqliteAssertSame(['in0010', 'in0011'], array_column($sameTitle, 'Instance HRID'), 'Equal titles are ordered by Instance HRID.');
});

marcCombinationSqliteCase('location scope honors effective_item and permanent_item bases', function () use ($pdo) {
    $main = '11111111-1111-4111-8111-111111111111';
    $effective = marcCombinationSqliteRun($pdo, [
        'locationIds' => $main, 'locationBasis' => 'effective_item',
        'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
    ]);
    $permanent = marcCombinationSqliteRun($pdo, [
        'locationIds' => $main, 'locationBasis' => 'permanent_item',
        'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
    ]);
    $effectiveIds = array_column($effective, 'Instance UUID');
    $permanentIds = array_column($permanent, 'Instance UUID');
    if (!in_array('effective-only', $effectiveIds, true) || in_array('effective-only', $permanentIds, true)) {
        throw new RuntimeException('Effective-item scope must use the item effective location.');
    }
    if (!in_array('permanent-only', $permanentIds, true) || in_array('permanent-only', $effectiveIds, true)) {
        throw new RuntimeException('Permanent-item scope must use the item permanent location.');
    }
    if (in_array('outside', $effectiveIds, true) || in_array('folio', $effectiveIds, true)) {
        throw new RuntimeException('The scope must exclude other locations and non-MARC instances.');
    }
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination SQL semantics cases failed.\n");
    exit(1);
}

echo "PASS: MARC combination SQL semantics\n";
