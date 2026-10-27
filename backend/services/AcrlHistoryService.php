<?php

namespace app\services;

/** Resolves approved survey links across the three preceding fiscal years. */
class AcrlHistoryService
{
    private $repository;
    private $rules;

    public function __construct($repository = null, $rules = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->rules = $rules ?: new AcrlEntryRulesService();
    }

    public function forQuestions(int $year, array $questions): array
    {
        $answersByYear = [];
        $linksByYear = [];
        for ($offset = 1; $offset <= 3; $offset += 1) {
            $priorYear = $year - $offset;
            $answersByYear[$priorYear] = $this->answerMap($this->repository->answers($priorYear, 'survey'));
            if ($offset < 4) {
                $linksByYear[$priorYear + 1] = $this->linksByCurrentKey($this->repository->approvedLinks($priorYear + 1));
            }
        }

        $result = [];
        foreach ($questions as $question) {
            $key = $this->entryQuestionKey($question['sheet'], $question['question_id'], $question['part']);
            if ($question['sheet'] !== 'survey') {
                $result[$key] = [];
                continue;
            }

            $nodes = [$this->questionKey($question['question_id'], $question['part'])];
            $currentYear = $year;
            $history = [];
            for ($offset = 1; $offset <= 3; $offset += 1) {
                $priorYear = $year - $offset;
                $links = $this->linksForNodes($linksByYear[$currentYear] ?? [], $nodes);
                $history[] = $this->valueForLinks($priorYear, $links, $answersByYear[$priorYear] ?? []);
                $nodes = array_map(function ($link) {
                    return $this->questionKey($link['previous_question_id'], $link['previous_part']);
                }, $links);
                $currentYear = $priorYear;
            }
            $result[$key] = $history;
        }
        return $result;
    }

    private function linksByCurrentKey(array $links): array
    {
        $map = [];
        foreach ($links as $link) {
            if (isset($link['sheet']) && $link['sheet'] !== 'survey') {
                continue;
            }
            $key = $this->questionKey($link['current_question_id'], $link['current_part']);
            $map[$key][] = $link;
        }
        return $map;
    }

    private function linksForNodes(array $linksByCurrentKey, array $nodes): array
    {
        $selected = [];
        foreach ($nodes as $node) {
            foreach ($linksByCurrentKey[$node] ?? [] as $link) {
                $sourceKey = $this->questionKey($link['previous_question_id'], $link['previous_part']);
                $isSumLink = in_array($link['match_kind'] ?? '', ['split', 'merged'], true);
                if (!isset($selected[$sourceKey])) {
                    $link['is_sum_link'] = $isSumLink;
                    $selected[$sourceKey] = $link;
                } elseif ($isSumLink) {
                    $selected[$sourceKey]['is_sum_link'] = true;
                }
            }
        }
        return array_values($selected);
    }

    private function valueForLinks(int $year, array $links, array $answers): array
    {
        $values = [];
        $linkedValues = [];
        $notes = [];
        $hasSumLink = false;
        foreach ($links as $link) {
            $hasSumLink = $hasSumLink || !empty($link['is_sum_link']);
            $questionId = $link['previous_question_id'];
            $part = $link['previous_part'];
            $answer = $answers[$this->questionKey($questionId, $part)] ?? null;
            if (!$answer) {
                continue;
            }
            $note = $answer['note'] ?? null;
            if (is_string($note) && trim($note) !== '') {
                $notes[] = ['questionId' => $questionId, 'part' => $part, 'note' => $note];
            }
            $value = ($answer['value_number'] ?? null) !== null
                ? $answer['value_number']
                : ($answer['value_text'] ?? null);
            if ($value === null || $value === '') {
                continue;
            }
            $values[] = $value;
            $linkedValues[] = [
                'questionId' => $questionId,
                'part' => $part,
                'value' => $value,
                'note' => $note,
            ];
        }

        $isSum = count($values) > 1 || $hasSumLink;
        if (empty($values)) {
            $value = null;
        } elseif (count($values) === 1) {
            $value = $values[0];
        } else {
            $value = $this->rules->sumLinkedValues($values);
        }
        $label = $value === null || $value === ''
            ? '—'
            : ($isSum ? 'Sum of linked values: ' . $this->displayValue($value) : $this->displayValue($value));

        return [
            'year' => $year,
            'value' => $value,
            'label' => $label,
            'isSum' => $isSum,
            'linkedValues' => $linkedValues,
            'notes' => $notes,
        ];
    }

    private function answerMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$this->questionKey($row['question_id'], $row['part'])] = $row;
        }
        return $map;
    }

    private function entryQuestionKey(string $sheet, string $questionId, string $part): string
    {
        return $sheet . ':' . $questionId . ':' . $part;
    }

    private function questionKey(string $questionId, string $part): string
    {
        return $questionId . ':' . $part;
    }

    private function displayValue($value): string
    {
        if (is_numeric($value)) {
            return number_format((float)$value, floor((float)$value) === (float)$value ? 0 : 2, '.', ',');
        }
        $text = (string)$value;
        return in_array(strtolower($text), ['yes', 'no'], true) ? ucfirst(strtolower($text)) : $text;
    }
}
