<?php

require_once __DIR__ . '/support/CatalogingSharedRecordReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
require_once __DIR__ . '/../services/ReportExecutionContractService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

function sharedRecordAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function sharedRecordExpectFieldError(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (app\exceptions\ReportParameterValidationException $error) {
        sharedRecordAssert(array_keys($error->getFieldErrors()) === [$field], 'Attach invalid input to ' . $field . '.');
        return;
    }
    sharedRecordAssert(false, 'Expected a ' . $field . ' validation error.');
}

function sharedRecordExpectInvalidDefinition(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        return;
    }
    sharedRecordAssert(false, 'A drifted report definition must be refused.');
}

function sharedRecordUuid(int $number): string
{
    return sprintf('00000000-0000-4000-8000-%012x', $number);
}

function sharedRecordCompiled(\app\models\ReportTemplate $report, array $inputs, SharedRecordLookupDb $db): array
{
    return app\services\CatalogingReportCompilerService::build($report, $inputs, $db);
}

function sharedRecordFind(array $rows, string $id): array
{
    foreach ($rows as $row) {
        if (($row['Instance UUID'] ?? null) === $id) {
            return $row;
        }
    }
    return [];
}

function sharedRecordInsertInstance(PDO $pdo, string $id, $hrid, $title, string $source = 'MARC', $discovery = null, $staff = null): void
{
    $statement = $pdo->prepare('INSERT INTO inventory.instance__t VALUES (?, ?, ?, ?, ?, ?)');
    $statement->execute([$id, $hrid, $title, $source, $discovery, $staff]);
}

function sharedRecordInsert998(PDO $pdo, string $id, string $code, string $subfield = 'a'): void
{
    $pdo->prepare('INSERT INTO marctab.mt998 VALUES (?, ?, ?)')->execute([$id, $subfield, $code]);
}

function sharedRecordInsertHolding(PDO $pdo, string $id, string $instanceId, string $locationId): void
{
    $pdo->prepare('INSERT INTO inventory.holdings_record__t VALUES (?, ?, ?)')->execute([$id, $instanceId, $locationId]);
}

