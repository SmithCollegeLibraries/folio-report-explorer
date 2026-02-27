<?php

use app\services\SettingsService;

/**
 * FOLIO Postgres connection — read-only access to LDP/LDLite data.
 * Values from data/settings.json override environment variables.
 */
$pgHost = SettingsService::get('pg_host', 'FOLIO_PG_HOST', 'localhost');
$pgPort = SettingsService::get('pg_port', 'FOLIO_PG_PORT', '5432');
$pgDb   = SettingsService::get('pg_db', 'FOLIO_PG_DB', 'ldplite');
$pgUser = SettingsService::get('pg_user', 'FOLIO_PG_USER', '');
$pgPass = SettingsService::get('pg_pass', 'FOLIO_PG_PASS', '');
$pgSsl  = SettingsService::get('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require');

return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        $pgHost, $pgPort, $pgDb, $pgSsl
    ),
    'username' => $pgUser,
    'password' => $pgPass,
    'charset' => 'utf8',
    'attributes' => [
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
    'on afterOpen' => function ($event) {
        $event->sender->createCommand("SET statement_timeout = 1800000")->execute();
        $event->sender->createCommand("SET idle_in_transaction_session_timeout = 600000")->execute();
    },
    'enableQueryCache' => true,
    'queryCacheDuration' => 3600,
];
