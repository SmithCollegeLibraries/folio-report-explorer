#!/bin/sh
set -eu

if [ "${LOCAL_REPORT_IT:-0}" != "1" ]; then
    printf '%s\n' 'SKIP: set LOCAL_REPORT_IT=1 only for a throwaway MySQL 8 or MariaDB 10.3 test database.'
    exit 0
fi

: "${MYSQL_HOST:?Set MYSQL_HOST to the throwaway database on the isolated test network.}"
: "${MYSQL_PORT:=3306}"
: "${MYSQL_DATABASE:?Set MYSQL_DATABASE to the throwaway application schema.}"
: "${LOCAL_REPORT_IT_ROOT_USER:?Set the throwaway database administrator user.}"
: "${LOCAL_REPORT_IT_ROOT_PASS:?Set the throwaway database administrator password.}"
: "${LOCAL_REPORT_DB_USER:?Set the throwaway local-report account name.}"
: "${LOCAL_REPORT_DB_PASS:?Set the throwaway local-report account password.}"

case "$LOCAL_REPORT_DB_PASS" in
    *"'"*|*'"'*|*'$'*|*" "*|*'#'*)
        printf '%s\n' 'FAIL: the local-report password contains a character unsupported by the deployment env files.' >&2
        exit 1
        ;;
esac

component_root=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
cd "$component_root"

php <<'PHP'
<?php

require getcwd() . '/backend/vendor/autoload.php';
require getcwd() . '/backend/vendor/yiisoft/yii2/Yii.php';