function sharedRecordRunSqlite(PDO $pdo, \app\models\ReportTemplate $report, SharedRecordLookupDb $db, array $inputs = []): array
{
    $compiled = sharedRecordCompiled($report, $inputs, $db);
    $statement = $pdo->prepare(sharedRecordSqliteSql($compiled['sql']));
    $statement->execute($compiled['params']);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$report = sharedRecordReportFixture();
$db = new SharedRecordLookupDb();
$db->definition = sharedRecordDefinition($report);
$ids = [sharedRecordUuid(1), sharedRecordUuid(2)];
$db->locations = [
    ['id' => $ids[0], 'name' => 'Synthetic Location A', 'code' => 'A1'],
    ['id' => $ids[1], 'name' => 'Synthetic Location B', 'code' => 'B1'],
];
$compilerProbe = new app\models\ReportTemplate();
$compilerProbe->slug = 'shared-record-check';
sharedRecordAssert(app\services\CatalogingReportCompilerService::supports($compilerProbe), 'The cataloging compiler supports the shared record report.');

$defaults = sharedRecordCompiled($report, [], $db);
sharedRecordAssert($defaults['params'] === [
    ':scopeKind' => 'owner', ':scopeCode' => 'SM', ':shareStatus' => 'all',
    ':suppressionMode' => 'all', ':locationIds' => '',
], 'Omitted inputs use the impact-check defaults.');
sharedRecordAssert($defaults['location'] === ['name' => 'shared-records', 'code' => 'SM'] && $defaults['locations'] === [], 'An empty location scope returns the report filename context without a location lookup.');
sharedRecordAssert($db->locationLookups === 0, 'An empty location scope does not look up locations.');
sharedRecordAssert($defaults['marcTag'] === '998', 'The compiled report identifies its MARC tag.');

$parameterDefinitions = json_decode($report->parameters, true);
$scopeParameter = $parameterDefinitions[0];
$shareParameter = $parameterDefinitions[1];
$suppressionParameter = $parameterDefinitions[2];
$locationParameter = $parameterDefinitions[3];
preg_match_all("/\\(([0-9]+), '([^']+)', '([^']+)'\\)/", $scopeParameter['options_sql'], $scopeOptions, PREG_SET_ORDER);
$scopeValues = array_map(function ($option) { return $option[2]; }, $scopeOptions);
sharedRecordAssert($scopeValues === [
    'owner:SM', 'owner:AM', 'owner:MH', 'owner:UM', 'owner:HA', 'owner:YB', 'owner:DP',
    'campus:SC', 'campus:AC', 'campus:MH', 'campus:UM', 'campus:HC', 'campus:RP', 'campus:YB',
], 'Scope dropdown options stay in the reviewed order.');
sharedRecordAssert(array_map(function ($option) { return $option[3]; }, $scopeOptions) === [
    '998 owner: Smith (SM)', '998 owner: Amherst (AM)', '998 owner: Mount Holyoke (MH)', '998 owner: UMass (UM or RC)',
    '998 owner: Hampshire (HA)', '998 owner: Yiddish Book Center (YB)', '998 owner: Five College Depository (DP)',
    'Held at: Smith College', 'Held at: Amherst College', 'Held at: Mount Holyoke College', 'Held at: UMass',
    'Held at: Hampshire College', 'Held at: Five Colleges Collections (RP)', 'Held at: Yiddish Book Center',
], 'Scope dropdown labels stay in the reviewed order.');
sharedRecordAssert($scopeParameter['default'] === 'owner:SM' && $scopeParameter['required'] === true, 'Scope is a required select defaulting to Smith ownership.');
sharedRecordAssert(array_column($shareParameter['options'], 'value') === ['all', 'shared', 'single', 'no_owner_multi', 'no_owner']
    && array_column($shareParameter['options'], 'label') === ['All statuses', 'Shared', 'Single owner', 'No owner code, held at 2+ colleges', 'No owner code']
    && $shareParameter['default'] === 'all', 'Share status dropdown options, labels, and default stay in the reviewed order.');
preg_match_all("/\\(([0-9]+), '([^']+)', '([^']+)'\\)/", $suppressionParameter['options_sql'], $suppressionOptions, PREG_SET_ORDER);
sharedRecordAssert(array_map(function ($option) { return $option[2]; }, $suppressionOptions) === ['all', 'exclude_discovery', 'exclude_staff', 'exclude_both']
    && array_map(function ($option) { return $option[3]; }, $suppressionOptions) === [
        'Include all instances', 'Exclude discovery-suppressed instances', 'Exclude staff-suppressed instances', 'Exclude both discovery and staff suppression',
    ] && $suppressionParameter['default'] === 'all', 'Suppression dropdown options, labels, and default stay in the reviewed order.');
sharedRecordAssert($locationParameter['type'] === 'multiselect' && $locationParameter['max_selections'] === 100 && $locationParameter['groupSelect'] === true, 'The optional location field enables grouped selection up to 100 locations.');
sharedRecordAssert(strpos($locationParameter['description'], 'permanent locations') !== false, 'The location filter explains that it uses permanent locations.');
sharedRecordAssert(strpos($report->help_text, 'Export FOLIO UUID list') !== false && strpos($report->help_text, '100,000 rows') !== false, 'Help text explains the row cap and UUID export.');

$blankDefaults = sharedRecordCompiled($report, ['scope' => '', 'shareStatus' => null, 'suppressionMode' => '', 'locationIds' => null], $db);
sharedRecordAssert($blankDefaults['params'] === $defaults['params'], 'Null and empty values use the documented defaults.');

foreach (['owner:RC', 'owner:SC', 'campus:SM', 'foo'] as $scope) {
    sharedRecordExpectFieldError(function () use ($report, $db, $scope) {
        sharedRecordCompiled($report, ['scope' => $scope], $db);
    }, 'scope');
}
foreach ([['shareStatus' => 'other'], ['suppressionMode' => 'other']] as $invalid) {
    $field = key($invalid);
    sharedRecordExpectFieldError(function () use ($report, $db, $invalid) {
        sharedRecordCompiled($report, $invalid, $db);
    }, $field);
}

$one = sharedRecordCompiled($report, ['locationIds' => $ids[0]], $db);
$two = sharedRecordCompiled($report, ['locationIds' => implode(',', $ids)], $db);
sharedRecordAssert($one['location']['id'] === $ids[0] && count($one['locations']) === 1, 'One selected location resolves export metadata.');
sharedRecordAssert($two['location'] === ['id' => implode(',', $ids), 'name' => '2 Locations', 'code' => 'MULTI'] && count($two['locations']) === 2, 'Two selected locations resolve export metadata.');
$db->locations = [];
try {
    sharedRecordCompiled($report, ['locationIds' => $ids[0]], $db);
    sharedRecordAssert(false, 'A selected location that disappeared must retain its integrity error.');
} catch (app\exceptions\ReportParameterValidationException $error) {
    sharedRecordAssert(false, 'A missing location is an integrity error, not a field validation error.');
} catch (InvalidArgumentException $error) {
    sharedRecordAssert($error->getMessage() === 'A selected location no longer exists.', 'Keep the location helper integrity error unchanged.');
}
$db->locations = [
    ['id' => $ids[0], 'name' => 'Synthetic Location A', 'code' => 'A1'],
    ['id' => $ids[1], 'name' => 'Synthetic Location B', 'code' => 'B1'],
];

$hundredIds = [];
for ($index = 1; $index <= 100; $index++) {
    $id = sharedRecordUuid($index);
    $hundredIds[] = $id;
    $db->locations[] = ['id' => $id, 'name' => 'Synthetic Location ' . $index, 'code' => 'L' . $index];
}
sharedRecordAssert(count(sharedRecordCompiled($report, ['locationIds' => implode(',', $hundredIds)], $db)['locations']) === 100, 'The 100-location limit is accepted.');
$oneHundredOneIds = [];
for ($index = 101; $index <= 201; $index++) {
    $oneHundredOneIds[] = sharedRecordUuid($index);
}
foreach ([
    implode(',', $oneHundredOneIds),
    sharedRecordUuid(1) . ',' . sharedRecordUuid(1),
    'malformed-location-id',
    [$ids[0]],
] as $invalidLocations) {
    sharedRecordExpectFieldError(function () use ($report, $db, $invalidLocations) {
        sharedRecordCompiled($report, ['locationIds' => $invalidLocations], $db);
    }, 'locationIds');
}

foreach ([['owner:SM', ''], ['owner:SM', $ids[0]], ['campus:SC', ''], ['campus:SC', $ids[0]]] as $case) {
    list($scope, $locationIds) = $case;
    $compiled = sharedRecordCompiled($report, ['scope' => $scope, 'locationIds' => $locationIds], $db);
    preg_match_all('/(?<!:):[A-Za-z][A-Za-z0-9_]*/', $compiled['sql'], $matches);
    $counts = array_count_values($matches[0]);
    $keys = array_keys($compiled['params']);
    sort($keys, SORT_STRING);
    $placeholders = array_keys($counts);
    sort($placeholders, SORT_STRING);
    sharedRecordAssert($placeholders === $keys && array_values($counts) === [1, 1, 1, 1, 1], 'Every SQL placeholder appears exactly once and matches its binding.');
}

app\services\SqlBuilderService::validateSafety($defaults['sql']);
app\services\SqlBuilderService::validateTablePolicy($defaults['sql']);
app\services\ReportExecutionContractService::assertStaticExportSql($defaults['sql'], ['fetchRowLimit' => 100001]);

$decodedReport = clone $report;
$decodedReport->parameters = json_decode($report->parameters, true);
$decodedReport->execution_config = json_decode($report->execution_config, true);
sharedRecordCompiled($decodedReport, [], $db);
$badComposite = clone $report;
$badComposite->composite_config = '{}';
sharedRecordExpectInvalidDefinition(function () use ($badComposite, $db) {
    sharedRecordCompiled($badComposite, [], $db);
});
$db->missingTable = true;
try {
    sharedRecordCompiled($report, [], $db);
    sharedRecordAssert(false, 'The report must not compile if marctab.mt998 is missing.');
} catch (InvalidArgumentException $error) {
    sharedRecordAssert(strpos($error->getMessage(), 'marctab.mt998') !== false, 'The missing MARC table integrity error names marctab.mt998.');
}
$db->missingTable = false;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable for the shared record semantics fixture.\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
$pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
$pdo->exec('CREATE TABLE inventory.instance__t (id TEXT, hrid TEXT, title TEXT, source TEXT, discovery_suppress BOOLEAN, staff_suppress BOOLEAN)');
$pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT, instance_id TEXT, permanent_location_id TEXT)');
$pdo->exec('CREATE TABLE inventory.location__t (id TEXT, library_id TEXT)');
$pdo->exec('CREATE TABLE inventory.loclibrary__t (id TEXT, campus_id TEXT)');
$pdo->exec('CREATE TABLE inventory.loccampus__t (id TEXT, code TEXT)');
$pdo->exec('CREATE TABLE inventory.item__t (id TEXT, holdings_record_id TEXT, effective_location_id TEXT)');
$pdo->exec('CREATE TABLE marctab.mt998 (instance_id TEXT, sf TEXT, content TEXT)');
$locationSc = sharedRecordUuid(201);
$locationAc = sharedRecordUuid(202);
$pdo->exec("INSERT INTO inventory.loccampus__t VALUES ('campus-sc', 'SC'), ('campus-ac', 'AC')");
$pdo->prepare('INSERT INTO inventory.loclibrary__t VALUES (?, ?)')->execute(['library-sc', 'campus-sc']);
$pdo->prepare('INSERT INTO inventory.loclibrary__t VALUES (?, ?)')->execute(['library-ac', 'campus-ac']);
$pdo->prepare('INSERT INTO inventory.location__t VALUES (?, ?)')->execute([$locationSc, 'library-sc']);
$pdo->prepare('INSERT INTO inventory.location__t VALUES (?, ?)')->execute([$locationAc, 'library-ac']);
$db->locations = [
    ['id' => $locationSc, 'name' => 'Synthetic SC Location', 'code' => 'SC1'],
    ['id' => $locationAc, 'name' => 'Synthetic AC Location', 'code' => 'AC1'],
];

