<?php

namespace app\services;

final class QueryJobHistoryMigrationRecognitionService
{
    public static function historyGroupKeyAndIndexAppearComplete($db): bool
    {
        $columns = $db->createCommand(
            'SELECT COLUMN_TYPE, DATA_TYPE, IS_NULLABLE '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND COLUMN_NAME = :columnName',
            [':tableName' => 'query_jobs', ':columnName' => 'history_group_key']
        )->queryAll();
        $column = $columns[0] ?? false;
        if (!$column
            || strtolower((string) ($column['COLUMN_TYPE'] ?? $column['column_type'] ?? '')) !== 'char(40)'
            || strtolower((string) ($column['DATA_TYPE'] ?? $column['data_type'] ?? '')) !== 'char'
            || strtoupper((string) ($column['IS_NULLABLE'] ?? $column['is_nullable'] ?? '')) !== 'YES'
        ) {
            return false;
        }

        $indexes = $db->createCommand(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND INDEX_NAME = :indexName '
            . 'ORDER BY SEQ_IN_INDEX',
            [':tableName' => 'query_jobs', ':indexName' => 'idx_query_jobs_history_group']
        )->queryAll();
        $expectedColumns = ['user_id', 'history_group_key', 'created_at'];
        if (count($indexes) !== count($expectedColumns)) {
            return false;
        }
        foreach ($expectedColumns as $index => $expectedColumn) {
            $row = $indexes[$index];
            if ((int) ($row['NON_UNIQUE'] ?? $row['non_unique'] ?? -1) !== 1
                || (int) ($row['SEQ_IN_INDEX'] ?? $row['seq_in_index'] ?? 0) !== $index + 1
                || strtolower((string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '')) !== $expectedColumn
                || ($row['SUB_PART'] ?? $row['sub_part'] ?? null) !== null
            ) {
                return false;
            }
        }
        return true;
    }
}
