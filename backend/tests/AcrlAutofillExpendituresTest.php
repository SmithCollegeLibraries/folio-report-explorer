<?php

function acrl030Check(bool $condition, string $message): void
{
    global $acrl030Failures;
    if (!$condition) {
        $acrl030Failures[] = $message;
    }
}

class Acrl030Repository
{
    public function year(int $year): ?array
    {
        return ['survey_year' => $year, 'status' => 'published'];
    }

    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        return ['survey_year' => $year, 'sheet' => $sheet, 'question_id' => $questionId, 'part' => $part];
    }
}

class Acrl030Transaction
{
    public $isActive = true;

    public function commit(): void
    {
        $this->isActive = false;
    }

    public function rollBack(): void
    {
        $this->isActive = false;
    }
}

class Acrl030Command
{
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function execute(): int
    {
        return 0;
    }

    public function queryScalar(): bool
    {
        return true;
    }

    public function queryOne(): array
    {
        return $this->row;
    }
}

class Acrl030Db
{
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function beginTransaction()
    {
        return new Acrl030Transaction();
    }

    public function createCommand($sql, array $params = [])
    {
        return new Acrl030Command($this->row);
    }
}

function acrl030Run(string $questionId, string $part, array $row): array
{
    $service = new \app\services\AcrlAutofillService(
        new Acrl030Repository(),
        new Acrl030Db($row),
        function () { return new DateTimeImmutable('2026-11-05T12:00:00-05:00'); },
        __DIR__ . '/../sql/acrl'
    );

    try {
        return ['result' => $service->run(2025, 'survey', $questionId, $part), 'exception' => null];
    } catch (Throwable $exception) {
        return ['result' => null, 'exception' => $exception];
    }
}

$acrl030Failures = [];
$servicePath = __DIR__ . '/../services/AcrlAutofillService.php';
$busyExceptionPath = __DIR__ . '/../services/AcrlAutofillBusyException.php';
require_once $busyExceptionPath;
require_once $servicePath;

$expectedDefinitions = [
    'survey|20|' => ['acrl-20', 'q20_one_time', 'One-Time', []],
    'survey|20a|' => ['acrl-20a', 'q20a_one_time_ebooks', 'One-Time', ['E-Book', 'E-Book Package']],
    'survey|21|' => ['acrl-21', 'q21_ongoing', 'Ongoing', []],
    'survey|21a|' => ['acrl-21a', 'q21a_ongoing_ebooks', 'Ongoing', ['E-Book', 'E-Book Package']],
    'survey|21b|' => ['acrl-21b', 'q21b_ongoing_ejournals', 'Ongoing', ['E-Journal', 'E-Journal Package']],
];
$definitionConstant = (new ReflectionClass(\app\services\AcrlAutofillService::class))
    ->getReflectionConstant('DEFINITIONS')
    ->getValue();

foreach ($expectedDefinitions as $key => $expected) {
    $definition = $definitionConstant[$key] ?? null;
    acrl030Check(is_array($definition), $key . ' must have an auto-fill definition.');
    if (!is_array($definition)) {
        continue;
    }

    acrl030Check(($definition['queryId'] ?? null) === $expected[0], $key . ' must use queryId ' . $expected[0] . '.');
    acrl030Check(($definition['version'] ?? null) === 1, $key . ' must use version 1.');
    acrl030Check(($definition['column'] ?? null) === $expected[1], $key . ' must use column ' . $expected[1] . '.');
    acrl030Check(($definition['kind'] ?? null) === 'dated', $key . ' must use kind dated.');
    acrl030Check(($definition['valueType'] ?? null) === 'currency', $key . ' must use valueType currency.');

    $summary = implode(' ', $definition['summary'] ?? []);
    acrl030Check(strpos($summary, $expected[2]) !== false, $key . ' summary must name order type ' . $expected[2] . '.');
    acrl030Check(strpos($summary, 'Admin') !== false, $key . ' summary must say that material type Admin is excluded.');
    acrl030Check(strpos($summary, 'FOLIO data only') !== false, $key . ' summary must identify the value as FOLIO data only.');
    acrl030Check(strpos($summary, 'adjust the value if other spending belongs here') !== false, $key . ' summary must invite adjustment for other spending.');
    foreach ($expected[3] as $materialType) {
        acrl030Check(strpos($summary, $materialType) !== false, $key . ' summary must name material type ' . $materialType . '.');
    }
}

