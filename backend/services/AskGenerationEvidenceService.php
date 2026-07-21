<?php

namespace app\services;

require_once __DIR__ . '/ExploratorySqlAnalysisService.php';

/**
 * Builds server-trusted Ask evidence before the ordinary response is sanitized.
 */
final class AskGenerationEvidenceService
{
    private const MATERIAL_DEFAULT_KEYS = [
        'purchase_date_basis',
        'investment_cost_basis',
        'circulation_window',
        'call_number_grouping',
        'roi_formula',
    ];

    public static function build(array $result, array $requestContext): array
    {
        $internalEvidence = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        $route = self::nullableString($result['route'] ?? null);
        $compilerVersion = self::nullableString(
            $internalEvidence['compilerVersion']
                ?? $result['compilerVersion']
                ?? $requestContext['compilerVersion']
                ?? null
        );
        $mode = self::nullableString($result['mode'] ?? null);
        if ($compilerVersion === 'physical_roi_v2') {
            $mode = 'exploratory';
        }

        $initialSql = self::candidateSql(
            $internalEvidence['initialSql']
                ?? $result['initialSql']
                ?? $requestContext['initialSql']
                ?? $result['initialCandidate']
                ?? null
        );
        $finalSql = self::candidateSql(
            $internalEvidence['finalSql']
                ?? $requestContext['finalSql']
                ?? $result['sql']
                ?? $result['finalCandidate']
                ?? null
        );
        $assumptions = is_array($result['assumptions'] ?? null) ? $result['assumptions'] : [];
        $defaultedKeys = self::defaultedAssumptionKeys($assumptions);
        $materialDefaultedKeys = array_values(array_filter(
            $defaultedKeys,
            static function (string $key): bool {
                return in_array($key, self::MATERIAL_DEFAULT_KEYS, true);
            }
        ));
        $semanticValidation = is_array($result['semanticValidation'] ?? null)
            ? $result['semanticValidation']
            : [];
        $validationSummary = is_array($result['validationSummary'] ?? null)
            ? $result['validationSummary']
            : [];
        $semanticContract = is_array($requestContext['semanticContract'] ?? null)
            ? $requestContext['semanticContract']
            : [];
        $validationStatus = self::validationStatus(
            $result,
            $validationSummary,
            $semanticValidation,
            $finalSql
        );
        $policyBlocked = !empty($result['policyBlocked'])
            || !empty($requestContext['policyBlocked'])
            || $route === 'blocked'
            || ($result['routeReason'] ?? null) === 'ask_policy_block';
        $repairAttempts = max(0, min(2, (int)(
            $internalEvidence['repairAttempts']
                ?? $result['repairAttempts']
                ?? $validationSummary['repairAttempts']
                ?? $requestContext['repairAttempts']
                ?? 0
        )));
        $physicalRoi = $compilerVersion === 'physical_roi_v2';

        $confidenceEvidence = [
            'version' => 1,
            'failureCategory' => self::nullableString($validationSummary['failureCategory'] ?? null),
            'validatorStage' => self::nullableString($validationSummary['validatorStage'] ?? null),
            'unmetRequirementKeys' => self::requirementKeys($result['unmetRequirements'] ?? []),
            'checkedRequirementKeys' => self::requirementKeys($semanticValidation['checkedRequirements'] ?? []),
            'semanticCoverageStatus' => self::nullableString(
                $semanticValidation['coverageStatus']
                    ?? $semanticContract['coverageStatus']
                    ?? $result['semanticCoverageStatus']
                    ?? null
            ),
            'repairAttempts' => $repairAttempts,
        ];

        $provenance = [
            'compilerVersion' => $compilerVersion,
            'modelName' => self::nullableString(
                $internalEvidence['modelName']
                    ?? $result['modelName']
                    ?? $result['model']
                    ?? $requestContext['modelName']
                    ?? $requestContext['model']
                    ?? null
            ),
            'promptVersion' => self::nullableString(
                $internalEvidence['promptVersion']
                    ?? $result['promptVersion']
                    ?? $requestContext['promptVersion']
                    ?? null
            ),
            'referenceBundleMetadata' => self::nullableArray(
                $internalEvidence['referenceBundleMetadata']
                    ?? $result['referenceBundleMetadata']
                    ?? $requestContext['referenceBundleMetadata']
                    ?? null
            ),
            'schemaMetadata' => self::nullableArray(
                $internalEvidence['schemaMetadata']
                    ?? $result['schemaMetadata']
                    ?? $requestContext['schemaMetadata']
                    ?? null
            ),
            'semanticContractVersion' => self::nullableScalar(
                $semanticValidation['contractVersion']
                    ?? $semanticContract['contractVersion']
                    ?? $result['semanticContractVersion']
                    ?? null
            ),
        ];

        $crossDomain = self::truthyEvidence($result, $requestContext, 'crossDomain') || $physicalRoi;
        $proxyLinkage = self::truthyEvidence($result, $requestContext, 'proxyLinkage') || $physicalRoi;
        $knownDataLimitations = self::truthyEvidence($result, $requestContext, 'knownDataLimitations')
            || $physicalRoi;
        $limitedSemanticCoverage = self::truthyEvidence(
            $result,
            $requestContext,
            'limitedSemanticCoverage'
        ) || in_array(
            $confidenceEvidence['semanticCoverageStatus'],
            ['limited', 'partial'],
            true
        );
        $generatedSql = in_array($validationStatus, ['exhausted', 'rejected'], true)
            ? null
            : $finalSql;

        return [
            'originalQuestion' => (string)($requestContext['prompt'] ?? $requestContext['originalQuestion'] ?? ''),
            'prompt' => (string)($requestContext['prompt'] ?? $requestContext['originalQuestion'] ?? ''),
            'promptFingerprint' => self::promptFingerprint(
                (string)($requestContext['prompt'] ?? $requestContext['originalQuestion'] ?? '')
            ),
            'followUpContext' => is_array($requestContext['followUpContext'] ?? null)
                ? $requestContext['followUpContext']
                : null,
            'parentGenerationId' => self::nullableString($requestContext['parentGenerationId'] ?? null),
            'responseMode' => $mode,
            'mode' => $mode,
            'executionMode' => self::executionMode($mode, $route, $policyBlocked),
            'route' => $route,
            'routeReason' => self::nullableString($result['routeReason'] ?? null),
            'queryFamily' => self::nullableString(
                $internalEvidence['queryFamily']
                    ?? $result['queryFamily']
                    ?? $requestContext['queryFamily']
                    ?? null
            ),
            'validationStatus' => $validationStatus,
            'generatedSql' => $generatedSql,
            'sqlHash' => $generatedSql === null ? null : hash('sha256', $generatedSql),
            'assumptions' => $assumptions,
            'userNotice' => self::userNotice($result),
            'confidenceEvidence' => $confidenceEvidence,
            'initialStructure' => $initialSql === null
                ? null
                : ExploratorySqlAnalysisService::structuralSignature($initialSql),
            'finalStructure' => $finalSql === null
                ? null
                : ExploratorySqlAnalysisService::structuralSignature($finalSql),
            'provenance' => $provenance,
            'repairAttempts' => $repairAttempts,
            'materialRepair' => $initialSql !== null && $finalSql !== null
                ? ExploratorySqlAnalysisService::materiallyDifferent($initialSql, $finalSql)
                : false,
            'defaultedAssumptionKeys' => $defaultedKeys,
            'materialDefaultedAssumptionKeys' => $materialDefaultedKeys,
            'limitedSemanticCoverage' => $limitedSemanticCoverage,
            'crossDomain' => $crossDomain,
            'proxyLinkage' => $proxyLinkage,
            'knownDataLimitations' => $knownDataLimitations,
            'unresolvedDomainAmbiguity' => self::truthyEvidence(
                $result,
                $requestContext,
                'unresolvedDomainAmbiguity'
            ),
            'policyBlocked' => $policyBlocked,
        ];
    }

