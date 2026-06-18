<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\services\FolioSchemaService;

/**
 * Samples the live LDLite/MetaDB database to build data_patterns.json.
 *
 * This file documents actual column data types (especially JSON-in-text traps),
 * sample values for enum-like columns, and preferred query approaches for
 * tables that commonly cause AI-generated SQL errors.
 *
 * Usage:
 *   php yii data-patterns/generate           — full discovery
 *   php yii data-patterns/generate --force   — regenerate even if cache is fresh
 *   php yii data-patterns/show               — print current patterns summary
 */
class DataPatternsController extends Controller
{
    /** @var bool Force regeneration even if cache is fresh. */
    public $force = false;

    /** @var int Max distinct values to sample for enum-like columns. */
    public $sampleLimit = 25;

    /** @inheritdoc */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['force', 'sampleLimit']);
    }

    /**
     * Curated list of tables to prioritise for deep sampling.
     * All other tables will also be scanned for JSON-in-text columns.
     */
    const PRIORITY_TABLES = [
        // SRS / MARC — most common source of AI confusion
        'folio_source_record.records__t',

        // Circulation
        'circulation.loan__t',
        'circulation.audit_loan__t',
        'circulation.request__t',
        'circulation.check_in__t',

        // Finance
        'invoice.invoices__t',
        'invoice.invoice_lines__t',
        'finance.transaction__t',
        'finance.budget__t',
        'finance.fund__t',
        'finance.fiscal_year__t',

        // Orders
        'orders.po_line__t',
        'orders.purchase_order__t',

        // Inventory core
        'inventory.item__t',
        'inventory.holdings_record__t',
        'inventory.instance__t',

        // Lookup/reference tables — sample enum values for these
        'inventory.material_type__t',
        'inventory.instance_type__t',
        'inventory.instance_status__t',
        'inventory.instance_format__t',
        'inventory.loan_type__t',
        'inventory.holdings_type__t',
        'inventory.call_number_type__t',
        'inventory.location__t',
        'inventory.loclibrary__t',
        'inventory.loccampus__t',
        'inventory.locinstitution__t',
        'users.groups__t',
        'finance.expense_class__t',
        'finance.fund_type__t',
        'finance.ledger__t',
        'orders.acquisitions_unit__t',
        'organizations.organizations__t',
        'feesfines.feefines__t',
        'feesfines.owners__t',
    ];

    /**
     * Columns on any table that should always be sampled for enum values
     * if they appear. These are the columns Gemini most often gets wrong.
     */
    const ENUM_COLUMN_NAMES = [
        'action', 'loan__action', 'status', 'workflow_status', 'payment_status',
        'invoice_line_status', 'record_type', 'request_type', 'item_status',
        'transaction_type', 'transaction_action', 'code', 'name', 'group',
    ];

    /**
     * Manually curated preferred-approach entries for known problem tables.
     * These are injected verbatim into the generated data_patterns.json
     * and override/supplement the auto-generated content.
     */
    const PREFERRED_APPROACHES = [
        'folio_source_record.records__t' => [
            'For MARC field-level queries (find by subject, title, ISBN, check field existence), ALWAYS use marctab.mtNNN tables — e.g. marctab.mt245 for title, marctab.mt300 for physical description. marctab tables are faster and avoid JSON.',
            'Use records__t ONLY for record-level status queries: checking deleted=true, additional_info__suppress_discovery, or record_type.',
            'NEVER use -> or ->> JSONB operators on parsed_record__content — it is a TEXT column. It stores a JSON string that must be cast with ::jsonb before JSON extraction, but prefer marctab over ever doing this.',
            'To find records MISSING a MARC field: NOT EXISTS (SELECT 1 FROM marctab.mtNNN m WHERE m.instance_hrid = inst.hrid). Do NOT use LEFT JOIN ... WHERE m.srs_id IS NULL — NOT EXISTS is cleaner and lets PostgreSQL use Index Only Scan.',
            'To join records__t to inventory: external_ids_holder__instance_id = inventory.instance__t.id (UUID stored as text).',
        ],
        'circulation.loan__t' => [
            'Use ONLY for current-state queries — e.g. what is checked out RIGHT NOW (WHERE status__name = \'Open\').',
            'For counting historical checkouts, returns, or renewals, use circulation.audit_loan__t instead. loan__t stores one row per loan that is updated in place.',
            'Do NOT use for historic circulation statistics — the return_date and renewal_count only reflect the current/final state.',
        ],
        'circulation.audit_loan__t' => [
            'PREFERRED for all circulation counts and statistics. One row per loan action.',
            'Filter by loan__action: \'checkedout\' or \'checkedOutThroughOverride\' for checkouts; \'checkedin\' for returns; \'renewed\' for renewals.',
            'Join to items: JOIN inventory.item__t AS ii ON al.loan__item_id = ii.id.',
            'Use loan__loan_date for checkout date. Use created_date for the audit event timestamp.',
        ],
        'invoice.invoice_lines__t' => [
            'For campus-scoped financial queries, ALWAYS work via the subtable chain:',
            'invoice.invoice_lines__t__fund_distributions → orders.po_line__t → orders.purchase_order__t__acq_unit_ids → orders.acquisitions_unit__t.',
            'Aggregate iltfd.total * (iltfd.fund_distributions__value * 0.01) for actual spend amount. Never sum invoice header totals (inv.total).',
        ],
        'orders.acquisitions_unit__t' => [
            'The name column stores 2-letter ABBREVIATION CODES (SC, AC, MH, UM, HC, RP, YB) — NOT full campus names.',
            'Always filter with exact match: au.name = \'SC\' for Smith College. Never LOWER(au.name) = LOWER(\'Smith College\').',
        ],
        'finance.transaction__t' => [
            'Each financial transaction has a transaction_type (Encumbrance, Payment, Credit, Pending payment, Rollover encumbrance) and an amount.',
            'For actual spending, filter transaction_type = \'Payment\' or use invoice tables which are pre-joined.',
        ],
        'inventory.material_type__t' => [
            'Use this table for patron-facing resource categories such as Book, Journal, Thesis/Dissertation, E-Thesis/Dissertation, DVD/Blu-ray, Audio CD, Newspaper, and Serial.',
            'If the user says document type or names a bibliographic category, filter inventory.material_type__t.name, not inventory.instance_type__t.name.',
            'When a prompt says thesis, theses, or dissertation, match material_type__t.name values like Thesis/Dissertation or E-Thesis/Dissertation with LOWER() or ILIKE.',
        ],
        'inventory.instance_type__t' => [
            'Use ONLY for RDA content types such as text, performed music, spoken word, sounds, or cartographic image.',
            'Do NOT use instance_type__t for book, thesis/dissertation, journal, serial, newspaper, DVD/Blu-ray, or Audio CD filters - those are material types.',
            'Only use this table when the user explicitly asks for content/resource type values like text or performed music.',
        ],
        'inventory.instance_format__t' => [
            'Use only for physical or carrier format such as volume, online resource, microfilm, videodisc, or streaming media.',
            'Do NOT use instance_format__t for book, thesis/dissertation, journal, or other bibliographic category terms unless the user explicitly asks for format.',
        ],
        'inventory.instance__t' => [
            'Use inventory.instance__t as the bibliographic anchor for title-level inventory queries.',
            'Join to holdings via holdings_record__t.instance_id = instance__t.id, then to items via item__t.holdings_record_id = holdings_record__t.id.',
            'For MARC field-level extraction, prefer folio_source_record.marctab instead of parsing source-record JSON text.',
            'For full MARC record output, ALWAYS include folio_source_record.records__t.parsed_record__content joined via records__t.external_id = instance__t.id AND records__t.state = \'ACTUAL\'.',
            'PERFORMANCE: When building instance-level CTEs with item-level filters (material type, item status), ALWAYS use nested EXISTS with AS MATERIALIZED. Never use JOIN + DISTINCT.',
            'PERFORMANCE: Resolve material_type_id via scalar subquery: ii.material_type_id = (SELECT id FROM inventory.material_type__t WHERE LOWER(name) = \'book\' LIMIT 1). Do not JOIN material_type__t in the main query.',
            'Document type requests usually map to material_type__t, not instance_type__t.',
        ],
    ];

    /**
     * Generate data_patterns.json by sampling the live LDLite database.
     */
    public function actionGenerate()
    {
        $outPath = Yii::getAlias('@app/data/data_patterns.json');

        // Check TTL unless forced
        if (!$this->force && file_exists($outPath)) {
            $existing = json_decode(file_get_contents($outPath), true);
            if (!empty($existing['_discovered_at'])) {
                $age = time() - strtotime($existing['_discovered_at']);
                if ($age < 86400) {
                    $this->stdout("data_patterns.json is fresh (" . round($age / 3600, 1) . "h old). Use --force to regenerate.\n");
                    return ExitCode::OK;
                }
            }
        }

        $this->stdout("Connecting to LDLite database...\n");

        try {
            $db = Yii::$app->folioDb;
        } catch (\Exception $e) {
            $this->stderr("Cannot connect to FOLIO DB: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Load full column cache so we know what columns exist per table
        $allColumns = FolioSchemaService::discoverAllColumns();
        $patterns = [];

        // --- Pass 1: All tables in column cache — detect JSON-in-text columns ---
        $this->stdout("Pass 1: Scanning all " . count($allColumns) . " tables for JSON-in-text traps...\n");
        $jsonTextTables = [];
        foreach ($allColumns as $tableName => $cols) {
            foreach ($cols as $col) {
                if (!in_array($col['type'], ['text', 'varchar'])) {
                    continue;
                }
                // Skip obviously non-JSON column names
                $name = $col['name'];
                if (in_array($name, ['id', 'name', 'code', 'barcode', 'title', 'description',
                    'note', 'hrid', '__id', 'created_by_user_id', 'updated_by_user_id'])) {
                    continue;
                }
                // Sample a non-null value
                try {
                    [$schema, $table] = $this->splitTableName($tableName);
                    $row = $db->createCommand(
                        "SELECT \"{$name}\" FROM {$tableName} WHERE \"{$name}\" IS NOT NULL AND \"{$name}\" != '' LIMIT 1"
                    )
                        ->setFetchMode(\PDO::FETCH_NUM)
                        ->queryOne();
                    if ($row === false || $row === null) {
                        continue;
                    }
                    $val = $row[0];
                    $trimmed = ltrim((string)$val);
                    if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                        $decoded = json_decode($trimmed, true);
                        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                            $jsonTextTables[$tableName][$name] = true;
                        }
                    }
                } catch (\Exception $e) {
                    // Column not accessible or table empty — skip
                }
            }
        }

        $this->stdout("  Found JSON-in-text columns in " . count($jsonTextTables) . " tables.\n");

        // --- Pass 2: Priority tables — deep sample enum/status/name columns ---
        $this->stdout("Pass 2: Sampling " . count(self::PRIORITY_TABLES) . " priority tables for enum values...\n");

        foreach (self::PRIORITY_TABLES as $tableName) {
            if (!isset($allColumns[$tableName])) {
                $this->stdout("  Skipping {$tableName} (not in column cache)\n");
                continue;
            }

            $this->stdout("  Sampling {$tableName}...\n");
            $cols = $allColumns[$tableName];
            $sampleValues = [];
            $columnWarnings = [];

            // Collect JSON-in-text warnings from pass 1
            foreach ($jsonTextTables[$tableName] ?? [] as $colName => $_) {
                $columnWarnings[$colName] = "TEXT column containing a JSON string. Must cast to ::jsonb before using -> or ->> operators. PREFER alternative tables/approaches listed below over parsing this column.";
            }

            // Collect additional column-level type notes
            foreach ($cols as $col) {
                $name = $col['name'];
                $type = $col['type'];

                // Flag numeric timestamp columns (LDLite stores some dates as epoch ms)
                if ($type === 'numeric' && (str_contains($name, '_date') || str_contains($name, 'date_'))) {
                    $columnWarnings[$name] = "NUMERIC type — epoch milliseconds, not a standard timestamp. Do not use DATE() or INTERVAL arithmetic directly; convert with: to_timestamp({$name}::bigint / 1000)";
                }

                // Flag text UUID columns that look like FK IDs
                if ($type === 'text' && str_ends_with($name, '_id') && $name !== '__id') {
                    // Only warn if it's a commonly misunderstood one
                    if (in_array($name, ['parsed_record__id', 'external_ids_holder__instance_id',
                        'external_ids_holder__instance_hrid'])) {
                        $columnWarnings[$name] = "TEXT type (UUID stored as text string, NOT a uuid type — do not cast or use uuid operators, join directly with =)";
                    }
                }

                // Sample enum-like columns
                $shouldSample = in_array($name, self::ENUM_COLUMN_NAMES) ||
                    (in_array($tableName, self::PRIORITY_TABLES) && in_array($name, ['name', 'code', 'group']));

                if ($shouldSample && in_array($type, ['text', 'varchar'])) {
                    try {
                        $rows = $db->createCommand(
                            "SELECT DISTINCT \"{$name}\" FROM {$tableName} WHERE \"{$name}\" IS NOT NULL ORDER BY \"{$name}\" LIMIT :lim",
                            [':lim' => $this->sampleLimit]
                        )->queryColumn();
                        if (!empty($rows)) {
                            $sampleValues[$name] = $rows;
                        }
                    } catch (\Exception $e) {
                        // Skip
                    }
                }
            }

            if (!empty($columnWarnings) || !empty($sampleValues)) {
                $patterns[$tableName] = [];
                if (!empty($columnWarnings)) {
                    $patterns[$tableName]['columnWarnings'] = $columnWarnings;
                }
                if (!empty($sampleValues)) {
                    $patterns[$tableName]['sampleValues'] = $sampleValues;
                }
                if (isset(self::PREFERRED_APPROACHES[$tableName])) {
                    $patterns[$tableName]['preferredApproach'] = self::PREFERRED_APPROACHES[$tableName];
                }
            } elseif (isset(self::PREFERRED_APPROACHES[$tableName])) {
                $patterns[$tableName] = [
                    'preferredApproach' => self::PREFERRED_APPROACHES[$tableName],
                ];
            }
        }

        // --- Pass 3: Add JSON-in-text warnings for non-priority tables ---
        foreach ($jsonTextTables as $tableName => $cols) {
            if (isset($patterns[$tableName])) {
                continue; // Already handled in pass 2
            }
            $warnings = [];
            foreach ($cols as $colName => $_) {
                $warnings[$colName] = "TEXT column containing a JSON string. Cast to ::jsonb before JSON operators.";
            }
            $patterns[$tableName] = ['columnWarnings' => $warnings];
        }

        // --- Pass 4: Always add preferred approaches for training tables not in priority list ---
        foreach (self::PREFERRED_APPROACHES as $tableName => $approach) {
            if (!isset($patterns[$tableName])) {
                $patterns[$tableName] = [];
            }
            if (!isset($patterns[$tableName]['preferredApproach'])) {
                $patterns[$tableName]['preferredApproach'] = $approach;
            }
        }

        // Merge with existing patterns so manual enrichments are preserved
        $existingPatterns = $this->loadExistingPatterns($outPath);
        $mergedPatterns = $this->mergePatterns($existingPatterns, $patterns);

        // Write output
        $output = [
            '_note' => 'Auto-generated column type warnings and sample values for AI prompt context. Edit preferredApproach entries manually.',
            '_discovered_at' => date('c'),
            '_tables_with_patterns' => count($mergedPatterns),
            'tables' => $mergedPatterns,
        ];

        $written = @file_put_contents($outPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            $this->stderr("Failed to write {$outPath}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nDone! Generated patterns for " . count($mergedPatterns) . " tables → {$outPath}\n");
        $this->stdout("Tables with JSON-in-text warnings: " . count($jsonTextTables) . "\n");
        $this->stdout("Tables with enum sample values: " . count(array_filter($mergedPatterns, function ($p) { return !empty($p['sampleValues']); })) . "\n");
        $this->stdout("Tables with preferred approaches: " . count(array_filter($mergedPatterns, function ($p) { return !empty($p['preferredApproach']); })) . "\n");

        return ExitCode::OK;
    }

    /**
     * Print a human-readable summary of the current data_patterns.json.
     */
    public function actionShow()
    {
        $outPath = Yii::getAlias('@app/data/data_patterns.json');
        if (!file_exists($outPath)) {
            $this->stderr("data_patterns.json not found. Run: php yii data-patterns/generate\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = json_decode(file_get_contents($outPath), true);
        if (!$data) {
            $this->stderr("Failed to parse data_patterns.json\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("data_patterns.json — Generated: " . ($data['_discovered_at'] ?? 'unknown') . "\n");
        $this->stdout("Tables with patterns: " . count($data['tables'] ?? []) . "\n\n");

        foreach ($data['tables'] ?? [] as $tableName => $info) {
            $this->stdout("  {$tableName}\n");
            foreach ($info['columnWarnings'] ?? [] as $col => $warn) {
                $this->stdout("    ⚠️  {$col}: {$warn}\n");
            }
            foreach ($info['sampleValues'] ?? [] as $col => $vals) {
                $preview = implode(', ', array_slice($vals, 0, 5));
                $more = count($vals) > 5 ? ' (+' . (count($vals) - 5) . ' more)' : '';
                $this->stdout("    📋 {$col}: [{$preview}{$more}]\n");
            }
            foreach ($info['preferredApproach'] ?? [] as $line) {
                $this->stdout("    ✅ {$line}\n");
            }
            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /** Split 'schema.table__t' into ['schema', 'table__t'] */
    private function splitTableName(string $fullName): array
    {
        $parts = explode('.', $fullName, 2);
        return count($parts) === 2 ? $parts : ['public', $parts[0]];
    }

    /**
     * Load existing data_patterns.json table entries.
     *
     * @param string $outPath
     * @return array
     */
    private function loadExistingPatterns(string $outPath): array
    {
        if (!file_exists($outPath)) {
            return [];
        }

        $existing = json_decode((string)file_get_contents($outPath), true);
        if (!is_array($existing) || !isset($existing['tables']) || !is_array($existing['tables'])) {
            return [];
        }

        return $existing['tables'];
    }

    /**
     * Merge newly discovered patterns with existing patterns.
     * Existing preferredApproach entries are preserved and appended to.
     *
     * @param array $existing
     * @param array $discovered
     * @return array
     */
    private function mergePatterns(array $existing, array $discovered): array
    {
        $merged = $existing;

        foreach ($discovered as $tableName => $info) {
            if (!isset($merged[$tableName]) || !is_array($merged[$tableName])) {
                $merged[$tableName] = [];
            }

            if (!empty($info['columnWarnings'])) {
                $merged[$tableName]['columnWarnings'] = array_merge(
                    $merged[$tableName]['columnWarnings'] ?? [],
                    $info['columnWarnings']
                );
            }

            if (!empty($info['sampleValues'])) {
                $merged[$tableName]['sampleValues'] = array_merge(
                    $merged[$tableName]['sampleValues'] ?? [],
                    $info['sampleValues']
                );
            }

            if (!empty($info['preferredApproach'])) {
                $existingApproach = $merged[$tableName]['preferredApproach'] ?? [];
                $merged[$tableName]['preferredApproach'] = array_values(array_unique(array_merge($existingApproach, $info['preferredApproach'])));
            }
        }

        return $merged;
    }
}
