<?php

require_once __DIR__ . '/../services/AcrlEntryRulesService.php';
require_once __DIR__ . '/../services/AcrlHistoryService.php';

use app\services\AcrlHistoryService;

class AcrlHistoryTestRepository
{
    public function answers(int $year, string $sheet = 'survey'): array
    {
        if ($sheet !== 'survey') {
            return [];
        }
        $answers = [
            2025 => [
                ['question_id' => '40', 'part' => 'A', 'value_text' => '100', 'value_number' => '100', 'note' => 'Prior note A'],
                ['question_id' => '40', 'part' => 'B', 'value_text' => '200', 'value_number' => '200', 'note' => null],
                ['question_id' => '0A', 'part' => '', 'value_text' => 'no', 'value_number' => null, 'note' => null],
            ],
            2024 => [
                ['question_id' => '40', 'part' => 'A', 'value_text' => '50', 'value_number' => '50', 'note' => null],
                ['question_id' => '40', 'part' => 'B', 'value_text' => '150', 'value_number' => '150', 'note' => 'Older note B'],
                ['question_id' => '0A', 'part' => '', 'value_text' => 'yes', 'value_number' => null, 'note' => null],
            ],
            2023 => [
                ['question_id' => '40', 'part' => 'A', 'value_text' => '20', 'value_number' => '20', 'note' => null],
                ['question_id' => '40', 'part' => 'B', 'value_text' => '80', 'value_number' => '80', 'note' => null],
                ['question_id' => '0A', 'part' => '', 'value_text' => 'no', 'value_number' => null, 'note' => null],
            ],
        ];
        return $answers[$year] ?? [];
    }

    public function approvedLinks(int $year): array
    {
        $links = [
            2026 => [
                ['previous_question_id' => '40', 'previous_part' => 'A', 'current_question_id' => '40', 'current_part' => 'B', 'match_kind' => 'merged'],
                ['previous_question_id' => '40', 'previous_part' => 'B', 'current_question_id' => '40', 'current_part' => 'B', 'match_kind' => 'merged'],
                ['previous_question_id' => '0A', 'previous_part' => '', 'current_question_id' => '0A', 'current_part' => '', 'match_kind' => 'same'],
            ],
            2025 => [
                ['previous_question_id' => '40', 'previous_part' => 'A', 'current_question_id' => '40', 'current_part' => 'A', 'match_kind' => 'same'],
                ['previous_question_id' => '40', 'previous_part' => 'B', 'current_question_id' => '40', 'current_part' => 'B', 'match_kind' => 'same'],
                ['previous_question_id' => '0A', 'previous_part' => '', 'current_question_id' => '0A', 'current_part' => '', 'match_kind' => 'same'],
            ],
            2024 => [
                ['previous_question_id' => '40', 'previous_part' => 'A', 'current_question_id' => '40', 'current_part' => 'A', 'match_kind' => 'same'],
                ['previous_question_id' => '40', 'previous_part' => 'B', 'current_question_id' => '40', 'current_part' => 'B', 'match_kind' => 'same'],
                ['previous_question_id' => '0A', 'previous_part' => '', 'current_question_id' => '0A', 'current_part' => '', 'match_kind' => 'same'],
            ],
        ];
        return $links[$year] ?? [];
    }
}

function acrlHistoryAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$service = new AcrlHistoryService(new AcrlHistoryTestRepository());
$questions = [
    ['sheet' => 'survey', 'question_id' => '40', 'part' => 'B'],
    ['sheet' => 'survey', 'question_id' => '0A', 'part' => ''],
    ['sheet' => 'survey', 'question_id' => '99', 'part' => ''],
    ['sheet' => 'trends', 'question_id' => 'T1', 'part' => ''],
];
$history = $service->forQuestions(2026, $questions);
$numbers = $history['survey:40:B'];
acrlHistoryAssert(array_column($numbers, 'year') === [2025, 2024, 2023], 'History must include the three prior fiscal years in order.');
acrlHistoryAssert(array_column($numbers, 'value') === [300.0, 200.0, 100.0], 'Merged links must be summed independently at each year in the approved chain.');
acrlHistoryAssert($numbers[0]['isSum'] && strpos($numbers[0]['label'], 'Sum of linked values:') === 0, 'A merged prior value must be labelled as a sum.');
acrlHistoryAssert($numbers[0]['notes'] === [['questionId' => '40', 'part' => 'A', 'note' => 'Prior note A']], 'Historical notes must stay attached to their linked prior-year answer.');
acrlHistoryAssert($numbers[1]['notes'] === [['questionId' => '40', 'part' => 'B', 'note' => 'Older note B']], 'Notes must follow the approved links at older years.');

$yesNo = $history['survey:0A:'];
acrlHistoryAssert(array_column($yesNo, 'value') === ['no', 'yes', 'no'], 'Yes/no values must be followed through all three approved links.');
acrlHistoryAssert($history['survey:99:'][0]['label'] === '—' && $history['survey:99:'][2]['label'] === '—', 'Missing years must use the em dash label.');
acrlHistoryAssert($history['trends:T1:'] === [], 'Trends questions must not include year-over-year history.');

echo "PASS: ACRL approved history chains, split/merge sums, notes, missing years, and Trends exclusion\n";
