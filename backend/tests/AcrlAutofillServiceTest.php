<?php

function acrlAutofillAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$servicePath = __DIR__ . '/../services/AcrlAutofillService.php';
$busyExceptionPath = __DIR__ . '/../services/AcrlAutofillBusyException.php';
acrlAutofillAssert(is_file($busyExceptionPath), 'The busy exception must have its own PSR-4 service file.');
require_once $busyExceptionPath;
if (is_file($servicePath)) {
    require_once $servicePath;
}
if (!class_exists('app\\services\\AcrlAutofillService')) {
    acrlAutofillAssert(false, 'The fixed ACRL auto-fill service must be implemented.');
}

class AcrlAutofillTestRepository
{
    public $status = 'published';
    public function year(int $year): ?array
    {
        return $year > 0 ? ['survey_year' => $year, 'status' => $this->status] : null;
    }
    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        return ['survey_year' => $year, 'sheet' => $sheet, 'question_id' => $questionId, 'part' => $part];
    }
}

class AcrlAutofillTestTransaction
{
    public $isActive = true;
    public $committed = false;
    public $rolledBack = false;
    public function commit(): void { $this->committed = true; $this->isActive = false; }
    public function rollBack(): void { $this->rolledBack = true; $this->isActive = false; }
}

class AcrlAutofillTestDb
{
    public $commands = [];
    public $transactions = [];
    public $lockAvailable = true;
    public function beginTransaction()
    {
        $transaction = new AcrlAutofillTestTransaction();
        $this->transactions[] = $transaction;
        return $transaction;
    }
    public function createCommand($sql, array $params = [])
    {
        $command = new AcrlAutofillTestCommand($this, (string)$sql, $params);
        $this->commands[] = $command;
        return $command;
    }
}

class AcrlAutofillMethodsNeverUseDb
{
    public $calls = 0;
    public function beginTransaction()
    {
        $this->calls++;
        throw new RuntimeException('The methods catalog must not query FOLIO.');
    }
    public function createCommand($sql, array $params = [])
    {
        $this->calls++;
        throw new RuntimeException('The methods catalog must not issue FOLIO commands.');
    }
}

class AcrlAutofillTestCommand
{
    private $db;
    public $sql;
    public $params;
    public function __construct(AcrlAutofillTestDb $db, string $sql, array $params)
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }
    public function execute(): int { return 0; }
    public function queryScalar()
    {
        if (strpos($this->sql, 'pg_try_advisory_xact_lock') !== false) {
            return $this->db->lockAvailable;
        }
        return null;
    }
    public function queryOne(): array
    {
        if (strpos($this->sql, 'with scope_items as') !== false) {
            return ['value' => 1287533];
        }
        if (strpos($this->sql, 'q60_physical_circulation') !== false) {
            return [
                'q60_physical_circulation' => 18438,
                'q81_consortial_lending' => 6017,
                'q82_consortial_borrowing' => 6897,
            ];
        }
        if (strpos($this->sql, 'Journal') !== false && strpos($this->sql, 'count(distinct it2.id)') !== false) {
            return ['value' => 51600];
        }
        if (strpos($this->sql, 'count(distinct it.id)') !== false) {
            return ['value' => 1282072];
        }
        return ['value' => 1077455];
    }
}

function acrlAutofillService(AcrlAutofillTestRepository $repository, AcrlAutofillTestDb $db): \app\services\AcrlAutofillService
{
    return new \app\services\AcrlAutofillService(
        $repository,
        $db,
        function () { return new DateTimeImmutable('2026-10-28T12:34:56-04:00'); },
        __DIR__ . '/../sql/acrl'
    );
}

