<?php

namespace app\services;

/** Validates and stores answer values without changing their displayed text. */
class AcrlAnswerValueService
{
    public function normalize(string $answerType, $value): array
    {
        if ($value === null || $value === '') {
            return ['value_text' => null, 'value_number' => null];
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Answer values must be plain text or numbers.');
        }

        $text = (string)$value;
        if ($answerType === 'number' || $answerType === 'currency') {
            if (!preg_match('/^-?\d{1,16}(?:\.\d{1,2})?$/', $text)) {
                throw new \InvalidArgumentException('Enter a number with up to two decimal places.');
            }
            return ['value_text' => $text, 'value_number' => $text];
        }

        if ($answerType === 'yes/no') {
            $text = strtolower(trim($text));
            if (!in_array($text, ['yes', 'no'], true)) {
                throw new \InvalidArgumentException('Choose yes or no.');
            }
        } elseif ($answerType !== 'choice' && $answerType !== 'text') {
            throw new \InvalidArgumentException('This answer type cannot be edited.');
        }

        if (strlen($text) > 4000) {
            throw new \InvalidArgumentException('Answer values must be 4,000 characters or fewer.');
        }
        return ['value_text' => $text, 'value_number' => null];
    }

    public function normalizeNote($note): ?string
    {
        if ($note === null || $note === '') {
            return null;
        }
        if (!is_string($note) || strlen($note) > 4000) {
            throw new \InvalidArgumentException('Notes must be 4,000 characters or fewer.');
        }
        return $note;
    }

    public function isEmpty(array $answer): bool
    {
        return ($answer['value_text'] ?? null) === null || ($answer['value_text'] ?? '') === '';
    }
}
