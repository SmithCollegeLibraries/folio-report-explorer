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

    public function __construct(
        string $stage,
        string $safeCategory,
        string $candidateSql,
        bool $repairable,
        string $internalMessage,
        ?\Throwable $previous = null
    ) {
        parent::__construct($internalMessage, 0, $previous);
        $this->stage = $stage;
        $this->safeCategory = $safeCategory;
        $this->candidateSql = $candidateSql;
        $this->repairable = $repairable;
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
}
