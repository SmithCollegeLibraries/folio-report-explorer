<?php

require_once __DIR__ . '/../services/BudgetYearFundReportMigrationRecognitionService.php';

use app\services\BudgetYearFundReportMigrationRecognitionService;

$storedTextFailures = [];

function storedTextAssert(bool $condition, string $message): void
{
    global $storedTextFailures;
    if (!$condition) {
        $storedTextFailures[] = $message;
    }
}

function storedTextMigrationRow(string $path): array
{
    $sql = (string)file_get_contents($path);
    $pattern = '/INSERT\s+INTO\s+`?report_templates`?\s*\((.*?)\)\s*VALUES\s*\(/is';
    if (preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException("Could not find report_templates insert in {$path}.");
    }

    $columns = array_map(
        static function (string $column): string {
            return trim($column, " `\t\r\n");
        },
        explode(',', $matches[1][0])
    );
    $offset = $matches[0][1] + strlen($matches[0][0]);
    $values = storedTextReadValues($sql, $offset);
    if (count($columns) !== count($values)) {
        throw new RuntimeException("Column/value count differs in {$path}.");
    }

    $row = array_combine($columns, $values);
    if (!is_array($row) || (int)($row['id'] ?? 0) !== 37 || ($row['slug'] ?? null) !== 'budget-year-fund-report') {
        throw new RuntimeException("Migration {$path} does not seed report id 37.");
    }
    return $row;
}

function storedTextReadValues(string $sql, int &$offset): array
{
    $values = [];
    $length = strlen($sql);
    while ($offset < $length) {
        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
        if ($offset >= $length || $sql[$offset] === ')') {
            if ($offset < $length) {
                $offset++;
            }
            break;
        }

        if ($sql[$offset] === "'") {
            $values[] = storedTextReadString($sql, $offset);
        } else {
            $start = $offset;
            while ($offset < $length && $sql[$offset] !== ',' && $sql[$offset] !== ')') {
                $offset++;
            }
            $value = trim(substr($sql, $start, $offset - $start));
            $values[] = preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : $value;
        }

        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
        if ($offset < $length && $sql[$offset] === ',') {
            $offset++;
            continue;
        }
        if ($offset < $length && $sql[$offset] === ')') {
            $offset++;
            break;
        }
        throw new RuntimeException('Unexpected token in report_templates VALUES list.');
    }
    return $values;
}

function storedTextReadString(string $sql, int &$offset): string
{
    $offset++;
    $value = '';
    $length = strlen($sql);
    while ($offset < $length) {
        $character = $sql[$offset++];
        if ($character === "'") {
            if ($offset < $length && $sql[$offset] === "'") {
                $value .= "'";
                $offset++;
                continue;
            }
            return $value;
        }
        if ($character === "\\" && $offset < $length) {
            $escaped = $sql[$offset++];
            $value .= [
                '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t",
                'Z' => chr(26), "\\" => "\\", "'" => "'", '"' => '"',
            ][$escaped] ?? $escaped;
            continue;
        }
        $value .= $character;
    }
    throw new RuntimeException('Unterminated MySQL string literal in report_templates VALUES list.');
}

final class StoredTextReportSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        return $table === 'report_templates' ? (object)['columns' => ['help_text' => new stdClass()]] : null;
    }
}

final class StoredTextReportDatabase
{
    public $schema;
    public $row;
    public $queries = [];

    public function __construct(array $row)
    {
        $this->schema = new StoredTextReportSchema();
        $this->row = $row;
    }

    public function createCommand(string $sql = '', array $params = []): StoredTextReportCommand
    {
        return new StoredTextReportCommand($this, $sql, $params);
    }

    public function evaluate(string $sql, array $params): int
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        if (strpos($sql, 'SELECT COUNT(*) FROM report_templates WHERE ') !== 0) {
            return 0;
        }

        if (($this->row['id'] ?? null) !== 37 || strpos($sql, 'id = 37') === false) {
            return 0;
        }
        $fieldParams = [
            'slug' => 'slug', 'name' => 'name', 'description' => 'description',
            'category' => 'category', 'data_source' => 'data_source',
            'default_limit' => 'default_limit', 'created_by' => 'created_by',
        ];
        foreach ($fieldParams as $field => $parameter) {
            $clause = $field . ' = :' . $parameter;
            if (strpos($sql, $clause) !== false
                && (($this->row[$field] ?? null) !== ($params[':' . $parameter] ?? null))) {
                return 0;
            }
        }
        if (strpos($sql, 'is_active = 1') !== false && (int)($this->row['is_active'] ?? 0) !== 1) {
            return 0;
        }

        if (preg_match('/JSON_LENGTH\(parameters\)\s*=\s*(\d+)/i', $sql, $lengthMatch) === 1) {
            $jsonParameters = json_decode((string)($this->row['parameters'] ?? ''), true);
            if (!is_array($jsonParameters) || count($jsonParameters) !== (int)$lengthMatch[1]) {
                return 0;
            }
        } else {
            $jsonParameters = json_decode((string)($this->row['parameters'] ?? ''), true);
        }

