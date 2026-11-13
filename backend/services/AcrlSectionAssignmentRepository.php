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

    public function assignments(int $year, string $institutionCode): array
    {
        return $this->db->createCommand(
            'SELECT section, user_id FROM acrl_section_assignments WHERE institution_code = :institution AND survey_year = :year ORDER BY section, user_id',
            [':institution' => $institutionCode, ':year' => $year]
        )->queryAll();
    }

    /** Add one assignment inside the caller's locked-year transaction. */
    public function addSectionAssignment(int $year, string $section, int $userId, ?int $assignedBy, string $institutionCode): void
    {
        $exists = $this->db->createCommand(
            'SELECT COUNT(*) FROM acrl_section_assignments'
                . ' WHERE institution_code = :institution AND survey_year = :year AND section = :section AND user_id = :userId',
            [':institution' => $institutionCode, ':year' => $year, ':section' => $section, ':userId' => $userId]
        )->queryScalar();
        if ((int)$exists > 0) {
            return;
        }
        $this->db->createCommand()->insert('acrl_section_assignments', [
            'institution_code' => $institutionCode,
            'survey_year' => $year,
            'section' => $section,
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
        ])->execute();
    }

    public function replaceSections(int $year, array $assignmentsBySection, int $assignedBy, string $institutionCode): void
    {
        $this->withLockedYear($year, function () use ($year, $assignmentsBySection, $assignedBy, $institutionCode) {
            foreach ($assignmentsBySection as $section => $userIds) {
                $this->db->createCommand()->delete('acrl_section_assignments', [
                    'institution_code' => $institutionCode,
                    'survey_year' => $year,
                    'section' => $section,
                ])->execute();
                foreach ($userIds as $userId) {
                    $this->db->createCommand()->insert('acrl_section_assignments', [
                        'institution_code' => $institutionCode,
                        'survey_year' => $year,
                        'section' => $section,
                        'user_id' => $userId,
                        'assigned_by' => $assignedBy,
                    ])->execute();
                }
            }
        });
    }

    public function withLockedYear(int $year, callable $callback)
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->lockYear($year);
            $result = $callback();
            $transaction->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function fillUnassignedSections(int $year, array $assignmentsBySection, int $assignedBy, string $institutionCode, ?callable $afterCopy = null): array
    {
        return $this->withLockedYear($year, function () use ($year, $assignmentsBySection, $assignedBy, $institutionCode, $afterCopy) {
            $assignedSections = $this->assignedSections($year, $institutionCode);
            $copiedSections = [];
            $skippedSections = [];
            foreach ($assignmentsBySection as $section => $userIds) {
                if (isset($assignedSections[$section]) || empty($userIds)) {
                    $skippedSections[] = $section;
                    continue;
                }
                $this->insertAssignments($year, $section, $userIds, $assignedBy, $institutionCode);
                $copiedSections[] = $section;
            }
            if ($afterCopy !== null) {
                $afterCopy();
            }
            return [
                'copiedSections' => $copiedSections,
                'skippedSections' => $skippedSections,
                'alreadyAssigned' => false,
            ];
        });
    }

    public function copySectionsIfYearEmpty(int $year, array $assignmentsBySection, int $assignedBy, string $institutionCode, ?callable $afterCopy = null): array
    {
        return $this->withLockedYear($year, function () use ($year, $assignmentsBySection, $assignedBy, $institutionCode, $afterCopy) {
            $assignedSections = $this->assignedSections($year, $institutionCode);
            if (!empty($assignedSections)) {
                if ($afterCopy !== null) {
                    $afterCopy();
                }
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
                $this->insertAssignments($year, $section, $userIds, $assignedBy, $institutionCode);
                $copiedSections[] = $section;
            }
            if ($afterCopy !== null) {
                $afterCopy();
            }
            return [
                'copiedSections' => $copiedSections,
                'skippedSections' => [],
                'alreadyAssigned' => false,
            ];
        });
    }

    private function lockYear(int $year): void
    {
        $this->db->createCommand(
            'SELECT survey_year FROM acrl_survey_years WHERE survey_year = :year FOR UPDATE',
            [':year' => $year]
        )->queryOne();
    }

    private function assignedSections(int $year, string $institutionCode): array
    {
        $sections = [];
        foreach ($this->assignments($year, $institutionCode) as $row) {
            $sections[$row['section']] = true;
        }
        return $sections;
    }

    private function insertAssignments(int $year, string $section, array $userIds, int $assignedBy, string $institutionCode): void
    {
        foreach ($userIds as $userId) {
            $this->db->createCommand()->insert('acrl_section_assignments', [
                'institution_code' => $institutionCode,
                'survey_year' => $year,
                'section' => $section,
                'user_id' => $userId,
                'assigned_by' => $assignedBy,
            ])->execute();
        }
    }
}
