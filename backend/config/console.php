<?php

/**
 * Yii2 console application configuration.
 * Used by the queue worker for background query execution.
 */

$db = require __DIR__ . '/db.php';
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
        'folioDb' => $dbFolio,
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning', 'info'],
                    'logVars' => [],
                ],
            ],
        ],
    ],
    'params' => $params,
];
