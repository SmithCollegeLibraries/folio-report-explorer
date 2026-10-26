<?php

$servicePath = __DIR__ . '/../services/AcrlQuestionMatcherService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FAIL: ACRL matcher service is missing.\n");
    exit(1);
}

require_once $servicePath;

use app\services\AcrlQuestionMatcherService;

function acrlMatcherAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlMatcherQuestion(string $id, string $part, string $section, string $prompt, int $order, string $sheet = 'survey'): array
{
    return [
        'question_id' => $id,
        'part' => $part,
        'sheet' => $sheet,
        'section' => $section,
        'prompt' => $prompt,
        'answer_type' => 'number',
        'required' => 'unknown',
        'calculated_from' => null,
        'survey_order' => $order,
    ];
}

function acrlMatcherFind(array $items, string $status, string $currentId = '', string $currentPart = '', string $previousId = ''): ?array
{
    foreach ($items as $item) {
        if ($item['status'] !== $status) {
            continue;
        }
        $current = $item['current'];
        $previous = $item['previous'];
        if ($currentId !== '' && (!$current || $current['question_id'] !== $currentId)) {
            continue;
        }
        if ($currentPart !== '' && (!$current || $current['part'] !== $currentPart)) {
            continue;
        }
        if ($previousId !== '' && (!$previous || $previous['question_id'] !== $previousId)) {
            continue;
        }
        return $item;
    }
    return null;
}

$matcher = new AcrlQuestionMatcherService();

$renumbered = $matcher->compare(
    [
        acrlMatcherQuestion('01', 'A', 'Staffing', 'Librarians', 1),
        acrlMatcherQuestion('79b', '', 'Library services', 'Before COVID-19 in a typical week (if available)', 2),
    ],
    [
        acrlMatcherQuestion('1', 'A', 'Staffing', 'Librarians', 1),
        acrlMatcherQuestion('79c', '', 'Library characteristics', 'Gate count in a typical week (if available)', 2),
    ]
);
acrlMatcherAssert(acrlMatcherFind($renumbered, 'renumbered', '1', 'A', '01') !== null, 'Leading-zero staffing identifiers should match by text and part as a renumbering.');
acrlMatcherAssert(acrlMatcherFind($renumbered, 'new', '79c') !== null, '79c must not be matched to the unrelated 79b COVID prompt.');
acrlMatcherAssert(acrlMatcherFind($renumbered, 'removed', '', '', '79b') !== null, 'The unmatched 79b prompt must remain removed.');

$caseVariant = $matcher->compare(
    [acrlMatcherQuestion('91e', '', 'Local characteristics', 'Please report the local measure.', 1)],
    [acrlMatcherQuestion('91E', '', 'Local characteristics', 'Please report the local measure.', 1)]
);
acrlMatcherAssert(acrlMatcherFind($caseVariant, 'unchanged', '91E', '', '91e') !== null, 'Question identifiers should match without regard to letter case.');

$sameIdReworded = $matcher->compare(
    [acrlMatcherQuestion('91e', '', 'Local characteristics', 'Original measure label for the old catalogue entry.', 1)],
    [acrlMatcherQuestion('91e', '', 'Local characteristics', 'Entirely changed wording with distinct vocabulary.', 1)]
);
acrlMatcherAssert(acrlMatcherFind($sameIdReworded, 'reworded', '91e', '', '91e') !== null, 'A same-ID, same-type prompt with changed wording should stay reviewable even at low text similarity.');

$redefined = $matcher->compare(
    [
        acrlMatcherQuestion('60', 'A', 'Library services', 'Total Circulation (use COUNTER 5 TR_B1 & IR_M1 for column B)', 1),
        acrlMatcherQuestion('61', '', 'Library services', 'usage COUNTER 4 BR1 & MR1', 2),
        acrlMatcherQuestion('62', '', 'Library services', 'usage COUNTER 4 BR2 & MR2', 3),
        acrlMatcherQuestion('63', '', 'Library services', 'E-serials usage', 4),
    ],
    [
        acrlMatcherQuestion('60', 'A', 'Library services', 'Total circulation - physical', 1),
        acrlMatcherQuestion('62', '', 'Library services', 'Total Usage (Physical and Digital Circulation)', 2),
        acrlMatcherQuestion('61B', '', 'Library services', 'E-serials Usage', 3),
    ]
);
$q60 = acrlMatcherFind($redefined, 'reworded', '60');
$q62 = acrlMatcherFind($redefined, 'reworded', '62');
acrlMatcherAssert($q60 !== null && $q60['score'] < 0.75, 'Redefined Q60 should be paired for review with a low similarity score.');
acrlMatcherAssert($q62 !== null && $q62['score'] < 0.75, 'Redefined Q62 should be paired for review with a low similarity score.');
acrlMatcherAssert(acrlMatcherFind($redefined, 'renumbered', '61B', '', '63') !== null, 'The E-serials measure should match from Q63 to Q61B by prompt text.');
acrlMatcherAssert(acrlMatcherFind($redefined, 'removed', '', '', '61') !== null, 'The old COUNTER Q61 measure should remain removed.');

