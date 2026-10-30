<?php

namespace app\services;

/**
 * Stub for RPT-015 tests. The build round replaces these methods with the title behavior.
 */
final class ReportRunTitleService
{
    private $optionSource;
    private $nameNormalizer;

    /**
     * @param object $optionSource Provides getOptions() and getExpenseClassOptions().
     * @param callable $nameNormalizer The existing query-job name normalizer.
     */
    public function __construct($optionSource, $nameNormalizer)
    {
        $this->optionSource = $optionSource;
        $this->nameNormalizer = $nameNormalizer;
    }

    /**
     * @param object $report
     * @param array $params
     * @param bool $identifierExport
     * @return string
     */
    public function buildTitle($report, array $params, $identifierExport = false)
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * @param array|null $metadata
     * @param int $reportTemplateId
     * @return array
     */
    public function mergeReportTemplateId($metadata, $reportTemplateId)
    {
        throw new \RuntimeException('not implemented');
    }
}
