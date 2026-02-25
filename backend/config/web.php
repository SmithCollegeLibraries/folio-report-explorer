<?php

/**
 * Main Yii2 web application configuration.
 */

$db = require __DIR__ . '/db.php';
$dbFolio = require __DIR__ . '/db-folio.php';
$params = require __DIR__ . '/params.php';

$config = [
    'id' => 'folio-report-explorer',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => getenv('COOKIE_VALIDATION_KEY') ?: 'folio-report-explorer-dev-key',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
            'baseUrl' => rtrim(getenv('APP_BASE_PATH') ?: '', '/') . '/api',
        ],
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
            'charset' => 'UTF-8',
            'on beforeSend' => function ($event) {
                $response = $event->sender;
                // Ensure CORS headers are always present
                $headers = $response->headers;
                if (!$headers->has('Access-Control-Allow-Origin')) {
                    $headers->add('Access-Control-Allow-Origin', '*');
                }
            },
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'enableStrictParsing' => true,
            'showScriptName' => false,
            'rules' => [
                // Schema endpoints
                'GET schema' => 'folio-query/schema',
                'POST schema/ask' => 'folio-query/schema-ask',
                'GET schema/<table:[\w.]+>' => 'folio-query/schema-detail',
                'GET path' => 'folio-query/path',
                'GET derived' => 'folio-query/derived',

                // Query endpoints
                'POST build' => 'folio-query/build',
                'POST execute' => 'folio-query/execute',
                'POST nl' => 'folio-query/nl',

                // Async query jobs
                'POST query/submit' => 'folio-query/query-submit',
                'GET query/status/<id:[\w-]+>' => 'folio-query/query-status',
                'POST query/cancel/<id:[\w-]+>' => 'folio-query/query-cancel',
                'GET query/jobs' => 'folio-query/query-list',

                // Saved queries
                'POST saved' => 'folio-query/save',
                'GET saved' => 'folio-query/saved-list',
                'GET saved/pinned' => 'folio-query/saved-pinned',
                'GET saved/<id:\d+>' => 'folio-query/saved-detail',
                'POST saved/<id:\d+>/pin' => 'folio-query/saved-pin',
                'POST saved/<id:\d+>/promote' => 'folio-query/saved-promote',
                'DELETE saved/<id:\d+>' => 'folio-query/saved-delete',

                // Report templates
                'GET reports' => 'folio-query/report-list',
                'GET reports/<id:\d+>' => 'folio-query/report-detail',
                'POST reports/<id:\d+>/run' => 'folio-query/report-run',
                'POST reports' => 'folio-query/report-create',
                'PUT reports/<id:\d+>' => 'folio-query/report-update',
                'DELETE reports/<id:\d+>' => 'folio-query/report-delete',
                'POST reports/generate' => 'folio-query/report-generate',
                'POST reports/convert' => 'folio-query/report-convert',

                // Health check
                'GET health' => 'folio-query/health',

                // Settings (dev)
                'GET settings' => 'folio-query/settings',
                'POST settings' => 'folio-query/settings-save',
                'POST settings/test' => 'folio-query/settings-test',

                // AI Training Hints
                'GET training' => 'folio-query/training-list',
                'GET training/<id:\d+>' => 'folio-query/training-detail',
                'POST training' => 'folio-query/training-create',
                'PUT training/<id:\d+>' => 'folio-query/training-update',
                'DELETE training/<id:\d+>' => 'folio-query/training-delete',
                'POST training/correct' => 'folio-query/training-correct',

                // CORS preflight
                'OPTIONS <path:.*>' => 'folio-query/options',
            ],
        ],
        'db' => $db,
        'folioDb' => $dbFolio,
        'user' => [
            'identityClass' => 'app\models\DummyIdentity',
            'enableSession' => false,
            'enableAutoLogin' => false,
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
    ],
    'params' => $params,
];

return $config;
