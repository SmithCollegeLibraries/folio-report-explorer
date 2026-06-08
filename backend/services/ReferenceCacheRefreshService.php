<?php

namespace app\services;

use Yii;
use app\commands\ReferenceCacheController;

class ReferenceCacheRefreshService
{
    public function refreshTableBySourceTable(string $sourceTable): array
    {
        $row = Yii::$app->db->createCommand(
            'SELECT id, source_table, enabled FROM folio_reference_tables WHERE source_table = :sourceTable',
            [':sourceTable' => $sourceTable]
        )->queryOne();

        if ($row === false) {
            throw new \RuntimeException('Reference table is not registered');
        }
        if (empty($row['enabled'])) {
            throw new \RuntimeException('Reference table is not enabled');
        }

        $started = microtime(true);
        $startedAt = date('Y-m-d H:i:s');
        try {
            $rowCount = $this->refreshTable((int)$row['id'], (string)$row['source_table']);
            $this->recordRefreshLog($sourceTable, 'success', $rowCount, $started, null, $startedAt);
            $this->markRefreshStatus($sourceTable, 'success', $rowCount, null);
        } catch (\Throwable $e) {
            $this->recordRefreshLog($sourceTable, 'failed', null, $started, $e->getMessage(), $startedAt);
            $this->markRefreshStatus($sourceTable, 'failed', null, $e->getMessage());
            throw $e;
        }

        return [
            'sourceTable' => $sourceTable,
            'rowCount' => $rowCount,
            'lastRefreshStatus' => 'success',
        ];
    }

    public function refreshTable(int $referenceTableId, string $sourceTable): int
    {
        [$schema, $table] = $this->splitSourceTable($sourceTable);
        $existingColumns = $this->loadExistingColumns($schema, $table);
        $config = ReferenceCacheController::DEFAULT_SOURCE_TABLES[$sourceTable] ?? $this->inferRefreshMapping($sourceTable, $existingColumns);
        $nameColumn = (string)$config['nameColumn'];
        $codeColumn = (string)($config['codeColumn'] ?? '');
        $metadataColumns = $config['metadataColumns'] ?? [];

        if (!isset($existingColumns['id'])) {
            throw new \RuntimeException("Required column id is missing from {$sourceTable}");
        }
        if (!isset($existingColumns[$nameColumn])) {
            throw new \RuntimeException("Required name column {$nameColumn} is missing from {$sourceTable}");
        }
        if ($codeColumn !== '' && !isset($existingColumns[$codeColumn])) {
            Yii::info("Skipping configured optional columns for {$sourceTable}: {$codeColumn}", __METHOD__);
            $codeColumn = '';
        }

        $filteredMetadata = $this->filterExistingColumns($metadataColumns, $existingColumns);
        $missingMetadata = array_values(array_diff(array_map('strval', $metadataColumns), $filteredMetadata));
        if (!empty($missingMetadata)) {
            Yii::info("Skipping configured optional columns for {$sourceTable}: " . implode(', ', $missingMetadata), __METHOD__);
        }
        $metadataColumns = $filteredMetadata;
        $select = ['id::text AS source_id'];
        $select[] = $this->quoteIdent($nameColumn) . '::text AS name';
        $select[] = $codeColumn !== '' ? $this->quoteIdent($codeColumn) . '::text AS code' : "NULL::text AS code";
        foreach ($metadataColumns as $column) {
            $select[] = $this->quoteIdent((string)$column) . '::text AS ' . $this->quoteIdent((string)$column);
        }

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdent($schema) . '.' . $this->quoteIdent($table)
            . ' WHERE ' . $this->quoteIdent($nameColumn) . ' IS NOT NULL'
            . ' ORDER BY ' . $this->quoteIdent($nameColumn);

        $rows = Yii::$app->folioDb->createCommand($sql)->queryAll();

        // Atomic swap: deactivate the existing rows and reinsert the fresh set
        // inside one transaction. Without it, a concurrent resolver query
        // (WHERE is_active = 1) sees a zero/partial-active window mid-refresh,
        // and a failure mid-reinsert would leave the cache wiped. The rollback
        // restores the previous active set on any error.
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->update('folio_reference_values', ['is_active' => 0], ['source_table' => $sourceTable])->execute();

            $count = 0;
            foreach ($rows as $row) {
                $metadata = [];
                foreach ($metadataColumns as $column) {
                    $metadata[$column] = (string)($row[$column] ?? '');
                }
                $this->replaceReferenceValue($referenceTableId, $sourceTable, $row, $metadata);
                $count++;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $count;
    }

    public function validateSourceTableCanRefresh(string $sourceTable)
    {
        [$schema, $table] = $this->splitSourceTable($sourceTable);
        $columns = $this->loadExistingColumns($schema, $table);

        if (!isset($columns['id'])) {
            return 'Cannot enable candidate because required id column was not found';
        }

        try {
            $this->inferRefreshMapping($sourceTable, $columns);
        } catch (\RuntimeException $e) {
            return 'Cannot enable candidate because no safe refresh label column was found';
        }

        return null;
    }

    private function inferRefreshMapping(string $sourceTable, array $existingColumns): array
    {
        $nameColumns = ['name', 'label', 'value', 'display_name', 'description'];
        $codeColumns = ['code', 'key', 'slug'];

        $nameColumn = '';
        foreach ($nameColumns as $column) {
            if (isset($existingColumns[$column])) {
                $nameColumn = $column;
                break;
            }
        }

        if ($nameColumn === '') {
            throw new \RuntimeException("No safe refresh mapping could be inferred for {$sourceTable}");
        }

        $codeColumn = '';
        foreach ($codeColumns as $column) {
            if (isset($existingColumns[$column])) {
                $codeColumn = $column;
                break;
            }
        }

        return [
            'nameColumn' => $nameColumn,
            'codeColumn' => $codeColumn,
            'metadataColumns' => [],
        ];
    }

    private function loadExistingColumns(string $schema, string $table): array
    {
        $rows = Yii::$app->folioDb->createCommand(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name = :table',
            [':schema' => $schema, ':table' => $table]
        )->queryColumn();

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row] = true;
        }

