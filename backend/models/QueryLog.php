<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * QueryLog model — audit trail of all executed queries.
 *
 * @property int $id
 * @property string $sql_text
 * @property string $params JSON
 * @property string $source builder|nl|manual|report
 * @property string $data_source folio|local
 * @property int    $user_id
 * @property int $row_count
 * @property int $execution_time_ms
 * @property string $error_message
 * @property string $created_at
 */
class QueryLog extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'query_log';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['sql_text'], 'required'],
            [['sql_text', 'error_message'], 'string'],
            [['params'], 'string'],
            [['source'], 'in', 'range' => ['builder', 'nl', 'manual', 'report']],
            [['data_source'], 'in', 'range' => ['folio', 'local']],
            [['row_count', 'execution_time_ms', 'user_id'], 'integer'],
        ];
    }
}
