<?php

/**
 * Dedicated SELECT-only connection for local reporting queries.
 * Connection settings match the app database; credentials never fall back to db.
 */
$config = require __DIR__ . '/db.php';
$config['username'] = getenv('LOCAL_REPORT_DB_USER') ?: '';
$config['password'] = getenv('LOCAL_REPORT_DB_PASS') ?: '';
$config['emulatePrepare'] = true;
$config['attributes'] = [
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
];
$config['on afterOpen'] = function ($event) {
    $db = $event->sender;
    $version = (string) $db->createCommand('SELECT VERSION()')->queryScalar();
    if (stripos($version, 'mariadb') !== false) {
        $db->createCommand('SET SESSION max_statement_time = 1800')->execute();
    } else {
        $db->createCommand('SET SESSION max_execution_time = 1800000')->execute();
    }
};

return $config;