        return $columns;
    }

    private function filterExistingColumns(array $columns, array $existingColumns): array
    {
        $filtered = [];
        foreach ($columns as $column) {
            $column = (string)$column;
            if (isset($existingColumns[$column])) {
                $filtered[] = $column;
            }
        }

        return $filtered;
    }

    private function replaceReferenceValue(int $referenceTableId, string $sourceTable, array $row, array $metadata): void
    {
        $name = trim((string)($row['name'] ?? ''));
        $code = trim((string)($row['code'] ?? ''));
        $sql = 'REPLACE INTO folio_reference_values
            (reference_table_id, source_table, source_id, name, code, normalized_name, normalized_code, metadata_json, is_active, refreshed_at)
            VALUES (:reference_table_id, :source_table, :source_id, :name, :code, :normalized_name, :normalized_code, :metadata_json, 1, NOW())';
        Yii::$app->db->createCommand($sql, [
            ':reference_table_id' => $referenceTableId,
            ':source_table' => $sourceTable,
            ':source_id' => (string)($row['source_id'] ?? ''),
            ':name' => $name,
            ':code' => $code !== '' ? $code : null,
            ':normalized_name' => $this->normalizeText($name),
            ':normalized_code' => $code !== '' ? $this->normalizeText($code) : null,
            ':metadata_json' => json_encode($metadata),
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

    private function splitSourceTable(string $sourceTable): array
    {
        $parts = explode('.', $sourceTable, 2);
        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['public', $sourceTable];
    }

    private function quoteIdent(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function normalizeText(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        return trim((string)preg_replace('/\s+/', ' ', (string)$normalized));
    }
}