$stderr = fopen('php://stderr', 'w');
$database = getenv('MYSQL_DATABASE');
$account = getenv('LOCAL_REPORT_DB_USER');
$password = getenv('LOCAL_REPORT_DB_PASS');
$accountHost = getenv('LOCAL_REPORT_IT_ACCOUNT_HOST') ?: '%';
$identifierPattern = '/^[A-Za-z0-9_]+$/';
if (!preg_match($identifierPattern, $database) || !preg_match('/^[A-Za-z0-9_-]+$/', $account)) {
    fwrite($stderr, "FAIL: database and account names must use simple identifier characters.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $password)) {
    fwrite($stderr, "FAIL: the test password must use alphanumeric, dot, underscore or hyphen characters.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_.%:-]+$/', $accountHost)) {
    fwrite($stderr, "FAIL: test account host contains unsupported characters.\n");
    exit(1);
}

$dsn = 'mysql:host=' . getenv('MYSQL_HOST')
    . ';port=' . (getenv('MYSQL_PORT') ?: '3306')
    . ';dbname=' . $database
    . ';charset=utf8mb4';
$admin = new PDO($dsn, getenv('LOCAL_REPORT_IT_ROOT_USER'), getenv('LOCAL_REPORT_IT_ROOT_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$admin->exec('CREATE TABLE IF NOT EXISTS report_expense_allocations (id INT PRIMARY KEY AUTO_INCREMENT)');
$admin->exec('CREATE TABLE IF NOT EXISTS acrl_statistics (id INT PRIMARY KEY AUTO_INCREMENT)');
$admin->exec('CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY AUTO_INCREMENT)');
$admin->exec("DROP USER IF EXISTS '{$account}'@'{$accountHost}'");
$admin->exec("CREATE USER '{$account}'@'{$accountHost}' IDENTIFIED BY '{$password}'");
$admin->exec("GRANT SELECT ON `{$database}`.`report_expense_allocations` TO '{$account}'@'{$accountHost}'");
$admin->exec("GRANT SELECT ON `{$database}`.`acrl_statistics` TO '{$account}'@'{$accountHost}'");

$grants = $admin->query("SHOW GRANTS FOR '{$account}'@'{$accountHost}'")->fetchAll(PDO::FETCH_COLUMN);
$allowedTableGrants = [
    strtolower("GRANT SELECT ON `{$database}`.`report_expense_allocations`"),
    strtolower("GRANT SELECT ON `{$database}`.`acrl_statistics`"),
];
$foundTableGrants = [];
foreach ($grants as $grant) {
    $normalizedGrant = strtolower((string) $grant);
    if (strpos($normalizedGrant, 'grant usage on *.*') === 0) {
        continue;
    }
    foreach ($allowedTableGrants as $allowedGrant) {
        if (strpos($normalizedGrant, $allowedGrant) === 0) {
            $foundTableGrants[] = $allowedGrant;
            continue 2;
        }
    }
    fwrite($stderr, "FAIL: the local-report account has a grant outside the two reporting tables.\n");
    exit(1);
}
if (count($foundTableGrants) !== 2) {
    fwrite($stderr, "FAIL: expected SELECT on exactly the two reporting tables.\n");
    exit(1);
}

$reportConfig = require getcwd() . '/backend/config/db_local_report.php';
$report = Yii::createObject($reportConfig);
$report->open();
$pdo = $report->getMasterPdo();
$pdo->query('SELECT * FROM report_expense_allocations')->closeCursor();
$pdo->query('SELECT * FROM acrl_statistics')->closeCursor();

$deniedUsers = false;
try {
    $pdo->query('SELECT * FROM users')->closeCursor();
} catch (PDOException $exception) {
    $errorInfo = $exception->errorInfo ?? [];
    $deniedUsers = in_array((int) ($errorInfo[1] ?? 0), [1044, 1142, 1143], true);
}
if (!$deniedUsers) {
    fwrite($stderr, "FAIL: the local-report account could read users.\n");
    exit(1);
}

$multiStatementBlocked = false;
try {
    $statement = $pdo->query('SELECT 1; SELECT 2');
    $multiStatementBlocked = $statement === false || !$statement->nextRowset();
    if ($statement instanceof PDOStatement) {
        $statement->closeCursor();
    }
} catch (PDOException $exception) {
    $multiStatementBlocked = true;
}
if (!$multiStatementBlocked) {
    fwrite($stderr, "FAIL: the local-report connection accepted multiple statements.\n");
    exit(1);
}

$outfileBlocked = false;
$outfile = '/tmp/local-report-' . bin2hex(random_bytes(8)) . '.csv';
try {
    $pdo->query("SELECT 1 INTO OUTFILE '{$outfile}'")->closeCursor();
} catch (PDOException $exception) {
    $outfileBlocked = true;
}
if (!$outfileBlocked) {
    fwrite($stderr, "FAIL: the local-report account could use INTO OUTFILE.\n");
    exit(1);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') !== false) {
    $timeoutVariable = 'max_statement_time';
    $expectedTimeout = 1800.0;
} else {
    $timeoutVariable = 'max_execution_time';
    $expectedTimeout = 1800000.0;
}
$timeout = (float) $pdo->query('SELECT @@session.' . $timeoutVariable)->fetchColumn();
if ($timeout !== $expectedTimeout) {
    fwrite($stderr, "FAIL: local-report statement timeout is not set to the FOLIO timeout.\n");
    exit(1);
}

$pdo->exec('SET SESSION ' . $timeoutVariable . ' = ' . ($timeoutVariable === 'max_statement_time' ? '0.05' : '50'));
$timeoutApplied = false;
$sleepStarted = microtime(true);
try {
    $sleepStatement = $pdo->query('SELECT SLEEP(1)');
    $sleepResult = $sleepStatement->fetchColumn();
    $sleepStatement->closeCursor();
    $timeoutApplied = (int) $sleepResult === 1 && microtime(true) - $sleepStarted < 0.75;
} catch (PDOException $exception) {
    $timeoutApplied = true;
}
if (!$timeoutApplied) {
    fwrite($stderr, "FAIL: the local-report statement timeout did not interrupt a long query.\n");
    exit(1);
}

$report->close();
echo "OK: grants, multi-statement block, OUTFILE block and statement timeout verified.\n";
PHP
