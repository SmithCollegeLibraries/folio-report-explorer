<?php

namespace yii\httpclient {
    class Client
    {
        public $transport;

        public function createRequest()
        {
            return new Request();
        }
    }

    class Request
    {
        private $content = '';

        public function setMethod($method) { return $this; }
        public function setUrl($url) { return $this; }
        public function setHeaders($headers) { return $this; }
        public function addOptions($options) { return $this; }

        public function setContent($content)
        {
            $this->content = (string)$content;
            return $this;
        }

        public function send()
        {
            TestTransport::$requests[] = json_decode($this->content, true);
            $response = array_shift(TestTransport::$responses);
            if ($response instanceof \Throwable) {
                throw $response;
            }
            if (!is_string($response)) {
                throw new \RuntimeException('No queued fake AI response.');
            }
            return new Response($response);
        }
    }

    class Response
    {
        public $isOk = true;
        public $statusCode = 200;
        public $content;

        public function __construct(string $text)
        {
            $this->content = json_encode([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => $text]]],
                ]],
            ]);
        }
    }

    class TestTransport
    {
        public static $responses = [];
        public static $requests = [];
    }
}

namespace app\services {
    class ReferenceResolverService
    {
        public static function resolvePrompt(string $prompt, $userId = null): array
        {
            if ($prompt === 'Show me a list of VHS and DVDs at Hillyer Library.') {
                return [
                    'needsClarification' => false,
                    'resolvedFilters' => [
                        [
                            'dimension' => 'library',
                            'source_table' => 'inventory.loclibrary__t',
                            'column' => 'name',
                            'values' => ['SC Hillyer Art Library'],
                            'value_metadata' => [
                                'SC Hillyer Art Library' => ['campus_name' => 'Smith College'],
                            ],
                        ],
                        [
                            'dimension' => 'material_type',
                            'source_table' => 'inventory.material_type__t',
                            'column' => 'name',
                            'values' => ['Videocassette', 'DVD/Blu-ray'],
                        ],
                    ],
                    'guidanceLines' => [],
                ];
            }

            if ($prompt === 'Show the 20 most-circulated books during the last five years at Neilson Library. Include title, call number, publication year, checkout count, and most recent checkout date.') {
                return [
                    'needsClarification' => false,
                    'resolvedFilters' => [
                        [
                            'dimension' => 'library',
                            'source_table' => 'inventory.loclibrary__t',
                            'column' => 'name',
                            'values' => ['SC Neilson Library'],
                            'value_metadata' => [
                                'SC Neilson Library' => ['campus_name' => 'Smith College'],
                            ],
                        ],
                        [
                            'dimension' => 'material_type',
                            'source_table' => 'inventory.material_type__t',
                            'column' => 'name',
                            'values' => ['Book'],
                        ],
                    ],
                    'guidanceLines' => [],
                ];
            }

            if ($prompt === 'Show annual checkout counts at Neilson Library for each of the last five completed calendar years.') {
                return [
                    'needsClarification' => false,
                    'resolvedFilters' => [
                        [
                            'dimension' => 'library',
                            'source_table' => 'inventory.loclibrary__t',
                            'column' => 'name',
                            'values' => ['SC Neilson Library'],
                            'value_metadata' => [
                                'SC Neilson Library' => ['campus_name' => 'Smith College'],
                            ],
                        ],
                    ],
                    'guidanceLines' => [],
                ];
            }

            return [
                'needsClarification' => false,
                'resolvedFilters' => [],
                'guidanceLines' => [],
            ];
        }

        public static function appendGuidanceToPrompt(string $prompt, array $resolution): string
        {
            $lines = [];
            foreach ($resolution['resolvedFilters'] ?? [] as $filter) {
                $values = array_values($filter['values'] ?? []);
                if ($values === []) {
                    continue;
                }
                $lines[] = 'Resolved ' . (string)($filter['dimension'] ?? 'reference')
                    . ' values: ' . implode('; ', $values) . '.';
            }
            return $lines === []
                ? $prompt
                : $prompt . "\n\nReference resolver guidance:\n- " . implode("\n- ", $lines);
        }

