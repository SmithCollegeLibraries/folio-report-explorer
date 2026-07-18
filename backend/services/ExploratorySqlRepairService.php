<?php

namespace app\services;

use app\exceptions\ExploratorySqlValidationException;

class ExploratorySqlRepairService
{
    public const MAX_REPAIR_ATTEMPTS = 2;

    public static function run(
        callable $attempt,
        array $context,
        int $repairAttemptsUsed = 0,
        ?ExploratorySqlValidationException $initialFailure = null
    ): array {
        $repairAttempts = max(0, min(self::MAX_REPAIR_ATTEMPTS, $repairAttemptsUsed));
        $attemptContext = self::sanitizeContext($context);
        $failure = $initialFailure;

        if ($failure === null) {
            try {
                return self::validatedOutcome($attempt(self::withFailureContext($attemptContext, 0, null)), $repairAttempts);
            } catch (ExploratorySqlValidationException $exception) {
                self::assertRepairable($exception);
                $failure = $exception;
            }
        } else {
            self::assertRepairable($failure);
        }

        while ($repairAttempts < self::MAX_REPAIR_ATTEMPTS) {
            $repairAttempts++;

            try {
                return self::validatedOutcome(
                    $attempt(self::withFailureContext($attemptContext, $repairAttempts, $failure)),
                    $repairAttempts
                );
            } catch (ExploratorySqlValidationException $exception) {
                self::assertRepairable($exception);
                $failure = $exception;
            }
        }

        $unmetRequirements = [];
        foreach ($failure->getSafeViolations() as $violation) {
            $unmetRequirements[$violation['key']] = [
                'key' => $violation['key'],
                'label' => $violation['label'],
            ];
        }

        return [
            'status' => 'exhausted',
            'repairAttempts' => $repairAttempts,
            'validatorStage' => $failure->getStage(),
            'failureCategory' => $failure->getSafeCategory(),
            'unmetRequirements' => array_values($unmetRequirements),
            'suggestions' => self::recoverySuggestions($failure->getSafeViolations()),
        ];
    }

    private static function sanitizeContext(array $context): array
    {
        return [
            'originalQuestion' => is_string($context['originalQuestion'] ?? null)
                ? $context['originalQuestion']
                : '',
            'campus' => is_string($context['campus'] ?? null)
                ? $context['campus']
                : null,
            'assumptions' => is_array($context['assumptions'] ?? null)
                ? $context['assumptions']
                : [],
            'attemptedPlan' => is_string($context['attemptedPlan'] ?? null)
                ? $context['attemptedPlan']
                : '',
            'semanticContract' => is_array($context['semanticContract'] ?? null)
                ? $context['semanticContract']
                : [],
            'safeViolations' => is_array($context['safeViolations'] ?? null)
                ? $context['safeViolations']
                : [],
        ];
    }

    private static function withFailureContext(
        array $context,
        int $repairNumber,
        ?ExploratorySqlValidationException $failure
    ): array {
        return array_merge($context, [
            'repairNumber' => $repairNumber,
            'previousCandidate' => $failure === null ? null : $failure->getCandidateSql(),
            'validatorStage' => $failure === null ? null : $failure->getStage(),
            'safeCategory' => $failure === null ? null : $failure->getSafeCategory(),
            'safeViolations' => $failure === null ? [] : $failure->getSafeViolations(),
        ]);
    }

    private static function validatedOutcome(array $result, int $repairAttempts): array
    {
        return [
            'status' => 'validated',
            'result' => $result,
            'repairAttempts' => $repairAttempts,
        ];
    }

    private static function assertRepairable(ExploratorySqlValidationException $exception): void
    {
        if (!$exception->isRepairable()) {
            throw $exception;
        }
    }

    private static function recoverySuggestions(array $safeViolations): array
    {
        $suggestions = [];
        foreach ($safeViolations as $violation) {
            $guidance = $violation['guidance'];
            $suggestions[$guidance] = $guidance;
            if (count($suggestions) === 3) {
                break;
            }
        }

        if ($suggestions !== []) {
            return array_values($suggestions);
        }

        return [
            'Retry the request.',
            'Correct an assumption.',
            'Narrow the period or output.',
        ];
    }
}