        if (preg_match_all('/JSON_UNQUOTE\(JSON_EXTRACT\(parameters,\s*\'\$\[(\d+)\]\.([a-z_]+)\'\)\)\s*=\s*(?:BINARY\s+)?(:[a-z0-9_]+)/i', $sql, $parameterMatches, PREG_SET_ORDER)) {
            foreach ($parameterMatches as $match) {
                $actual = $jsonParameters[(int)$match[1]][$match[2]] ?? null;
                if ($actual !== ($params[$match[3]] ?? null)) {
                    return 0;
                }
            }
        }
        if (preg_match_all('/JSON_EXTRACT\(parameters,\s*\'\$\[(\d+)\]\.required\'\)\s*=\s*true/i', $sql, $requiredMatches, PREG_SET_ORDER)) {
            foreach ($requiredMatches as $match) {
                if (($jsonParameters[(int)$match[1]]['required'] ?? null) !== true) {
                    return 0;
                }
            }
        }

        if (preg_match_all('/(sql_template|help_text|CAST\(parameters AS CHAR\))\s+(NOT\s+)?LIKE\s+(:[a-z0-9_]+)/i', $sql, $likeMatches, PREG_SET_ORDER)) {
            foreach ($likeMatches as $match) {
                $field = strtoupper($match[1]) === 'CAST(PARAMETERS AS CHAR)' ? 'parameters' : $match[1];
                $value = (string)($this->row[$field] ?? '');
                $pattern = (string)($params[$match[3]] ?? '');
                $needle = trim($pattern, '%');
                $found = stripos($value, $needle) !== false;
                if (!empty($match[2]) ? $found : !$found) {
                    return 0;
                }
            }
        }

        if (preg_match_all('/LOCATE\(\'AS "([^"]+)"\',\s*sql_template\)\s*<\s*LOCATE\(\'AS "([^"]+)"\',\s*sql_template\)/i', $sql, $orderMatches, PREG_SET_ORDER)) {
            foreach ($orderMatches as $match) {
                $first = strpos((string)$this->row['sql_template'], 'AS "' . $match[1] . '"');
                $second = strpos((string)$this->row['sql_template'], 'AS "' . $match[2] . '"');
                if ($first === false || $second === false || $first >= $second) {
                    return 0;
                }
            }
        }
        return 1;
    }
}

final class StoredTextReportCommand
{
    private $database;
    private $sql;
    private $params;

    public function __construct(StoredTextReportDatabase $database, string $sql, array $params)
    {
        $this->database = $database;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryScalar(): int
    {
        return $this->database->evaluate($this->sql, $this->params);
    }
}

function storedTextRecognizes(string $method, array $row): array
{
    $database = new StoredTextReportDatabase($row);
    $result = BudgetYearFundReportMigrationRecognitionService::$method($database);
    return [$result, $database];
}

$migrationDirectory = __DIR__ . '/../../mysql/migrations/';
$rows = [
    '035' => storedTextMigrationRow($migrationDirectory . '035_budget_year_fund_report.sql'),
    '036' => storedTextMigrationRow($migrationDirectory . '036_budget_year_fund_report_fiscal_year_options.sql'),
    '037' => storedTextMigrationRow($migrationDirectory . '037_budget_year_fund_report_payment_distributions.sql'),
    '062' => storedTextMigrationRow($migrationDirectory . '062_budget_year_fund_report_encumbrance_status.sql'),
];

$testCases = [
    ['budgetYearFundReportEncumbranceStatusAppearComplete', '062', true, '062 recognition must match the row stored by migration 062.'],
    ['budgetYearFundReportPaymentDistributionsAppearComplete', '037', true, '037 recognition must match the row stored by migration 037.'],
    ['budgetYearFundReportFiscalYearOptionsAppearComplete', '036', true, '036 recognition must match the row stored by migration 036.'],
    ['budgetYearFundReportAppearsComplete', '035', true, '035 recognition must match the row stored by migration 035.'],
    ['budgetYearFundReportAppearsComplete', '062', true, '035 recognition must accept the 062 successor row.'],
    ['budgetYearFundReportFiscalYearOptionsAppearComplete', '062', true, '036 recognition must accept the 062 successor row.'],
    ['budgetYearFundReportPaymentDistributionsAppearComplete', '062', true, '037 recognition must accept the 062 successor row.'],
    ['budgetYearFundReportEncumbranceStatusAppearComplete', '037', false, '062 recognition must reject the 037 row.'],
];
foreach ($testCases as $case) {
    [$result, $database] = storedTextRecognizes($case[0], $rows[$case[1]]);
    storedTextAssert($result === $case[2], $case[3]);
    storedTextAssert($database->queries !== [], $case[3] . ' Recognition must query the stored row.');
}

if ($storedTextFailures !== []) {
    fwrite(STDERR, "FAIL: Budget Year Fund Report recognition against migration-stored text\n- " . implode("\n- ", $storedTextFailures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: Budget Year Fund Report recognition matches stored migration text\n");
