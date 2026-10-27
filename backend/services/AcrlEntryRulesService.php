<?php

namespace app\services;

/** Pure comparison and calculation rules for annual ACRL answers. */
class AcrlEntryRulesService
{
    public function compare($current, $previous, $threshold, bool $hasApprovedLink, string $sheet): array
    {
        if ($sheet !== 'survey' || !$hasApprovedLink || $previous === null || $previous === '') {
            return [
                'previousValue' => null,
                'previousLabel' => 'No previous value',
                'changePercent' => null,
                'highlighted' => false,
                'addNote' => false,
            ];
        }

        if (!is_numeric($previous)) {
            return [
                'previousValue' => $previous,
                'previousLabel' => (string)$previous,
                'changePercent' => null,
                'highlighted' => false,
                'addNote' => false,
            ];
        }

        $previousNumber = (float)$previous;
        if ($previousNumber == 0.0) {
            return [
                'previousValue' => 0,
                'previousLabel' => 'New (was 0)',
                'changePercent' => null,
                'highlighted' => false,
                'addNote' => false,
            ];
        }

        $changePercent = null;
        if ($current !== null && $current !== '' && is_numeric($current)) {
            $changePercent = (((float)$current - $previousNumber) / abs($previousNumber)) * 100;
        }
        $highlighted = $changePercent !== null && abs($changePercent) >= (float)$threshold;

        return [
            'previousValue' => $previousNumber,
            'previousLabel' => $this->formatNumber($previousNumber),
            'changePercent' => $changePercent === null ? null : round($changePercent, 2),
            'highlighted' => $highlighted,
            'addNote' => $highlighted,
        ];
    }

    public function sumLinkedValues(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        $total = 0.0;
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return null;
            }
            $total += (float)$value;
        }
        return $total;
    }

    public function calculatedValue($expression, array $answers): array
    {
        if (!is_string($expression)
            || !preg_match('/^\s*sum of\s+([A-Za-z0-9]+(?:\s*,\s*[A-Za-z0-9]+)*)\s*$/i', $expression, $matches)
        ) {
            return ['supported' => false, 'value' => null, 'inputIds' => []];
        }

        $inputIds = preg_split('/\s*,\s*/', $matches[1]);
        $values = [];
        foreach ($inputIds as $inputId) {
            if (!array_key_exists($inputId, $answers) || !is_numeric($answers[$inputId])) {
                return ['supported' => true, 'value' => null, 'inputIds' => $inputIds];
            }
            $values[] = (float)$answers[$inputId];
        }

        return ['supported' => true, 'value' => array_sum($values), 'inputIds' => $inputIds];
    }

    public function calculatedStatus($entered, $calculated): array
    {
        $hasBoth = $entered !== null && $entered !== '' && is_numeric($entered)
            && $calculated !== null && is_numeric($calculated);
        return [
            'value' => $calculated === null ? null : (float)$calculated,
            'mismatch' => $hasBoth && abs((float)$entered - (float)$calculated) > 0.01,
        ];
    }

    private function formatNumber(float $value): string
    {
        if (floor($value) === $value) {
            return number_format($value, 0, '.', ',');
        }
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
