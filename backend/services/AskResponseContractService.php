<?php

namespace app\services;

final class AskResponseContractService
{
    const PROVENANCE_VERIFIED_PATTERN = 'verified_pattern';
    const PROVENANCE_AI_BUILT = 'ai_built';

    private static $provenanceLabels = [
        self::PROVENANCE_VERIFIED_PATTERN => 'Verified pattern',
        self::PROVENANCE_AI_BUILT => 'AI-built',
    ];

    public static function withGenerationProvenance(array $result, string $provenance): array
    {
        if (!isset($result['sql'])) {
            unset($result['generationProvenance'], $result['provenanceLabel']);
            return $result;
        }
        if (!isset(self::$provenanceLabels[$provenance])) {
            throw new \InvalidArgumentException('Unknown report generation provenance.');
        }
        $result['generationProvenance'] = $provenance;
        $result['provenanceLabel'] = self::$provenanceLabels[$provenance];
        return $result;
    }

    public static function normalizeMode(array $result): array
    {
        $route = (string)($result['route'] ?? '');
        $reason = (string)($result['routeReason'] ?? '');
        if ($route === 'builder_intent' && strpos($reason, 'family_contract_supported:') === 0) {
            $result['mode'] = 'canonical';
        }
        unset($result['needsExploratoryApproval']);
        return $result;
    }

    public static function toUserResponse(array $result): array
    {
        $result = self::normalizeMode($result);
        $items = [];
        foreach (($result['unmetRequirements'] ?? []) as $requirement) {
            $label = trim((string)($requirement['label'] ?? ''));
            if ($label !== '') {
                $items[$label] = $label;
            }
        }
        if ($items !== []) {
            $result['recoveryItems'] = array_values($items);
        }
        unset($result['unmetRequirements']);
        if (isset($result['validationSummary']) && is_array($result['validationSummary'])) {
            unset($result['validationSummary']['failureCategory']);
            unset($result['validationSummary']['validatorStage']);
        }
        if (($result['route'] ?? null) === 'exploratory_recovery'
            && ($result['_attemptedPlanProvenance'] ?? null) !== 'server_defaults'
        ) {
            unset($result['attemptedPlan']);
        }
        unset(
            $result['referenceResolver'],
            $result['_askEvidence'],
            $result['_attemptedPlanProvenance']
        );
        return $result;
    }
}
