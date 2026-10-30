<?php

namespace app\services;

use Yii;

require_once __DIR__ . '/ExpenseClassScopeService.php';

/** Loads only the option rows needed to label one report-run title setting. */
final class ReportRunTitleOptionSource
{
    /**
     * @param object $report
     * @param array $definition
     * @return array
     */
    public function getOptions($report, array $definition): array
    {
        $sql = (string) ($definition['options_sql'] ?? '');
        if (trim($sql) === '') {
            return [];
        }

        $dataSource = $report->data_source ?? null;
        $useLocalDb = in_array($dataSource, ['local', 'composite'], true);
        if (!empty($definition['options_db'])) {
            $useLocalDb = $definition['options_db'] !== 'folio';
        }

        $rows = $useLocalDb
            ? LocalReportSqlService::queryAll($sql)
            : Yii::$app->folioDb->createCommand($sql)->queryAll();
        if (!is_array($rows)) {
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = array_values($row);
            $value = $values[0] ?? '';
            $options[] = [
                'value' => $value,
                'label' => $values[1] ?? $value,
            ];
        }
        return $options;
    }

    /**
     * @param object $report
     * @param array $params
     * @return array
     */
    public function getExpenseClassOptions($report, array $params): array
    {
        return ExpenseClassScopeService::getOptions(Yii::$app->folioDb, $params['acqUnit'] ?? null);
    }
}