$repository = new AcrlAutofillTestRepository();
$db = new AcrlAutofillTestDb();
$service = acrlAutofillService($repository, $db);
$circulation = $service->run(2025, 'survey', '60', 'A');
acrlAutofillAssert($circulation['value'] === 18438, 'Q60 should return the Q60 alias from the combined circulation query.');
acrlAutofillAssert($circulation['queryId'] === 'acrl-60' && $circulation['queryVersion'] === 1, 'Q60 must report its fixed query identity and version.');
acrlAutofillAssert($circulation['fyStart'] === '2024-07-01' && $circulation['fyEnd'] === '2025-07-01', 'FY2025 must use the half-open July-to-July range.');
acrlAutofillAssert($circulation['asOf'] === null && $circulation['kind'] === 'dated', 'Dated results must return FY dates and no snapshot date.');
acrlAutofillAssert($circulation['ranAt'] === '2026-10-28T12:34:56-04:00', 'The response must preserve the query run time.');

$fyCommand = null;
$setupSql = [];
foreach ($db->commands as $command) {
    $setupSql[] = $command->sql;
    if (strpos($command->sql, 'q60_physical_circulation') !== false) $fyCommand = $command;
}
acrlAutofillAssert(in_array('SET TRANSACTION READ ONLY', $setupSql, true), 'Every FOLIO transaction must be set read-only.');
acrlAutofillAssert(in_array('SET LOCAL statement_timeout = 150000', $setupSql, true), 'Every FOLIO transaction must use a 150-second statement timeout.');
acrlAutofillAssert($fyCommand !== null, 'The circulation SQL must be executed.');
acrlAutofillAssert(($fyCommand->params[':fy_start'] ?? null) === '2024-07-01' && ($fyCommand->params[':fy_end'] ?? null) === '2025-07-01', 'FY boundaries must be bound as SQL parameters.');
$executableFySql = preg_replace('/--.*$/m', '', $fyCommand->sql);
acrlAutofillAssert(strpos($executableFySql, '2024-07-01') === false && strpos($executableFySql, '2025-07-01') === false, 'FY dates must not be interpolated into SQL.');

$expectedQueries = [
    ['60', 'A', 'acrl-60', 'acr003-60-81-82-circulation.sql', 'q60_physical_circulation', 'dated', 18438],
    ['81', '', 'acrl-81', 'acr003-60-81-82-circulation.sql', 'q81_consortial_lending', 'dated', 6017],
    ['82', '', 'acrl-82', 'acr003-60-81-82-circulation.sql', 'q82_consortial_borrowing', 'dated', 6897],
    ['40', 'A', 'acrl-40A', 'acr003-40A-physical-titles.sql', 'value', 'snapshot', 1077455],
    ['40a', '', 'acrl-40a', 'acr003-40a-physical-volumes.sql', 'value', 'snapshot', 1287533],
    ['40', 'B', 'acrl-40B', 'acr003-40B-digital-titles.sql', 'value', 'snapshot', 1077455],
    ['42', 'A', 'acrl-42A', 'acr003-42A-physical-media.sql', 'value', 'snapshot', 1077455],
    ['42', 'B', 'acrl-42B', 'acr003-42B-digital-media.sql', 'value', 'snapshot', 1077455],
    ['43', 'A', 'acrl-43A', 'acr003-43A-physical-serials.sql', 'value', 'snapshot', 51600],
];

$beforeBlankPart = count($db->commands);
try {
    $service->run(2025, 'survey', '60', '');
    acrlAutofillAssert(false, 'Question 60 without part A must not have an auto-fill query.');
} catch (\DomainException $exception) {
    acrlAutofillAssert(count($db->commands) === $beforeBlankPart, 'A blank part for question 60 must be rejected before opening an FOLIO transaction.');
}

$expectedSqlFiles = array_values(array_unique(array_column($expectedQueries, 3)));
sort($expectedSqlFiles);
$actualSqlFiles = array_map('basename', glob(__DIR__ . '/../sql/acrl/*.sql'));
sort($actualSqlFiles);
acrlAutofillAssert($actualSqlFiles === $expectedSqlFiles, 'Every SQL file must be covered by an executed-query safety test.');

