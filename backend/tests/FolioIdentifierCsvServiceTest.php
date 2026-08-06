<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use app\services\FolioIdentifierCsvService;

function folioIdentifierAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$config = ['sourceColumn' => 'Instance UUID', 'header' => 'UUID'];

folioIdentifierAssertSame("UUID\r\n", FolioIdentifierCsvService::encodeRow(['UUID']), 'Identifier CSV rows must use CRLF endings.');
folioIdentifierAssertSame('"a,b""c"' . "\r\n", FolioIdentifierCsvService::encodeRow(['a,b"c']), 'Identifier CSV rows must quote delimiters and embedded quotes.');
folioIdentifierAssertSame(
    '11111111-1111-4111-8111-111111111111',
    FolioIdentifierCsvService::project(
        ['Instance UUID' => '11111111-1111-4111-8111-111111111111'],
        $config
    ),
    'A configured RFC-4122 UUID must be projected unchanged.'
);
folioIdentifierAssertSame(
    '11111111-1111-4111-8111-111111111111',
    FolioIdentifierCsvService::project(
        ['Instance UUID' => ' 11111111-1111-4111-8111-111111111111 '],
        $config
    ),
    'A configured UUID must be normalized before export.'
);
folioIdentifierAssertSame(null, FolioIdentifierCsvService::project(['Instance UUID' => ''], $config), 'Blank identifiers must not be exported.');
folioIdentifierAssertSame(null, FolioIdentifierCsvService::project(['Instance UUID' => 'not-a-uuid'], $config), 'Malformed identifiers must not be exported.');
folioIdentifierAssertSame(null, FolioIdentifierCsvService::project(['Another column' => '11111111-1111-4111-8111-111111111111'], $config), 'Projection must read only the server-configured column.');

fwrite(STDOUT, "FOLIO identifier CSV service test passed\n");
