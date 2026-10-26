<?php

namespace app\services;

/**
 * Proposes adjacent-year ACRL question matches without treating an identifier as proof.
 */
class AcrlQuestionMatcherService
{
    private const MIN_TEXT_MATCH_SCORE = 0.62;
    private const MIN_RENUMBER_SCORE = 0.62;
    private const MIN_IDENTIFIER_TEXT_SIMILARITY = 0.08;

    public function compare(array $previous, array $current): array
    {
        $previous = $this->sortQuestions($previous);
        $current = $this->sortQuestions($current);
        $usedPrevious = [];
        $results = [];

        foreach ($current as $currentQuestion) {
            $bestIndex = null;
            $bestScore = -1.0;
            $hasSameTypeAlternative = false;
            foreach ($previous as $index => $previousQuestion) {
                if (isset($usedPrevious[$index]) || $previousQuestion['sheet'] !== $currentQuestion['sheet']
                    || $previousQuestion['part'] !== $currentQuestion['part']
                    || ($previousQuestion['answer_type'] ?? '') !== ($currentQuestion['answer_type'] ?? '')) {
                    continue;
                }
                if ($this->score($previousQuestion, $currentQuestion) >= self::MIN_TEXT_MATCH_SCORE) {
                    $hasSameTypeAlternative = true;
                    break;
                }
            }
            foreach ($previous as $index => $previousQuestion) {
                if ($previousQuestion['sheet'] !== $currentQuestion['sheet']) {
                    continue;
                }
                $isSplit = $previousQuestion['part'] === '' && $currentQuestion['part'] !== '';
                if (isset($usedPrevious[$index]) && !$isSplit) {
                    continue;
                }

                $score = $this->score($previousQuestion, $currentQuestion);
                $textSimilarity = $this->textSimilarity($previousQuestion['prompt'], $currentQuestion['prompt']);
                $sameId = $this->sameIdentifier($previousQuestion['question_id'], $currentQuestion['question_id']);
                $samePart = $previousQuestion['part'] === $currentQuestion['part'];
                $sameAnswerType = ($previousQuestion['answer_type'] ?? '') === ($currentQuestion['answer_type'] ?? '');
                $parentPartMatch = $sameId && ($previousQuestion['part'] === '' || $currentQuestion['part'] === '');
                if ($hasSameTypeAlternative && $sameId && $samePart && !$sameAnswerType) {
                    continue;
                }
                if ($currentQuestion['sheet'] === 'trends' && $score < self::MIN_TEXT_MATCH_SCORE) {
                    continue;
                }
                $sameIdentifierMatch = $sameId && $samePart;
                if (!($sameIdentifierMatch
                    || ($parentPartMatch && $textSimilarity >= self::MIN_IDENTIFIER_TEXT_SIMILARITY)
                    || $score >= self::MIN_TEXT_MATCH_SCORE)) {
                    continue;
                }
                if ($score > $bestScore) {
                    $bestIndex = $index;
                    $bestScore = $score;
                }
            }

            if ($bestIndex === null) {
                $results[] = $this->result('new', null, $currentQuestion, null);
                continue;
            }

            $previousQuestion = $previous[$bestIndex];
            $usedPrevious[$bestIndex] = true;
            $results[] = $this->matchedResult($previousQuestion, $currentQuestion, $bestScore);
        }

        foreach ($previous as $index => $previousQuestion) {
            if (!isset($usedPrevious[$index])) {
                $results[] = $this->result('removed', $previousQuestion, null, null);
            }
        }

        return $results;
    }

    private function matchedResult(array $previous, array $current, float $score): array
    {
        $sameId = strtolower($previous['question_id']) === strtolower($current['question_id']);
        $samePart = $previous['part'] === $current['part'];
        $samePrompt = $this->normalizePrompt($previous['prompt']) === $this->normalizePrompt($current['prompt']);
        $parentPartMatch = !$samePart && ($previous['part'] === '' || $current['part'] === '');

        if ($parentPartMatch) {
            $status = 'reworded';
            $kind = $previous['part'] === '' ? 'split' : 'merged';
        } elseif ($sameId && $samePart && $samePrompt) {
            $status = 'unchanged';
            $kind = 'same';
        } elseif (!$sameId && $samePrompt && $samePart) {
            $status = 'renumbered';
            $kind = 'renumbered';
        } elseif (!$sameId && $this->sameIdentifier($previous['question_id'], $current['question_id']) && $samePart) {
            $status = 'renumbered';
            $kind = 'renumbered';
        } elseif (!$sameId && $samePart && $score >= self::MIN_RENUMBER_SCORE) {
            $status = 'renumbered';
            $kind = 'renumbered';
        } else {
            $status = 'reworded';
            $kind = 'reworded';
        }

        return $this->result($status, $previous, $current, $kind, $score);
    }

    private function result(string $status, ?array $previous, ?array $current, ?string $kind, ?float $score = null): array
    {
        return [
            'status' => $status,
            'match_kind' => $kind,
            'score' => $score === null ? 0.0 : round($score, 4),
            'previous' => $previous,
            'current' => $current,
            'topical' => (($current['sheet'] ?? $previous['sheet'] ?? null) === 'trends'),
        ];
    }

    private function score(array $previous, array $current): float
    {
        $text = $this->textSimilarity($previous['prompt'], $current['prompt']);
        $sameId = $this->sameIdentifier($previous['question_id'], $current['question_id']);
        $sameNumericId = $this->sameIdentifier($previous['question_id'], $current['question_id']);
        $samePart = $previous['part'] === $current['part'];
        $parentPartMatch = !$samePart && ($previous['part'] === '' || $current['part'] === '');
        $sameSection = $this->normalizePrompt($previous['section']) === $this->normalizePrompt($current['section']);
        $sameAnswerType = ($previous['answer_type'] ?? '') === ($current['answer_type'] ?? '');

        return (0.55 * $text)
            + (0.12 * ($sameId || $sameNumericId ? 1 : 0))
            + (0.15 * ($samePart ? 1 : ($parentPartMatch ? 0.25 : 0)))
            + (0.08 * ($sameSection ? 1 : 0))
            + (0.10 * ($sameAnswerType ? 1 : 0));
    }

    private function textSimilarity(string $left, string $right): float
    {
        $leftTokens = array_unique($this->tokens($left));
        $rightTokens = array_unique($this->tokens($right));
        if (empty($leftTokens) && empty($rightTokens)) {
            return 1.0;
        }
        if (empty($leftTokens) || empty($rightTokens)) {
            return 0.0;
        }
        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique(array_merge($leftTokens, $rightTokens)));
        $jaccard = $union === 0 ? 0.0 : $intersection / $union;
        $overlap = $intersection / min(count($leftTokens), count($rightTokens));
        return (0.5 * $jaccard) + (0.5 * $overlap);
    }

    private function tokens(string $text): array
    {
        $normalized = $this->normalizePrompt($text);
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        return $tokens ?: [];
    }

    private function normalizePrompt(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string)$text));
    }

    private function sameIdentifier(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (preg_match('/^\d+$/', $left) && preg_match('/^\d+$/', $right)) {
            return ltrim($left, '0') === ltrim($right, '0');
        }
        return strtolower($left) === strtolower($right);
    }

    private function sortQuestions(array $questions): array
    {
        usort($questions, function ($left, $right) {
            $order = ((int)($left['survey_order'] ?? 0)) <=> ((int)($right['survey_order'] ?? 0));
            if ($order !== 0) {
                return $order;
            }
            return strcmp($left['question_id'] . $left['part'], $right['question_id'] . $right['part']);
        });
        return $questions;
    }
}