foreach ([
    ['shared-sm-am', 'h1', 'Shared', 'MARC', 0, 0], ['shared-sm-dp', 'h2', 'Shared DP', 'MARC', 0, 0],
    ['um-rc', 'h3', 'UM RC', 'FOLIO', null, null], ['dp-only', 'h4', 'DP', 'MARC', 0, 0],
    ['lowercase-sm', 'h5', 'Lower', 'MARC', 0, 0], ['wrong-subfield', 'h6', 'Wrong subfield', 'MARC', 0, 0],
    ['nonowner-sc', 'h7', 'No owner multi', 'MARC', 0, 0], ['campus-no-998', 'h8', 'No 998', 'MARC', 0, 0],
    ['noholdings-sm', 'h9', 'No holdings', 'MARC', 0, 0], ['all-holdings', 'h10', 'All holdings', 'MARC', 0, 0],
    ['permanent-sc-effective-ac', 'h11', 'Permanent SC', 'MARC', 0, 0], ['permanent-ac-effective-sc', 'h12', 'Permanent AC', 'MARC', 0, 0],
    ['duplicate-data', 'h13', 'Duplicate data', 'MARC', 0, 0], ['suppress-discovery', 'h14', 'Suppression', 'MARC', 1, 0],
    ['suppress-staff', 'h15', 'Suppression', 'MARC', 0, 1], ['suppress-null', 'h16', 'Suppression', 'MARC', null, null],
    ['suppress-both', 'h18', 'Suppression', 'MARC', 1, 1], ['folio-source-owner', 'h17', 'Other source', 'FOLIO', 0, 0], ['order-b', 'same', 'Order sort', 'MARC', 0, 0],
    ['order-a', 'same', 'Order sort', 'MARC', 0, 0], ['order-null-hrid', null, 'Order sort', 'MARC', 0, 0],
    ['order-null-title-a', 'a', null, 'MARC', 0, 0], ['order-null-title-null', null, null, 'MARC', 0, 0],
] as $instance) {
    sharedRecordInsertInstance($pdo, $instance[0], $instance[1], $instance[2], $instance[3], $instance[4], $instance[5]);
}
sharedRecordInsert998($pdo, 'shared-sm-am', 'SM');
sharedRecordInsert998($pdo, 'shared-sm-am', 'AM');
sharedRecordInsert998($pdo, 'shared-sm-dp', 'SM');
sharedRecordInsert998($pdo, 'shared-sm-dp', 'DP');
sharedRecordInsert998($pdo, 'um-rc', 'UM');
sharedRecordInsert998($pdo, 'um-rc', 'RC');
sharedRecordInsert998($pdo, 'dp-only', 'DP');
sharedRecordInsert998($pdo, 'lowercase-sm', ' sm ');
sharedRecordInsert998($pdo, 'wrong-subfield', 'SM', 'b');
sharedRecordInsert998($pdo, 'nonowner-sc', 'SC');
sharedRecordInsert998($pdo, 'noholdings-sm', 'SM');
sharedRecordInsert998($pdo, 'all-holdings', 'SC');
sharedRecordInsert998($pdo, 'all-holdings', 'SM');
sharedRecordInsert998($pdo, 'permanent-sc-effective-ac', 'SM');
sharedRecordInsert998($pdo, 'permanent-ac-effective-sc', 'SM');
sharedRecordInsert998($pdo, 'duplicate-data', 'SM');
sharedRecordInsert998($pdo, 'duplicate-data', 'SM');
foreach (['suppress-discovery', 'suppress-staff', 'suppress-null', 'suppress-both', 'folio-source-owner', 'order-b', 'order-a', 'order-null-hrid', 'order-null-title-a', 'order-null-title-null'] as $ownerId) {
    sharedRecordInsert998($pdo, $ownerId, 'SM');
}
sharedRecordInsertHolding($pdo, 'h-shared-sc', 'shared-sm-am', $locationSc);
sharedRecordInsertHolding($pdo, 'h-nonowner-sc', 'nonowner-sc', $locationSc);
sharedRecordInsertHolding($pdo, 'h-nonowner-ac', 'nonowner-sc', $locationAc);
sharedRecordInsertHolding($pdo, 'h-no998-sc', 'campus-no-998', $locationSc);
sharedRecordInsertHolding($pdo, 'h-all-sc', 'all-holdings', $locationSc);
sharedRecordInsertHolding($pdo, 'h-all-ac', 'all-holdings', $locationAc);
sharedRecordInsertHolding($pdo, 'h-permanent-sc', 'permanent-sc-effective-ac', $locationSc);
sharedRecordInsertHolding($pdo, 'h-permanent-ac', 'permanent-ac-effective-sc', $locationAc);
sharedRecordInsertHolding($pdo, 'h-duplicate-sc-one', 'duplicate-data', $locationSc);
sharedRecordInsertHolding($pdo, 'h-duplicate-sc-two', 'duplicate-data', $locationSc);
$pdo->exec("INSERT INTO inventory.item__t VALUES ('item-permanent-sc', 'h-permanent-sc', '{$locationAc}'), ('item-permanent-ac', 'h-permanent-ac', '{$locationSc}')");

$smithRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:SM']);
sharedRecordAssert(sharedRecordFind($smithRows, 'shared-sm-am')['Share status'] === 'Shared', 'SM and AM count as two owners.');
sharedRecordAssert(sharedRecordFind($smithRows, 'shared-sm-dp')['Share status'] === 'Shared', 'SM and DP count as two owners.');
sharedRecordAssert(sharedRecordFind($smithRows, 'lowercase-sm') !== [], 'Padded lowercase 998$a owner values match.');
sharedRecordAssert(sharedRecordFind($smithRows, 'wrong-subfield') === [], 'A matching value in 998$b is not an owner.');
sharedRecordAssert(sharedRecordFind($smithRows, 'noholdings-sm') !== [], 'Owner-scoped instances without holdings remain when the location filter is empty.');
sharedRecordAssert(sharedRecordFind($smithRows, 'folio-source-owner')['Source'] === 'FOLIO', 'The scope includes every instance source.');

$umRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:UM']);
sharedRecordAssert(sharedRecordFind($umRows, 'um-rc')['Share status'] === 'Single owner' && sharedRecordFind($umRows, 'um-rc')['998 codes'] === 'RC; UM', 'UM and RC count as one owner while both normalized codes are shown in order.');
$dpRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:DP']);
sharedRecordAssert(sharedRecordFind($dpRows, 'dp-only')['Share status'] === 'Single owner', 'DP alone is one owner.');

$campusRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'campus:SC']);
sharedRecordAssert(sharedRecordFind($campusRows, 'campus-no-998')['Share status'] === 'No owner code', 'A campus scope keeps an instance without 998 rows.');
sharedRecordAssert(sharedRecordFind($campusRows, 'campus-no-998')['998 codes'] === null, 'Missing 998$a values remain null in the codes column.');
sharedRecordAssert(sharedRecordFind($campusRows, 'nonowner-sc')['Share status'] === 'No owner code, held at 2+ colleges' && sharedRecordFind($campusRows, 'nonowner-sc')['998 codes'] === 'SC', 'An unrecognized 998$a value remains visible and does not count as an owner.');
foreach ([
    'all' => null, 'shared' => 'Shared', 'single' => 'Single owner',
    'no_owner_multi' => 'No owner code, held at 2+ colleges', 'no_owner' => 'No owner code',
] as $filter => $expectedStatus) {
    $filtered = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'campus:SC', 'shareStatus' => $filter]);
    if ($expectedStatus === null) {
        sharedRecordAssert(count($filtered) >= 4, 'All share statuses remain visible.');
    } else {
        sharedRecordAssert(count($filtered) > 0, 'The ' . $filter . ' filter has matching synthetic rows.');
        foreach ($filtered as $row) {
            sharedRecordAssert($row['Share status'] === $expectedStatus, 'The ' . $filter . ' filter returns only its selected status.');
        }
    }
}

$selectedLocationRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:SM', 'locationIds' => $locationSc]);
sharedRecordAssert(sharedRecordFind($selectedLocationRows, 'all-holdings')['Holding colleges'] === 'AC; SC', 'A selected location scopes instances while the output lists all their holding campuses.');
sharedRecordAssert(sharedRecordFind($selectedLocationRows, 'permanent-sc-effective-ac') !== [], 'The permanent location controls the location filter.');
sharedRecordAssert(sharedRecordFind($selectedLocationRows, 'permanent-ac-effective-sc') === [], 'An effective location does not replace the permanent location in the filter.');
$selectedCampusRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'campus:SC', 'locationIds' => $locationSc]);
sharedRecordAssert(sharedRecordFind($selectedCampusRows, 'nonowner-sc')['Share status'] === 'No owner code, held at 2+ colleges', 'Location filtering retains the unselected campus evidence for no-owner records.');
sharedRecordAssert(count(array_filter($selectedLocationRows, function ($row) { return $row['Instance UUID'] === 'duplicate-data'; })) === 1, 'Duplicate holdings and 998 rows do not duplicate result rows.');

foreach ([
    'exclude_discovery' => ['suppress-discovery' => false, 'suppress-staff' => true, 'suppress-null' => true, 'suppress-both' => false],
    'exclude_staff' => ['suppress-discovery' => true, 'suppress-staff' => false, 'suppress-null' => true, 'suppress-both' => false],
    'exclude_both' => ['suppress-discovery' => false, 'suppress-staff' => false, 'suppress-null' => true, 'suppress-both' => false],
] as $mode => $expected) {
    $filtered = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:SM', 'suppressionMode' => $mode]);
    foreach ($expected as $id => $included) {
        sharedRecordAssert((sharedRecordFind($filtered, $id) !== []) === $included, 'Suppression filter ' . $mode . ' treats true and NULL flags as specified.');
    }
}
$flagRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:SM']);
sharedRecordAssert(sharedRecordFind($flagRows, 'suppress-discovery')['Discovery suppressed'] === 'Yes', 'True suppression flags display Yes.');
sharedRecordAssert(sharedRecordFind($flagRows, 'suppress-null')['Discovery suppressed'] === 'No' && sharedRecordFind($flagRows, 'suppress-null')['Staff suppressed'] === 'No', 'NULL suppression flags display No.');
$orderRows = sharedRecordRunSqlite($pdo, $report, $db, ['scope' => 'owner:SM']);
$sortIds = [];
foreach ($orderRows as $row) {
    if (strpos((string) $row['Title'], 'Order sort') === 0 || $row['Title'] === null) {
        $sortIds[] = $row['Instance UUID'];
    }
}
sharedRecordAssert($sortIds === ['order-a', 'order-b', 'order-null-hrid', 'order-null-title-a', 'order-null-title-null'], 'Rows with duplicate and NULL titles and HRIDs have deterministic NULLS LAST ordering.');

echo "Shared record report compiler, input validation, export contract, and SQLite semantics checks passed\n";
