<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/../services/CatalogingMarcLocationScopeService.php';
if (is_file(__DIR__ . '/../services/CatalogingMarcConditionValidator.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcConditionValidator.php';
}
if (is_file(__DIR__ . '/../services/CatalogingMarcCombinationFinderService.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';
}

$failures = [];

final class MarcCombinationServiceLookupCommand
{
    private $sql;
    private $params;
    private $missingTables;

    public function __construct(string $sql, array $params, array $missingTables)
    {
        $this->sql = $sql;
        $this->params = $params;
        $this->missingTables = $missingTables;
    }

    public function queryOne(): array
    {
        if (strpos($this->sql, 'to_regclass') === false) {
            throw new RuntimeException('Unexpected scalar lookup: ' . $this->sql);
        }
        $table = $this->params[':table_name'] ?? null;
        return ['to_regclass' => in_array($table, $this->missingTables, true) ? null : $table];
    }

    public function queryAll(): array
    {
        if (strpos($this->sql, 'FROM inventory.location__t') === false) {
            throw new RuntimeException('Unexpected option lookup: ' . $this->sql);
        }
        $locations = [
            '11111111-1111-4111-8111-111111111111' => ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Main', 'code' => 'MAIN'],
            '22222222-2222-4222-8222-222222222222' => ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Internet', 'code' => 'ONLINE'],
        ];
        $rows = [];
        foreach ($this->params as $id) {
            if (isset($locations[$id])) {
                $rows[] = $locations[$id];
            }
        }
        return $rows;
    }
}

final class MarcCombinationServiceLookupDb
{
    private $missingTables;
    public $locationLookups = 0;

    public function __construct(array $missingTables = [])
    {
        $this->missingTables = $missingTables;
    }

    public function createCommand(string $sql, array $params = []): MarcCombinationServiceLookupCommand
    {
        if (strpos($sql, 'FROM inventory.location__t') !== false) $this->locationLookups++;
        return new MarcCombinationServiceLookupCommand($sql, $params, $this->missingTables);
    }
}

function marcCombinationServiceCase(string $name, callable $callback): void
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

function marcCombinationServiceSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        global $failures;
        $failures[] = $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function marcCombinationServiceBuild(array $overrides = [], array $missingTables = []): array
{
    if (!class_exists('app\\services\\CatalogingMarcCombinationFinderService')) {
        throw new RuntimeException('CatalogingMarcCombinationFinderService is missing.');
    }
    return \app\services\CatalogingMarcCombinationFinderService::build(
        marcCombinationFixtureReport(),
        marcCombinationFixtureInputs($overrides),
        new MarcCombinationServiceLookupDb($missingTables)
    );
}

marcCombinationServiceCase('Smith record scopes ignore locations and retain the export binding contract', function () {
    foreach ([
        'holdings_no_items' => 'holdings-no-items',
        'no_holdings' => 'no-holdings',
    ] as $basis => $locationName) {
        $db = new MarcCombinationServiceLookupDb();
        $compiled = \app\services\CatalogingMarcCombinationFinderService::build(
            marcCombinationFixtureReport(),
            marcCombinationFixtureInputs(['locationBasis' => $basis, 'locationIds' => 'stale-location']),
            $db
        );
        $placeholders = [];
        preg_match_all('/(?<!:):([A-Za-z][A-Za-z0-9_]*)/', preg_replace('/::[A-Za-z][A-Za-z0-9_]*(?:\[\])?/', '', $compiled['sql']), $matches);
        foreach ($matches[1] as $name) $placeholders[] = ':' . $name;
        $placeholders = array_values(array_unique($placeholders));
        $bindings = array_keys($compiled['params']);
        sort($placeholders, SORT_STRING);
        sort($bindings, SORT_STRING);
        marcCombinationServiceSame($placeholders, $bindings, 'Scope SQL binds exactly its placeholders.');
        marcCombinationServiceSame(false, in_array(':locationIds', $bindings, true), 'A record scope never binds selected locations.');
        marcCombinationServiceSame(0, $db->locationLookups, 'A record scope never resolves location options.');
        marcCombinationServiceSame('', $compiled['identity']['locationIds'] ?? null, 'Stale locations normalize to an empty identity value.');
        marcCombinationServiceSame($basis, $compiled['identity']['locationBasis'] ?? null, 'The chosen record scope remains in history identity.');
        marcCombinationServiceSame($locationName, $compiled['location']['name'] ?? null, 'Export context uses the fixed scope name.');
        marcCombinationServiceSame('SM', $compiled['location']['code'] ?? null, 'Export context uses the Smith code.');
        marcCombinationServiceSame([], $compiled['locations'] ?? null, 'Record scopes have no selected location list.');
    }
});

function marcCombinationServiceExpectFieldError(array $overrides, string $field, string $message): void
{
    try {
        marcCombinationServiceBuild($overrides);
    } catch (\app\exceptions\ReportParameterValidationException $error) {
        marcCombinationServiceSame([$field], array_keys($error->getFieldErrors()), 'The compiler must return only the failing cN_ field.');
        marcCombinationServiceSame($message, $error->getFieldErrors()[$field], 'The compiler must preserve the existing field validation message.');
        return;
    }
    global $failures;
    $failures[] = 'Invalid combination parameters were accepted for ' . $field . '.';
    fwrite(STDERR, 'FAIL: Invalid combination parameters were accepted for ' . $field . ".\n");
}

marcCombinationServiceCase('the compiler accepts the new report and returns the compiled binding contract', function () {
    $report = marcCombinationFixtureReport();
    if (!class_exists('app\\services\\CatalogingMarcCombinationFinderService')) {
        throw new RuntimeException('CatalogingMarcCombinationFinderService is missing.');
    }
    marcCombinationServiceSame(true, \app\services\CatalogingMarcCombinationFinderService::supports($report), 'The combination slug must dispatch to its compiler.');
    $compiled = marcCombinationServiceBuild([
        'c1_contentRule' => 'contains', 'c1_searchValue' => 'Fiction',
        'c2_marcTag' => '563', 'c2_subfieldCode' => 'a',
    ]);
    marcCombinationServiceSame('655+563', $compiled['marcTag'] ?? null, 'The safe filename tag source lists active tags in slot order.');
    marcCombinationServiceSame('11111111-1111-4111-8111-111111111111', $compiled['location']['id'] ?? null, 'The compiler resolves the selected location.');
    foreach (['sql', 'params', 'location', 'locations', 'marcTag', 'identity'] as $key) {
        marcCombinationServiceSame(true, array_key_exists($key, $compiled), 'The compiler result includes ' . $key . '.');
    }
});

marcCombinationServiceCase('all empty condition slots fail on c1_marcTag', function () {
    marcCombinationServiceExpectFieldError(['c1_marcTag' => ''], 'c1_marcTag', 'At least one active MARC condition is required.');
});

marcCombinationServiceCase('unknown location bases are reported on locationBasis', function () {
    marcCombinationServiceExpectFieldError(['locationBasis' => 'all_items'], 'locationBasis', 'Location basis must be one of the four supported scopes.');
});

marcCombinationServiceCase('active slots must be contiguous and report the first gap', function () {
    marcCombinationServiceExpectFieldError(['c2_marcTag' => '', 'c3_marcTag' => '563'], 'c2_marcTag', 'Conditions must be contiguous.');
});

marcCombinationServiceCase('per-slot validation prefixes fields and maps occurrenceCondition exactly', function () {
    marcCombinationServiceExpectFieldError(['c2_marcTag' => '000'], 'c2_marcTag', 'MARC tag must be exactly three ASCII digits from 001 through 999.');
    marcCombinationServiceExpectFieldError(['c2_marcTag' => '563', 'c2_occurrenceCondition' => 'either'], 'c2_occurrenceCondition', 'Occurrence condition must be has or missing.');
});

marcCombinationServiceCase('inactive slot values do not affect validation, SQL, parameters, or history identity', function () {
    $compiled = marcCombinationServiceBuild([
        'c2_marcTag' => '',
        'c2_firstIndicator' => 'invalid',
        'c2_searchValue' => 'ignored text',
        'c5_caseExact' => 'not-boolean',
    ]);
    $sql = (string) ($compiled['sql'] ?? '');
    $params = is_array($compiled['params'] ?? null) ? $compiled['params'] : [];
    $identity = is_array($compiled['identity'] ?? null) ? $compiled['identity'] : [];
    foreach (['sql', 'params', 'identity'] as $key) {
        marcCombinationServiceSame(true, array_key_exists($key, $compiled), 'Compiler output includes the ' . $key . ' contract before inactive-slot values can be checked.');
    }
    foreach ([':c2_firstIndicator', ':c2_searchValue', ':c5_caseExact'] as $placeholder) {
        marcCombinationServiceSame(false, strpos($sql, $placeholder) !== false, 'Inactive parameters do not appear in compiled SQL: ' . $placeholder . '.');
        marcCombinationServiceSame(false, array_key_exists($placeholder, $params), 'Inactive parameters are not bound: ' . $placeholder . '.');
    }
    marcCombinationServiceSame(false, array_key_exists('c2_marcTag', $identity), 'Inactive slot identity fields must be omitted.');
});

marcCombinationServiceCase('the compiler binds exactly the named placeholders present in its SQL', function () {
    $compiled = marcCombinationServiceBuild([
        'c1_contentRule' => 'contains', 'c1_searchValue' => 'shelf: A',
        'c2_marcTag' => '563', 'c2_occurrenceCondition' => 'missing',
    ]);
    $sql = (string) ($compiled['sql'] ?? '');
    $params = is_array($compiled['params'] ?? null) ? $compiled['params'] : [];
    preg_match_all('/(?<!:):([A-Za-z][A-Za-z0-9_]*)/', preg_replace('/::[A-Za-z][A-Za-z0-9_]*(?:\[\])?/', '', $sql), $matches);
    $placeholders = array_values(array_unique(array_map(function ($name) { return ':' . $name; }, $matches[1] ?? [])));
    $bound = array_keys($params);
    sort($placeholders, SORT_STRING);
    sort($bound, SORT_STRING);
    marcCombinationServiceSame($placeholders, $bound, 'Compiler parameters must equal the unique placeholders used by generated SQL.');
    marcCombinationServiceSame('shelf: A', $params[':c1_searchValue'] ?? null, 'Slot values must remain bound data, not interpolated SQL.');
    marcCombinationServiceSame(false, strpos($sql, 'shelf: A') !== false, 'Search values must not be interpolated into SQL.');
});

marcCombinationServiceCase('repeated tags remain separate slots and each slot uses its own bind names', function () {
    $compiled = marcCombinationServiceBuild([
        'c1_contentRule' => 'contains', 'c1_searchValue' => 'fiction',
        'c2_marcTag' => '655', 'c2_contentRule' => 'contains', 'c2_searchValue' => 'history',
    ]);
    $sql = (string) ($compiled['sql'] ?? '');
    $params = is_array($compiled['params'] ?? null) ? $compiled['params'] : [];
    marcCombinationServiceSame(true, strpos($sql, 'marctab.mt655') !== false, 'Repeated tags compile as two independent MARC table checks.');
    marcCombinationServiceSame(true, isset($params[':c1_searchValue']), 'The first repeated-tag condition has its own search binding.');
    marcCombinationServiceSame(true, isset($params[':c2_searchValue']), 'The second repeated-tag condition has its own search binding.');
    marcCombinationServiceSame('fiction', $params[':c1_searchValue'] ?? null, 'The first condition keeps its own search value.');
    marcCombinationServiceSame('history', $params[':c2_searchValue'] ?? null, 'The second condition keeps its own search value.');
});

marcCombinationServiceCase('the compiler returns a normalized identity map for active slots and combine mode', function () {
    $compiled = marcCombinationServiceBuild([
        'combine' => 'any', 'locationIds' => '11111111-1111-4111-8111-111111111111',
        'c1_contentRule' => 'contains', 'c1_searchValue' => ' Fiction ',
        'c2_marcTag' => '563', 'c2_occurrenceCondition' => 'missing',
    ]);
    $identity = $compiled['identity'] ?? [];
    marcCombinationServiceSame('any', $identity['combine'] ?? null, 'History grouping includes combine mode.');
    marcCombinationServiceSame('655', $identity['c1_marcTag'] ?? null, 'History grouping includes the first active tag.');
    marcCombinationServiceSame('563', $identity['c2_marcTag'] ?? null, 'History grouping includes each active tag.');
    marcCombinationServiceSame(false, array_key_exists('c3_marcTag', $identity), 'History identity excludes inactive slots.');
    marcCombinationServiceSame('11111111-1111-4111-8111-111111111111', $identity['locationIds'] ?? null, 'History identity includes the normalized location selection.');
});

marcCombinationServiceCase('a missing MARC table is reported with the tag and condition number only', function () {
    try {
        marcCombinationServiceBuild(['c2_marcTag' => '563'], ['marctab.mt563']);
    } catch (InvalidArgumentException $error) {
        marcCombinationServiceSame("MARC tag 563 (condition 2) isn't available in the reporting database.", $error->getMessage(), 'Missing-table errors must be safe and condition-specific.');
        return;
    }
    global $failures;
    $failures[] = 'A missing condition table was accepted.';
    fwrite(STDERR, "FAIL: A missing condition table was accepted.\n");
});

marcCombinationServiceCase('the combination seed fingerprint accepts text and decoded JSON but rejects drift', function () {
    if (!class_exists('app\\services\\CatalogingMarcCombinationFinderService')) {
        throw new RuntimeException('CatalogingMarcCombinationFinderService is missing.');
    }
    $definition = marcCombinationFixtureSeed();
    marcCombinationServiceSame(true, \app\services\CatalogingMarcCombinationFinderService::isCanonicalSeedDefinition($definition), 'Stored text JSON matches its fingerprint.');
    $decoded = $definition;
    $decoded['parameters'] = json_decode($decoded['parameters'], true);
    $decoded['execution_config'] = json_decode($decoded['execution_config'], true);
    marcCombinationServiceSame(true, \app\services\CatalogingMarcCombinationFinderService::isCanonicalSeedDefinition($decoded), 'Already-decoded JSON columns match the same fingerprint.');
    $decoded['name'] = 'Changed by local administrator';
    marcCombinationServiceSame(false, \app\services\CatalogingMarcCombinationFinderService::isCanonicalSeedDefinition($decoded), 'A non-canonical row must not be recognized.');
    $decoded = $definition;
    $decoded['sql_template'] = str_replace('{{target_instances}}', 'SELECT * FROM inventory.instance__t', $decoded['sql_template']);
    marcCombinationServiceSame(false, \app\services\CatalogingMarcCombinationFinderService::isCanonicalSeedDefinition($decoded), 'A replacement other than the four reviewed target constants cannot change the canonical template.');
    $legacy = marcCombinationFixtureSeed(__DIR__ . '/../../mysql/migrations/068_marc_field_combination_finder.sql');
    marcCombinationServiceSame(false, \app\services\CatalogingMarcCombinationFinderService::isCanonicalSeedDefinition($legacy), 'Runtime compilation accepts only the 069 definition.');
    marcCombinationServiceSame(true, \app\services\CatalogingMarcCombinationFinderService::isCanonical068SeedDefinition($legacy), 'Migration recognition retains the exact historical 068 definition.');
    marcCombinationServiceSame(false, \app\services\CatalogingMarcLocationScopeService::isCombinationTargetInstances('SELECT * FROM inventory.instance__t'), 'Only the four reviewed target-instance constants are accepted.');
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination compiler cases failed.\n");
    exit(1);
}

echo "PASS: MARC combination finder compiler contracts\n";