        public static function appendGenerationContextToPrompt(
            string $prompt,
            array $resolution,
            ?array $ambiguity = null
        ): string {
            $prompt = self::appendGuidanceToPrompt($prompt, $resolution);
            $lines = [];
            foreach (array_slice($resolution['unresolvedNamedIntents'] ?? [], 0, 8) as $intent) {
                $span = trim((string)($intent['span'] ?? ''));
                $dimension = trim((string)($intent['dimension'] ?? 'unknown'));
                if ($span !== '') {
                    $lines[] = "Unresolved local term: {$span} ({$dimension})";
                }
            }
            foreach (array_slice($resolution['clarificationItems'] ?? [], 0, 8) as $item) {
                $labels = [];
                foreach (array_slice($item['options'] ?? [], 0, 5) as $option) {
                    $label = trim((string)($option['label'] ?? ''));
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                $term = trim((string)($item['term'] ?? ''));
                if ($term !== '' && $labels !== []) {
                    $lines[] = $term . ' candidate values: ' . implode('; ', array_values(array_unique($labels)));
                }
            }
            return $lines === []
                ? $prompt
                : $prompt . "\n\nLocal reference generation context:\n- " . implode("\n- ", $lines);
        }
    }
}

namespace {
    if (!defined('CURLOPT_TIMEOUT')) {
        define('CURLOPT_TIMEOUT', 13);
    }

    class Yii
    {
        public static $app;
        public static $logs = [];

        public static function getAlias($alias)
        {
            $prefix = '@app/data/';
            if (strpos((string)$alias, $prefix) === 0) {
                return __DIR__ . '/../data/' . substr((string)$alias, strlen($prefix));
            }
            return $alias;
        }

        public static function info($message, $category = null)
        {
            self::$logs[] = ['level' => 'info', 'message' => $message, 'category' => $category];
        }

        public static function warning($message, $category = null)
        {
            self::$logs[] = ['level' => 'warning', 'message' => $message, 'category' => $category];
        }
    }

    class TwoLaneLocalDb
    {
        public function createCommand($sql)
        {
            return new TwoLaneLocalCommand();
        }
    }

    class TwoLaneLocalCommand
    {
        public function queryAll(): array
        {
            return [];
        }
    }

    Yii::$app = (object) [
        'cache' => null,
        'db' => new TwoLaneLocalDb(),
        'params' => [
            'aiProvider' => 'gemini',
            'geminiApiKey' => 'fake-test-key',
            'geminiModel' => 'fake-test-model',
            'geminiMaxRetries' => 1,
            'schemaPath' => __DIR__ . '/../data/folio_schema.json',
            'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
            'defaultQueryLimit' => 100,
            'maxQueryRows' => 1000,
            'nl2sqlForceLegacy' => false,
            'nl2sqlHardenedPhysicalRoi' => true,
            'nl2sqlTwoLaneEnabled' => true,
        ],
    ];

    foreach ([
        'FolioSchemaService',
        'SqlBuilderService',
        'CanonicalQueryGraphArtifactBuilder',
        'CanonicalQueryGraphService',
        'QueryFamilyContractService',
        'QueryFamilySchemaManifestService',
        'QueryFamilySlotService',
        'QueryFamilyCompilerService',
        'QueryIntentService',
    ] as $service) {
        require_once __DIR__ . '/../services/' . $service . '.php';
    }
    require_once __DIR__ . '/../services/GeminiService.php';

    use app\exceptions\ExploratorySqlValidationException;
    use app\exceptions\PolicyViolationException;
    use app\services\FolioSchemaService;
    use app\services\GeminiService;
    use yii\httpclient\TestTransport;

