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
            'askAiProvenance' => [
                'provenance' => ['generationProvenance' => 'verified_pattern'],
            ],
        ]),
        'completed_at' => '2026-06-04 09:00:00',
        'row_count' => 1,
        'execution_time_ms' => 40,
    ],
    [
        'id' => 'parameterized-match',
        'name' => 'How many items are in the Smith College collection?',
        'status' => 'completed',
        'source' => 'nl',
        'data_source' => 'folio',
        'sql_text' => 'SELECT COUNT(*) FROM inventory.item__t WHERE tenant_id = :tenant',
        'params' => json_encode(['tenant' => 'smith']),
        'metadata' => json_encode([
            'originalPrompt' => 'How many items are in the Smith College collection?',
            'resolvedContext' => ['campus' => 'Smith College', 'domain' => 'inventory'],
            'askAiProvenance' => [
                'provenance' => ['generationProvenance' => 'verified_pattern'],
            ],
        ]),
        'completed_at' => '2026-06-05 09:00:00',
        'row_count' => 1,
        'execution_time_ms' => 20,
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
assertReuseTest(($match['generationProvenance'] ?? null) === 'verified_pattern', 'Expected stored stable provenance to be returned with a reuse candidate.');
assertReuseTest(($match['provenanceLabel'] ?? null) === 'Verified pattern', 'Expected a reuse candidate to include the public label derived from stored provenance.');

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

$legacySqlScopedMatch = PreviousSuccessfulQueryReuseService::findStrongMatch(
    'Show materials purchased in the last 90 days grouped by material type.',
    'folio',
    ['campus' => 'Smith College'],
    [
        [
            'id' => 'newer-unscoped-material-type',
            'name' => 'Show materials purchased in the last 90 days grouped by material type.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => "SELECT imt.name FROM orders.po_line__t pol JOIN inventory.material_type__t imt ON pol.physical__material_type = imt.id",
            'metadata' => json_encode([
                'originalPrompt' => 'Show materials purchased in the last 90 days grouped by material type.',
            ]),
            'completed_at' => '2026-06-08 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
        [
            'id' => 'older-scoped-material-type',
            'name' => 'Show materials purchased in the last 90 days grouped by material type.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => "SELECT imt.name FROM orders.po_line__t pol JOIN orders.purchase_order__t po ON pol.purchase_order_id = po.id JOIN orders.purchase_order__t__acq_unit_ids potaui ON potaui.id = po.id JOIN orders.acquisitions_unit__t au ON au.id = potaui.acq_unit_ids JOIN inventory.material_type__t imt ON pol.physical__material_type = imt.id WHERE TRIM(au.name) = 'SC'",
            'metadata' => json_encode([
                'originalPrompt' => 'Show materials purchased in the last 90 days grouped by material type.',
            ]),
            'completed_at' => '2026-06-07 09:00:00',
            'row_count' => 10,
            'execution_time_ms' => 50,
        ],
    ]
);

assertReuseTest($legacySqlScopedMatch !== null, 'Expected exact legacy prompt with SQL-proven Smith scope to be reusable.');
assertReuseTest($legacySqlScopedMatch['jobId'] === 'older-scoped-material-type', 'Expected SQL-proven Smith scope to outrank an unscoped newer exact match.');

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

assertReuseTest($reviewedReuseMatch !== null, 'Expected a successful query candidate.');
assertReuseTest($reviewedReuseMatch['jobId'] === 'newer-plain-run', 'Legacy reuse-panel decisions must not promote trust or outrank newer equivalent candidates.');
assertReuseTest(
    !in_array('human_reviewed_reuse', $reviewedReuseMatch['matchReasons'], true),
    'Legacy reuse-panel decisions must not appear as trust evidence.'
);

$allStrongMatches = PreviousSuccessfulQueryReuseService::findStrongMatches(
    'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
    'folio',
    ['campus' => 'Smith College'],
    [
        [
            'id' => 'first-candidate',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT first.po_number FROM orders.purchase_order__t first',
            'metadata' => json_encode(['originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.']),
            'completed_at' => '2026-06-06 09:00:00',
        ],
        [
            'id' => 'second-candidate',
            'name' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.',
            'status' => 'completed',
            'source' => 'nl',
            'data_source' => 'folio',
            'sql_text' => 'SELECT second.po_number FROM orders.purchase_order__t second',
            'metadata' => json_encode(['originalPrompt' => 'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.']),
            'completed_at' => '2026-06-07 09:00:00',
        ],
    ]
);
assertReuseTest(count($allStrongMatches) === 2, 'The shaping helper must expose every strong candidate for QueryMemoryService trust evaluation.');
assertReuseTest($allStrongMatches[0]['jobId'] === 'second-candidate', 'Candidate shaping should retain deterministic recency ordering.');
assertReuseTest($allStrongMatches[0]['question'] !== '', 'Candidate shaping must expose the prior question to QueryMemoryService.');

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