foreach ($expectedQueries as $expected) {
    $before = count($db->commands);
    $result = $service->run(2026, 'survey', $expected[0], $expected[1]);
    acrlAutofillAssert($result['value'] === $expected[6], $expected[2] . ' must return its SQL result column.');
    acrlAutofillAssert($result['queryId'] === $expected[2], $expected[0] . $expected[1] . ' must map to its approved query id.');
    $expectedVersion = $expected[2] === 'acrl-40a' ? 3 : ($expected[2] === 'acrl-40A' ? 2 : 1);
    acrlAutofillAssert($result['queryVersion'] === $expectedVersion, $expected[2] . ' must report version ' . $expectedVersion . '.');
    acrlAutofillAssert($result['kind'] === $expected[5], $expected[2] . ' must report its dated or snapshot kind.');
    if ($expected[5] === 'snapshot') {
        acrlAutofillAssert($result['asOf'] === '2026-10-28' && $result['fyStart'] === null && $result['fyEnd'] === null, $expected[2] . ' must report its snapshot date.');
    } else {
        acrlAutofillAssert($result['fyStart'] === '2025-07-01' && $result['fyEnd'] === '2026-07-01' && $result['asOf'] === null, $expected[2] . ' must use the requested FY2026 range.');
    }
    $sourceSql = file_get_contents(__DIR__ . '/../sql/acrl/' . $expected[3]);
    if ($expected[2] === 'acrl-40A') {
        acrlAutofillAssert(strpos($sourceSql, "lt.campus_id in ('7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', 'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1')") !== false, 'Q40A must include Smith and the Five College Repository campuses.');
        acrlAutofillAssert(strpos($sourceSql, 'count(distinct it2.id)') !== false, 'Q40A must continue counting distinct physical book titles.');
        acrlAutofillAssert(strpos($sourceSql, "it.status__name in ('Awaiting pickup', 'In transit', 'Checked out', 'Available', 'Restricted', 'Paged')") !== false, 'Q40A must preserve its approved statuses.');
        acrlAutofillAssert(strpos($sourceSql, "mtt.name in ('Archival material', 'Book', 'Government Publication', 'Score', 'Thesis/Dissertation')") !== false, 'Q40A must preserve its approved material types.');
    }
    if ($expected[2] === 'acrl-40a') {
        $sourceMaterialTypeMatch = [];
        acrlAutofillAssert(preg_match('/\bmtt\.name\s+in\s*\(([^)]*)\)/i', $sourceSql, $sourceMaterialTypeMatch) === 1, 'Q40a must limit its item scope to the approved material types.');
        $sourceMaterialTypeValues = [];
        preg_match_all("/'([^']*)'/", $sourceMaterialTypeMatch[1], $sourceMaterialTypeValues);
        acrlAutofillAssert($sourceMaterialTypeValues[1] === ['Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation', 'Journal', 'Newspaper', 'Serial'], 'Q40a must not add material types outside the approved six types and three serial types.');
        $materialTypeMatches = [];
        acrlAutofillAssert(preg_match_all('/\b(?:si\.)?material_type\s+in\s*\(([^)]*)\)/i', $sourceSql, $materialTypeMatches) === 2, 'Q40a must have separate base-volume and serial material type scopes.');
        $materialTypeGroups = [];
        foreach ($materialTypeMatches[1] as $materialTypeSql) {
            $materialTypeValues = [];
            preg_match_all("/'([^']*)'/", $materialTypeSql, $materialTypeValues);
            $materialTypeGroups[] = $materialTypeValues[1];
        }
        $expectedMaterialTypeGroups = [
            ['Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation'],
            ['Journal', 'Newspaper', 'Serial'],
        ];
        acrlAutofillAssert($materialTypeGroups === $expectedMaterialTypeGroups, 'Q40a must count the six base types and scope serial types separately.');
        acrlAutofillAssert(strpos($sourceSql, "join inventory.mode_of_issuance__t moi on moi.id = inst.mode_of_issuance_id and moi.name = 'single unit'") !== false, 'Q40a must count serial types only for single-unit instances.');
        acrlAutofillAssert(strpos($sourceSql, "lt.campus_id in ('7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', 'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1')") !== false, 'Q40a must include Smith and the Five College Repository campuses.');
        acrlAutofillAssert(strpos($sourceSql, "it.status__name in ('Awaiting pickup', 'In transit', 'Checked out', 'Available', 'Restricted', 'Paged')") !== false, 'Q40a must preserve its approved statuses.');
        $serialFilterPosition = strpos($sourceSql, "where si.material_type in ('Journal', 'Newspaper', 'Serial')");
        $singleUnitFilterPosition = strpos($sourceSql, "moi.name = 'single unit'");
        acrlAutofillAssert($serialFilterPosition !== false && $singleUnitFilterPosition !== false && $singleUnitFilterPosition < $serialFilterPosition, 'Q40a must apply the single-unit mode before counting serial items.');
        acrlAutofillAssert(strpos($sourceSql, '(select count(distinct id) from scope_items') !== false && strpos($sourceSql, '(select count(distinct si.id)') !== false, 'Q40a must keep the approved two-part count form.');
        $excludedLocationIds = [
            '67204874-e4d7-495b-9247-62cd27d9ea31',
            '409b947f-d67d-4cda-846f-35c9344ae81b',
            'b0ecdd73-6416-4bd1-9cf3-483fd40b95cd',
            '81772d53-e7d0-40d4-8249-a380f3fd33f0',
            '270dceaa-8100-4e53-bf19-6decf7926f78',
            '3ffae749-2131-48e1-8bf8-7adf36a92b9c',
            '38fa998f-5044-4589-a182-2e027af0bb04',
        ];
        acrlAutofillAssert(strpos($sourceSql, 'it.effective_location_id not in') !== false, 'Q40a must exclude non-physical locations by effective location id.');
        foreach ($excludedLocationIds as $locationId) {
            acrlAutofillAssert(strpos($sourceSql, $locationId) !== false, 'Q40a must exclude location ' . $locationId . '.');
        }
    }
    acrlAutofillAssert(preg_match('/--[^\r\n]*:[A-Za-z]/', $sourceSql) !== 1, $expected[2] . ' SQL comments must not contain a colon followed by a letter.');
    $expectedSql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sourceSql);
    $queryCommand = null;
    foreach (array_slice($db->commands, $before) as $command) {
        if (trim($command->sql) === trim($expectedSql)) $queryCommand = $command;
    }
    acrlAutofillAssert($queryCommand !== null, $expected[2] . ' must execute a SELECT query.');
    acrlAutofillAssert(trim($queryCommand->sql) === trim($expectedSql), $expected[2] . ' must execute the SQL file with full-line comments removed.');
    acrlAutofillAssert(preg_match('/^[ \t]*--/m', $queryCommand->sql) !== 1, $expected[2] . ' executed SQL must omit full-line comments.');
    preg_match_all('/(?<!:):[A-Za-z][A-Za-z0-9_]*/', $queryCommand->sql, $placeholderMatches);
    $actualPlaceholders = array_values(array_unique($placeholderMatches[0]));
    sort($actualPlaceholders);
    $expectedPlaceholders = $expected[5] === 'dated' ? [':fy_end', ':fy_start'] : [];
    sort($expectedPlaceholders);
    acrlAutofillAssert($actualPlaceholders === $expectedPlaceholders, $expected[2] . ' executed SQL must contain exactly its expected bound placeholders.');
    $executableQuerySql = preg_replace('/--.*$/m', '', $queryCommand->sql);
    $header = [];
    foreach (explode("\n", $sourceSql) as $line) {
        if (strpos($line, '--') !== 0) break;
        $header[] = $line;
    }
    $headerText = implode("\n", $header);
    acrlAutofillAssert(preg_match('/\b(owner|pasted|submitted|FY2025)\b|LDLite|FIX 60/i', $headerText) !== 1, $expected[2] . ' SQL header must not include coordination evidence or source-document names.');
    if ($expected[2] === 'acrl-60') {
        foreach (['UUID ids are compared as text', 'FY end is exclusive at July 1', 'one row per checkout, so no renewal filter'] as $rule) {
            acrlAutofillAssert(strpos($headerText, $rule) !== false, 'The circulation header must state the rule: ' . $rule . '.');
        }
    }
    acrlAutofillAssert(preg_match('/^\s*(select|with)\b/i', $executableQuerySql) === 1, $expected[2] . ' must execute a SELECT query.');
    acrlAutofillAssert(preg_match('/\b(insert|update|delete|alter|drop|truncate|create|grant|revoke)\b/i', $executableQuerySql) !== 1, $expected[2] . ' SQL must not contain write statements.');
    acrlAutofillAssert(preg_match('/\busers\s*\./i', $executableQuerySql) !== 1, $expected[2] . ' SQL must not access the users schema.');
    if ($expected[5] === 'snapshot') {
        acrlAutofillAssert($queryCommand->params === [], $expected[2] . ' snapshot SQL must not receive dated parameters.');
    } else {
        acrlAutofillAssert(($queryCommand->params[':fy_start'] ?? null) === '2025-07-01' && ($queryCommand->params[':fy_end'] ?? null) === '2026-07-01', $expected[2] . ' must bind the survey-year fiscal range.');
    }
}

