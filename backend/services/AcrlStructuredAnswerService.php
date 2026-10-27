<?php

namespace app\services;

/** Validates structured ACRL answers against options stored on their question. */
class AcrlStructuredAnswerService
{
    public function decodeOptions($rawOptions): ?array
    {
        if ($rawOptions === null || $rawOptions === '') {
            return null;
        }
        if (is_string($rawOptions)) {
            $rawOptions = json_decode($rawOptions, true);
        }
        if (!is_array($rawOptions) || !isset($rawOptions['kind'])
            || !in_array($rawOptions['kind'], ['checkbox', 'radio', 'rating', 'grid', 'text'], true)
            || !isset($rawOptions['rows']) || !is_array($rawOptions['rows'])
            || !isset($rawOptions['choices']) || !is_array($rawOptions['choices'])
            || !array_key_exists('allowOther', $rawOptions) || !is_bool($rawOptions['allowOther'])) {
            throw new \InvalidArgumentException('Stored answer options are invalid.');
        }
        return $rawOptions;
    }

    public function normalize($rawOptions, $value): ?array
    {
        $options = $this->decodeOptions($rawOptions);
        if ($options === null || $options['kind'] === 'text') {
            return null;
        }
        if ($value === null || $value === '') {
            return ['value_text' => null, 'value_number' => null];
        }
        if (!is_string($value) || strlen($value) > 4000) {
            throw new \InvalidArgumentException('Structured answers must be JSON text up to 4,000 characters.');
        }
        $answer = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($answer)) {
            throw new \InvalidArgumentException('Structured answers must be valid JSON.');
        }

