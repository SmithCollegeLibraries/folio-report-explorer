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

            if ($prompt === 'Show me the 20 most-circulated books at Neilson Library during the last five years. Include title, call number, publication year, checkout count, and most recent checkout date.') {
                return [
                    'needsClarification' => true,
                    'route' => 'clarification',
                    'routeReason' => 'reference_resolver_batch_clarification',
                    'question' => 'Which Neilson Library value should I use?',
                    'resolvedFilters' => [],
                    'unresolvedNamedIntents' => [[
                        'dimension' => 'library',
                        'span' => 'Neilson Library',
                    ]],
                    'clarificationItems' => [[
                        'term' => 'Neilson Library',
                        'options' => [
                            ['label' => 'SC Neilson Library'],
                            ['label' => 'Neilson Library Annex'],
                        ],
                    ]],
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

    $neilsonPrompt = 'Show me the 20 most-circulated books at Neilson Library during the last five years. Include title, call number, publication year, checkout count, and most recent checkout date.';
    $neilsonSql = <<<'SQL'
SELECT inst.title,
       hr.call_number,
       inst.dates__date1 AS publication_year,
       COUNT(al.id) AS checkout_count,
       MAX(al.created_date) AS most_recent_checkout_date
FROM circulation.audit_loan__t al
JOIN inventory.item__t item ON item.id = al.loan__item_id
JOIN inventory.holdings_record__t hr ON hr.id = item.holdings_record_id
JOIN inventory.instance__t inst ON inst.id = hr.instance_id
JOIN inventory.location__t loc ON loc.id = item.effective_location_id
JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id
WHERE lib.name = 'SC Neilson Library'
  AND al.created_date >= CURRENT_DATE - INTERVAL '5 years'
GROUP BY inst.title, hr.call_number, inst.dates__date1
ORDER BY checkout_count DESC
LIMIT 20
SQL;
    TestTransport::$responses = [
        twoLaneFamilyIntent('circulation_top_items', [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'Book',
            'limit' => '20',
            'requested_outputs' => [
                'title',
                'call_number',
                'publication_year',
                'checkout_count',
                'most_recent_checkout_date',
            ],
        ]),
        twoLaneGeminiSql($neilsonSql),
    ];
    TestTransport::$requests = [];
    unset(Yii::$app->params['nl2sqlTwoLaneEnabled']);
    $neilson = GeminiService::generateSqlWithShadow($neilsonPrompt, 'Smith College');
    twoLaneAssertTrustedSuccess($neilson, 'ai_built', 'Neilson unsupported canonical shape');
    twoLaneAssertSame(2, count(TestTransport::$requests), 'Neilson routing must use one family attempt and one automatic AI-built request.');
    twoLaneAssertContains('Local reference generation context', json_encode(TestTransport::$requests[0]), 'Neilson reference uncertainty must reach model-only context.');

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

    Yii::$app->params['nl2sqlTwoLaneEnabled'] = false;
    TestTransport::$responses = [];
    TestTransport::$requests = [];
    $rollback = GeminiService::generateSqlWithShadow($neilsonPrompt, 'Smith College');
    twoLaneAssertSame(true, $rollback['needsClarification'] ?? null, 'False switch must retain rollback clarification compatibility.');
    twoLaneAssertSame('clarification', $rollback['route'] ?? null, 'False switch must retain the strict blocker route.');
    twoLaneAssertSame(false, isset($rollback['sql']), 'Rollback clarification must not expose SQL.');
    twoLaneAssertSame(false, isset($rollback['generationProvenance']), 'Rollback clarification must not claim success provenance.');
    twoLaneAssertSame(0, count(TestTransport::$requests), 'Rollback clarification must stop before model generation.');
    Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;

    foreach ([
        ['DELETE FROM inventory.item__t', 'destructive SQL'],
        ['SELECT id FROM inventory.item__t; SELECT id FROM inventory.instance__t', 'multiple statements'],
    ] as $unsafeCase) {
        TestTransport::$responses = [twoLaneGeminiSql($unsafeCase[0])];
        TestTransport::$requests = [];
        try {
            GeminiService::generateSqlWithShadow('Unsafe fake-provider response.', 'Smith College', null, true);
            fwrite(STDERR, $unsafeCase[1] . " must remain a hard stop.\n");
            exit(1);
        } catch (ExploratorySqlValidationException $exception) {
            twoLaneAssertSame(false, $exception->isRepairable(), $unsafeCase[1] . ' must not enter automatic repair.');
        } catch (\InvalidArgumentException $exception) {
            // The production safety validator intentionally retains this hard-stop type.
        }
        twoLaneAssertSame(1, count(TestTransport::$requests), $unsafeCase[1] . ' must consume no repair request.');
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

    fwrite(STDOUT, "GeminiService two-lane routing test passed (3 routing cases, 3 service hard gates)\n");
}