$sqlPath = __DIR__ . '/../sql/acrl/acr030-20-21-expenditures.sql';
$sqlExists = is_file($sqlPath);
acrl030Check($sqlExists, 'The static SQL checks require backend/sql/acrl/acr030-20-21-expenditures.sql to exist.');
if ($sqlExists) {
    $sql = file_get_contents($sqlPath);
    $executableSql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql);

    preg_match_all('/(?<!:):[A-Za-z][A-Za-z0-9_]*/', $executableSql, $placeholderMatches);
    $placeholders = array_values(array_unique($placeholderMatches[0]));
    sort($placeholders);
    acrl030Check($placeholders === [':fy_end', ':fy_start'], 'The expenditures SQL must bind only :fy_start and :fy_end.');
    acrl030Check(
        preg_match('/[\'\"]SCFY[\'\"]\s*\|\|\s*extract\s*\(\s*year\s+from\s+[\s\S]{0,80}:fy_end/i', $executableSql) === 1,
        'The expenditures SQL must derive the SCFY year from :fy_end.'
    );
    acrl030Check(
        preg_match('/transaction_type\s+in\s*\(\s*\'Payment\'\s*,\s*\'Credit\'\s*\)/i', $executableSql) === 1,
        'The expenditures SQL must include Payment and Credit transactions.'
    );
    acrl030Check(strpos($executableSql, 'finance.transaction__t') !== false, 'The expenditures SQL must read finance.transaction__t.');
    acrl030Check(
        preg_match('/case\s+when\s+[^;]*transaction_type\s*=\s*\'Credit\'\s+then\s+-[^;]*amount/is', $executableSql) === 1,
        'The expenditures SQL must subtract Credit amounts from Payment amounts.'
    );
    foreach ([
        'source_invoice_line_id',
        'invoice.invoice_lines__t',
        'po_line_id',
        'orders.po_line__t',
        'purchase_order_id',
        'orders.purchase_order__t',
    ] as $joinPart) {
        acrl030Check(strpos($executableSql, $joinPart) !== false, 'The expenditures SQL must join through ' . $joinPart . '.');
    }
    acrl030Check(strpos($executableSql, 'eresource__material_type') !== false, 'The expenditures SQL must use the PO line electronic material type.');
    acrl030Check(strpos($executableSql, 'physical__material_type') !== false, 'The expenditures SQL must fall back to the PO line physical material type.');
    acrl030Check(substr_count($executableSql, '::text') >= 2, 'The expenditures SQL must compare PO line material type UUIDs as text.');
    foreach (['One-Time', 'Ongoing', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package'] as $filterValue) {
        acrl030Check(strpos($executableSql, $filterValue) !== false, 'The expenditures SQL must scope values by ' . $filterValue . '.');
    }
    acrl030Check(
        strpos($executableSql, 'Admin') !== false
            && preg_match('/(?:<>|!=)\s*\'Admin\'|\'Admin\'\s*(?:<>|!=)/i', $executableSql) === 1,
        'The expenditures SQL must exclude material type Admin.'
    );
    foreach ([
        'q20_one_time',
        'q20a_one_time_ebooks',
        'q21_ongoing',
        'q21a_ongoing_ebooks',
        'q21b_ongoing_ejournals',
    ] as $column) {
        acrl030Check(preg_match('/\bas\s+' . preg_quote($column, '/') . '\b/i', $executableSql) === 1, 'The expenditures SQL must return column ' . $column . '.');
    }
    acrl030Check(substr_count(strtolower($executableSql), 'round(') >= 5, 'Each expenditures total must be rounded to two decimal places.');
    acrl030Check(substr_count(strtolower($executableSql), 'coalesce(') >= 5, 'Each expenditures total must return zero when it has no rows.');
}

$currency = acrl030Run('20', '', ['q20_one_time' => 1234.56]);
acrl030Check($currency['exception'] === null, 'Q20 must accept a numeric currency result.');
acrl030Check(
    is_array($currency['result']) && ($currency['result']['value'] ?? null) === 1234.56,
    'Q20 currency results must preserve cents as the numeric value 1234.56.'
);

$negativeCurrency = acrl030Run('20', '', ['q20_one_time' => -1234.56]);
acrl030Check($negativeCurrency['exception'] instanceof RuntimeException, 'Negative Q20 currency results must be rejected.');
acrl030Check(
    $negativeCurrency['exception'] instanceof RuntimeException
        && $negativeCurrency['exception']->getMessage() === 'The ACRL auto-fill query returned an invalid count.',
    'Negative Q20 currency results must keep the existing invalid-count message.'
);

$nonNumericCurrency = acrl030Run('20', '', ['q20_one_time' => 'not-a-number']);
acrl030Check($nonNumericCurrency['exception'] instanceof RuntimeException, 'Non-numeric Q20 currency results must be rejected.');
acrl030Check(
    $nonNumericCurrency['exception'] instanceof RuntimeException
        && $nonNumericCurrency['exception']->getMessage() === 'The ACRL auto-fill query did not return its expected count.',
    'Non-numeric Q20 currency results must keep the existing invalid-result message.'
);

$count = acrl030Run('60', 'A', ['q60_physical_circulation' => 18438.75]);
acrl030Check($count['exception'] === null, 'Existing count definitions must still accept numeric results.');
acrl030Check(
    is_array($count['result']) && ($count['result']['value'] ?? null) === 18438,
    'Existing count definitions must still truncate fractional values to an integer.'
);
if ($count['exception'] === null && is_array($count['result']) && ($count['result']['value'] ?? null) === 18438) {
    fwrite(STDOUT, "PASS: existing count definitions still truncate fractional results to integers\n");
}

if ($acrl030Failures) {
    foreach ($acrl030Failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    fwrite(STDERR, 'FAIL: ' . count($acrl030Failures) . " ACR-030 expenditure checks failed.\n");
    exit(1);
}

echo "PASS: ACR-030 expenditure definitions, currency validation, SQL rules, and legacy count behavior\n";