$methodsDb = new AcrlAutofillMethodsNeverUseDb();
$methodsService = new \app\services\AcrlAutofillService(
    new AcrlAutofillTestRepository(),
    $methodsDb,
    function () { return new DateTimeImmutable('2026-10-28T12:34:56-04:00'); },
    __DIR__ . '/../sql/acrl'
);
$methods = $methodsService->methods();
acrlAutofillAssert(count($methods) === count($expectedQueries), 'The methods catalog must return one entry for every auto-fill definition.');
$circulationMethod = null;
foreach ($methods as $method) {
    if ($method['queryId'] === 'acrl-60') $circulationMethod = $method;
}
acrlAutofillAssert($circulationMethod !== null && $circulationMethod['questionId'] === '60' && $circulationMethod['part'] === 'A', 'The methods catalog must identify the circulation query as question 60 part A.');
foreach ($methods as $method) {
    acrlAutofillAssert(!empty($method['summary']) && is_array($method['summary']), $method['queryId'] . ' must have a non-empty rule summary.');
    foreach ($method['summary'] as $summaryLine) {
        acrlAutofillAssert(is_string($summaryLine) && trim($summaryLine) !== '', $method['queryId'] . ' summary lines must be non-empty text.');
    }
    $sourceSql = file_get_contents(__DIR__ . '/../sql/acrl/' . array_column($expectedQueries, 3, 2)[$method['queryId']]);
    $expectedSql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sourceSql);
    acrlAutofillAssert($method['sql'] === $expectedSql, $method['queryId'] . ' methods SQL must exactly match the executed, stripped SQL.');
    acrlAutofillAssert(preg_match('/^[ \t]*--/m', $method['sql']) !== 1, $method['queryId'] . ' methods SQL must not include full-line comments.');
}
$physicalVolumesMethod = null;
foreach ($methods as $method) {
    if ($method['queryId'] === 'acrl-40a') $physicalVolumesMethod = $method;
}
acrlAutofillAssert($physicalVolumesMethod !== null, 'The Q40a methods entry must be present.');
acrlAutofillAssert($physicalVolumesMethod['queryVersion'] === 3, 'The Q40a methods entry must reflect ACR-024 version 3.');
acrlAutofillAssert(strpos($physicalVolumesMethod['sql'], "moi.name = 'single unit'") !== false, 'The Q40a methods SQL must limit serial materials to single-unit instances.');
acrlAutofillAssert(strpos($physicalVolumesMethod['sql'], 'Journal') !== false && strpos($physicalVolumesMethod['sql'], 'Serial') !== false && strpos($physicalVolumesMethod['sql'], 'Newspaper') !== false, 'The Q40a methods SQL must include all three serial material types.');
$physicalVolumesSummary = implode(' ', $physicalVolumesMethod['summary']);
acrlAutofillAssert(strpos($physicalVolumesSummary, 'serial volumes cataloged as monographs') !== false, 'The Q40a summary must explain the single-unit rule in plain language.');
acrlAutofillAssert(strpos($physicalVolumesSummary, 'Changed in v3 (2026-10): serial items count only when cataloged as monographs; v2 (2026-10) counted all journals, serials and newspapers; FY2023–FY2025 used v1') !== false, 'The Q40a summary must state the approved version history.');
$physicalTitlesMethod = null;
foreach ($methods as $method) {
    if ($method['queryId'] === 'acrl-40A') $physicalTitlesMethod = $method;
}
acrlAutofillAssert($physicalTitlesMethod !== null && $physicalTitlesMethod['queryVersion'] === 2, 'The Q40A methods entry must reflect ACR-024 version 2.');
$physicalTitlesSummary = implode(' ', $physicalTitlesMethod['summary']);
acrlAutofillAssert(strpos($physicalTitlesSummary, 'at Smith College and the Five College Repository') !== false, 'The Q40A summary must name both campuses.');
acrlAutofillAssert(strpos($physicalTitlesSummary, 'Changed in v2 (2026-10): Five College Repository titles are now included, as in past reporting; v1 counted the Smith campus only.') !== false, 'The Q40A summary must explain its version change.');
acrlAutofillAssert($methodsDb->calls === 0, 'The methods catalog must not call the FOLIO database.');
$circulationRules = [
    'acrl-60' => ['q60_physical_circulation', 'checked out at a Smith service point and returned at Smith or still checked out'],
    'acrl-81' => ['q81_consortial_lending', 'checked out at a non-Smith service point'],
    'acrl-82' => ['q82_consortial_borrowing', 'checked out at a Smith service point'],
];
foreach ($circulationRules as $queryId => $rules) {
    foreach ($methods as $method) {
        if ($method['queryId'] !== $queryId) continue;
        $summaryText = implode(' ', $method['summary']);
        acrlAutofillAssert(strpos($summaryText, $rules[0]) !== false, $queryId . ' must name its result column in the summary.');
        acrlAutofillAssert(strpos($summaryText, $rules[1]) !== false, $queryId . ' must explain its service-point rule in the summary.');
    }
}