        if ($options['kind'] === 'checkbox') {
            $normalized = $this->checkboxAnswer($answer, $options);
            if (empty($normalized['selected']) && $normalized['other'] === '') {
                return ['value_text' => null, 'value_number' => null];
            }
        } elseif ($options['kind'] === 'radio' || $options['kind'] === 'rating') {
            $normalized = $this->radioAnswer($answer, $options);
            if ($normalized['value'] === '') {
                return ['value_text' => null, 'value_number' => null];
            }
        } else {
            $normalized = $this->gridAnswer($answer, $options);
            if (!$this->hasGridAnswer($normalized['rows'])) {
                return ['value_text' => null, 'value_number' => null];
            }
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 4000) {
            throw new \InvalidArgumentException('Structured answers must be valid JSON up to 4,000 characters.');
        }
        return ['value_text' => $encoded, 'value_number' => null];
    }

    public function displayValue($rawOptions, $value): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }
        $options = $this->decodeOptions($rawOptions);
        if ($options === null || $options['kind'] === 'text' || !is_string($value)) {
            return is_scalar($value) ? (string)$value : null;
        }
        $answer = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($answer)) {
            return $value;
        }
        if ($options['kind'] === 'checkbox') {
            $selected = isset($answer['selected']) && is_array($answer['selected']) ? $answer['selected'] : [];
            $parts = [];
            foreach ($options['choices'] as $choice) {
                if (in_array($choice, $selected, true)) {
                    $parts[] = isOtherLabel($choice) && !empty($answer['other']) ? 'Other: ' . $answer['other'] : $choice;
                }
            }
            return implode('; ', $parts);
        }
        if ($options['kind'] === 'radio' || $options['kind'] === 'rating') {
            $selected = isset($answer['value']) && is_string($answer['value']) ? $answer['value'] : '';
            if (isOtherLabel($selected) && !empty($answer['other'])) {
                return 'Other: ' . $answer['other'];
            }
            return $selected;
        }
        $rows = isset($answer['rows']) && is_array($answer['rows']) ? $answer['rows'] : [];
        $parts = [];
        foreach ($options['rows'] as $row) {
            $selected = $rows[$row] ?? '';
            if (is_string($selected) && $selected !== '') {
                $parts[] = $row . ': ' . $selected;
            }
        }
        return implode('; ', $parts);
    }

    private function checkboxAnswer(array $answer, array $options): array
    {
        $this->assertKeys($answer, ['selected', 'other'], ['selected']);
        if (!is_array($answer['selected'])) {
            throw new \InvalidArgumentException('Checkbox answers must include selected choices.');
        }
        $selected = [];
        foreach ($answer['selected'] as $choice) {
            if (!is_string($choice) || !in_array($choice, $options['choices'], true) || in_array($choice, $selected, true)) {
                throw new \InvalidArgumentException('Checkbox answers contain an unknown or repeated choice.');
            }
            $selected[] = $choice;
        }
        $selected = array_values(array_filter($options['choices'], function ($choice) use ($selected) {
            return in_array($choice, $selected, true);
        }));
        $other = $this->otherText($answer['other'] ?? '', $options['allowOther']);
        if ($other !== '' && !$this->containsOtherChoice($selected)) {
            throw new \InvalidArgumentException('Choose the Other option before entering an Other response.');
        }
        return $options['allowOther'] ? ['selected' => $selected, 'other' => $other] : ['selected' => $selected];
    }

    private function radioAnswer(array $answer, array $options): array
    {
        $this->assertKeys($answer, ['value', 'other'], ['value']);
        if (!is_string($answer['value']) || ($answer['value'] !== '' && !in_array($answer['value'], $options['choices'], true))) {
            throw new \InvalidArgumentException('Choose one of the stored answer options.');
        }
        $other = $this->otherText($answer['other'] ?? '', $options['allowOther']);
        if ($other !== '' && !isOtherLabel($answer['value'])) {
            throw new \InvalidArgumentException('Choose the Other option before entering an Other response.');
        }
        return $options['allowOther']
            ? ['value' => $answer['value'], 'other' => $other]
            : ['value' => $answer['value']];
    }

    private function gridAnswer(array $answer, array $options): array
    {
        $this->assertKeys($answer, ['rows'], ['rows']);
        if (!is_array($answer['rows'])) {
            throw new \InvalidArgumentException('Grid answers must contain row values.');
        }
        foreach ($answer['rows'] as $row => $value) {
            if (!is_string($row) || !in_array($row, $options['rows'], true)) {
                throw new \InvalidArgumentException('Grid answers contain a row absent from the stored options.');
            }
        }
        $rows = [];
        foreach ($options['rows'] as $row) {
            $value = $answer['rows'][$row] ?? '';
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Grid answers must be text values.');
            }
            if ($options['allowOther'] && isOtherLabel($row)) {
                $rows[$row] = $this->otherText($value, true);
            } elseif ($value !== '' && !in_array($value, $options['choices'], true)) {
                throw new \InvalidArgumentException('Grid answers must use one of the stored column choices.');
            } else {
                $rows[$row] = $value;
            }
        }
        return ['rows' => $rows];
    }

    private function otherText($value, bool $allowed): string
    {
        if (!$allowed) {
            if ($value !== '' && $value !== null) {
                throw new \InvalidArgumentException('This question does not allow an Other response.');
            }
            return '';
        }
        if (!is_string($value) || strlen($value) > 1000) {
            throw new \InvalidArgumentException('Other responses must be text up to 1,000 characters.');
        }
        return trim($value);
    }

    private function assertKeys(array $value, array $allowed, array $required): void
    {
        if (count(array_diff(array_keys($value), $allowed)) > 0) {
            throw new \InvalidArgumentException('Structured answers contain unsupported fields.');
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new \InvalidArgumentException('Structured answer is missing a required field.');
            }
        }
    }

    private function containsOtherChoice(array $choices): bool
    {
        foreach ($choices as $choice) {
            if (isOtherLabel($choice)) return true;
        }
        return false;
    }

    private function hasGridAnswer(array $rows): bool
    {
        foreach ($rows as $value) {
            if ($value !== '') return true;
        }
        return false;
    }
}

function isOtherLabel(string $value): bool
{
    return preg_match('/^other\b/i', trim($value)) === 1;
}
