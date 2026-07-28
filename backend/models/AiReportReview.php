<?php

namespace app\models;

use yii\db\ActiveRecord;

class AiReportReview extends ActiveRecord
{
    public static function tableName()
    {
        return 'ai_report_reviews';
    }

    public function rules()
    {
        return [
            [['id', 'generation_id'], 'required'],
            [['id', 'generation_id', 'superseded_by_job_id'], 'string', 'max' => 36],
            [['status'], 'in', 'range' => ['pending', 'in_review', 'resolved', 'dismissed']],
            [['disposition'], 'in', 'range' => [
                'acceptable', 'assumption_change', 'deterministic_candidate', 'generation_defect',
                'data_unavailable', 'specialist_interpretation',
            ]],
            [['advisory_state'], 'in', 'range' => ['none', 'cautioned', 'superseded']],
            [['reviewed_by'], 'integer'],
            [['administrator_notes', 'claimed_at', 'resolved_at', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    public static function generateUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
