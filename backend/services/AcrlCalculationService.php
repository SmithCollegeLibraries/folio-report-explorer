<?php

namespace app\services;

/** Evaluates normalized sum rules against question parts and same-year answers. */
class AcrlCalculationService
{
    public function evaluate(array $questions, array $answers): array
    {
        $byKey = [];
        $answerValues = [];
        foreach ($questions as $question) {
            $byKey[$this->key($question)] = $question;
        }
        foreach ($answers as $answer) {
            $answerValues[$this->key($answer)] = $answer['value_number'] ?? null;
        }

        $values = [];
        $states = [];
        $results = [];
        $evaluateKey = null;
        $evaluateKey = function ($key) use (&$evaluateKey, &$values, &$states, &$results, $byKey, $answerValues) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
            if (($states[$key] ?? null) === 'visiting') {
                return null;
            }
            if (!isset($byKey[$key])) {
                return null;
            }

            $question = $byKey[$key];
            $expression = $question['calculated_from'] ?? null;
            if (!is_string($expression) || trim($expression) === '') {
                $value = $answerValues[$key] ?? null;
                $values[$key] = $value !== null && is_numeric($value) ? (float)$value : null;
                return $values[$key];
            }

            $references = $this->references($expression);
            if ($references === null) {
                $states[$key] = 'done';
                $values[$key] = null;
                $results[$key] = ['supported' => false, 'value' => null, 'inputIds' => [], 'waitingFor' => []];
                return null;
            }

            $states[$key] = 'visiting';
            $total = 0.0;
            $waitingFor = [];
            $inputIds = [];
            foreach ($references as $reference) {
                $dependency = $this->resolve($reference, $question, $byKey);
                if ($dependency === null) {
                    $inputIds[] = $reference;
                    $waitingFor[] = $reference;
                    continue;
                }
                $inputIds[] = $this->displayId($dependency);
                $dependencyKey = $this->key($dependency);
                $dependencyValue = $evaluateKey($dependencyKey);
                if ($dependencyValue === null) {
                    $waitingFor[] = $this->displayId($dependency);
                } else {
                    $total += $dependencyValue;
                }
            }
            $value = empty($waitingFor) ? $total : null;
            $values[$key] = $value;
            $states[$key] = 'done';
            $results[$key] = [
                'supported' => true,
                'value' => $value,
                'inputIds' => $inputIds,
                'waitingFor' => array_values(array_unique($waitingFor)),
            ];
            return $value;
        };

        foreach ($questions as $question) {
            if (is_string($question['calculated_from'] ?? null) && trim($question['calculated_from']) !== '') {
                $evaluateKey($this->key($question));
            }
        }
        return $results;
    }

    public function dependentCalculations(array $questions, array $changedQuestion): array
    {
        $affected = [$this->key($changedQuestion) => true];
        $calculated = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($questions as $question) {
                $key = $this->key($question);
                if (isset($calculated[$key])) {
                    continue;
                }
                $references = $this->references((string)($question['calculated_from'] ?? ''));
                if ($references === null) {
                    continue;
                }
                foreach ($references as $reference) {
                    $dependency = $this->resolve($reference, $question, $questions);
                    if ($dependency && isset($affected[$this->key($dependency)])) {
                        $calculated[$key] = true;
                        $affected[$key] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        return $calculated;
    }

    private function references(string $expression): ?array
    {
        $expression = trim(preg_replace('/\.$/', '', trim($expression)));
        if (!preg_match('/^sum of\s+(.+)$/i', $expression, $match)) {
            return null;
        }
        $list = trim($match[1]);
        if (!preg_match('/^[A-Za-z]?\d+[A-Za-z]?(?:\s*,\s*(?:and\s+)?[A-Za-z]?\d+[A-Za-z]?)*$/i', $list)) {
            return null;
        }
        preg_match_all('/[A-Za-z]?\d+[A-Za-z]?/', $list, $matches);
        return count($matches[0]) >= 1 ? $matches[0] : null;
    }

    private function resolve(string $reference, array $target, array $questions): ?array
    {
        $needle = strtolower($reference);
        foreach ($questions as $question) {
            if (strtolower($question['question_id'] . ($question['part'] ?? '')) === $needle) {
                return $question;
            }
        }

        if (preg_match('/^(\d+)$/', $reference) && !empty($target['part'])) {
            foreach ($questions as $question) {
                if (strtolower($question['question_id']) === $needle
                    && strtolower($question['part'] ?? '') === strtolower($target['part'])) {
                    return $question;
                }
            }
        }
        return null;
    }

    private function key(array $question): string
    {
        return $question['sheet'] . '|' . $question['question_id'] . '|' . ($question['part'] ?? '');
    }

    private function displayId(array $question): string
    {
        return $question['question_id'] . ($question['part'] ?? '');
    }
}