$beforeInvalid = count($db->commands);
try {
    $service->run(2026, 'survey', '61', '');
    acrlAutofillAssert(false, 'A question without an approved query must be rejected.');
} catch (DomainException $exception) {
    acrlAutofillAssert(count($db->commands) === $beforeInvalid, 'Unknown question keys must be rejected before opening an LDP transaction.');
}

$repository->status = 'draft';
$beforeDraft = count($db->commands);
try {
    $service->run(2026, 'survey', '60', '');
    acrlAutofillAssert(false, 'An unpublished year must be rejected.');
} catch (DomainException $exception) {
    acrlAutofillAssert(count($db->commands) === $beforeDraft, 'Unpublished years must be rejected before opening an LDP transaction.');
}

$repository->status = 'published';
$db->lockAvailable = false;
$beforeLocked = count($db->commands);
try {
    $service->run(2026, 'survey', '81', '');
    acrlAutofillAssert(false, 'A duplicate in-flight query must be rejected.');
} catch (\app\services\AcrlAutofillBusyException $exception) {
    acrlAutofillAssert($exception->getMessage() === 'An auto-fill query is already running for this question.', 'The busy response must explain that the question is already running.');
    $busySqlRan = false;
    foreach (array_slice($db->commands, $beforeLocked) as $command) {
        if (trim($command->sql) === trim(file_get_contents(__DIR__ . '/../sql/acrl/acr003-60-81-82-circulation.sql'))) $busySqlRan = true;
    }
    acrlAutofillAssert(!$busySqlRan, 'A busy question must not run its SQL.');
    acrlAutofillAssert(end($db->transactions)->rolledBack, 'The transaction must release its lock when a concurrent run is rejected.');
}

echo "PASS: ACRL auto-fill mappings, fiscal dates, query safety, locking, and response metadata\n";
