<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

if (!file_exists($geminiServicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$geminiServicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
    }
}

$capturedSql = null;
$capturedParams = null;

Yii::$app = (object) [
    'params' => [],
    'db' => new class($capturedSql, $capturedParams) {
        private $capturedSql;
        private $capturedParams;

        public function __construct(&$capturedSql, &$capturedParams)
        {
            $this->capturedSql = &$capturedSql;
            $this->capturedParams = &$capturedParams;
        }

        public function createCommand($sql = '', $params = [])
        {
            $this->capturedSql = (string)$sql;
            $this->capturedParams = $params;

            return new class {
                public function queryColumn()
                {
                    return ['location_alias.mrbc_reference'];
                }
            };
        }
    },
];

require_once $geminiServicePath;

use app\services\ClarificationService;
use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$loader = new ReflectionMethod(GeminiService::class, 'loadAcceptedClarificationKeys');
$keys = $loader->invoke(null, 42);

assertSameValue(['location_alias.mrbc_reference'], $keys, 'GeminiService should load accepted clarification keys from the clarification event tracker.');
assertSameValue([':user_id' => 42], $capturedParams, 'Clarification memory lookup should be scoped to the current user.');

$clarification = ClarificationService::detectPromptAmbiguity(
    'List holdings in the MRBC Reference collection.',
    $keys
);

assertSameValue(null, $clarification, 'Loaded clarification memory should suppress repeated MRBC Reference confirmation prompts.');

fwrite(STDOUT, "GeminiService clarification memory test passed\n");
