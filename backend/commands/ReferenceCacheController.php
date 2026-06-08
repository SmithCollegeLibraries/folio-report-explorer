<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\services\ReferenceResolverService;
use app\services\ReferenceCacheRefreshService;

/**
 * Discover and refresh local FOLIO reference data used before NL2SQL generation.
 *
     * Usage:
     *   php yii reference-cache/discover-candidates
     *   php yii reference-cache/review-candidates
     *   php yii reference-cache/refresh
 *   php yii reference-cache/refresh --table=inventory.location__t
 *   php yii reference-cache/status
 */
class ReferenceCacheController extends Controller
{
    /** @var string|null Refresh or inspect one source table. */
    public $table;

    /** @inheritdoc */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['table']);
    }

    const DEFAULT_SOURCE_TABLES = [
        'inventory.location__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => ['library_id'],
        ],
        'inventory.loclibrary__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => ['campus_id'],
        ],
        'inventory.loccampus__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.locinstitution__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'finance.fund__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => ['ledger_id', 'fund_type_id'],
        ],
        'finance.ledger__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'finance.fiscal_year__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => ['period_start', 'period_end'],
        ],
        'finance.fund_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'finance.expense_class__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'finance.groups__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.material_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.item_damaged_status__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.loan_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.instance_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.instance_status__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.holdings_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.call_number_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.instance_relationship_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.electronic_access_relationship__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.ill_policy__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.statistical_code_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.statistical_code__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => ['statistical_code_type_id'],
        ],
        'inventory.holdings_note_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.item_note_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.contributor_name_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.mode_of_issuance__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.subject_sources__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.alternative_title_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.nature_of_content_term__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.instance_note_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.contributor_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'inventory.identifier_type__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
        'inventory.service_point__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => 'code',
            'metadataColumns' => [],
        ],
        'orders.acquisitions_unit__t' => [
            'enabled' => true,
            'nameColumn' => 'name',
            'codeColumn' => '',
            'metadataColumns' => [],
        ],
    ];

    /**
     * Inspect FOLIO table sizes and record candidate reference tables locally.
     */
    public function actionDiscoverCandidates()
    {
        $rows = $this->loadTableStats();
        if ($rows === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $count = 0;
        foreach ($rows as $row) {
            $sourceTable = $row['schema'] . '.' . $row['table'];
            $classification = ReferenceResolverService::classifyDiscoveryCandidate($row);
            $default = self::DEFAULT_SOURCE_TABLES[$sourceTable] ?? null;
            if ($default !== null && !empty($default['enabled'])) {
                $classification['classification'] = ReferenceResolverService::CLASS_CACHEABLE;
                $classification['reason'] = 'default_reference_allowlist';
            }

            $this->upsertReferenceTable(
                $sourceTable,
                $row['schema'],
                $row['table'],
                !empty($default['enabled']),
                $classification['classification'],
                (int)$row['estimated_rows'],
                (int)$row['total_bytes'],
                null,
                null
            );
            $count++;
        }

        $this->stdout("Discovered {$count} FOLIO table reference candidates.\n");
        return ExitCode::OK;
    }

    /**
     * Refresh enabled local reference tables from FOLIO.
     */
    public function actionRefresh()
    {
        $this->ensureDefaultReferenceTables();
        $tables = $this->loadEnabledTables();
        if ($this->table) {
            $tables = array_values(array_filter($tables, function ($row) {
                return (string)$row['source_table'] === (string)$this->table;
            }));
        }

        if (empty($tables)) {
            $this->stdout("No enabled reference tables found.\n");
            return ExitCode::OK;
        }

        $ok = true;
        foreach ($tables as $tableRow) {
            $sourceTable = (string)$tableRow['source_table'];
            $started = microtime(true);
            $startedAt = date('Y-m-d H:i:s');
            try {
                $rowCount = $this->refreshOneTable((int)$tableRow['id'], $sourceTable);
                $this->recordRefreshLog($sourceTable, 'success', $rowCount, $started, null, $startedAt);
                $this->markRefreshStatus($sourceTable, 'success', $rowCount, null);
                $this->stdout("Refreshed {$sourceTable}: {$rowCount} rows.\n");
            } catch (\Throwable $e) {
                $ok = false;
                $this->recordRefreshLog($sourceTable, 'failed', null, $started, $e->getMessage(), $startedAt);
                $this->markRefreshStatus($sourceTable, 'failed', null, $e->getMessage());
                $this->stderr("Failed {$sourceTable}: " . $e->getMessage() . "\n");
            }
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Show local reference cache freshness.
     */
    public function actionStatus()
    {
        try {
            $rows = Yii::$app->db->createCommand(
                'SELECT source_table, enabled, classification, row_count, last_refreshed_at, last_refresh_status, last_error
                 FROM folio_reference_tables
                 ORDER BY enabled DESC, source_table'
            )->queryAll();
        } catch (\Throwable $e) {
            $this->stderr("Cannot read reference cache status: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($rows as $row) {
            $enabled = !empty($row['enabled']) ? 'enabled' : 'disabled';
            $this->stdout(sprintf(
                "%s [%s/%s] rows=%s refreshed=%s status=%s\n",
                $row['source_table'],
                $enabled,
                $row['classification'],
                $row['row_count'] ?? 'n/a',
                $row['last_refreshed_at'] ?? 'never',
                $row['last_refresh_status'] ?? 'never'
            ));
            if (!empty($row['last_error'])) {
                $this->stdout("  error: " . $row['last_error'] . "\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Print a compact review report for discovered but disabled reference candidates.
     */
    public function actionReviewCandidates()
    {
        try {
            $summary = Yii::$app->db->createCommand(
                'SELECT classification, source_schema, COUNT(*) AS table_count
                 FROM folio_reference_tables
                 WHERE enabled = 0
                 GROUP BY classification, source_schema
                 ORDER BY classification, table_count DESC, source_schema'
            )->queryAll();

            $examples = Yii::$app->db->createCommand(
                'SELECT source_table, classification, estimated_rows, total_bytes
                 FROM folio_reference_tables
                 WHERE enabled = 0
                   AND classification IN ("cacheable_reference", "manual_review")
                 ORDER BY classification, estimated_rows ASC, total_bytes ASC, source_table
                 LIMIT 80'
            )->queryAll();
        } catch (\Throwable $e) {
            $this->stderr("Cannot read candidate review data: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Reference candidate summary by schema:\n");
        foreach ($summary as $row) {
            $this->stdout(sprintf(
                "  %-20s %-32s %5d\n",
                (string)$row['classification'],
                (string)$row['source_schema'],
                (int)$row['table_count']
            ));
        }

        $this->stdout("\nSmallest disabled cacheable/manual-review candidates:\n");
        foreach ($examples as $row) {
            $this->stdout(sprintf(
                "  %-48s %-20s rows=%s bytes=%s\n",
                (string)$row['source_table'],
                (string)$row['classification'],
                $row['estimated_rows'] ?? 'n/a',
                $row['total_bytes'] ?? 'n/a'
            ));
        }

        return ExitCode::OK;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadTableStats()
    {
        try {
            $stats = Yii::$app->folioDb->createCommand(
                "SELECT
                    n.nspname AS schema,
                    c.relname AS table,
                    GREATEST(c.reltuples::bigint, 0) AS estimated_rows,
                    pg_total_relation_size(c.oid) AS total_bytes
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind IN ('r', 'p')
                   AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                 ORDER BY n.nspname, c.relname"
            )->queryAll();

            $columns = Yii::$app->folioDb->createCommand(
                "SELECT table_schema AS schema, table_name AS table, column_name
                 FROM information_schema.columns
                 WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                 ORDER BY table_schema, table_name, ordinal_position"
            )->queryAll();
        } catch (\Throwable $e) {
            $this->stderr("Cannot inspect FOLIO tables: " . $e->getMessage() . "\n");
            return null;
        }

        $columnMap = [];
        foreach ($columns as $column) {
            $key = $column['schema'] . '.' . $column['table'];
            if (!isset($columnMap[$key])) {
                $columnMap[$key] = [];
            }
            $columnMap[$key][] = (string)$column['column_name'];
        }

        foreach ($stats as &$row) {
            $key = $row['schema'] . '.' . $row['table'];
            $row['columns'] = $columnMap[$key] ?? [];
        }
        unset($row);

        return $stats;
    }

    private function ensureDefaultReferenceTables(): void
    {
        foreach (self::DEFAULT_SOURCE_TABLES as $sourceTable => $config) {
            [$schema, $table] = $this->splitSourceTable($sourceTable);
            $this->upsertReferenceTable(
                $sourceTable,
                $schema,
                $table,
                !empty($config['enabled']),
                ReferenceResolverService::CLASS_CACHEABLE,
                null,
                null,
                null,
                null
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEnabledTables(): array
    {
        try {
            return Yii::$app->db->createCommand(
                'SELECT id, source_table FROM folio_reference_tables WHERE enabled = 1 ORDER BY source_table'
            )->queryAll();
        } catch (\Throwable $e) {
            $this->stderr("Cannot load enabled reference tables: " . $e->getMessage() . "\n");
            return [];
        }
    }

    private function refreshOneTable(int $referenceTableId, string $sourceTable): int
    {
        return (new ReferenceCacheRefreshService())->refreshTable($referenceTableId, $sourceTable);
    }

    private function upsertReferenceTable(
        string $sourceTable,
        string $schema,
        string $table,
        bool $enabled,
        string $classification,
        $estimatedRows,
        $totalBytes,
        $rowCount,
        $lastError
    ): void {
        $sql = 'INSERT INTO folio_reference_tables
            (source_table, source_schema, source_name, enabled, classification, estimated_rows, total_bytes, row_count, last_error)
            VALUES (:source_table, :source_schema, :source_name, :enabled, :classification, :estimated_rows, :total_bytes, :row_count, :last_error)
            ON DUPLICATE KEY UPDATE
                source_schema = VALUES(source_schema),
                source_name = VALUES(source_name),
                enabled = GREATEST(enabled, VALUES(enabled)),
                classification = VALUES(classification),
                estimated_rows = COALESCE(VALUES(estimated_rows), estimated_rows),
                total_bytes = COALESCE(VALUES(total_bytes), total_bytes),
                row_count = COALESCE(VALUES(row_count), row_count),
                last_error = VALUES(last_error)';
        Yii::$app->db->createCommand($sql, [
            ':source_table' => $sourceTable,
            ':source_schema' => $schema,
            ':source_name' => $table,
            ':enabled' => $enabled ? 1 : 0,
            ':classification' => $classification,
            ':estimated_rows' => $estimatedRows,
            ':total_bytes' => $totalBytes,
            ':row_count' => $rowCount,
            ':last_error' => $lastError,
        ])->execute();
    }

    private function markRefreshStatus(string $sourceTable, string $status, $rowCount, $error): void
    {
        Yii::$app->db->createCommand()->update('folio_reference_tables', [
            'row_count' => $rowCount,
            'last_refreshed_at' => date('Y-m-d H:i:s'),
            'last_refresh_status' => $status,
            'last_error' => $error,
        ], ['source_table' => $sourceTable])->execute();
    }

    private function recordRefreshLog(string $sourceTable, string $status, $rowCount, float $started, $error, string $startedAt): void
    {
        Yii::$app->db->createCommand()->insert('folio_reference_refresh_log', [
            'source_table' => $sourceTable,
            'status' => $status,
            'row_count' => $rowCount,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'error_message' => $error,
            'started_at' => $startedAt,
            'finished_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSourceTable(string $sourceTable): array
    {
        $parts = explode('.', $sourceTable, 2);
        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['public', $sourceTable];
    }

}
