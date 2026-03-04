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
            [['expense_class_code'], 'string', 'max' => 10],
            [['expense_class_code'], 'filter', 'filter' => function ($value) {
                return strtoupper(trim((string) $value));
            }],
        ];
    }

    public static function getAvailableYears()
    {
        return self::find()
            ->select('fiscal_year')
            ->distinct()
            ->orderBy(['fiscal_year' => SORT_DESC])
            ->column();
    }

    public static function copyFromPreviousYear($targetYear)
    {
        $sourceYear = (int) $targetYear - 1;
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $sourceRows = self::find()->where(['fiscal_year' => $sourceYear])->all();
            if (empty($sourceRows)) {
                $transaction->rollBack();
                return ['copied' => 0, 'skipped' => 0, 'sourceYear' => $sourceYear, 'foundSource' => false];
            }

            $copied = 0;
            $skipped = 0;

            foreach ($sourceRows as $src) {
                $existing = self::findOne([
                    'fiscal_year' => (int) $targetYear,
                    'expense_class_code' => $src->expense_class_code,
                ]);

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $row = new self();
                $row->fiscal_year = (int) $targetYear;
                $row->expense_class_code = $src->expense_class_code;
                $row->allocation_amount = $src->allocation_amount;
                if ($row->save()) {
                    $copied++;
                }
            }

            $transaction->commit();
            return ['copied' => $copied, 'skipped' => $skipped, 'sourceYear' => $sourceYear, 'foundSource' => true];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
