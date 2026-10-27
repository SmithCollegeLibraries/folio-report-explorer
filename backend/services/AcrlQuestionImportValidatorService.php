<?php

namespace app\services;

/** Validates question parts sent by the browser-side worksheet parser. */
class AcrlQuestionImportValidatorService
{
    private const ANSWER_TYPES = ['number', 'currency', 'yes/no', 'choice', 'text'];
    private const REQUIRED_VALUES = ['yes', 'no', 'unknown'];

    public function validateUpload($year, $fileName, $sha256, $items): array
    {
        if (!is_numeric($year) || (int)$year < 1900 || (int)$year > 2100 || (string)(int)$year !== (string)$year) {
            throw new \InvalidArgumentException('Survey year must be a four-digit year.');
        }
        if (!is_string($fileName) || trim($fileName) === '' || strlen($fileName) > 255
            || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false
            || preg_match('/[\x00-\x1f]/', $fileName)) {
            throw new \InvalidArgumentException('Source file name must be a plain file name up to 255 characters.');
        }
        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new \InvalidArgumentException('Source file checksum must be a SHA-256 hexadecimal value.');
        }
        if (!is_array($items) || empty($items) || count($items) > 2000) {
            throw new \InvalidArgumentException('Worksheet must contain between 1 and 2000 question parts.');
        }

        $normalized = [];
        $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Every worksheet item must be an object.');
            }
            $sheet = $item['sheet'] ?? null;
            $questionId = $item['question_id'] ?? null;
            $part = $item['part'] ?? '';
            $section = $item['section'] ?? null;
            $prompt = $item['prompt'] ?? null;
            $answerType = $item['answer_type'] ?? null;
            $answerOptions = $this->validateAnswerOptions($item['answer_options'] ?? null, $answerType);
            $required = $item['required'] ?? null;
            $calculatedFrom = $item['calculated_from'] ?? null;
            $surveyOrder = $item['survey_order'] ?? null;

            if (!in_array($sheet, ['survey', 'trends'], true)) {
                throw new \InvalidArgumentException('Worksheet sheet must be survey or trends.');
            }
            if (!is_string($questionId)) {
                throw new \InvalidArgumentException('Question identifier must be text.');
            }
            $questionId = trim($questionId);
            $questionId = trim(preg_replace('/\.+$/', '', $questionId));
            if (!preg_match('/^[A-Za-z0-9]{1,32}$/', $questionId)) {
                throw new \InvalidArgumentException('Question identifier must be 1 to 32 letters or digits.');
            }
            if (!is_string($part) || !preg_match('/^[A-Z]?$/', $part)) {
                throw new \InvalidArgumentException('Question part must be empty or one uppercase letter.');
            }
            if (!is_string($section) || trim($section) === '' || strlen($section) > 120) {
                throw new \InvalidArgumentException('Section must contain 1 to 120 characters.');
            }
            if (!is_string($prompt) || trim($prompt) === '' || strlen($prompt) > 4000) {
                throw new \InvalidArgumentException('Prompt must contain 1 to 4000 characters.');
            }
            if (!in_array($answerType, self::ANSWER_TYPES, true)) {
                throw new \InvalidArgumentException('Question answer type is not supported.');
            }
            if (!in_array($required, self::REQUIRED_VALUES, true)) {
                throw new \InvalidArgumentException('Required status must be yes, no, or unknown.');
            }
            if ($calculatedFrom !== null && (!is_string($calculatedFrom) || strlen($calculatedFrom) > 1000)) {
                throw new \InvalidArgumentException('Calculated-from text must be at most 1000 characters.');
            }
            if (!is_numeric($surveyOrder) || (int)$surveyOrder < 1) {
                throw new \InvalidArgumentException('Survey order must be a positive integer.');
            }

            $key = json_encode([(int)$year, $sheet, $questionId, $part]);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Worksheet contains duplicate question identifiers and parts.');
            }
            $seen[$key] = true;
            $normalized[] = [
                'sheet' => $sheet,
                'question_id' => $questionId,
                'part' => $part,
                'section' => trim($section),
                'prompt' => trim($prompt),
                'answer_type' => $answerType,
                'answer_options' => $answerOptions,
                'required' => $required,
                'calculated_from' => $calculatedFrom === null ? null : trim($calculatedFrom),
                'survey_order' => (int)$surveyOrder,
            ];
        }

        return $normalized;
    }

    private function validateAnswerOptions($options, $answerType): ?array
    {
        if ($options === null) {
            return null;
        }
        if (!is_array($options)) {
            throw new \InvalidArgumentException('Question answer options must be an object.');
        }
        if (count(array_diff(array_keys($options), ['kind', 'rows', 'choices', 'allowOther'])) > 0) {
            throw new \InvalidArgumentException('Question answer options contain unsupported fields.');
        }
        $kind = $options['kind'] ?? null;
        $rows = $options['rows'] ?? null;
        $choices = $options['choices'] ?? null;
        $allowOther = $options['allowOther'] ?? null;
        if (!in_array($kind, ['checkbox', 'radio', 'rating', 'grid', 'text'], true)
            || !is_array($rows) || !is_array($choices) || !is_bool($allowOther)
            || count($rows) > 100 || count($choices) > 100) {
            throw new \InvalidArgumentException('Question answer options have an invalid shape.');
        }

        $rows = $this->optionLabels($rows, 'row');
        $choices = $this->optionLabels($choices, 'choice');
        if ($kind === 'text') {
            if (!empty($rows) || !empty($choices) || $allowOther || $answerType !== 'text') {
                throw new \InvalidArgumentException('Free-text answer options must not contain fixed rows or choices.');
            }
        } elseif ($answerType !== 'choice') {
            throw new \InvalidArgumentException('Structured answer options require the choice answer type.');
        } elseif ($kind === 'grid') {
            if (empty($rows) || count($choices) < 2) {
                throw new \InvalidArgumentException('Grid answer options require rows and at least two choices.');
            }
        } elseif (!empty($rows) || empty($choices)) {
            throw new \InvalidArgumentException('List answer options require choices and no rows.');
        }

        $hasOther = false;
        foreach ($kind === 'grid' ? $rows : $choices as $label) {
            if (preg_match('/^other\b/i', $label) === 1) $hasOther = true;
        }
        if ($allowOther !== $hasOther || ($kind === 'rating' && $allowOther)) {
            throw new \InvalidArgumentException('Other-response metadata must match an Other row or choice.');
        }
        $normalized = ['kind' => $kind, 'rows' => $rows, 'choices' => $choices, 'allowOther' => $allowOther];
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 60000) {
            throw new \InvalidArgumentException('Question answer options are too large to store.');
        }
        return $normalized;
    }

    private function optionLabels(array $labels, string $labelKind): array
    {
        $normalized = [];
        foreach ($labels as $label) {
            if (!is_string($label)) {
                throw new \InvalidArgumentException('Question answer ' . $labelKind . ' labels must be text.');
            }
            $label = trim($label);
            if ($label === '' || strlen($label) > 255 || in_array($label, $normalized, true)) {
                throw new \InvalidArgumentException('Question answer ' . $labelKind . ' labels must be unique and up to 255 characters.');
            }
            $normalized[] = $label;
        }
        return $normalized;
    }
}
