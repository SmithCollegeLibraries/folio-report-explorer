<?php

$migration035 = __DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql';
$migration036 = __DIR__ . '/../../mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql';
$migration037 = __DIR__ . '/../../mysql/migrations/037_budget_year_fund_report_payment_distributions.sql';
$migration062 = __DIR__ . '/../../mysql/migrations/062_budget_year_fund_report_encumbrance_status.sql';
$failures = [];

function budgetEncumbranceMigrationAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function budgetEncumbranceMigrationContains(string $needle, string $haystack, string $message): void
{
    budgetEncumbranceMigrationAssert(strpos($haystack, $needle) !== false, $message);
}

function budgetEncumbranceMigrationFinalSelect(string $sql): string
{
    return preg_match('/SELECT sf\.code AS "Fund Code".*?\nFROM budgets b/s', $sql, $matches) === 1
        ? $matches[0]
        : '';
}

function budgetEncumbranceMigrationSelectMap(string $select): array
{
    $select = preg_replace('/^SELECT\s+/i', '', trim($select));
    $parts = [];
    $start = 0;
    $depth = 0;
    $quote = '';
    $length = strlen($select);
    for ($index = 0; $index < $length; $index++) {
        $character = $select[$index];
        if ($quote !== '') {
            if ($character === $quote) {
                if ($quote === "'" && $index + 1 < $length && $select[$index + 1] === "'") {
                    $index++;
                } else {
                    $quote = '';
                }
            }
            continue;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
        } elseif ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        } elseif ($character === ',' && $depth === 0) {
            $parts[] = trim(substr($select, $start, $index - $start));
            $start = $index + 1;
        }
    }
    $parts[] = trim(substr($select, $start));

    $map = [];
    foreach ($parts as $part) {
        if (preg_match('/^(.*?)\s+AS\s+"([^"]+)"$/s', $part, $matches) === 1) {
            $map[$matches[2]] = preg_replace('/\s+/', ' ', trim($matches[1]));
        }
    }
    return $map;
}

budgetEncumbranceMigrationAssert(
    hash_file('sha256', $migration035) === 'ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab',
    'PASS NOW (protects existing behavior): migration 035 must keep its pinned hash.'
);
budgetEncumbranceMigrationAssert(
    hash_file('sha256', $migration036) === 'cb90cd367bee5943c10c9922315f78a0ff16e0465c1eb283df7defa915c19c76',
    'PASS NOW (protects existing behavior): migration 036 must keep its pinned hash.'
);
budgetEncumbranceMigrationAssert(
    hash_file('sha256', $migration037) === '552c75e498996e6f65c8bcb846676002380e549cd30d6317b57798d8fcaa3ed2',
    'PASS NOW (protects existing behavior): migration 037 must keep its pinned hash.'
);
if ($failures !== []) {
    fwrite(STDERR, "FAIL: historical budget report migration hashes\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS NOW (protects existing behavior): migration 035/036/037 hashes are unchanged.\n");

if (!file_exists($migration062)) {
    fwrite(STDERR, "FAIL: migration 062 is missing; its contract assertions cannot run until the build round adds it.\n");
    exit(1);
}
$sql = (string)file_get_contents($migration062);
$oldSql = (string)file_get_contents($migration037);

budgetEncumbranceMigrationAssert(
    preg_match('/INSERT\s+INTO\s+`?report_templates`?/i', $sql) === 1,
    'Migration 062 must upsert the report template row.'
);
budgetEncumbranceMigrationContains("'budget-year-fund-report'", $sql, 'Migration 062 must retain the fixed report slug.');
budgetEncumbranceMigrationContains('ON DUPLICATE KEY UPDATE', $sql, 'Migration 062 must be safe to run twice.');
budgetEncumbranceMigrationAssert(
    preg_match('/VALUES\s*\(\s*37\s*,\s*\n?\s*\'budget-year-fund-report\'/s', $sql) === 1,
    'Migration 062 must update report id 37 with the fixed slug.'
);
budgetEncumbranceMigrationAssert(
    strpos($sql, "'finance'") !== false && strpos($sql, "'folio'") !== false && preg_match('/\n\s*1000\s*,/s', $sql) === 1,
    'Migration 062 must preserve the finance category, FOLIO source, and 1000 row limit.'
);
budgetEncumbranceMigrationAssert(
    strpos($sql, "'Budget Year Fund Report'") !== false && strpos($sql, "'manual'") !== false,
    'Migration 062 must preserve the report name and created_by value.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/\n\s*\'(\[.*?\])\',\s*\n\s*\'folio\',\s*\n\s*1000,/s', $sql, $parameterMatch) === 1,
    'Migration 062 must retain the report parameter JSON block.'
);
$parameters = isset($parameterMatch[1]) ? json_decode(str_replace("''", "'", $parameterMatch[1]), true) : null;
budgetEncumbranceMigrationAssert(
    is_array($parameters)
        && count($parameters) === 2
        && ($parameters[0]['name'] ?? null) === 'fiscalYearEndYear'
        && ($parameters[1]['name'] ?? null) === 'acqUnitId',
    'Migration 062 must preserve exactly the two existing parameters in order.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/\b(RENAME\s+TABLE|DROP\s+TABLE|TRUNCATE)\b/i', $sql) !== 1,
    'Migration 062 must not rename, drop, or truncate tables.'
);

