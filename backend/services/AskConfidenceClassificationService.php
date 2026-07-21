<?php

namespace app\services;

final class AskConfidenceClassificationService
{
    private const REVIEW_RULES = [
        'crossDomain' => 'cross_domain_analysis',
        'materialRepair' => 'material_repair',
        'limitedSemanticCoverage' => 'limited_semantic_coverage',
        'proxyLinkage' => 'proxy_linkage',
        'knownDataLimitations' => 'known_data_limitation',
        'unresolvedDomainAmbiguity' => 'unresolved_domain_ambiguity',
    ];

    public static function classify(array $evidence): array
    {
        if (!empty($evidence['policyBlocked']) || ($evidence['route'] ?? null) === 'clarification') {
            return self::unreviewed();
        }

        if (($evidence['mode'] ?? null) === 'canonical'
            && ($evidence['validationStatus'] ?? null) === 'validated'
        ) {
            return self::unreviewed();
        }

        $reasons = [];
        foreach (self::REVIEW_RULES as $evidenceKey => $reason) {
            if (!empty($evidence[$evidenceKey])) {
                $reasons[] = $reason;
            }
        }

        if (in_array($evidence['validationStatus'] ?? null, ['exhausted', 'rejected'], true)) {
            $reasons[] = 'unable_to_validate';
        }

        if (self::hasMaterialDocumentedDefault($evidence)) {
            $reasons[] = 'documented_default';
        }

        return [
            'reviewRequired' => $reasons !== [],
            'reviewReasons' => $reasons,
        ];
    }

    private static function unreviewed(): array
    {
        return ['reviewRequired' => false, 'reviewReasons' => []];
    }

    private static function hasMaterialDocumentedDefault(array $evidence): bool
    {
        $defaultedKeys = self::assumptionKeys($evidence['defaultedAssumptionKeys'] ?? []);
        if ($defaultedKeys === []) {
            return false;
        }

        $materialKeys = self::assumptionKeys($evidence['materialDefaultedAssumptionKeys'] ?? []);
        foreach ($defaultedKeys as $key) {
            if (in_array($key, $materialKeys, true)) {
                return true;
            }
        }

        foreach (($evidence['defaultedAssumptionKeys'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['material'])) {
                return true;
            }
        }

        return false;
    }

    private static function assumptionKeys($entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $keys = [];
        foreach ($entries as $index => $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $keys[] = $entry;
                continue;
            }
            if (is_array($entry) && is_string($entry['key'] ?? null) && trim($entry['key']) !== '') {
                $keys[] = $entry['key'];
                continue;
            }
            if (is_string($index) && $entry === true) {
                $keys[] = $index;
            }
        }

        return array_values(array_unique($keys));
    }
}
