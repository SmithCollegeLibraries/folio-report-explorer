<?php

namespace app\services;

use Yii;

/** Database access for annual ACRL answers, status, imports, and audit history. */
class AcrlAnswerRepository
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    public function publishedYears(): array
    {
        return $this->db->createCommand(
            "SELECT survey_year FROM acrl_survey_years WHERE status = 'published' ORDER BY survey_year DESC"
        )->queryColumn();
    }

    public function year(int $year): ?array
    {
        $row = $this->db->createCommand(
            'SELECT survey_year, status FROM acrl_survey_years WHERE survey_year = :year',
            [':year' => $year]
        )->queryOne();
        return $row ?: null;
    }

    public function questions(int $year, string $sheet = 'survey'): array
    {
        return $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, section, prompt, answer_type, required_status, calculated_from, survey_order '
            . 'FROM acrl_questions WHERE survey_year = :year AND sheet = :sheet ORDER BY survey_order, question_id, part',
            [':year' => $year, ':sheet' => $sheet]
        )->queryAll();
    }

    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, section, prompt, answer_type, required_status, calculated_from, survey_order '
            . 'FROM acrl_questions WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part',
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryOne();
        return $row ?: null;
    }

    public function answers(int $year, string $sheet = 'survey'): array
    {
        return $this->db->createCommand(
            'SELECT survey_year, sheet, question_id, part, value_text, value_number, note, source, source_label, updated_by, updated_at '
            . 'FROM acrl_answers WHERE survey_year = :year AND sheet = :sheet',
            [':year' => $year, ':sheet' => $sheet]
        )->queryAll();
    }

    public function answer(int $year, string $sheet, string $questionId, string $part): ?array
    {
        $row = $this->db->createCommand(
            'SELECT survey_year, sheet, question_id, part, value_text, value_number, note, source, source_label, updated_by, updated_at '
            . 'FROM acrl_answers WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part',
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryOne();
        return $row ?: null;
    }

    public function approvedLinks(int $year): array
    {
        return $this->db->createCommand(
            "SELECT previous_year, current_year, sheet, previous_question_id, previous_part, current_question_id, current_part, match_kind "
            . "FROM acrl_question_links WHERE current_year = :year AND sheet = 'survey' AND decision = 'approved' ORDER BY id",
            [':year' => $year]
        )->queryAll();
    }

    public function sectionStatuses(int $year): array
    {
        return $this->db->createCommand(
            'SELECT section, status, set_by, set_at, reopened_by, reopened_at FROM acrl_section_status WHERE survey_year = :year',
            [':year' => $year]
        )->queryAll();
    }

    public function sectionStatus(int $year, string $section): ?array
    {
        $row = $this->db->createCommand(
            'SELECT section, status, set_by, set_at, reopened_by, reopened_at FROM acrl_section_status WHERE survey_year = :year AND section = :section',
            [':year' => $year, ':section' => $section]
        )->queryOne();
        return $row ?: null;
    }

    public function saveAnswer(array $question, array $values, ?string $note, string $userId, ?string $expectedUpdatedAt, string $source = 'manual', ?string $sourceLabel = null): array
    {
        $key = [
            'survey_year' => (int)$question['survey_year'],
            'sheet' => $question['sheet'],
            'question_id' => $question['question_id'],
            'part' => $question['part'],
        ];
        $transaction = $this->db->beginTransaction();
        try {
            $old = $this->db->createCommand(
                'SELECT * FROM acrl_answers WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part FOR UPDATE',
                [':year' => $key['survey_year'], ':sheet' => $key['sheet'], ':question_id' => $key['question_id'], ':part' => $key['part']]
            )->queryOne();
            if ($old === false) {
                $old = null;
            }
            if ($old && ($expectedUpdatedAt === null || $expectedUpdatedAt !== $old['updated_at'])) {
                $transaction->rollBack();
                return ['conflict' => true, 'current' => $old];
            }
            if (!$old && $expectedUpdatedAt !== null) {
                $transaction->rollBack();
                return ['conflict' => true, 'current' => null];
            }

            $new = array_merge($key, $values, [
                'note' => $note,
                'source' => $source,
                'source_label' => $sourceLabel,
                'updated_by' => $userId,
            ]);
            if ($old) {
                $this->db->createCommand()->update('acrl_answers', $new, $key)->execute();
            } else {
                try {
                    $this->db->createCommand()->insert('acrl_answers', $new)->execute();
                } catch (\yii\db\IntegrityException $exception) {
                    if (!$this->isDuplicateKey($exception)) {
                        throw $exception;
                    }
                    $transaction->rollBack();
                    return ['conflict' => true, 'current' => $this->answer($key['survey_year'], $key['sheet'], $key['question_id'], $key['part'])];
                }
            }
            $this->appendHistory($key, $old, $new, $userId);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return ['conflict' => false, 'current' => $this->answer($key['survey_year'], $key['sheet'], $key['question_id'], $key['part'])];
    }

    public function importAnswers(array $items, string $userId, string $sourceLabel, bool $overwrite): array
    {
        $transaction = $this->db->beginTransaction();
        $counts = ['imported' => 0, 'unchanged' => 0, 'skipped' => 0];
        try {
            foreach ($items as $item) {
                $key = [
                    'survey_year' => (int)$item['survey_year'],
                    'sheet' => $item['sheet'],
                    'question_id' => $item['question_id'],
                    'part' => $item['part'],
                ];
                $old = $this->db->createCommand(
                    'SELECT * FROM acrl_answers WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part FOR UPDATE',
                    [':year' => $key['survey_year'], ':sheet' => $key['sheet'], ':question_id' => $key['question_id'], ':part' => $key['part']]
                )->queryOne();
                if ($old === false) {
                    $old = null;
                }
                if ($old && $this->sameValues($old, $item)) {
                    $counts['unchanged'] += 1;
                    continue;
                }
                if ($old && !$overwrite && !$this->isEmptyAnswer($old)) {
                    $counts['skipped'] += 1;
                    continue;
                }
                $new = array_merge($key, [
                    'value_text' => $item['value_text'],
                    'value_number' => $item['value_number'],
                    'note' => $item['note'],
                    'source' => 'import',
                    'source_label' => $sourceLabel,
                    'updated_by' => $userId,
                ]);
                if ($old) {
                    $this->db->createCommand()->update('acrl_answers', $new, $key)->execute();
                } else {
                    $this->db->createCommand()->insert('acrl_answers', $new)->execute();
                }
                $this->appendHistory($key, $old, $new, $userId);
                $counts['imported'] += 1;
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
        return $counts;
    }

    public function setSectionStatus(int $year, string $section, string $status, string $userId): ?array
    {
        if ($status === 'ready') {
            $sql = "INSERT INTO acrl_section_status (survey_year, section, status, set_by, set_at) VALUES (:year, :section, 'ready', :user, CURRENT_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE status = 'ready', set_by = VALUES(set_by), set_at = VALUES(set_at), reopened_by = NULL, reopened_at = NULL";
        } else {
            $sql = "INSERT INTO acrl_section_status (survey_year, section, status, reopened_by, reopened_at) VALUES (:year, :section, 'open', :user, CURRENT_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE status = 'open', reopened_by = VALUES(reopened_by), reopened_at = VALUES(reopened_at)";
        }
        $this->db->createCommand($sql, [':year' => $year, ':section' => $section, ':user' => $userId])->execute();
        return $this->sectionStatus($year, $section);
    }

    private function appendHistory(array $key, ?array $old, array $new, string $userId): void
    {
        $this->db->createCommand()->insert('acrl_answer_history', array_merge($key, [
            'old_value_text' => $old['value_text'] ?? null,
            'old_value_number' => $old['value_number'] ?? null,
            'old_note' => $old['note'] ?? null,
            'old_source' => $old['source'] ?? null,
            'new_value_text' => $new['value_text'],
            'new_value_number' => $new['value_number'],
            'new_note' => $new['note'],
            'new_source' => $new['source'],
            'changed_by' => $userId,
        ]))->execute();
    }

    private function isDuplicateKey(\yii\db\IntegrityException $exception): bool
    {
        return isset($exception->errorInfo[1]) && (int)$exception->errorInfo[1] === 1062;
    }

    private function sameValues(array $old, array $new): bool
    {
        $oldNumber = $old['value_number'] ?? null;
        $newNumber = $new['value_number'] ?? null;
        $sameNumber = $oldNumber === null && $newNumber === null;
        if ($oldNumber !== null && $newNumber !== null && is_numeric($oldNumber) && is_numeric($newNumber)) {
            $sameNumber = round((float)$oldNumber, 2) === round((float)$newNumber, 2);
        }
        return ($old['value_text'] ?? null) === $new['value_text']
            && $sameNumber
            && ($old['note'] ?? null) === $new['note'];
    }

    private function isEmptyAnswer(array $answer): bool
    {
        return ($answer['value_text'] ?? null) === null || ($answer['value_text'] ?? '') === '';
    }
}
