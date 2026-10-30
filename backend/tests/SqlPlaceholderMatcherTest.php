<?php

require_once __DIR__ . '/../services/SqlPlaceholderMatcher.php';
require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';

use app\services\SqlPlaceholderMatcher;

function sqlPlaceholderAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function sqlPlaceholderSelectParams($sql, array $params)
{
    $selected = [];
    foreach ($params as $key => $value) {
        if (SqlPlaceholderMatcher::containsPlaceholder($sql, $key)) {
            $selected[$key] = $value;
        }
    }
    return $selected;
}

$cases = [
    'matches an exact placeholder name' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :fiscalYear, :other', ':fiscalYear'),
            'An exact named placeholder must match.'
        );
    },
    'does not match a prefix of a longer placeholder' => function () {
        sqlPlaceholderAssertSame(
            false,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :fiscalYearId', ':fiscalYear'),
            'A parameter name that prefixes another placeholder must not match.'
        );
    },
    'matches at end of string' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :name', ':name'),
            'A placeholder at end of string must match.'
        );
    },
    'matches before a closing parenthesis' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT (:name)', ':name'),
            'A placeholder followed by a closing parenthesis must match.'
        );
    },
    'matches before a comma' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :name, :other', ':name'),
            'A placeholder followed by a comma must match.'
        );
    },
    'matches before a space' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :name FROM report', ':name'),
            'A placeholder followed by a space must match.'
        );
    },
    'matches before a newline' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder("SELECT :name\nFROM report", ':name'),
            'A placeholder followed by a newline must match.'
        );
    },
    'does not count a PostgreSQL cast as a placeholder' => function () {
        sqlPlaceholderAssertSame(
            false,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT value::date', ':date'),
            'A cast type after :: must not be treated as a named placeholder.'
        );
    },
    'accepts a key without a leading colon' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :fiscalYear', 'fiscalYear'),
            'A key without its leading colon must match the named placeholder.'
        );
    },
    'accepts a key with a leading colon' => function () {
        sqlPlaceholderAssertSame(
            true,
            SqlPlaceholderMatcher::containsPlaceholder('SELECT :fiscalYear', ':fiscalYear'),
            'A key with its leading colon must match the named placeholder.'
        );
    },
    'selects migration 051 report 36 params for primary and secondary SQL' => function () {
        $report = budgetYearExpenseClassReportFixture();
        $compositeConfig = json_decode($report->composite_config, true);
        if (!is_array($compositeConfig) || !isset($compositeConfig['secondary_sql'])) {
            throw new \RuntimeException('Migration 051 report 36 must provide composite secondary SQL.');
        }

        $primarySql = $report->sql_template;
        $secondarySql = $compositeConfig['secondary_sql'];
        if (strpos($primarySql, ':fiscalYearId') === false
            || strpos($primarySql, ':invoiceFiscalYearId') === false
            || strpos($secondarySql, ':fiscalYear') === false) {
            throw new \RuntimeException('Migration 051 report 36 SQL must contain its stored fiscal-year placeholders.');
        }

        $params = [
            ':fiscalYear' => '2026',
            ':fiscalYearId' => 'synthetic-folio-fiscal-year-id',
            ':invoiceFiscalYearId' => 'synthetic-invoice-fiscal-year-id',
        ];
        $primaryParams = sqlPlaceholderSelectParams($primarySql, $params);
        $secondaryParams = sqlPlaceholderSelectParams($secondarySql, $params);

        sqlPlaceholderAssertSame(
            false,
            array_key_exists(':fiscalYear', $primaryParams),
            'Primary params from migration 051 SQL must exclude :fiscalYear.'
        );
        sqlPlaceholderAssertSame(
            true,
            array_key_exists(':fiscalYear', $secondaryParams),
            'Secondary params from migration 051 SQL must include :fiscalYear.'
        );
    },
];

$failures = [];
foreach ($cases as $name => $case) {
    try {
        $case();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (\Throwable $error) {
        $failures[] = $name;
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
}

if (count($failures) > 0) {
    fwrite(STDERR, count($failures) . ' placeholder matcher test case(s) failed.');
    exit(1);
}

fwrite(STDOUT, 'All placeholder matcher test cases passed.' . "\n");
