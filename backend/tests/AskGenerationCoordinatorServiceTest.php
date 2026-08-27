<?php

if (!class_exists('Yii')) {
    class Yii
    {
        public static $messages = [];

        public static function info($message, $category = null): void
        {
            self::$messages[] = ['level' => 'info', 'message' => $message, 'category' => $category];
        }

        public static function warning($message, $category = null): void
        {
            self::$messages[] = ['level' => 'warning', 'message' => $message, 'category' => $category];
        }
    }
}

require_once __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
require_once __DIR__ . '/../services/AskRequestPolicyService.php';
require_once __DIR__ . '/../services/AskGenerationCoordinatorService.php';

use app\exceptions\ExploratorySqlValidationException;
use app\services\AskGenerationCoordinatorService;

function coordinatorAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$blocked = AskGenerationCoordinatorService::run(
    'Delete every inventory item.',
    function (): array {
        throw new RuntimeException('Initial generation must not run for blocked write intent.');
    },
    function (): array {
        throw new RuntimeException('Fresh generation must not run for blocked write intent.');
    }
);
coordinatorAssertSame('request_blocked', $blocked['errorType'] ?? null, 'Explicit writes stop before generation.');
coordinatorAssertSame(
    'Report Explorer runs read-only reports and cannot modify database data.',
    $blocked['message'] ?? null,
    'Write intent should receive the read-only request policy copy.'
);

$calls = [];
$recovered = AskGenerationCoordinatorService::run(
    'Summarize receipts by vendor.',
    function () use (&$calls): array {
        $calls[] = 'initial';
        return [
            'state' => 'candidate_rejected',
            'reason' => 'non_select',
            'candidateSqlHash' => hash('sha256', 'DELETE ...'),
        ];
    },
    function () use (&$calls): array {
        $calls[] = 'fresh';
        return [
            'state' => 'handled',
            'result' => [
                'sql' => 'SELECT vendor, COUNT(*) FROM orders.pieces__t GROUP BY vendor',
                'generationProvenance' => 'ai_built',
            ],
        ];
    }
);
coordinatorAssertSame(['initial', 'fresh'], $calls, 'Candidate rejection must trigger fresh AI.');
coordinatorAssertSame('ai_built', $recovered['generationProvenance'] ?? null, 'Fresh SQL is AI-built.');
coordinatorAssertSame(false, array_key_exists('state', $recovered), 'Internal states must not leak into public success responses.');

$notHandledCalls = [];
$fromNotHandled = AskGenerationCoordinatorService::run(
    'Compare annual circulation and spending.',
    function () use (&$notHandledCalls): array {
        $notHandledCalls[] = 'initial';
        return ['state' => 'not_handled', 'reason' => 'canonical_family_unavailable'];
    },
    function () use (&$notHandledCalls): array {
        $notHandledCalls[] = 'fresh';
        return ['state' => 'handled', 'result' => ['sql' => 'SELECT 1', 'generationProvenance' => 'ai_built']];
    }
);
coordinatorAssertSame(['initial', 'fresh'], $notHandledCalls, 'Canonical non-handling must continue to fresh AI.');
coordinatorAssertSame('SELECT 1', $fromNotHandled['sql'] ?? null, 'Fresh AI should satisfy a canonical non-handling result.');

$repairable = AskGenerationCoordinatorService::run(
    'Count inventory items.',
    function (): array {
        throw new ExploratorySqlValidationException(
            'safety',
            'non_select',
            'DELETE FROM inventory.item__t',
            true,
            'Unsafe generated candidate.'
        );
    },
    function (): array {
        return ['state' => 'handled', 'result' => ['sql' => 'SELECT COUNT(*) FROM inventory.item__t']];
    }
);
coordinatorAssertSame('SELECT COUNT(*) FROM inventory.item__t', $repairable['sql'] ?? null, 'Repairable validation failures should continue to fresh AI.');

$exhausted = AskGenerationCoordinatorService::run(
    'Summarize receipts by vendor.',
    function (): array {
        return ['state' => 'candidate_rejected', 'reason' => 'non_select'];
    },
    function (): array {
        return ['state' => 'candidate_rejected', 'reason' => 'preflight_failed'];
    }
);
coordinatorAssertSame('sql_generation_failed', $exhausted['errorType'] ?? null, 'Replacement exhaustion should be a generation failure.');
coordinatorAssertSame(
    'Report Explorer could not build a valid report after retrying. Please retry.',
    $exhausted['message'] ?? null,
    'Generation exhaustion must not describe the request as unsafe.'
);
coordinatorAssertSame(2, $exhausted['validationSummary']['repairAttempts'] ?? null, 'Exhaustion should report the bounded attempt count.');

foreach ([
    'ai_provider_failure',
    'postgres_connectivity',
    'policy_violation',
    'database_cancelled',
    'database_resource_limit',
    'ai_timeout',
] as $errorType) {
    $response = AskGenerationCoordinatorService::run(
        'Summarize receipts by vendor.',
        function () use ($errorType): array {
            return [
                'state' => 'infrastructure_failure',
                'reason' => $errorType,
                'result' => ['errorType' => $errorType, 'message' => 'typed failure'],
            ];
        },
        function (): array {
            throw new RuntimeException('Infrastructure failures must not trigger fresh AI.');
        }
    );
    coordinatorAssertSame($errorType, $response['errorType'] ?? null, "{$errorType} should propagate accurately.");
    coordinatorAssertSame(false, array_key_exists('state', $response), 'Internal infrastructure state must not leak.');
}

try {
    AskGenerationCoordinatorService::run(
        'Summarize receipts.',
        function (): array { return ['state' => 'mystery']; },
        function (): array { return ['state' => 'handled', 'result' => []]; }
    );
    fwrite(STDERR, "Unknown coordinator states must be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
    // Expected.
}

fwrite(STDOUT, "AskGenerationCoordinatorService test passed\n");
