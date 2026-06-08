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
                'POST clarifications/resolve' => 'folio-query/clarification-resolve',
                'POST query-feedback' => 'folio-query/query-feedback',
                'GET campuses' => 'folio-query/campus-list',
                'PATCH user/campus' => 'folio-query/campus-save',

                // Async query jobs
                'POST query/submit' => 'folio-query/query-submit',
                'GET query/status/<id:[\w-]+>' => 'folio-query/query-status',
                'POST query/cancel/<id:[\w-]+>' => 'folio-query/query-cancel',
                'GET query/export/<id:[\w-]+>' => 'folio-query/query-export',
                'GET query/jobs' => 'folio-query/query-list',

                // Saved queries
                'POST saved' => 'folio-query/save',
                'GET saved' => 'folio-query/saved-list',
                'GET saved/pinned' => 'folio-query/saved-pinned',
                'GET saved/<id:\d+>' => 'folio-query/saved-detail',
                'POST saved/<id:\d+>/pin' => 'folio-query/saved-pin',
                'PATCH saved/<id:\d+>/global' => 'folio-query/saved-global',
                'POST saved/<id:\d+>/promote' => 'folio-query/saved-promote',
                'DELETE saved/<id:\d+>' => 'folio-query/saved-delete',

                // Per-user dashboard
                'GET dashboard' => 'folio-query/dashboard',
                'PATCH dashboard/reorder' => 'folio-query/dashboard-reorder',
                'POST dashboard/<id:\d+>/hide' => 'folio-query/dashboard-hide',
                'POST dashboard/<id:\d+>/show' => 'folio-query/dashboard-show',
                'POST dashboard/<id:\d+>/refresh' => 'folio-query/dashboard-refresh',
                'PATCH dashboard/<id:\d+>/display' => 'folio-query/dashboard-display',

                // Report templates
                'GET reports' => 'folio-query/report-list',
                'GET reports/<id:\d+>' => 'folio-query/report-detail',
                'POST reports/<id:\d+>/run' => 'folio-query/report-run',
                'POST reports' => 'folio-query/report-create',
                'PUT reports/<id:\d+>' => 'folio-query/report-update',
                'DELETE reports/<id:\d+>' => 'folio-query/report-delete',
                'POST reports/generate' => 'folio-query/report-generate',
                'POST reports/convert' => 'folio-query/report-convert',

                // Local supplementary data (admin)
                'GET local/acrl' => 'folio-query/local-acrl-list',
                'GET local/acrl/years' => 'folio-query/local-acrl-years',
                'POST local/acrl' => 'folio-query/local-acrl-create',
                'PUT local/acrl/<id:\d+>' => 'folio-query/local-acrl-update',
                'DELETE local/acrl/<id:\d+>' => 'folio-query/local-acrl-delete',
                'POST local/acrl/copy-year' => 'folio-query/local-acrl-copy-year',

                'GET local/allocations' => 'folio-query/local-alloc-list',
                'GET local/allocations/years' => 'folio-query/local-alloc-years',
                'POST local/allocations' => 'folio-query/local-alloc-upsert',
                'DELETE local/allocations/<id:\d+>' => 'folio-query/local-alloc-delete',
                'POST local/allocations/copy-year' => 'folio-query/local-alloc-copy-year',

                // Expense class monitor (per-user budget tracking)
                'GET expense-monitor/codes' => 'folio-query/expense-monitor-codes',
                'GET expense-monitor' => 'folio-query/expense-monitor-list',
                'POST expense-monitor' => 'folio-query/expense-monitor-save',
                'DELETE expense-monitor/<code:[\w]+>' => 'folio-query/expense-monitor-remove',
                'POST expense-monitor/refresh' => 'folio-query/expense-monitor-refresh',

                // Dashboard widget gallery
                'GET dashboard/widgets' => 'folio-query/dashboard-widgets',
                'POST dashboard/widgets/<id:\d+>/add' => 'folio-query/dashboard-widget-add',
                'DELETE dashboard/widgets/<id:\d+>/remove' => 'folio-query/dashboard-widget-remove',

                // Admin: manage widget templates
                'POST admin/dashboard-widgets' => 'folio-query/admin-widget-create',
                'PUT admin/dashboard-widgets/<id:\d+>' => 'folio-query/admin-widget-update',
                'DELETE admin/dashboard-widgets/<id:\d+>' => 'folio-query/admin-widget-delete',

                // Health check
                'GET health' => 'folio-query/health',

                // Settings (dev)
                'GET settings' => 'folio-query/settings',
                'GET nl2sql-preflight' => 'folio-query/nl2sql-preflight',
                'GET reference-cache/status' => 'folio-query/reference-cache-status',
                'GET reference-cache/candidates' => 'folio-query/reference-cache-candidates',
                'POST reference-cache/candidates/review' => 'folio-query/reference-cache-candidate-review',
                'POST reference-cache/refresh' => 'folio-query/reference-cache-refresh',
                'POST settings' => 'folio-query/settings-save',
                'POST settings/test' => 'folio-query/settings-test',

                // AI Training Hints
                'GET training' => 'folio-query/training-list',
                'GET training/<id:\d+>' => 'folio-query/training-detail',
                'POST training' => 'folio-query/training-create',
                'PUT training/<id:\d+>' => 'folio-query/training-update',
                'DELETE training/<id:\d+>' => 'folio-query/training-delete',
                'POST training/correct' => 'folio-query/training-correct',

                // Auth endpoints
                'GET auth/me' => 'folio-query/auth-me',
                'POST auth/refresh' => 'folio-query/auth-refresh',
                'POST auth/logout' => 'folio-query/auth-logout',

                // User management (admin)
                'GET users' => 'folio-query/user-list',
                'PUT users/<id:\d+>/approve' => 'folio-query/user-approve',
                'PUT users/<id:\d+>/role' => 'folio-query/user-role',
                'DELETE users/<id:\d+>' => 'folio-query/user-delete',
                'PUT users/<id:\d+>/notifications' => 'folio-query/user-notifications',

                // Query history
                'GET query/history' => 'folio-query/query-history',
                'POST query/index-recommendations' => 'folio-query/query-index-recommendations',
                'POST query/history/<id:[\w-]+>/suggestions' => 'folio-query/query-history-suggestions',
                'PATCH query/history/<id:[\w-]+>' => 'folio-query/rename-history-job',
                'DELETE query/history/<id:[\w-]+>' => 'folio-query/delete-history-job',

                // CORS preflight
                'OPTIONS <path:.*>' => 'folio-query/options',
            ],
        ],
        'db' => $db,
        'folioDb' => $dbFolio,
        'user' => [
            'identityClass' => 'app\models\User',
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
                    'logVars' => [],
                ],
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['info'],
                    'categories' => ['nl2sql.telemetry'],
                    'logVars' => [],
                ],
            ],
        ],
    ],
    'params' => $params,
];

return $config;
