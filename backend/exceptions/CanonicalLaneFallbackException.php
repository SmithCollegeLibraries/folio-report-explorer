<?php

namespace app\exceptions;

final class CanonicalLaneFallbackException extends \RuntimeException
{
    private $familyKey;
    private $safeReason;
    private $candidateResult;

    public function __construct(
        string $familyKey,
        string $safeReason,
        array $candidateResult = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct('Canonical generation requires AI fallback.', 0, $previous);
        $this->familyKey = self::sanitizeLabel($familyKey, '');
        $this->safeReason = self::sanitizeLabel($safeReason, 'canonical_generation_failed');
        $this->candidateResult = $candidateResult;
    }

    public function getFamilyKey(): string
    {
        return $this->familyKey;
    }

    public function getSafeReason(): string
    {
        return $this->safeReason;
    }

    public function getCandidateResult(): array
    {
        return $this->candidateResult;
    }

    private static function sanitizeLabel(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_]{1,120}$/', $value) === 1 ? $value : $fallback;
    }
}
