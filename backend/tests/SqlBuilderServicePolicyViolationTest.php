<?php

// Regression test: validateTablePolicy() threw plain InvalidArgumentException for
// security blocks (blocked table/schema). The controller then decided 403-vs-200
// by substring-matching the message, which is fragile. Policy blocks must throw a
// dedicated PolicyViolationException (a subclass of InvalidArgumentException so
// existing catch blocks still work) so the decision is type-based.

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderServicePath = __DIR__ . '/../services/SqlBuilderService.php';
$exceptionPath = __DIR__ . '/../exceptions/PolicyViolationException.php';

if (!file_exists($schemaServicePath) || !file_exists($sqlBuilderServicePath)) {
    fwrite(STDERR, "Required service files are missing.\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static function getAlias($alias) { return $alias; }
        public static function warning($m, $c = null) {}
        public static function info($m, $c = null) {}
    }
}

require_once $schemaServicePath;
require_once $sqlBuilderServicePath;
if (file_exists($exceptionPath)) {
    require_once $exceptionPath;
}

use app\services\SqlBuilderService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// A query referencing a blocked PII table must raise a typed policy violation.
$thrownClass = null;
$isInvalidArg = false;
try {
    SqlBuilderService::validateTablePolicy('SELECT * FROM users.users__t');
} catch (\Throwable $e) {
    $thrownClass = get_class($e);
    $isInvalidArg = $e instanceof \InvalidArgumentException;
}

assertSameValue('app\\exceptions\\PolicyViolationException', $thrownClass, 'Blocked-table policy violations must throw PolicyViolationException.');
assertSameValue(true, $isInvalidArg, 'PolicyViolationException must remain an InvalidArgumentException subclass for backward compatibility.');

// A blocked schema reference must also raise the typed exception.
$schemaThrown = null;
try {
    SqlBuilderService::validateTablePolicy('SELECT * FROM perms.permissions__t');
} catch (\Throwable $e) {
    $schemaThrown = get_class($e);
}
assertSameValue('app\\exceptions\\PolicyViolationException', $schemaThrown, 'Blocked-schema policy violations must throw PolicyViolationException.');

$commaThrown = null;
try {
    SqlBuilderService::validateTablePolicy('SELECT * FROM inventory.item__t ii, users.users__t u WHERE u.id = ii.id');
} catch (\Throwable $e) {
    $commaThrown = get_class($e);
}
assertSameValue(
    'app\\exceptions\\PolicyViolationException',
    $commaThrown,
    'Blocked tables introduced through an implicit comma join must not evade table policy.'
);

$mixedJoinThrown = null;
try {
    SqlBuilderService::validateTablePolicy(
        'SELECT * FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id, users.users__t u'
    );
} catch (\Throwable $e) {
    $mixedJoinThrown = get_class($e);
}
assertSameValue(
    'app\\exceptions\\PolicyViolationException',
    $mixedJoinThrown,
    'A blocked comma table after an explicit join must not evade table policy.'
);

$onlyThrown = null;
try {
    SqlBuilderService::validateTablePolicy('SELECT * FROM ONLY users.users__t u');
} catch (\Throwable $e) {
    $onlyThrown = get_class($e);
}
assertSameValue(
    'app\\exceptions\\PolicyViolationException',
    $onlyThrown,
    'A blocked table introduced through PostgreSQL FROM ONLY must not evade table policy.'
);

$parenthesizedOnlyThrown = null;
try {
    SqlBuilderService::validateTablePolicy('SELECT * FROM ONLY (users.users__t) u');
} catch (\Throwable $e) {
    $parenthesizedOnlyThrown = get_class($e);
}
assertSameValue(
    'app\\exceptions\\PolicyViolationException',
    $parenthesizedOnlyThrown,
    'A blocked table introduced through parenthesized PostgreSQL ONLY must not evade table policy.'
);

fwrite(STDOUT, "SqlBuilderService policy violation test passed\n");
