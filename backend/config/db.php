<?php

/**
 * Local MySQL connection — used for saved queries and audit log.
 */
return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        getenv('MYSQL_HOST') ?: 'mysql',
        getenv('MYSQL_PORT') ?: '3306',
        getenv('MYSQL_DATABASE') ?: 'folio_reports'
    ),
    'username' => getenv('MYSQL_USER') ?: 'folio_app',
    'password' => getenv('MYSQL_PASSWORD') ?: 'folio_app_pass',
    'charset' => 'utf8mb4',
];
