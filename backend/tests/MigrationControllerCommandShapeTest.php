<?php

$controllerPath = __DIR__ . '/../commands/MigrationController.php';
$servicePath = __DIR__ . '/../services/MigrationService.php';

foreach ([
    'MigrationController' => $controllerPath,
    'MigrationService' => $servicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

$controller = (string)file_get_contents($controllerPath);
$service = (string)file_get_contents($servicePath);

function assertMigrationCommandContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertMigrationCommandContains('class MigrationController', $controller, 'Migration console controller should exist.');
assertMigrationCommandContains('function actionAudit()', $controller, 'Migration controller should expose audit command.');
assertMigrationCommandContains('function actionRun()', $controller, 'Migration controller should expose run command.');
assertMigrationCommandContains('Yii::$app->db', $controller, 'Migration controller should use the configured Yii local DB.');
assertMigrationCommandContains('class MigrationService', $service, 'Migration service should exist.');
assertMigrationCommandContains('function schemaMigrationsTableSql()', $service, 'Migration service should define ledger DDL.');
assertMigrationCommandContains('function run(', $service, 'Migration service should apply unapplied migrations.');
assertMigrationCommandContains('function auditDirectory(', $service, 'Migration service should audit migration files.');

fwrite(STDOUT, "Migration controller command shape test passed\n");
