<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class ExpenseAllocation extends ActiveRecord
{
    public static function tableName()
    {
        return 'report_expense_allocations';
    }

    public function rules()
    {
        return [
            [['fiscal_year', 'expense_class_code', 'allocation_amount'], 'required'],
            [['fiscal_year'], 'integer', 'min' => 1900, 'max' => 3000],
            [['allocation_amount'], 'number', 'min' => 0],
            [['expense_class_code'], 'string', 'max' => 32],
            [['expense_class_code'], 'filter', 'filter' => function ($value) {
                return strtoupper(trim((string) $value));
            }],
        ];
    }

    public static function getAvailableYears($prefix)
    {
        return \app\services\ExpenseAllocationService::availableYears(Yii::$app->db->getMasterPdo(), $prefix);
    }

    public static function copyFromPreviousYear($targetYear, $prefix)
    {
        return \app\services\ExpenseAllocationService::copyFromPreviousYear(
            Yii::$app->db->getMasterPdo(),
            $targetYear,
            $prefix
        );
    }
}