    function twoLaneAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function twoLaneAssertContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            fwrite(STDERR, $message . "\nMissing: {$needle}\nActual: {$haystack}\n");
            exit(1);
        }
    }

    function twoLaneGeminiSql(string $sql, string $explanation = 'Generated report.'): string
    {
        return "```sql\n{$sql}\n```\n{$explanation}\nDATA SOURCE: folio";
    }

    function twoLaneFamilyIntent(string $familyKey, array $slots): string
    {
        return json_encode(['familyKey' => $familyKey, 'slots' => $slots]);
    }

    function twoLaneAssertTrustedSuccess(array $result, string $provenance, string $name): void
    {
        $label = $provenance === 'verified_pattern' ? 'Verified pattern' : 'AI-built';
        twoLaneAssertSame(true, isset($result['sql']) && trim((string)$result['sql']) !== '', $name . ' must return SQL.');
        twoLaneAssertSame($provenance, $result['generationProvenance'] ?? null, $name . ' provenance mismatch.');
        twoLaneAssertSame($label, $result['provenanceLabel'] ?? null, $name . ' label mismatch.');
        twoLaneAssertSame(false, !empty($result['needsClarification']), $name . ' must not block for clarification.');
        twoLaneAssertSame(false, ($result['route'] ?? null) === 'clarification', $name . ' must not select clarification.');
        twoLaneAssertSame(false, ($result['route'] ?? null) === 'exploratory_recovery', $name . ' must not select recovery.');
        foreach (['correctionInstruction', 'recoveryContext', 'recoveryItems', 'attemptedPlan'] as $forbiddenField) {
            twoLaneAssertSame(false, array_key_exists($forbiddenField, $result), $name . ' must omit blocker field ' . $forbiddenField . '.');
        }
    }

    $tableMapCache = json_decode((string)file_get_contents(__DIR__ . '/../data/table_mapping_cache.json'), true);
    $columnCache = json_decode((string)file_get_contents(__DIR__ . '/../data/column_cache.json'), true);
    $subtableCache = json_decode((string)file_get_contents(__DIR__ . '/../data/subtable_cache.json'), true);
    (new \ReflectionProperty(FolioSchemaService::class, 'discoveredMap'))->setValue(
        null,
        is_array($tableMapCache['mapping'] ?? null) ? $tableMapCache['mapping'] : []
    );
    (new \ReflectionProperty(FolioSchemaService::class, 'discoveredColumns'))->setValue(
        null,
        is_array($columnCache['columns'] ?? null) ? $columnCache['columns'] : []
    );
    (new \ReflectionProperty(FolioSchemaService::class, 'discoveredSubtables'))->setValue(
        null,
        is_array($subtableCache['subtables'] ?? null) ? $subtableCache['subtables'] : []
    );

    $hillyerPrompt = 'Show me a list of VHS and DVDs at Hillyer Library.';
    TestTransport::$responses = [
        twoLaneFamilyIntent('inventory_library_location_listing', [
            'campus' => 'Smith College',
            'library' => 'Hillyer Library',
            'material_type' => 'DVD',
            'requested_outputs' => ['title', 'material_type'],
        ]),
    ];
    TestTransport::$requests = [];
    $hillyer = GeminiService::generateSqlWithShadow($hillyerPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($hillyer, 'verified_pattern', 'verified inventory pattern');
    twoLaneAssertSame(1, count(TestTransport::$requests), 'Verified Hillyer routing must use one structured-intent request.');
    twoLaneAssertContains('SC Hillyer Art Library', (string)$hillyer['sql'], 'Verified SQL must use the resolved Hillyer library value.');
    twoLaneAssertContains('Videocassette', (string)$hillyer['sql'], 'Verified SQL must preserve the VHS material value.');
    twoLaneAssertContains('DVD/Blu-ray', (string)$hillyer['sql'], 'Verified SQL must preserve the DVD material value.');

    $neilsonPrompt = 'Show the 20 most-circulated books during the last five years at Neilson Library. Include title, call number, publication year, checkout count, and most recent checkout date.';
    TestTransport::$responses = [
        twoLaneFamilyIntent('circulation_top_items', [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'Book',
            'requested_outputs' => ['ranked_circulation_items'],
        ]),
    ];
    TestTransport::$requests = [];
    unset(Yii::$app->params['nl2sqlTwoLaneEnabled']);
    $neilson = GeminiService::generateSqlWithShadow($neilsonPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($neilson, 'verified_pattern', 'Neilson detailed top-circulation pattern');
    twoLaneAssertSame(1, count(TestTransport::$requests), 'Neilson routing must compile after one family-intent request without AI SQL repair.');
    twoLaneAssertSame('builder_intent', $neilson['route'] ?? null, 'Neilson routing should use the canonical builder lane.');
    twoLaneAssertSame(0, $neilson['repairAttempts'] ?? 0, 'Neilson canonical routing should not require automatic SQL repair.');
    twoLaneAssertContains("INTERVAL '5 years'", (string)$neilson['sql'], 'Neilson canonical SQL must preserve the five-year window from the prompt.');
    twoLaneAssertContains('COUNT(*) AS checkout_count', (string)$neilson['sql'], 'Neilson canonical SQL must aggregate checkout events across copies.');
    twoLaneAssertContains('SC Neilson Library', (string)$neilson['sql'], 'Neilson canonical SQL must preserve the resolved library value.');
    twoLaneAssertContains("imt.name ILIKE 'book'", (string)$neilson['sql'], 'Neilson canonical SQL must preserve the normalized resolved book material type.');
    twoLaneAssertSame(false, strpos((string)$neilson['sql'], 'call_number_type__t') !== false, 'Neilson canonical SQL must not invent a call-number-type filter from the requested title output.');
    twoLaneAssertContains('Resolved library values: SC Neilson Library', json_encode(TestTransport::$requests[0]), 'Neilson resolved reference context must reach the family-intent request.');

    $crossDomainPrompt = 'Compare annual circulation with acquisition spending by material type for the last three completed fiscal years.';
    $crossDomainSql = <<<'SQL'
SELECT EXTRACT(YEAR FROM al.created_date) AS circulation_year,
       mt.name AS material_type,
       COUNT(al.id) AS circulation_count,
       SUM(fd.total) AS acquisition_spend
FROM circulation.audit_loan__t al
JOIN inventory.item__t item ON item.id = al.loan__item_id
JOIN inventory.holdings_record__t hr ON hr.id = item.holdings_record_id
JOIN inventory.instance__t inst ON inst.id = hr.instance_id
JOIN inventory.material_type__t mt ON mt.id = item.material_type_id
LEFT JOIN orders.po_line__t pol ON pol.instance_id = inst.id
LEFT JOIN invoice.invoice_lines__t il ON il.po_line_id = pol.id
LEFT JOIN invoice.invoice_lines__t__fund_distributions fd ON fd.id = il.id
WHERE al.created_date >= CURRENT_DATE - INTERVAL '3 years'
GROUP BY EXTRACT(YEAR FROM al.created_date), mt.name
ORDER BY circulation_year, mt.name
SQL;
    TestTransport::$responses = [twoLaneGeminiSql($crossDomainSql)];
    TestTransport::$requests = [];
    $crossDomain = GeminiService::generateSqlWithShadow($crossDomainPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($crossDomain, 'ai_built', 'novel cross-domain request');
    twoLaneAssertSame(1, count(TestTransport::$requests), 'Novel cross-domain routing must go directly to AI-built generation.');

    $annualCheckoutPrompt = 'Show annual checkout counts at Neilson Library for each of the last five completed calendar years.';
    $invalidAnnualCheckoutSql = 'SELECT checkout_year FROM circulation.unknown_checkout_events__t';
    $validAnnualCheckoutSql = <<<'SQL'
SELECT EXTRACT(YEAR FROM al.created_date)::int AS calendar_year,
       COUNT(*) AS checkout_count
FROM circulation.audit_loan__t al
JOIN inventory.item__t item ON item.id = al.loan__item_id
JOIN inventory.location__t loc ON loc.id = item.effective_location_id
JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id
WHERE lib.name = 'SC Neilson Library'
  AND al.loan__action IN ('checkedout', 'checkedOutThroughOverride')
  AND al.created_date >= DATE_TRUNC('year', CURRENT_DATE) - INTERVAL '5 years'
  AND al.created_date < DATE_TRUNC('year', CURRENT_DATE)
GROUP BY EXTRACT(YEAR FROM al.created_date)
ORDER BY calendar_year
SQL;
    TestTransport::$responses = [
        twoLaneGeminiSql($invalidAnnualCheckoutSql),
        twoLaneGeminiSql($invalidAnnualCheckoutSql),
        twoLaneGeminiSql($invalidAnnualCheckoutSql),
        twoLaneGeminiSql($validAnnualCheckoutSql),
    ];
    TestTransport::$requests = [];
    $annualCheckouts = GeminiService::generateSqlWithShadow($annualCheckoutPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($annualCheckouts, 'ai_built', 'annual Neilson checkout fallback');
    twoLaneAssertSame(
        4,
        count(TestTransport::$requests),
        'An unsupported report whose first AI candidate chain exhausts validation must receive one fresh AI generation.'
    );
    twoLaneAssertContains(
        "lib.name = 'SC Neilson Library'",
        (string)$annualCheckouts['sql'],
        'Fresh AI generation must retain the resolved Neilson library scope.'
    );

    $overflowIdentifiers = [];
    foreach (range(1, 501) as $identifierNumber) {
        $overflowIdentifiers[] = sprintf('in%04d', $identifierNumber);
    }
    $overflowPrompt = 'For instance HRIDs: ' . implode(', ', $overflowIdentifiers) . ', show title.';
    TestTransport::$responses = [];
    TestTransport::$requests = [];
    $overflow = GeminiService::generateSqlWithShadow($overflowPrompt, 'Smith College');
    twoLaneAssertSame('configured_resource_limit', $overflow['errorType'] ?? null, '501 identifiers must use a typed configured-resource hard failure when two-lane mode is enabled.');
    twoLaneAssertSame('configured_resource_limit', $overflow['route'] ?? null, '501 identifiers must not select the clarification route when two-lane mode is enabled.');
    twoLaneAssertSame('too_many_explicit_identifiers', $overflow['routeReason'] ?? null, '501 identifiers must retain a stable internal resource reason.');
    twoLaneAssertContains('retry', strtolower((string)($overflow['message'] ?? '')), '501 identifiers must use concise Retry copy.');
    foreach (['sql', 'generationProvenance', 'provenanceLabel', 'needsClarification', 'clarificationItems', 'correctionInstruction', 'recoveryContext', 'recoveryItems'] as $forbiddenField) {
        twoLaneAssertSame(false, array_key_exists($forbiddenField, $overflow), '501-identifier hard failure must omit ' . $forbiddenField . '.');
    }
    twoLaneAssertSame(0, count(TestTransport::$requests), '501 identifiers must stop before any provider call.');

    Yii::$app->params['nl2sqlTwoLaneEnabled'] = false;
    TestTransport::$responses = [];
    TestTransport::$requests = [];
    $rollbackOverflow = GeminiService::generateSqlWithShadow($overflowPrompt, 'Smith College');
    twoLaneAssertSame(true, $rollbackOverflow['needsClarification'] ?? null, 'False switch may retain the 501-identifier rollback clarification.');
    twoLaneAssertSame('clarification', $rollbackOverflow['route'] ?? null, 'False switch must retain the 501-identifier clarification route.');
    twoLaneAssertSame(0, count(TestTransport::$requests), 'Rollback identifier clarification must stop before provider generation.');

    TestTransport::$responses = [
        twoLaneFamilyIntent('inventory_library_location_listing', [
            'campus' => 'Smith College',
            'library' => 'Hillyer Library',
            'material_type' => 'DVD',
            'requested_outputs' => ['title', 'material_type'],
        ]),
    ];
    TestTransport::$requests = [];
    $rollbackCanonical = GeminiService::generateSqlWithShadow($hillyerPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($rollbackCanonical, 'verified_pattern', 'rollback verified inventory pattern');
    twoLaneAssertSame(1, count(TestTransport::$requests), 'Rollback canonical routing must use one structured-intent request without AI rewriting.');

    TestTransport::$responses = [
        twoLaneFamilyIntent('circulation_top_items', [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'Book',
            'requested_outputs' => ['ranked_circulation_items'],
        ]),
    ];
    TestTransport::$requests = [];
    $rollback = GeminiService::generateSqlWithShadow($neilsonPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($rollback, 'verified_pattern', 'rollback Neilson canonical pattern');
    twoLaneAssertSame('builder_intent', $rollback['route'] ?? null, 'False switch should still compile a now-supported canonical report.');
    twoLaneAssertSame(1, count(TestTransport::$requests), 'Rollback canonical routing should use one structured-intent request.');
    Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;

    foreach ([
        ['DELETE FROM inventory.item__t', 'destructive SQL'],
        ['SELECT id FROM inventory.item__t; SELECT id FROM inventory.instance__t', 'multiple statements'],
    ] as $unsafeCase) {
        TestTransport::$responses = [
            twoLaneGeminiSql($unsafeCase[0]),
            twoLaneGeminiSql('SELECT COUNT(*) AS item_count FROM inventory.item__t'),
        ];
        TestTransport::$requests = [];
        $recoveredUnsafeCase = GeminiService::generateSqlWithShadow(
            'Unsafe fake-provider response.',
            'Smith College',
            null,
            true
        );
        twoLaneAssertSame(
            'SELECT COUNT(*) AS item_count FROM inventory.item__t',
            $recoveredUnsafeCase['sql'] ?? null,
            $unsafeCase[1] . ' must be replaced by safe AI-built SQL.'
        );
        twoLaneAssertSame(2, count(TestTransport::$requests), $unsafeCase[1] . ' must consume one replacement request.');
    }

    TestTransport::$responses = [twoLaneGeminiSql('SELECT id FROM users.users__t')];
    TestTransport::$requests = [];
    try {
        GeminiService::generateSqlWithShadow('Show restricted patron data.', 'Smith College', null, true);
        fwrite(STDERR, "Restricted patron data must remain a hard stop.\n");
        exit(1);
    } catch (PolicyViolationException $exception) {
        twoLaneAssertSame(1, count(TestTransport::$requests), 'Restricted patron SQL must consume no repair request.');
    }

    fwrite(STDOUT, "GeminiService two-lane routing test passed (3 routing cases, 4 service hard gates)\n");
}
