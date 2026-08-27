<?php

namespace app\services;

use app\exceptions\ExploratorySqlValidationException;

/**
 * Owns the public Ask transition from request policy through SQL generation.
 *
 * Generation callbacks exchange typed internal outcomes. Only this coordinator
 * unwraps those outcomes into public API responses.
 */
final class AskGenerationCoordinatorService
{
    private const STATES = [
        'handled',
        'not_handled',
        'candidate_rejected',
        'infrastructure_failure',
        'request_blocked',
    ];

    private const TELEMETRY_CATEGORY = 'nl2sql.coordinator';

    public static function run(
        string $question,
        callable $initialAttempt,
        callable $freshAiAttempt
    ): array {
        $policy = AskRequestPolicyService::classify($question);
        if (($policy['state'] ?? null) === AskRequestPolicyService::REQUEST_BLOCKED) {
            self::logTransition($question, 'request', 'request_blocked', (string)($policy['reason'] ?? 'explicit_write_intent'));
            return self::requestBlocked();
        }

        self::logTransition($question, 'request', 'initial_attempt', (string)($policy['reason'] ?? 'read_only'));
        $initial = self::invoke($initialAttempt);
        self::assertKnownState($initial);
        self::logTransition(
            $question,
            'initial_attempt',
            (string)$initial['state'],
            (string)($initial['reason'] ?? $initial['state']),
            self::candidateHash($initial)
        );

        if ($initial['state'] === 'handled') {
            return self::publicResult($initial);
        }
        if ($initial['state'] === 'infrastructure_failure') {
            return self::publicResult($initial);
        }
        if ($initial['state'] === 'request_blocked') {
            return self::publicResultOrDefault($initial, self::requestBlocked());
        }

        $fresh = self::invoke($freshAiAttempt);
        self::assertKnownState($fresh);
        self::logTransition(
            $question,
            (string)$initial['state'],
            (string)$fresh['state'],
            (string)($fresh['reason'] ?? $fresh['state']),
            self::candidateHash($fresh)
        );

        if ($fresh['state'] === 'handled' || $fresh['state'] === 'infrastructure_failure') {
            return self::publicResult($fresh);
        }
        if ($fresh['state'] === 'request_blocked') {
            return self::publicResultOrDefault($fresh, self::requestBlocked());
        }

        return self::generationFailed(2);
    }

    private static function invoke(callable $attempt): array
    {
        try {
            $outcome = $attempt();
        } catch (ExploratorySqlValidationException $exception) {
            if (!$exception->isRepairable()) {
                throw $exception;
            }
            $candidateSql = trim($exception->getCandidateSql());
            return [
                'state' => 'candidate_rejected',
                'reason' => $exception->getSafeCategory(),
                'candidateSqlHash' => $candidateSql === '' ? null : hash('sha256', $candidateSql),
            ];
        }

        if (!is_array($outcome)) {
            throw new \InvalidArgumentException('Ask generation attempts must return a typed outcome array.');
        }
        return $outcome;
    }

    private static function assertKnownState(array $outcome): void
    {
        $state = (string)($outcome['state'] ?? '');
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown Ask generation outcome state: ' . ($state === '' ? '(missing)' : $state));
        }
        if (in_array($state, ['handled', 'infrastructure_failure'], true)
            && !is_array($outcome['result'] ?? null)
        ) {
            throw new \InvalidArgumentException("Ask generation outcome '{$state}' requires a result array.");
        }
    }

    private static function publicResult(array $outcome): array
    {
        return $outcome['result'];
    }

    private static function publicResultOrDefault(array $outcome, array $default): array
    {
        return is_array($outcome['result'] ?? null) ? $outcome['result'] : $default;
    }

    private static function requestBlocked(): array
    {
        return [
            'errorType' => 'request_blocked',
            'message' => 'Report Explorer runs read-only reports and cannot modify database data.',
            'route' => 'request_blocked',
            'routeReason' => 'explicit_write_intent',
        ];
    }

    private static function generationFailed(int $attempts): array
    {
        return [
            'errorType' => 'sql_generation_failed',
            'message' => 'Report Explorer could not build a valid report after retrying. Please retry.',
            'route' => 'generation_failed',
            'routeReason' => 'sql_repair_exhausted',
            'validationSummary' => [
                'status' => 'exhausted',
                'repairAttempts' => min(2, $attempts),
            ],
        ];
    }

    private static function candidateHash(array $outcome): ?string
    {
        $hash = trim((string)($outcome['candidateSqlHash'] ?? ''));
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    private static function logTransition(
        string $question,
        string $fromState,
        string $toState,
        string $reason,
        ?string $candidateSqlHash = null
    ): void {
        if (!class_exists('Yii') || !method_exists('Yii', 'info')) {
            return;
        }
        $payload = [
            'event' => 'nl2sql.coordinator_transition',
            'timestamp' => gmdate('c'),
            'promptFingerprint' => substr(hash('sha256', trim($question)), 0, 16),
            'fromState' => $fromState,
            'toState' => $toState,
            'reason' => preg_replace('/[^a-z0-9_.-]+/i', '_', $reason),
            'candidateSqlHash' => $candidateSqlHash,
        ];
        \Yii::info('NL2SQL telemetry: ' . json_encode($payload), self::TELEMETRY_CATEGORY);
    }
}
