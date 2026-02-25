<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * SavedQuery model — stores query builder state in MySQL.
 *
 * @property int $id
 * @property string $name
 * @property int    $user_id
 * @property string $description
 * @property string $query_definition JSON
 * @property string $generated_sql
 * @property string $source          'builder' or 'nl'
 * @property string $nl_prompt       Original NL question (source=nl)
 * @property int    $is_pinned       1 if pinned to dashboard
 * @property string $created_at
 * @property string $updated_at
 */
class SavedQuery extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'saved_queries';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'query_definition'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['description', 'generated_sql', 'nl_prompt'], 'string'],
            [['query_definition'], 'string'],
            [['source'], 'in', 'range' => ['builder', 'nl']],
            [['source'], 'default', 'value' => 'builder'],
            [['is_pinned', 'user_id'], 'integer'],
            [['is_pinned'], 'default', 'value' => 0],
        ];
    }

    /**
     * Get the query definition as an array.
     * @return array
     */
    public function getDefinition()
    {
        return json_decode($this->query_definition, true) ?: [];
    }

    /**
     * Set the query definition from an array.
     * @param array $def
     */
    public function setDefinition(array $def)
    {
        $this->query_definition = json_encode($def);
    }
}
