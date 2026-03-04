<?php

namespace app\models;

use yii\db\ActiveRecord;

class AcrlStatistic extends ActiveRecord
{
    public static function tableName()
    {
        return 'acrl_statistics';
    }

    public function rules()
    {
        return [
            [['category', 'subcategory', 'year'], 'required'],
            [['category', 'subcategory'], 'string', 'max' => 255],
            [['year'], 'integer', 'min' => 1900, 'max' => 3000],
            [['value'], 'number'],
            [['notes'], 'string', 'max' => 255],
        ];
    }

    public static function getAvailableYears()
    {
        return self::find()
            ->select('year')
            ->distinct()
            ->orderBy(['year' => SORT_DESC])
            ->column();
    }
}
