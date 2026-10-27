<?php

$servicePath = __DIR__ . '/../services/AcrlInstructionsService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FAIL: ACRL instruction blocks must validate, replace, and link to catalogue keys.\n");
    exit(1);
}
require_once $servicePath;

function acrlInstructionsAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class AcrlInstructionsFakeRepository
{
    public $years = [2026 => ['survey_year' => 2026, 'status' => 'published']];
    public $questions = [
        '2026:survey:40:' => ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '40', 'part' => ''],
        '2026:survey:1:A' => ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '1', 'part' => 'A'],
    ];
    public $saved = [];
    public $saveCount = 0;

    public function year(int $year): ?array { return $this->years[$year] ?? null; }

    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        return $this->questions[$year . ':' . $sheet . ':' . $questionId . ':' . $part] ?? null;
    }

    public function replaceInstructionData(int $year, array $blocks, array $links, string $fileName, string $sha256, string $userId): void
    {
        $this->saveCount += 1;
        $this->saved = compact('year', 'blocks', 'links', 'fileName', 'sha256', 'userId');
    }

    public function instructionBlocksForQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        $questionKey = $sheet . ':' . $questionId . ':' . $part;
        return array_values(array_filter($this->saved['links'] ?? [], function ($link) use ($questionKey) {
            return $link['key'] === $questionKey;
        }));
    }
}

$repository = new AcrlInstructionsFakeRepository();
$service = new \app\services\AcrlInstructionsService($repository);
$blocks = [
    ['blockKey' => 'question:40', 'heading' => 'Q40. Physical book titles', 'text' => 'Count searchable titles.', 'kind' => 'question'],
    ['blockKey' => 'group:1a-6a', 'heading' => '1A–6A', 'text' => 'Use the first staffing breakdown.', 'kind' => 'group'],
    ['blockKey' => 'definition:title', 'heading' => 'Title', 'text' => 'One title may have several physical copies.', 'kind' => 'definition'],
];
$links = [
    ['sheet' => 'survey', 'question_id' => '40', 'part' => '', 'blockKey' => 'question:40'],
    ['sheet' => 'survey', 'question_id' => '40', 'part' => '', 'blockKey' => 'definition:title'],
    ['sheet' => 'survey', 'question_id' => '1', 'part' => 'A', 'blockKey' => 'group:1a-6a'],
];
$sha256 = str_repeat('a', 64);
$saved = $service->replace(2026, $blocks, $links, '2026-instructions.pdf', $sha256, 'admin-1');
acrlInstructionsAssert($saved['blockCount'] === 3 && $saved['linkCount'] === 3, 'Saving must persist each block and question link.');
acrlInstructionsAssert($repository->saved['fileName'] === '2026-instructions.pdf' && $repository->saved['userId'] === 'admin-1', 'Instruction metadata must preserve the PDF source and administrator.');

$service->replace(2026, [$blocks[0]], [$links[0]], 'replacement.pdf', $sha256, 'admin-1');
acrlInstructionsAssert($repository->saveCount === 2 && count($repository->saved['blocks']) === 1, 'Re-upload must replace the year’s previous instruction set.');

try {
    $service->replace(2026, $blocks, [$links[0], ['sheet' => 'survey', 'question_id' => '999', 'part' => '', 'blockKey' => 'question:40']], 'bad.pdf', $sha256, 'admin-1');
    acrlInstructionsAssert(false, 'Unknown catalogue question keys must be rejected.');
} catch (InvalidArgumentException $exception) {
    acrlInstructionsAssert($repository->saveCount === 2, 'Invalid links must not replace saved instructions.');
}

echo "PASS: ACRL instructions replace atomically and validate linked question keys\n";
