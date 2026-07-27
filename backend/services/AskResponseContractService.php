<?php

namespace app\services;

final class AskResponseContractService
{
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
        unset($result['referenceResolver'], $result['_askEvidence']);
        return $result;
    }
}
