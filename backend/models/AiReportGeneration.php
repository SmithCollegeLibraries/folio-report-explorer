<?php

namespace app\models;

use yii\db\ActiveRecord;

class AiReportGeneration extends ActiveRecord
{
    public static function tableName()
    {
        return 'ai_report_generations';
    }

    public function rules()
    {
        return [
            [['id', 'conversation_id', 'prompt_fingerprint', 'original_question', 'confidence_evidence_json', 'provenance_json', 'review_reasons_json'], 'required'],
            [['id', 'conversation_id', 'parent_generation_id', 'query_job_id'], 'string', 'max' => 36],
            [['prompt_fingerprint'], 'string', 'max' => 16],
            [['response_mode'], 'string', 'max' => 32],
            [['route'], 'string', 'max' => 128],
            [['route_reason'], 'string', 'max' => 255],
            [['sql_hash'], 'string', 'max' => 64],
            [['execution_mode'], 'in', 'range' => ['deterministic', 'exploratory']],
            [['validation_status'], 'in', 'range' => ['validated', 'exhausted', 'rejected']],
            [['user_id', 'review_required'], 'integer'],
            [[
                'original_question', 'follow_up_context', 'generated_sql', 'assumptions_json',
                'user_notice_json', 'confidence_evidence_json', 'initial_structure_json',
                'final_structure_json', 'provenance_json', 'review_reasons_json', 'created_at',
                'linked_at', 'updated_at',
            ], 'safe'],
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
