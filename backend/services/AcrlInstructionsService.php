<?php

namespace app\services;

/** Validates browser-parsed instructions and stores linked blocks for a survey year. */
class AcrlInstructionsService
{
    private $repository;
    private $catalogue;

    public function __construct($repository = null, $catalogue = null)
    {
        $this->repository = $repository ?: new AcrlDetailsRepository();
        $this->catalogue = $catalogue ?: ($repository ?: new AcrlAnswerRepository());
    }

    public function replace(int $year, $blocks, $links, string $fileName, string $sha256, string $userId): array
    {
        if (!$this->catalogue->year($year)) {
            throw new \DomainException('The selected ACRL year does not exist.');
        }
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName === '' || strlen($fileName) > 255 || !preg_match('/\.pdf$/i', $fileName)) {
            throw new \InvalidArgumentException('Choose an instructions PDF file.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new \InvalidArgumentException('The PDF checksum is invalid.');
        }
        if (!is_array($blocks) || empty($blocks) || count($blocks) > 1000) {
            throw new \InvalidArgumentException('Provide between 1 and 1,000 instruction blocks.');
        }
        if (!is_array($links) || count($links) > 20000) {
            throw new \InvalidArgumentException('Instruction links must be an array with no more than 20,000 entries.');
        }

        $preparedBlocks = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new \InvalidArgumentException('Every instruction block must contain named fields.');
            }
            $blockKey = trim((string)($block['blockKey'] ?? ''));
            $heading = trim((string)($block['heading'] ?? ''));
            $text = trim((string)($block['text'] ?? ''));
            $kind = (string)($block['kind'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9:_-]{0,79}$/', $blockKey) || $heading === '' || strlen($heading) > 255
                || $text === '' || strlen($text) > 50000 || !in_array($kind, ['question', 'group', 'definition'], true)) {
                throw new \InvalidArgumentException('Instruction blocks need a unique key, heading, text, and supported kind.');
            }
            if (isset($preparedBlocks[$blockKey])) {
                throw new \InvalidArgumentException('Instruction block keys must be unique.');
            }
            $preparedBlocks[$blockKey] = [
                'block_key' => $blockKey,
                'heading' => $heading,
                'block_text' => $text,
                'block_kind' => $kind,
            ];
        }

        $preparedLinks = [];
        $seenLinks = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                throw new \InvalidArgumentException('Every instruction link must contain named fields.');
            }
            $sheet = (string)($link['sheet'] ?? 'survey');
            $questionId = trim((string)($link['question_id'] ?? ''));
            $part = trim((string)($link['part'] ?? ''));
            $blockKey = trim((string)($link['blockKey'] ?? $link['block_key'] ?? ''));
            if (!in_array($sheet, ['survey', 'trends'], true) || $questionId === '' || strlen($questionId) > 32 || strlen($part) > 1
                || !isset($preparedBlocks[$blockKey]) || !$this->catalogue->question($year, $sheet, $questionId, $part)) {
                throw new \InvalidArgumentException('Instruction links must point to an existing question and block.');
            }
            $key = $sheet . ':' . $questionId . ':' . $part . ':' . $blockKey;
            if (isset($seenLinks[$key])) continue;
            $seenLinks[$key] = true;
            $preparedLinks[] = ['sheet' => $sheet, 'question_id' => $questionId, 'part' => $part, 'block_key' => $blockKey];
        }

        if (empty($preparedLinks)) {
            throw new \InvalidArgumentException('At least one instruction block must be linked to a question.');
        }
        $this->repository->replaceInstructionData($year, array_values($preparedBlocks), $preparedLinks, $fileName, strtolower($sha256), $userId);
        return ['success' => true, 'surveyYear' => $year, 'blockCount' => count($preparedBlocks), 'linkCount' => count($preparedLinks)];
    }

    public function forQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        if (!in_array($sheet, ['survey', 'trends'], true) || !$this->catalogue->question($year, $sheet, $questionId, $part)) {
            throw new \DomainException('The selected ACRL question part does not exist.');
        }
        return $this->repository->instructionBlocksForQuestion($year, $sheet, $questionId, $part);
    }
}