    private static function validationStatus(
        array $result,
        array $validationSummary,
        array $semanticValidation,
        ?string $finalSql
    ): ?string {
        $status = self::nullableString(
            $validationSummary['status'] ?? $result['validationStatus'] ?? null
        );
        if ($status !== null) {
            return $status;
        }
        if (($semanticValidation['status'] ?? null) === 'rejected') {
            return 'rejected';
        }
        if ($finalSql !== null) {
            return 'validated';
        }
        return null;
    }

    private static function executionMode(?string $mode, ?string $route, bool $policyBlocked): ?string
    {
        if ($policyBlocked || $route === 'clarification') {
            return null;
        }
        if ($mode === 'canonical') {
            return 'deterministic';
        }
        return $mode === 'exploratory' ? 'exploratory' : null;
    }

    private static function defaultedAssumptionKeys(array $assumptions): array
    {
        $keys = [];
        foreach ($assumptions as $assumption) {
            if (!is_array($assumption) || ($assumption['source'] ?? null) !== 'default') {
                continue;
            }
            $key = self::nullableString($assumption['key'] ?? null);
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private static function requirementKeys($requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }
        $keys = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $key = self::nullableString($requirement['key'] ?? null);
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private static function candidateSql($candidate): ?string
    {
        if (is_array($candidate)) {
            $candidate = $candidate['sql'] ?? null;
        }
        return self::nullableString($candidate);
    }

    private static function truthyEvidence(array $result, array $context, string $key): bool
    {
        return !empty($result[$key]) || !empty($context[$key]);
    }

    private static function userNotice(array $result)
    {
        foreach (['reviewNotice', 'exploratoryNotice', 'message', 'error'] as $key) {
            if (array_key_exists($key, $result)) {
                return $result[$key];
            }
        }
        return null;
    }

    private static function promptFingerprint(string $prompt): string
    {
        return substr(hash('sha256', trim($prompt)), 0, 16);
    }

    private static function nullableString($value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private static function nullableArray($value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private static function nullableScalar($value)
    {
        return is_scalar($value) ? $value : null;
    }
}