$expectedAliases = [
    'Fund Code',
    'Fund Name',
    'Fiscal Year',
    'Allocated',
    'Payments',
    'Calculated Current Encumbrances',
    'Unreleased Count',
    'Released Encumbrances',
    'Released Count',
    'Total Committed',
    'Calculated Remaining',
    'FOLIO Expenditures',
    'FOLIO Encumbered',
    'FOLIO Available',
];
$newSelect = budgetEncumbranceMigrationFinalSelect($sql);
$oldSelect = budgetEncumbranceMigrationFinalSelect($oldSql);
$newColumns = budgetEncumbranceMigrationSelectMap(str_replace("''", "'", $newSelect));
$oldColumns = budgetEncumbranceMigrationSelectMap(str_replace("''", "'", $oldSelect));
budgetEncumbranceMigrationAssert(
    array_keys($newColumns) === $expectedAliases,
    'Migration 062 must expose exactly the 14 aliases in the approved order.'
);
$oldAliases = array_slice($expectedAliases, 0, 6);
$oldAliases = array_merge($oldAliases, array_slice($expectedAliases, 9));
budgetEncumbranceMigrationAssert(
    array_keys($oldColumns) === ['Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments', 'Calculated Current Encumbrances', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures', 'FOLIO Encumbered', 'FOLIO Available'],
    'PASS NOW (protects existing behavior): migration 037 must retain its 11 aliases in order.'
);
foreach ($oldAliases as $alias) {
    budgetEncumbranceMigrationAssert(
        isset($newColumns[$alias], $oldColumns[$alias]) && $newColumns[$alias] === $oldColumns[$alias],
        "The existing output expression for {$alias} must remain unchanged from migration 037."
    );
}

$encumbranceCte = '';
if (preg_match('/encumbrances\s+AS\s*\((.*?)\n\s*\),\s*payments\s+AS\s*\(/is', $sql, $cteMatches) === 1) {
    $encumbranceCte = $cteMatches[1];
}
budgetEncumbranceMigrationAssert($encumbranceCte !== '', 'Migration 062 must define the encumbrances CTE before payments.');
budgetEncumbranceMigrationAssert(
    substr_count($encumbranceCte, 'SELECT') === 1 && substr_count($encumbranceCte, 'finance.transaction__t') === 1,
    'The encumbrances CTE must aggregate in one pass without per-row source subqueries.'
);
budgetEncumbranceMigrationContains(
    "encumbrance__status IN (''Unreleased'', ''Active'', ''Released'')",
    $encumbranceCte,
    'The encumbrances CTE must include Released, Unreleased, and Active transaction rows.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/SUM\s*\(\s*CASE\s+WHEN\s+t\.encumbrance__status\s+IN\s*\(\s*\'\'Unreleased\'\'\s*,\s*\'\'Active\'\'\s*\)\s+THEN\s+COALESCE\s*\(\s*t\.encumbrance__initial_amount_encumbered\s*,\s*0\s*\)\s*-\s*COALESCE\s*\(\s*t\.encumbrance__amount_expended\s*,\s*0\s*\)\s*-\s*COALESCE\s*\(\s*t\.encumbrance__amount_awaiting_payment\s*,\s*0\s*\)\s+ELSE\s+0\s+END\s*\)\s+AS\s+current_encumbrance/is',
        $encumbranceCte
    ) === 1,
    'Calculated Current Encumbrances must sum only Unreleased and Active rows using the original arithmetic.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/SUM\s*\(\s*CASE\s+WHEN\s+t\.encumbrance__status\s*=\s*\'\'Unreleased\'\'\s+THEN\s+1\s+ELSE\s+0\s+END\s*\)\s+AS\s+unreleased_count/is', $encumbranceCte) === 1,
    'Unreleased Count must count Unreleased transactions without depending on amount values.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/SUM\s*\(\s*CASE\s+WHEN\s+t\.encumbrance__status\s*=\s*\'\'Released\'\'\s+THEN\s+COALESCE\s*\(\s*t\.encumbrance__initial_amount_encumbered\s*,\s*0\s*\)\s+ELSE\s+0\s+END\s*\)\s+AS\s+released_encumbrances/is', $encumbranceCte) === 1,
    'Released Encumbrances must conditionally sum the initial amount for Released transactions.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/SUM\s*\(\s*CASE\s+WHEN\s+t\.encumbrance__status\s*=\s*\'\'Released\'\'\s+THEN\s+1\s+ELSE\s+0\s+END\s*\)\s+AS\s+released_count/is', $encumbranceCte) === 1,
    'Released Count must count Released transactions, including rows whose amount is null.'
);
budgetEncumbranceMigrationContains('GROUP BY t.from_fund_id, t.fiscal_year_id', $encumbranceCte, 'Encumbrances must remain grouped by fund and fiscal-year id.');
budgetEncumbranceMigrationContains(
    'LEFT JOIN encumbrances e' . "\n  ON e.fund_id = b.fund_id" . "\n AND e.fiscal_year_id = b.fiscal_year_id",
    $sql,
    'The report must join encumbrances using both fund id and fiscal-year id.'
);
budgetEncumbranceMigrationContains('ROUND(COALESCE(e.released_encumbrances, 0), 2) AS "Released Encumbrances"', $newSelect, 'Released Encumbrances must render a numeric two-decimal zero when absent.');
budgetEncumbranceMigrationContains('COALESCE(e.unreleased_count, 0) AS "Unreleased Count"', $newSelect, 'Unreleased Count must render zero when absent.');
budgetEncumbranceMigrationContains('COALESCE(e.released_count, 0) AS "Released Count"', $newSelect, 'Released Count must render zero when absent.');
budgetEncumbranceMigrationAssert(
    preg_match('/encumbrance__status\s*=\s*\'\'Pending\'\'/i', $encumbranceCte) !== 1,
    'Pending must be excluded from all encumbrance aggregates.'
);
budgetEncumbranceMigrationContains('Unreleased Count:', $sql, 'Help text must describe Unreleased Count.');
budgetEncumbranceMigrationContains('Released Encumbrances:', $sql, 'Help text must describe Released Encumbrances.');
budgetEncumbranceMigrationContains('Released Count:', $sql, 'Help text must describe Released Count.');
budgetEncumbranceMigrationAssert(
    preg_match('/Pending[^.]{0,120}(excluded|not counted|not included)|(excluded|not counted|not included)[^.]{0,120}Pending/i', $sql) === 1,
    'Help text must explicitly state that Pending transactions are excluded from the new figures.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/Calculated Current Encumbrances:[^.]{0,160}unreleased amount/i', $sql) === 1,
    'Help text must describe Calculated Current Encumbrances as the unreleased amount.'
);
budgetEncumbranceMigrationAssert(
    preg_match('/released and unreleased encumbrances/i', $sql) === 1,
    'The description must mention released and unreleased encumbrances.'
);

if ($failures !== []) {
    fwrite(STDERR, "FAIL: Budget Year Fund Report migration 062 contract\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: Budget Year Fund Report migration 062 contract\n");
