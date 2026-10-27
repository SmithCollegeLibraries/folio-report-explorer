<?php

namespace app\services;

use Yii;

/** Database operations for ACRL instruction blocks and collection notes. */
class AcrlDetailsRepository
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    public function replaceInstructionData(int $year, array $blocks, array $links, string $fileName, string $sha256, string $userId): void
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand()->delete('acrl_instruction_links', ['survey_year' => $year])->execute();
            $this->db->createCommand()->delete('acrl_instruction_blocks', ['survey_year' => $year])->execute();
            foreach ($blocks as $block) {
                $this->db->createCommand()->insert('acrl_instruction_blocks', [
                    'survey_year' => $year,
                    'block_key' => $block['block_key'],
                    'heading' => $block['heading'],
                    'block_text' => $block['block_text'],
                    'block_kind' => $block['block_kind'],
                    'source_file' => $fileName,
                    'source_sha256' => $sha256,
                    'created_by' => $userId,
                ])->execute();
            }
            foreach ($links as $link) {
                $this->db->createCommand()->insert('acrl_instruction_links', [
                    'survey_year' => $year,
                    'sheet' => $link['sheet'],
                    'question_id' => $link['question_id'],
                    'part' => $link['part'],
                    'block_key' => $link['block_key'],
                ])->execute();
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
    }

    public function instructionBlocksForQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        return $this->db->createCommand(
            "SELECT b.block_key AS blockKey, b.heading, b.block_text AS text, b.block_kind AS kind, b.source_file AS sourceFile, b.source_sha256 AS sourceSha256 "
            . 'FROM acrl_instruction_links l INNER JOIN acrl_instruction_blocks b '
            . 'ON b.survey_year = l.survey_year AND b.block_key = l.block_key '
            . 'WHERE l.survey_year = :year AND l.sheet = :sheet AND l.question_id = :question_id AND l.part = :part '
            . "ORDER BY CASE b.block_kind WHEN 'question' THEN 0 WHEN 'group' THEN 1 ELSE 2 END, l.block_key",
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryAll();
    }

    public function hasQuestionNote(int $year, string $sheet, string $questionId, string $part, string $note, string $source, int $originYear): bool
    {
        return (bool)$this->db->createCommand(
            'SELECT 1 FROM acrl_question_notes WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id '
            . 'AND part = :part AND note = :note AND source = :source AND origin_year = :origin_year LIMIT 1',
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part, ':note' => $note, ':source' => $source, ':origin_year' => $originYear]
        )->queryScalar() !== false;
    }

    public function createQuestionNote(array $values): array
    {
        $this->db->createCommand()->insert('acrl_question_notes', $values)->execute();
        return $this->findQuestionNote((int)$this->db->getLastInsertID());
    }

    public function findQuestionNote(int $id): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, note, source, origin_year, created_by, created_at, edited_by, edited_at '
            . 'FROM acrl_question_notes WHERE id = :id',
            [':id' => $id]
        )->queryOne();
        return $row ?: null;
    }

    public function updateQuestionNote(int $id, array $changes, string $userId): ?array
    {
        $changes['edited_by'] = $userId;
        $changes['edited_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('acrl_question_notes', $changes, ['id' => $id])->execute();
        return $this->findQuestionNote($id);
    }

    public function deleteQuestionNote(int $id): bool
    {
        return $this->db->createCommand()->delete('acrl_question_notes', ['id' => $id])->execute() > 0;
    }

    public function questionNotesForKey(int $year, string $sheet, string $questionId, string $part): array
    {
        return $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, note, source, origin_year, created_by, created_at, edited_by, edited_at '
            . 'FROM acrl_question_notes WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part '
            . 'ORDER BY created_at DESC, id DESC',
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryAll();
    }

    public function approvedPreviousQuestions(int $year, string $sheet, string $questionId, string $part): array
    {
        return $this->db->createCommand(
            "SELECT previous_year, previous_question_id AS question_id, previous_part AS part FROM acrl_question_links "
            . "WHERE current_year = :year AND sheet = :sheet AND current_question_id = :question_id AND current_part = :part AND decision = 'approved' ORDER BY id",
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryAll();
    }

    public function questionNoteKeysAndLinksThroughYear(int $year): array
    {
        $notes = $this->db->createCommand(
            'SELECT DISTINCT survey_year, sheet, question_id, part FROM acrl_question_notes WHERE survey_year <= :year',
            [':year' => $year]
        )->queryAll();
        $links = $this->db->createCommand(
            "SELECT previous_year, current_year, sheet, previous_question_id, previous_part, current_question_id, current_part "
            . "FROM acrl_question_links WHERE current_year <= :year AND decision = 'approved' ORDER BY current_year, id",
            [':year' => $year]
        )->queryAll();
        return ['notes' => $notes, 'links' => $links];
    }
}
