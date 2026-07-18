<?php

namespace app\exceptions;

class ExploratorySqlValidationException extends \RuntimeException
{
    /** @var string */
    private $stage;

    /** @var string */
    private $safeCategory;

    /** @var string */
    private $candidateSql;

    /** @var bool */
    private $repairable;

    /** @var array */
    private $safeViolations;

    public function __construct(
        string $stage,
        string $safeCategory,
        string $candidateSql,
        bool $repairable,
        string $internalMessage,
        ?\Throwable $previous = null,
        array $safeViolations = []
    ) {
        parent::__construct($internalMessage, 0, $previous);
        $this->stage = $stage;
        $this->safeCategory = $safeCategory;
        $this->candidateSql = $candidateSql;
        $this->repairable = $repairable;
        $this->safeViolations = self::normalizeSafeViolations($safeViolations);
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getSafeCategory(): string
    {
        return $this->safeCategory;
    }

    public function getCandidateSql(): string
    {
        return $this->candidateSql;
    }

    public function isRepairable(): bool
    {
        return $this->repairable;
    }

    public function getSafeViolations(): array
    {
        return $this->safeViolations;
    }

    private static function normalizeSafeViolations(array $violations): array
    {
        $safe = [];
        foreach ($violations as $violation) {
            if (!is_array($violation)) {
                continue;
            }
            $key = trim((string)($violation['key'] ?? ''));
            $category = trim((string)($violation['category'] ?? ''));
            $label = trim((string)($violation['label'] ?? ''));
            $guidance = trim((string)($violation['guidance'] ?? ''));
            if (preg_match('/^[a-z0-9_]{1,80}$/', $key) !== 1
                || preg_match('/^[a-z0-9_]{1,80}$/', $category) !== 1
                || $label === '' || $guidance === '') {
                continue;
            }
            $safe[] = compact('key', 'category', 'label', 'guidance');
        }
        return $safe;
    }
}
