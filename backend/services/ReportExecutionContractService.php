<?php

namespace app\services;

use app\models\QueryJob;
use app\models\ReportTemplate;

require_once __DIR__ . '/SqlSelectStructureService.php';

/**
 * Canonicalizes the server-governed execution rules for static report exports.
 */
final class ReportExecutionContractService
{
    public const METADATA_KEY = 'reportExecution';
    private const MAX_PUBLIC_ROW_CAP = 100000;

    public static function fromReport(ReportTemplate $report, array $context): ?array
    {
        $config = $report->getExecutionConfig();
        if ($config === null) {
            return null;
        }
        if (array_key_exists('sourceColumn', $context) || array_key_exists('identifierSourceColumn', $context)) {
            throw new \InvalidArgumentException('Identifier export columns are controlled by the report template.');
        }

        $publicRowCap = self::positiveInteger($config['public_row_cap'] ?? null, 'public row cap');
        $fetchRowLimit = self::positiveInteger($config['fetch_row_limit'] ?? null, 'fetch row limit');
        if ($publicRowCap > self::MAX_PUBLIC_ROW_CAP || $fetchRowLimit !== $publicRowCap + 1) {
            throw new \InvalidArgumentException('Report execution row limits must use a one-row sentinel within the public cap.');
        }
        if (($config['preserve_export_order'] ?? null) !== true) {
            throw new \InvalidArgumentException('Report execution must preserve the reviewed export order.');
        }

        $identifierExport = $config['identifier_export'] ?? null;
        if (!is_array($identifierExport)) {
            throw new \InvalidArgumentException('Report execution must define identifier export metadata.');
        }
        $sourceColumn = self::nonEmptyString($identifierExport['source_column'] ?? null, 'identifier source column');
        $header = self::nonEmptyString($identifierExport['header'] ?? null, 'identifier export header');

        $exportKind = $context['exportKind'] ?? 'worklist';
        if (!is_string($exportKind) || !in_array($exportKind, ['worklist', 'identifier'], true)) {
            throw new \InvalidArgumentException('Unsupported report export kind.');
        }

        $slug = self::filenameComponent($report->slug ?? '', 'report slug');
        $marcTag = self::filenameComponent($context['marcTag'] ?? '', 'MARC tag');
        $locationCode = self::filenameComponent($context['locationCode'] ?? '', 'location code');
        $locationName = self::filenameComponent($context['locationName'] ?? '', 'location name');
        $suffix = $exportKind === 'identifier' ? 'folio-uuids.csv' : 'worklist.csv';

        return [
            'reportTemplateId' => (int) $report->id,
            'reportSlug' => $slug,
            'publicRowCap' => $publicRowCap,
            'fetchRowLimit' => $fetchRowLimit,
            'preserveExportOrder' => true,
            'exportKind' => $exportKind,
            'identifierExport' => [
                'sourceColumn' => $sourceColumn,
                'header' => $header,
            ],
            'downloadFilename' => implode('-', [$slug, $marcTag, $locationCode, $locationName, $suffix]),
        ];
    }

    public static function fromJob(QueryJob $job): ?array
    {
        $metadata = $job->getDecodedMetadata();
        $contract = $metadata[self::METADATA_KEY] ?? null;
        if ($contract === null) {
            return null;
        }
        if (!is_array($contract)) {
            throw new \InvalidArgumentException('Report execution metadata must be an object.');
        }

        $publicRowCap = self::positiveInteger($contract['publicRowCap'] ?? null, 'public row cap');
        $fetchRowLimit = self::positiveInteger($contract['fetchRowLimit'] ?? null, 'fetch row limit');
        if ($publicRowCap > self::MAX_PUBLIC_ROW_CAP || $fetchRowLimit !== $publicRowCap + 1) {
            throw new \InvalidArgumentException('Stored report execution row limits are invalid.');
        }
        if (($contract['preserveExportOrder'] ?? null) !== true
            || !in_array($contract['exportKind'] ?? null, ['worklist', 'identifier'], true)) {
            throw new \InvalidArgumentException('Stored report execution metadata is invalid.');
        }
        $reportTemplateId = self::positiveInteger($contract['reportTemplateId'] ?? null, 'report template ID');
        self::nonEmptyString($contract['reportSlug'] ?? null, 'report slug');
        $identifierExport = $contract['identifierExport'] ?? null;
        if (!is_array($identifierExport)
            || self::nonEmptyString($identifierExport['sourceColumn'] ?? null, 'identifier source column') === ''
            || self::nonEmptyString($identifierExport['header'] ?? null, 'identifier export header') === '') {
            throw new \InvalidArgumentException('Stored identifier export metadata is invalid.');
        }
        self::assertSafeFilename($contract['downloadFilename'] ?? null);
        $contract['reportTemplateId'] = $reportTemplateId;
        $contract['publicRowCap'] = $publicRowCap;
        $contract['fetchRowLimit'] = $fetchRowLimit;
        if (array_key_exists('truncated', $contract)) {
            $contract['truncated'] = (bool) $contract['truncated'];
        }
        return $contract;
    }

