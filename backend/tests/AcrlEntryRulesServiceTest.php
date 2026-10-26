<?php

require_once __DIR__ . '/../services/AcrlEntryRulesService.php';

use app\services\AcrlEntryRulesService;

function acrlEntryRulesAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rules = new AcrlEntryRulesService();
$change = $rules->compare(110, 100, 10, true, 'survey');
acrlEntryRulesAssert($change['changePercent'] === 10.0 && $change['highlighted'], 'A change at the threshold must be highlighted.');
acrlEntryRulesAssert($change['addNote'], 'A highlighted change should invite a note without requiring one.');

$negative = $rules->compare(-8, -10, 10, true, 'survey');
acrlEntryRulesAssert($negative['changePercent'] === 20.0, 'Negative prior values use the absolute prior value as the divisor.');

$zero = $rules->compare(25, 0, 10, true, 'survey');
acrlEntryRulesAssert($zero['previousLabel'] === 'New (was 0)' && !$zero['highlighted'], 'A zero previous value should use the advisory label without highlighting.');

$missing = $rules->compare(25, null, 10, false, 'survey');
acrlEntryRulesAssert($missing['previousLabel'] === 'No previous value' && $missing['changePercent'] === null, 'A new or unlinked question has no comparison.');

$trends = $rules->compare(25, 10, 0, true, 'trends');
acrlEntryRulesAssert($trends['previousLabel'] === 'No previous value' && !$trends['highlighted'], 'Trends questions do not show previous values or change.');

acrlEntryRulesAssert($rules->sumLinkedValues([12, 8]) === 20.0, 'Approved split or merge links should sum numeric previous values.');
acrlEntryRulesAssert($rules->sumLinkedValues([12, null]) === null, 'A linked sum is unavailable until every source value is present.');

$sum = $rules->calculatedValue('sum of 1A, 1B', ['1A' => 3, '1B' => 7]);
acrlEntryRulesAssert($sum['supported'] && $sum['value'] === 10.0, 'Simple sum-of-IDs expressions should calculate when all inputs are present.');
$partial = $rules->calculatedValue('sum of 1A, 1B', ['1A' => 3]);
acrlEntryRulesAssert($partial['supported'] && $partial['value'] === null, 'Calculated values need every input.');
$raw = $rules->calculatedValue('sum after adjustment', ['1A' => 3]);
acrlEntryRulesAssert(!$raw['supported'] && $raw['value'] === null, 'Unrecognized expressions should remain available as raw text.');

$calculated = $rules->calculatedStatus(10, 12);
acrlEntryRulesAssert($calculated['value'] === 12.0 && $calculated['mismatch'], 'A calculated mismatch should be advisory.');

echo "PASS: ACRL change, previous-value, and calculated-part rules\n";