$previousFringePaid = acrlMatcherQuestion('7', '', 'Staffing', 'Are staff fringe benefits paid from the library budget?', 1);
$previousFringeAmount = acrlMatcherQuestion('8', '', 'Staffing', 'Staff fringe benefits if paid from the library budget', 2);
$currentFringePaid = acrlMatcherQuestion('8', '', 'Staffing', 'Are staff fringe benefits paid from the library budget? If benefits are paid by the library, report all cash contributions in the form of supplementary or deferred compensation other than salary. Do not include employee contributions.', 1);
$currentFringeAmount = acrlMatcherQuestion('9', '', 'Staffing', 'Staff fringe benefits if paid from the library budget. Report all cash contributions in the form of supplementary or deferred compensation other than salary. Do not include the employee contribution. Employee fringe benefits include retirement plans, social security taxes, medical and dental plans, unemployment compensation plans, group life insurance plans, workers compensation plans, and other benefits in-kind with cash options. Exclude employee fringe benefits if not paid from the library budget.', 2);
$previousFringePaid['answer_type'] = 'yes/no';
$previousFringeAmount['answer_type'] = 'currency';
$currentFringePaid['answer_type'] = 'yes/no';
$currentFringeAmount['answer_type'] = 'currency';
$staffingRenumbered = $matcher->compare(
    [$previousFringePaid, $previousFringeAmount],
    [$currentFringePaid, $currentFringeAmount]
);
acrlMatcherAssert(acrlMatcherFind($staffingRenumbered, 'renumbered', '8', '', '7') !== null, 'A shifted yes/no item should not be stolen by an exact-number match to a different answer type.');
acrlMatcherAssert(acrlMatcherFind($staffingRenumbered, 'renumbered', '9', '', '8') !== null, 'The displaced currency item should follow its text to the next number.');

$split = $matcher->compare(
    [
        acrlMatcherQuestion('6', 'A', 'Staffing', 'Total, FTEs and Salary & Wages (Excluding Fringe)', 1),
        acrlMatcherQuestion('6', 'B', 'Staffing', 'Total, FTEs and Salary & Wages (Excluding Fringe)', 2),
        acrlMatcherQuestion('70', '', 'Library services', 'Number of synchronous presentations', 3),
        acrlMatcherQuestion('72', '', 'Library services', 'Number of asynchronous presentations', 4),
    ],
    [
        acrlMatcherQuestion('6', 'A', 'Staffing', 'Total # of Staff FTEs', 1),
        acrlMatcherQuestion('6', 'B', 'Staffing', 'Total salaries and wages (excluding fringe)', 2),
        acrlMatcherQuestion('6', 'C', 'Staffing', 'Total salaries and wages if unable to report separately', 3),
        acrlMatcherQuestion('70', 'A', 'Library services', 'Number of Synchronous Presentations - Physical', 4),
        acrlMatcherQuestion('70', 'B', 'Library services', 'Number of Synchronous Presentations - Digital/Electronic', 5),
        acrlMatcherQuestion('70', 'C', 'Library services', 'Number of Synchronous Presentations - Total (if unable to break apart)', 6),
        acrlMatcherQuestion('72', 'B', 'Library services', 'Number of Asynchronous Presentations', 7),
    ]
);
acrlMatcherAssert(acrlMatcherFind($split, 'new', '6', 'C') !== null, 'The new Q6C fallback must not be mistaken for an existing Q6 part.');
foreach (['A', 'B', 'C'] as $part) {
    $match = acrlMatcherFind($split, 'reworded', '70', $part, '70');
    acrlMatcherAssert($match !== null && $match['match_kind'] === 'split', 'Each Q70 answer part should be reviewable as a split from the previous single measure.');
}
acrlMatcherAssert(acrlMatcherFind($split, 'reworded', '72', 'B', '72') !== null, 'Q72B should align with the previous unparted Q72 item.');

$topical = $matcher->compare(
    [acrlMatcherQuestion('T1', '', 'Trends', 'Does your library have an active AI policy?', 1, 'trends')],
    [acrlMatcherQuestion('T1', '', 'Trends', 'How have academic freedom concerns changed?', 1, 'trends')]
);
$topicalNew = acrlMatcherFind($topical, 'new', 'T1');
acrlMatcherAssert($topicalNew !== null && $topicalNew['topical'] === true, 'A reused Trends number must not create a link when the yearly prompt is a different topic.');
acrlMatcherAssert(acrlMatcherFind($topical, 'removed', '', '', 'T1') !== null, 'The unrelated prior Trends prompt must remain removed.');

echo "PASS: ACRL matcher handles renumbering, redefinitions, splits, and unrelated suffix identifiers\n";