    public static function trimRows(array $rows, array $contract): array
    {
        $cap = self::positiveInteger($contract['publicRowCap'] ?? null, 'public row cap');
        if ($cap > self::MAX_PUBLIC_ROW_CAP) {
            throw new \InvalidArgumentException('Public row cap exceeds the supported maximum.');
        }
        return [
            'rows' => array_slice($rows, 0, $cap),
            'truncated' => count($rows) > $cap,
        ];
    }

    public static function assertStaticExportSql(string $sql, array $contract): string
    {
        $fetchRowLimit = self::positiveInteger($contract['fetchRowLimit'] ?? null, 'fetch row limit');
        $tokens = SqlSelectStructureService::tokenizeForAnalysis($sql);
        $orderBy = [];
        $limits = [];
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) !== 0) {
                continue;
            }
            if (self::isUnquotedKeyword($token, 'ORDER') && self::isTopLevelKeyword($tokens, $index + 1, 'BY')) {
                $orderBy[] = $index;
            }
            if (self::isUnquotedKeyword($token, 'LIMIT')) {
                $limits[] = $index;
            }
        }
        if (count($orderBy) !== 1 || count($limits) !== 1) {
            throw new \InvalidArgumentException('Governed export SQL requires exactly one top-level ORDER BY and LIMIT.');
        }
        $limitIndex = $limits[0];
        $limitToken = $tokens[$limitIndex + 1] ?? null;
        if (($limitToken['depth'] ?? -1) !== 0 || ($limitToken['kind'] ?? '') !== 'number'
            || (string) $limitToken['value'] !== (string) $fetchRowLimit) {
            throw new \InvalidArgumentException('Governed export SQL must use the configured numeric sentinel limit.');
        }
        foreach (array_slice($tokens, $limitIndex + 2) as $token) {
            if (($token['depth'] ?? -1) === 0 && ($token['value'] ?? '') !== ';') {
                throw new \InvalidArgumentException('Governed export SQL cannot contain clauses after its sentinel limit.');
            }
        }
        return $sql;
    }

    public static function updateMetadata(array $metadata, array $contract, bool $truncated): array
    {
        $contract['truncated'] = $truncated;
        $metadata[self::METADATA_KEY] = $contract;
        return $metadata;
    }

    private static function positiveInteger($value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("Report execution {$label} must be a positive integer.");
        }
        return (int) $value;
    }

    private static function nonEmptyString($value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Report execution {$label} is required.");
        }
        return trim($value);
    }

    private static function filenameComponent($value, string $label): string
    {
        $value = self::nonEmptyString($value, $label);
        if (preg_match('/[\\\\\/\x00]/', $value) === 1) {
            throw new \InvalidArgumentException("Report execution {$label} cannot contain a path separator.");
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');
        if ($value === '') {
            throw new \InvalidArgumentException("Report execution {$label} cannot be normalized into a filename.");
        }
        return $value;
    }

    private static function assertSafeFilename($value): void
    {
        if (!is_string($value) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\.csv\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Stored report execution filename is unsafe.');
        }
    }

    private static function isTopLevelKeyword(array $tokens, int $index, string $keyword): bool
    {
        return isset($tokens[$index])
            && ($tokens[$index]['depth'] ?? -1) === 0
            && self::isUnquotedKeyword($tokens[$index], $keyword);
    }

    private static function isUnquotedKeyword(array $token, string $keyword): bool
    {
        return ($token['kind'] ?? '') === 'identifier'
            && empty($token['quoted'])
            && strtoupper((string) ($token['value'] ?? '')) === $keyword;
    }
}
