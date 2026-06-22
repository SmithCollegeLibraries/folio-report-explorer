<?php

$servicePath = __DIR__ . '/../services/PreviousSuccessfulQueryReuseService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "PreviousSuccessfulQueryReuseService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\PreviousSuccessfulQueryReuseService;

function assertReuseTest($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$jobs = [
    [
        'id' => 'failed-repeat',
        'name' => 'How many items are in the Smith College collection?',
        'status' => 'failed',
        'source' => 'nl',
        'data_source' => 'folio',
        'sql_text' => 'SELECT broken',
        'metadata' => json_encode(['originalPrompt' => 'How many items are in the Smith College collection?']),
        'completed_at' => '2026-06-01 09:00:00',
        'row_count' => 0,
        'execution_time_ms' => 10,
    ],
    [
        'id' => 'manual-repeat',
        'name' => 'How many items are in the Smith College collection?',
        'status' => 'completed',
        'source' => 'manual',
        'data_source' => 'folio',
        'sql_text' => 'SELECT COUNT(*) FROM inventory.item__t',
        'metadata' => null,
        'completed_at' => '2026-06-02 09:00:00',
        'row_count' => 1,
        'execution_time_ms' => 20,
    ],
    [
        'id' => 'wrong-source',
        'name' => 'How many items are in the Smith College collection?',
        'status' => 'completed',
        'source' => 'nl',
        'data_source' => 'local',
        'sql_text' => 'SELECT COUNT(*) FROM local_table',
        'metadata' => json_encode(['originalPrompt' => 'How many items are in the Smith College collection?']),
        'completed_at' => '2026-06-03 09:00:00',
        'row_count' => 1,
        'execution_time_ms' => 30,
    ],
    [
        'id' => 'strong-match',
        'name' => 'How many items are in Smith College collection?',
        'status' => 'completed',
        'source' => 'nl',
        'data_source' => 'folio',
        'sql_text' => 'SELECT COUNT(*) AS item_count FROM inventory.item__t i',
        'metadata' => json_encode([
            'originalPrompt' => 'How many items are in Smith College collection?',
            'resolvedContext' => ['campus' => 'Smith College', 'domain' => 'inventory'],
        ]),
        'completed_at' => '2026-06-04 09:00:00',
        'row_count' => 1,
        'execution_time_ms' => 40,
    ],
];

$match = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'How many items are in the Smith College collection?',
    'folio',
    ['campus' => 'Smith College', 'domain' => 'inventory'],
    $jobs
);

assertReuseTest($match !== null, 'Expected a strong reuse match.');
assertReuseTest($match['jobId'] === 'strong-match', 'Expected the completed NL/FOLIO job to be selected.');
assertReuseTest($match['sql'] === 'SELECT COUNT(*) AS item_count FROM inventory.item__t i', 'Expected previous SQL to be returned for review.');
assertReuseTest($match['score'] >= 90, 'Expected exact/near-exact normalized prompt match to score strongly.');
assertReuseTest(in_array('same_data_source', $match['matchReasons'], true), 'Expected same data source match reason.');
assertReuseTest(in_array('same_campus', $match['matchReasons'], true), 'Expected same campus match reason.');
assertReuseTest(in_array('completed_successfully', $match['matchReasons'], true), 'Expected successful completion match reason.');

$legacyMatch = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
    'folio',
    ['campus' => 'Smith College'],
    [
        [
            'id' => 'legacy-no-context',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT po.po_number FROM orders.purchase_order__t po',
            'metadata' => json_encode([
                'originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            ]),
            'completed_at' => '2026-06-05 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
    ]
);

assertReuseTest($legacyMatch !== null, 'Expected identical legacy successful prompts without resolved context to remain eligible.');
assertReuseTest($legacyMatch['jobId'] === 'legacy-no-context', 'Expected the legacy successful query to be selected.');

$reviewedReuseMatch = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
    'folio',
    ['campus' => 'Smith College'],
    [
        [
            'id' => 'newer-plain-run',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT newer_plain.po_number FROM orders.purchase_order__t newer_plain',
            'metadata' => json_encode([
                'originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            ]),
            'completed_at' => '2026-06-07 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
        [
            'id' => 'older-human-reviewed-reuse',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT reviewed.po_number FROM orders.purchase_order__t reviewed',
            'metadata' => json_encode([
                'originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
                'queryReuse' => [
                    'decision' => 'accepted',
                    'candidateJobId' => 'seed-job',
                    'edited' => false,
                    'score' => 100,
                ],
            ]),
            'completed_at' => '2026-06-06 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
    ]
);

assertReuseTest($reviewedReuseMatch !== null, 'Expected a reviewed reuse match.');
assertReuseTest($reviewedReuseMatch['jobId'] === 'older-human-reviewed-reuse', 'Expected human-reviewed reuse outcomes to outrank newer plain successful runs.');

$newestTieMatch = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
    'folio',
    ['campus' => 'Smith College'],
    [
        [
            'id' => 'older-identical-run',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT older.po_number FROM orders.purchase_order__t older',
            'metadata' => json_encode([
                'originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            ]),
            'completed_at' => '2026-06-06 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
        [
            'id' => 'newer-identical-run',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT newer.po_number FROM orders.purchase_order__t newer',
            'metadata' => json_encode([
                'originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            ]),
            'completed_at' => '2026-06-07 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
    ]
);

assertReuseTest($newestTieMatch !== null, 'Expected a newest tie match.');
assertReuseTest($newestTieMatch['jobId'] === 'newer-identical-run', 'Expected newest successful run to win when prompt and review ranking tie.');

$noMatch = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'How many open purchase orders are at Smith College?',
    'folio',
    ['campus' => 'Smith College', 'domain' => 'acquisitions'],
    $jobs
);

assertReuseTest($noMatch === null, 'Expected weak/different-intent prompt to produce no reuse match.');

echo "PreviousSuccessfulQueryReuseServiceTest passed\n";
