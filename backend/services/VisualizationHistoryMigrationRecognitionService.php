<?php

namespace app\services;

/** Confirms the history schema, unique key, and complete seed before migration discovery skips 064. */
final class VisualizationHistoryMigrationRecognitionService
{
    public static function visualizationHistoryAppearsComplete($db): bool
    {
        try {
            $table = $db->createCommand(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => 'visualization_history']
            )->queryScalar();
            if ((int)$table !== 1 || !self::columnsAreComplete($db) || !self::uniqueKeyIsComplete($db)) return false;

            $rows = $db->createCommand(
                'SELECT kind, fiscal_year_end FROM visualization_history WHERE acq_unit_code = :code'
                    . ' AND ((kind = :purchases AND fiscal_year_end BETWEEN :purchaseFirst AND :purchaseLast)'
                    . ' OR (kind = :budget AND fiscal_year_end BETWEEN :budgetFirst AND :budgetLast))',
                [':code' => 'SC', ':purchases' => 'purchases', ':purchaseFirst' => 2007, ':purchaseLast' => 2022,
                    ':budget' => 'budget', ':budgetFirst' => 2011, ':budgetLast' => 2024]
            )->queryAll();
            $keys = [];
            foreach ($rows as $row) $keys[(string)$row['kind'] . '|' . (int)$row['fiscal_year_end']] = true;
            return count($keys) === 30;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private static function columnsAreComplete($db): bool
    {
        $rows = $db->createCommand(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH,'
                . ' NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => 'visualization_history']
        )->queryAll();
        $actual = [];
        foreach ($rows as $row) $actual[strtolower((string)$row['COLUMN_NAME'])] = $row;
        $expected = [
            'id' => ['int', 'NO', null, 10, 0], 'acq_unit_code' => ['varchar(50)', 'NO', 50, null, null],
            'kind' => ["enum('purchases','budget')", 'NO', 9, null, null], 'fiscal_year_end' => ['smallint', 'NO', null, 5, 0],
            'total_amount' => ['decimal(14,2)', 'NO', null, 14, 2], 'electronic_amount' => ['decimal(14,2)', 'YES', null, 14, 2],
            'physical_amount' => ['decimal(14,2)', 'YES', null, 14, 2], 'note' => ['varchar(255)', 'YES', 255, null, null],
            'updated_by' => ['int', 'YES', null, 10, 0], 'updated_at' => ['timestamp', 'NO', null, null, null],
            'deleted_at' => ['datetime', 'YES', null, null, null], 'deleted_by' => ['int', 'YES', null, 10, 0],
        ];
        if (count($actual) !== count($expected)) return false;
        foreach ($expected as $name => $shape) {
            if (!isset($actual[$name])) return false;
            $row = $actual[$name];
            $type = strtolower((string)$row['DATA_TYPE']);
            $columnType = strtolower((string)$row['COLUMN_TYPE']);
            $typeMatches = $shape[0] === 'int'
                ? in_array($columnType, ['int', 'int(11)'], true)
                : ($name === 'fiscal_year_end' ? strpos($columnType, 'smallint') === 0 : $columnType === $shape[0]);
            if (!$typeMatches || $type !== strtok($shape[0], '(') || strtoupper((string)$row['IS_NULLABLE']) !== $shape[1]) return false;
            if ($shape[2] !== null && (int)$row['CHARACTER_MAXIMUM_LENGTH'] !== $shape[2]) return false;
            if ($shape[3] !== null && (int)$row['NUMERIC_PRECISION'] !== $shape[3]) return false;
            if ($shape[4] !== null && (int)$row['NUMERIC_SCALE'] !== $shape[4]) return false;
            if ($name === 'id' && stripos((string)$row['EXTRA'], 'auto_increment') === false) return false;
        }
        return true;
    }

    private static function uniqueKeyIsComplete($db): bool
    {
        $rows = $db->createCommand(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index ORDER BY SEQ_IN_INDEX',
            [':table' => 'visualization_history', ':index' => 'uq_visualization_history']
        )->queryAll();
        $columns = ['acq_unit_code', 'kind', 'fiscal_year_end'];
        if (count($rows) !== count($columns)) return false;
        foreach ($columns as $index => $column) {
            $row = $rows[$index];
            if ((int)$row['NON_UNIQUE'] !== 0 || (int)$row['SEQ_IN_INDEX'] !== $index + 1
                || strtolower((string)$row['COLUMN_NAME']) !== $column || $row['SUB_PART'] !== null) return false;
        }
        return true;
    }
}
