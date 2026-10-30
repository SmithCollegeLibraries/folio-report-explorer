<?php

namespace app\services;

use app\models\ReportTemplate;
use app\models\SavedQuery;

class SavedQueryPromotionService
{
    /**
     * Find an active report that exactly matches a saved query's name and SQL.
     */
    public static function findActiveMatch(SavedQuery $query)
    {
        $matches = ReportTemplate::find()->where([
            'is_active' => 1,
            'name' => $query->name,
            'sql_template' => $query->generated_sql,
        ])->all();

        foreach ($matches as $report) {
            if ($report->name === $query->name && $report->sql_template === $query->generated_sql) {
                return $report;
            }
        }

        return null;
    }

    /**
     * Build the report for a saved query, preserving the existing slug and field rules.
     */
    public static function buildReport(SavedQuery $query)
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($query->name));
        $slug = trim($slug, '-');
        if (ReportTemplate::findOne(['slug' => $slug])) {
            $slug .= '-' . time();
        }

        $report = new ReportTemplate();
        $report->slug = $slug;
        $report->name = $query->name;
        $report->description = $query->description ?: ($query->nl_prompt ?: '');
        $report->category = 'other';
        $report->sql_template = $query->generated_sql;
        $report->parameters = '[]';
        $report->default_limit = 100;
        $report->is_active = 1;
        $report->created_by = $query->source === 'nl' ? 'ai' : 'manual';

        return $report;
    }
}
