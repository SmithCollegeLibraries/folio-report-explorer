<?php

/**
 * Yii2 console application configuration.
 * Used by the queue worker for background query execution.
 */

$db = require __DIR__ . '/db.php';
$dbLocalReport = require __DIR__ . '/db_local_report.php';
$dbFolio = require __DIR__ . '/db-folio.php';
$params = require __DIR__ . '/params.php';

return [
    'id' => 'folio-report-explorer-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'db' => $db,
        'localReportDb' => $dbLocalReport,
        'folioDb' => $dbFolio,
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'errorHandler' => [
            'class' => 'app\components\RedactingConsoleErrorHandler',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'app\components\RedactingFileTarget',
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                ],
                [
                    'class' => 'app\components\RedactingFileTarget',
                    'levels' => ['info'],
                    'except' => ['yii\httpclient\*'],
                    'logVars' => [],
                ],
            ],
        ],
    ],
    'params' => $params,
];
