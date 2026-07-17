<?php

namespace app\services;

class ExploratoryQueryDefaultsService
{
    private const ARTIFACT_VERSION = 1;

    /**
     * @return array<int, array{key:string,label:string,value:string,explanation:string,correctionExample:string,source:string}>
     */
    public static function resolve(string $prompt): array
    {
        $normalized = self::normalize($prompt);
        if (!self::isCrossDomainRoiPrompt($normalized)) {
            return [];
        }

        $artifact = self::loadArtifact();
        $assumptions = [];

        foreach ($artifact['defaults'] as $default) {
            $key = $default['key'];
            $assumptions[$key] = [
                'key' => $key,
                'label' => $default['label'],
                'value' => $default['defaultValue'],
                'explanation' => $default['defaultExplanation'],
                'correctionExample' => $default['correctionExample'],
                'source' => 'default',
            ];
        }

        if (self::matchesExplicitRequest($normalized, 'payment date')) {
            $assumptions['purchase_date_basis']['explanation'] = 'Purchases are assigned to the payment date, as explicitly requested.';
            $assumptions['purchase_date_basis']['source'] = 'explicit';
        } elseif (self::matchesExplicitRequest($normalized, 'invoice date')) {
            $assumptions['purchase_date_basis']['value'] = 'invoice_date';
            $assumptions['purchase_date_basis']['explanation'] = 'Purchases are assigned to the invoice date, as explicitly requested.';
            $assumptions['purchase_date_basis']['source'] = 'explicit';
        }

        if (preg_match('/\b(?:use|using|prefer) (?:the )?estimated po line price\b/', $normalized) === 1) {
            $assumptions['investment_cost_basis']['value'] = 'estimated_po_line_price';
            $assumptions['investment_cost_basis']['explanation'] = 'Investment uses the estimated PO-line price, as explicitly requested.';
            $assumptions['investment_cost_basis']['source'] = 'explicit';
        }

        if (preg_match('/\b(?:use|using|prefer) (?:the )?lifetime circulation\b/', $normalized) === 1) {
            $assumptions['circulation_window']['value'] = 'lifetime_circulation';
            $assumptions['circulation_window']['explanation'] = 'Circulation is counted over the item lifetime, as explicitly requested.';
            $assumptions['circulation_window']['source'] = 'explicit';
        }

        if (preg_match('/\b(?:group by|use|using|prefer) (?:the )?first two call number letters\b/', $normalized) === 1) {
            $assumptions['call_number_grouping']['value'] = 'first_two_call_number_letters';
            $assumptions['call_number_grouping']['explanation'] = 'Call numbers are grouped by their first two letters, as explicitly requested.';
            $assumptions['call_number_grouping']['source'] = 'explicit';
        }

        if (self::matchesExplicitRequest($normalized, 'checkouts? per dollar')) {
            $assumptions['roi_formula']['explanation'] = 'ROI is checkouts per dollar, with cost per checkout returned as a companion measure, as explicitly requested.';
            $assumptions['roi_formula']['source'] = 'explicit';
        } elseif (self::matchesExplicitRequest($normalized, 'cost per (?:checkout|use)')) {
            $assumptions['roi_formula']['value'] = 'cost_per_checkout';
            $assumptions['roi_formula']['explanation'] = 'ROI is cost per checkout, as explicitly requested.';
            $assumptions['roi_formula']['source'] = 'explicit';
        }

        ksort($assumptions);
        return array_values($assumptions);
    }

    /**
     * @param array<int, array{key:string,label:string,value:string,explanation:string,correctionExample:string,source:string}> $assumptions
     */
    public static function buildPromptGuidance(array $assumptions): string
    {
        if ($assumptions === []) {
            return '';
        }

        $artifact = self::loadArtifact();
        $lines = ['DOCUMENTED INTERPRETATIONS'];

        foreach ($assumptions as $assumption) {
            $lines[] = sprintf(
                '- %s (%s) = %s: %s [%s]',
                $assumption['key'],
                $assumption['label'],
                $assumption['value'],
                $assumption['explanation'],
                $assumption['source']
            );
        }

        $lines[] = '';
        $lines[] = 'ROI PLAN GUIDANCE';
        foreach ($artifact['roiPlanGuidance'] as $guidance) {
            $lines[] = '- ' . $guidance;
        }

        return implode("\n", $lines);
    }

    private static function isCrossDomainRoiPrompt(string $normalized): bool
    {
        return preg_match('/\b(?:purchas[a-z]*|acquisitions?)\b/', $normalized) === 1
            && preg_match('/\b(?:circulation|checkouts?)\b/', $normalized) === 1
            && preg_match('/\bcall numbers?\b/', $normalized) === 1
            && preg_match('/\b(?:roi|return on investment)\b/', $normalized) === 1;
    }

    private static function normalize(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));
        return (string)$normalized;
    }

    private static function matchesExplicitRequest(string $normalized, string $phrasePattern): bool
    {
        return preg_match(
            '/(?<!not )\b(?:use|using|prefer) (?:the )?(?:' . $phrasePattern . ')\b/',
            $normalized
        ) === 1;
    }

    private static function loadArtifact(): array
    {
        $path = __DIR__ . '/../data/exploratory_query_defaults.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Documented exploratory query defaults are unavailable.');
        }

        $artifact = json_decode($contents, true);
        if (!is_array($artifact)) {
            throw new \RuntimeException('Documented exploratory query defaults are invalid.');
        }

        self::validateArtifact($artifact);
        return $artifact;
    }

    private static function validateArtifact(array $artifact): void
    {
        if (($artifact['artifactVersion'] ?? null) !== self::ARTIFACT_VERSION) {
            throw new \RuntimeException('Documented exploratory query defaults have an unsupported version.');
        }

        if (!is_array($artifact['defaults'] ?? null) || !is_array($artifact['roiPlanGuidance'] ?? null)) {
            throw new \RuntimeException('Documented exploratory query defaults are incomplete.');
        }

        $requiredFields = ['key', 'label', 'defaultValue', 'defaultExplanation', 'correctionExample'];
        $actualKeys = [];
        foreach ($artifact['defaults'] as $default) {
            if (!is_array($default)) {
                throw new \RuntimeException('Documented exploratory query defaults are invalid.');
            }

            foreach ($requiredFields as $field) {
                if (!isset($default[$field]) || !is_string($default[$field]) || trim($default[$field]) === '') {
                    throw new \RuntimeException('Documented exploratory query defaults are invalid.');
                }
            }

            $actualKeys[] = $default['key'];
        }

        sort($actualKeys);
        $requiredKeys = [
            'call_number_grouping',
            'circulation_window',
            'investment_cost_basis',
            'purchase_date_basis',
            'roi_formula',
        ];
        if ($actualKeys !== $requiredKeys) {
            throw new \RuntimeException('Documented exploratory query defaults must contain exactly the required unique keys.');
        }

        foreach ($artifact['roiPlanGuidance'] as $guidance) {
            if (!is_string($guidance) || trim($guidance) === '') {
                throw new \RuntimeException('Documented exploratory query defaults are invalid.');
            }
        }
    }
}
