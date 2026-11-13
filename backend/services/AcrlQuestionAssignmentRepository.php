<?php

namespace app\services;

use Yii;

/** Persists question-level assignments for published ACRL surveys. */
class AcrlQuestionAssignmentRepository
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

    public function questions(int $year, ?string $sheet = null): array
    {
        $sql = 'SELECT survey_year, sheet, question_id, part, section, prompt, survey_order FROM acrl_questions WHERE survey_year = :year';
        $params = [':year' => $year];
        if ($sheet !== null) {
            $sql .= ' AND sheet = :sheet';
            $params[':sheet'] = $sheet;
        }
        $sql .= " ORDER BY CASE sheet WHEN 'survey' THEN 0 WHEN 'trends' THEN 1 ELSE 2 END, survey_order, question_id, part";
        return $this->db->createCommand($sql, $params)->queryAll();
    }

    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        $row = $this->db->createCommand(
            'SELECT survey_year, sheet, question_id, part, section, prompt, survey_order FROM acrl_questions'
                . ' WHERE survey_year = :year AND sheet = :sheet AND question_id = :questionId AND part = :part LIMIT 1',
            [':year' => $year, ':sheet' => $sheet, ':questionId' => $questionId, ':part' => $part]
        )->queryOne();
        return $row ?: null;
    }

    public function assignments(int $year, string $institutionCode): array
    {
        return $this->db->createCommand(
            'SELECT survey_year, sheet, question_id, part, user_id FROM acrl_question_assignments'
                . ' WHERE institution_code = :institution AND survey_year = :year ORDER BY sheet, question_id, part, user_id',
            [':institution' => $institutionCode, ':year' => $year]
        )->queryAll();
    }

    /** Add one assignment inside the caller's locked-year transaction. */
    public function addQuestionAssignment(int $year, string $sheet, string $questionId, string $part, int $userId, ?int $assignedBy, string $institutionCode): void
    {
        $exists = $this->db->createCommand(
            'SELECT COUNT(*) FROM acrl_question_assignments'
                . ' WHERE institution_code = :institution AND survey_year = :year AND sheet = :sheet AND question_id = :questionId'
                . ' AND part = :part AND user_id = :userId',
            [':institution' => $institutionCode, ':year' => $year, ':sheet' => $sheet, ':questionId' => $questionId, ':part' => $part, ':userId' => $userId]
        )->queryScalar();
        if ((int)$exists > 0) {
            return;
        }
        $this->db->createCommand()->insert('acrl_question_assignments', [
            'institution_code' => $institutionCode,
            'survey_year' => $year,
            'sheet' => $sheet,
            'question_id' => $questionId,
            'part' => $part,
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
        ])->execute();
    }

    public function approvedLinks(int $previousYear, int $currentYear): array
    {
        return $this->db->createCommand(
            "SELECT previous_year, current_year, sheet, previous_question_id, previous_part, current_question_id, current_part"
                . " FROM acrl_question_links WHERE previous_year = :previousYear AND current_year = :currentYear"
                . " AND decision = 'approved' ORDER BY id",
            [':previousYear' => $previousYear, ':currentYear' => $currentYear]
        )->queryAll();
    }

    public function replaceQuestionAssignments(int $year, string $sheet, string $questionId, string $part, array $userIds, int $assignedBy, string $institutionCode, ?callable $validateBeforeReplace = null): void
    {
        $this->withLockedYear($year, function () use ($year, $sheet, $questionId, $part, $userIds, $assignedBy, $institutionCode, $validateBeforeReplace) {
            if ($validateBeforeReplace !== null) {
                $validateBeforeReplace();
            }
            $this->db->createCommand()->delete('acrl_question_assignments', [
                'institution_code' => $institutionCode,
                'survey_year' => $year,
                'sheet' => $sheet,
                'question_id' => $questionId,
                'part' => $part,
            ])->execute();
            $rows = [];
            foreach ($userIds as $userId) {
                $rows[] = [
                    'survey_year' => $year,
                    'institution_code' => $institutionCode,
                    'sheet' => $sheet,
                    'question_id' => $questionId,
                    'part' => $part,
                    'user_id' => $userId,
                    'assigned_by' => $assignedBy,
                ];
            }
            $this->insertQuestionAssignments($rows, $institutionCode);
        });
    }

    public function insertQuestionAssignments(array $rows, string $institutionCode): void
    {
        if (empty($rows)) {
            return;
        }
        $year = (int)$rows[0]['survey_year'];
        $this->withLockedYear($year, function () use ($rows, $institutionCode) {
            foreach ($rows as $row) {
                $row['institution_code'] = $institutionCode;
                $this->db->createCommand()->insert('acrl_question_assignments', $row)->execute();
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

    private function lockYear(int $year): void
    {
        $sql = 'SELECT survey_year FROM acrl_survey_years WHERE survey_year = :year';
        if ($this->db->driverName !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $this->db->createCommand($sql, [':year' => $year])->queryOne();
    }
}
