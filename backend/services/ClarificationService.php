<?php

namespace app\services;

class ClarificationService
{
    public static function detectPromptAmbiguity(string $prompt): ?array
    {
        $normalized = self::normalize($prompt);
        if ($normalized === '') {
            return null;
        }

        if (strpos($normalized, 'clarification use inventory location t name') !== false) {
            return null;
        }

        if (preg_match('/\bmrbc\b/', $normalized) === 1) {
            if (preg_match('/\b(reference|ref)\b/', $normalized) === 1) {
                return self::resolvedAliasConfirmation(
                    'location_alias.mrbc_reference',
                    'MRBC Reference Collection',
                    'SC Rare Book Collection Reference',
                    'confirm_resolved_alias:location_alias.mrbc_reference'
                );
            }
            if (preg_match('/\b(collection|rare book collection)\b/', $normalized) === 1) {
                return self::resolvedAliasConfirmation(
                    'location_alias.mrbc_collection',
                    'MRBC Collection',
                    'SC Rare Book Collection',
                    'confirm_resolved_alias:location_alias.mrbc_collection'
                );
            }

            return self::mrbcClarification();
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function buildPromptGuidance(string $prompt): array
    {
        $normalized = self::normalize($prompt);
        $lines = [];

        if (preg_match('/\b(?:5|five)\s+colleg(?:e|es|se)\b/', $normalized) === 1) {
            $lines[] = 'Treat "5 Collegse" as "Five Colleges" when it appears in user prompts; this is a spelling/number variant, not a separate institution.';
            $lines[] = 'Five Colleges, 5 Colleges, Five College, and 5 Collegse all refer to the Five Colleges consortium context.';
        }

        if (preg_match('/\bmrbc\s+(?:reference|ref)\b/', $normalized) === 1) {
            $lines[] = "MRBC Reference means inventory.location__t.name = 'SC Rare Book Collection Reference'.";
        } elseif (preg_match('/\bmrbc\s+(?:collection|rare book collection)\b/', $normalized) === 1) {
            $lines[] = "MRBC Collection means inventory.location__t.name = 'SC Rare Book Collection'.";
        }

        return $lines;
    }

    private static function mrbcClarification(): array
    {
        return [
            'needsClarification' => true,
            'clarificationType' => 'ambiguous_local_alias',
            'clarificationKey' => 'location_alias.mrbc',
            'question' => 'Which rare book location do you mean?',
            'inputType' => 'single_choice',
            'freeTextAllowed' => true,
            'options' => [
                [
                    'id' => 'sc_rare_book_collection',
                    'label' => 'SC Rare Book Collection',
                    'recommended' => true,
                    'clarifiedPromptSuffix' => 'Use inventory.location__t.name = SC Rare Book Collection for MRBC.',
                    'resolvedFilter' => [
                        'table' => 'inventory.location__t',
                        'column' => 'name',
                        'operator' => '=',
                        'value' => 'SC Rare Book Collection',
                    ],
                ],
                [
                    'id' => 'sc_rare_book_collection_reference',
                    'label' => 'SC Rare Book Collection Reference',
                    'recommended' => false,
                    'clarifiedPromptSuffix' => 'Use inventory.location__t.name = SC Rare Book Collection Reference for MRBC.',
                    'resolvedFilter' => [
                        'table' => 'inventory.location__t',
                        'column' => 'name',
                        'operator' => '=',
                        'value' => 'SC Rare Book Collection Reference',
                    ],
                ],
            ],
            'warnings' => [],
            'suggestions' => [],
            'route' => 'clarification',
            'routeReason' => 'ambiguous_local_alias:location_alias.mrbc',
        ];
    }

    private static function resolvedAliasConfirmation(
        string $clarificationKey,
        string $aliasLabel,
        string $locationName,
        string $routeReason
    ): array {
        return [
            'needsClarification' => true,
            'clarificationType' => 'confirm_resolved_alias',
            'clarificationKey' => $clarificationKey,
            'question' => "I interpreted {$aliasLabel} as {$locationName}. Continue?",
            'inputType' => 'single_choice',
            'freeTextAllowed' => true,
            'options' => [
                [
                    'id' => strtolower(str_replace(' ', '_', $locationName)),
                    'label' => "Continue with {$locationName}",
                    'recommended' => true,
                    'clarifiedPromptSuffix' => "Use inventory.location__t.name = {$locationName} for MRBC.",
                    'resolvedFilter' => [
                        'table' => 'inventory.location__t',
                        'column' => 'name',
                        'operator' => '=',
                        'value' => $locationName,
                    ],
                ],
            ],
            'warnings' => [],
            'suggestions' => [],
            'route' => 'clarification',
            'routeReason' => $routeReason,
        ];
    }

    private static function normalize(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));
        return (string)$normalized;
    }
}
