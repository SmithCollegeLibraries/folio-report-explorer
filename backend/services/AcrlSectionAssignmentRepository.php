<?php

namespace app\services;

use Yii;

/** Persists annual ACRL section assignments. */
class AcrlSectionAssignmentRepository
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    public function isPublishedYear(int $year): bool
    {
        return (bool)$this->db->createCommand(
            "SELECT COUNT(*) FROM acrl_survey_years WHERE survey_year = :year AND status = 'published'",
            [':year' => $year]
        )->queryScalar();
    }

    public function sections(int $year): array
    {
        return $this->db->createCommand(
            'SELECT section FROM acrl_questions WHERE survey_year = :year GROUP BY section ORDER BY MIN(survey_order), section',
            [':year' => $year]
        )->queryColumn();
    }

    public function assignments(int $year): array
    {
        return $this->db->createCommand(
            'SELECT section, user_id FROM acrl_section_assignments WHERE survey_year = :year ORDER BY section, user_id',
            [':year' => $year]
        )->queryAll();
    }

    public function replaceSections(int $year, array $assignmentsBySection, int $assignedBy): void
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->lockYear($year);
            foreach ($assignmentsBySection as $section => $userIds) {
                $this->db->createCommand()->delete('acrl_section_assignments', [
                    'survey_year' => $year,
                    'section' => $section,
                ])->execute();
                foreach ($userIds as $userId) {
                    $this->db->createCommand()->insert('acrl_section_assignments', [
                        'survey_year' => $year,
                        'section' => $section,
                        'user_id' => $userId,
                        'assigned_by' => $assignedBy,
                    ])->execute();
                }
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function fillUnassignedSections(int $year, array $assignmentsBySection, int $assignedBy): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->lockYear($year);
            $assignedSections = $this->assignedSections($year);
            $copiedSections = [];
            $skippedSections = [];
            foreach ($assignmentsBySection as $section => $userIds) {
                if (isset($assignedSections[$section]) || empty($userIds)) {
                    $skippedSections[] = $section;
                    continue;
                }
                $this->insertAssignments($year, $section, $userIds, $assignedBy);
                $copiedSections[] = $section;
            }
            $transaction->commit();
            return [
                'copiedSections' => $copiedSections,
                'skippedSections' => $skippedSections,
                'alreadyAssigned' => false,
            ];
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function copySectionsIfYearEmpty(int $year, array $assignmentsBySection, int $assignedBy): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->lockYear($year);
            $assignedSections = $this->assignedSections($year);
            if (!empty($assignedSections)) {
                $transaction->commit();
                return [
                    'copiedSections' => [],
                    'skippedSections' => array_keys($assignedSections),
                    'alreadyAssigned' => true,
                ];
            }
            $copiedSections = [];
            foreach ($assignmentsBySection as $section => $userIds) {
                if (empty($userIds)) {
                    continue;
                }
                $this->insertAssignments($year, $section, $userIds, $assignedBy);
                $copiedSections[] = $section;
            }
            $transaction->commit();
            return [
                'copiedSections' => $copiedSections,
                'skippedSections' => [],
                'alreadyAssigned' => false,
            ];
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function lockYear(int $year): void
    {
        $this->db->createCommand(
            'SELECT survey_year FROM acrl_survey_years WHERE survey_year = :year FOR UPDATE',
            [':year' => $year]
        )->queryOne();
    }

    private function assignedSections(int $year): array
    {
        $sections = [];
        foreach ($this->assignments($year) as $row) {
            $sections[$row['section']] = true;
        }
        return $sections;
    }

    private function insertAssignments(int $year, string $section, array $userIds, int $assignedBy): void
    {
        foreach ($userIds as $userId) {
            $this->db->createCommand()->insert('acrl_section_assignments', [
                'survey_year' => $year,
                'section' => $section,
                'user_id' => $userId,
                'assigned_by' => $assignedBy,
            ])->execute();
        }
    }
}
