<?php

namespace app\services;

class QueryFamilySlotService
{
    const DEFAULT_LIMIT = 100;
    const DEFAULT_MATCH_POLICY = 'case_insensitive_contains';

    public static function validateFamilyPayload($payload, array $defaults = []): array
    {
        $errors = [];

        if (!is_array($payload)) {
            return [
                'valid' => false,
                'errors' => [self::err('payload', 'type', 'Family slot payload must be an object.')],
                'normalizedPayload' => null,
            ];
        }

        $familyKey = trim((string)($payload['familyKey'] ?? ''));
        if ($familyKey === '') {
            $errors[] = self::err('familyKey', 'required', 'familyKey is required.');
        }

        $slots = $payload['slots'] ?? null;
        if (!is_array($slots)) {
            $errors[] = self::err('slots', 'required', 'slots object is required.');
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'normalizedPayload' => null,
            ];
        }

        try {
            $contracts = QueryFamilyContractService::loadContracts();
        } catch (\RuntimeException $e) {
            return [
                'valid' => false,
                'errors' => [self::err('familyKey', 'contract_missing', $e->getMessage())],
                'normalizedPayload' => null,
            ];
        }

        $contract = $contracts[$familyKey] ?? null;
        if (!is_array($contract)) {
            return [
                'valid' => false,
                'errors' => [self::err('familyKey', 'unsupported_family', 'Unsupported query family: ' . $familyKey . '.')],
                'normalizedPayload' => null,
            ];
        }

        $supportedSlotNames = self::sortedUniqueStrings($contract['slots']['supported'] ?? []);
        $requiredSlotNames = self::sortedUniqueStrings($contract['slots']['required'] ?? []);
        $allowedOutputs = self::sortedUniqueStrings($contract['outputs']['allowed'] ?? []);
        $supportedMatchPolicies = self::sortedUniqueStrings($contract['matchPolicy']['supported'] ?? []);
        $defaultMatchPolicy = trim((string)($contract['matchPolicy']['default'] ?? 'case_insensitive_contains'));

        $normalizedSlots = [];
        foreach ($supportedSlotNames as $slotName) {
            $value = $slots[$slotName] ?? ($defaults[$slotName] ?? null);
            if ($value === null) {
                continue;
            }

            if (self::isGenericPlaceholderSlotValue($slotName, $value)) {
                continue;
            }

            if (self::isEmptyOmittableSlotValue($slotName, $value)) {
                continue;
            }

            $normalizedValue = self::normalizeSlotValue($slotName, $value);
            if ($normalizedValue === null) {
                $message = $slotName === 'year_buckets'
                    ? 'year_buckets must be a non-empty array of 4-digit years.'
                    : 'Slot values must be non-empty strings.';
                $errors[] = self::err('slots.' . $slotName, 'type', $message);
                continue;
            }

            $normalizedSlots[$slotName] = $normalizedValue;
        }

        if (
            $familyKey === 'inventory_library_location_listing'
            && self::locationCodeScopeSatisfiesListingRequirement($normalizedSlots['location_code'] ?? null)
        ) {
            $requiredSlotNames = array_values(array_filter(
                $requiredSlotNames,
                static function (string $slotName): bool {
                    return $slotName !== 'library';
                }
            ));
        }

        foreach ($requiredSlotNames as $slotName) {
            if (!isset($normalizedSlots[$slotName])) {
                $errors[] = self::err('slots.' . $slotName, 'required', 'Slot is required for this query family.');
            }
        }

        $requestedOutputs = $slots['requested_outputs'] ?? null;
        if (!is_array($requestedOutputs) || empty($requestedOutputs)) {
            $errors[] = self::err('slots.requested_outputs', 'required', 'requested_outputs must be a non-empty array.');
        } else {
            $normalizedOutputs = [];
            foreach ($requestedOutputs as $index => $outputField) {
                $outputField = trim((string)$outputField);
                if ($outputField === '') {
                    $errors[] = self::err('slots.requested_outputs[' . $index . ']', 'type', 'Output fields must be non-empty strings.');
                    continue;
                }

                if (!in_array($outputField, $allowedOutputs, true)) {
                    $errors[] = self::err(
                        'slots.requested_outputs[' . $index . ']',
                        'unsupported_output',
                        'Unsupported output field for this query family: ' . $outputField . '.'
                    );
                    continue;
                }

                $normalizedOutputs[] = $outputField;
            }

            $normalizedSlots['requested_outputs'] = self::sortedUniqueStrings($normalizedOutputs);
        }

        $matchPolicy = trim((string)($slots['match_policy'] ?? $defaults['match_policy'] ?? $defaultMatchPolicy));
        if ($matchPolicy === '') {
            $matchPolicy = $defaultMatchPolicy;
        }

        if (!in_array($matchPolicy, $supportedMatchPolicies, true)) {
            $errors[] = self::err('slots.match_policy', 'unsupported_match_policy', 'Unsupported match policy: ' . $matchPolicy . '.');
        } else {
            $normalizedSlots['match_policy'] = $matchPolicy;
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'normalizedPayload' => null,
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'normalizedPayload' => [
                'familyKey' => $familyKey,
                'slots' => $normalizedSlots,
            ],
        ];
    }

    public static function toQueryIntent(array $payload): array
    {
        $validation = self::validateFamilyPayload($payload);
        if (!$validation['valid']) {
            $firstError = $validation['errors'][0] ?? ['message' => 'Invalid family slot payload.'];
            throw new \InvalidArgumentException((string)($firstError['message'] ?? 'Invalid family slot payload.'));
        }

        $normalizedPayload = $validation['normalizedPayload'];
        $familyKey = $normalizedPayload['familyKey'];
        $slots = $normalizedPayload['slots'];
        $contracts = QueryFamilyContractService::loadContracts();
        $contract = $contracts[$familyKey];

        $tables = self::sortedUniqueStrings($contract['graph']['requiredEntities'] ?? []);
        if (!empty($slots['material_type'])) {
            $tables[] = 'inventory_material_types';
            $tables = self::sortedUniqueStrings($tables);
        }

        $select = [];
        foreach ($slots['requested_outputs'] as $outputField) {
            switch ($outputField) {
                case 'barcode':
                    $select[] = ['table' => 'inventory_items', 'column' => 'barcode'];
                    break;
                case 'contributor_name':
                    $select[] = [
                        'table' => 'inventory_instance__t__contributors',
                        'column' => 'contributors__name',
                        'alias' => 'contributor_name',
                    ];
                    break;
                case 'instance_hrid':
                    $select[] = ['table' => 'inventory_instances', 'column' => 'hrid'];
                    break;
                case 'item_id':
                    $select[] = ['table' => 'inventory_items', 'column' => 'id', 'alias' => 'item_id'];
                    break;
                case 'publication_date':
                    $select[] = ['table' => 'inventory_instances', 'column' => 'dates__date1', 'alias' => 'publication_date'];
                    break;
                case 'title':
                    $select[] = ['table' => 'inventory_instances', 'column' => 'title'];
                    break;
            }
        }

        $where = [];
        $where[] = self::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
        $where[] = self::buildSlotFilter(
            'contributor_name',
            'inventory_instance__t__contributors',
            'contributors__name',
            $slots['contributor_name'],
            $slots['match_policy']
        );

        if (!empty($slots['contributor_name_type'])) {
            $where[] = self::buildSlotFilter(
                'contributor_name_type',
                'inventory_contributor_name_types',
                'name',
                $slots['contributor_name_type'],
                $slots['match_policy']
            );
        }

        if (!empty($slots['material_type'])) {
            $where[] = self::buildSlotFilter(
                'material_type',
                'inventory_material_types',
                'name',
                $slots['material_type'],
                $slots['match_policy']
            );
        }

        return [
            'intentVersion' => 1,
            'query' => [
                'tables' => $tables,
                'select' => $select,
                'where' => $where,
                'joins' => 'auto',
                'groupBy' => [],
                'having' => [],
                'sort' => [],
                'distinct' => false,
                'limit' => self::DEFAULT_LIMIT,
            ],
        ];
    }

    public static function applyPromptMatchPolicy(array $normalizedPayload, string $prompt, $campus = null): array
    {
        if (!is_array($normalizedPayload['slots'] ?? null)) {
            return $normalizedPayload;
        }

        $slots = $normalizedPayload['slots'];
        if (self::promptRequiresExactPhrase($prompt, $slots, $campus)) {
            $slots['match_policy'] = 'exact_phrase';
        }

        $normalizedPayload['slots'] = $slots;
        return $normalizedPayload;
    }

    public static function buildSlotFilter(string $slotName, string $table, string $column, string $value, string $matchPolicy): array
    {
        $normalized = self::resolveSlotMatch($slotName, $value, $matchPolicy);
        return [
            'table' => $table,
            'column' => $column,
            'op' => $normalized['op'],
            'value' => $normalized['value'],
        ];
    }

    public static function resolveSlotMatch(string $slotName, string $value, string $matchPolicy): array
    {
        $normalizedValue = self::stripWildcards(trim($value));
        if ($slotName === 'location') {
            $normalizedValue = self::normalizeLocationScopeLabel($normalizedValue);
        }
        $lowerValue = strtolower($normalizedValue);
        $effectiveMatchPolicy = trim($matchPolicy) === '' ? self::DEFAULT_MATCH_POLICY : trim($matchPolicy);

        if ($slotName === 'contributor_name_type') {
            return [
                'op' => 'ILIKE',
                'value' => $normalizedValue,
            ];
        }

        if ($slotName === 'material_type') {
            if (strpos($lowerValue, 'thes') !== false) {
                return [
                    'op' => 'ILIKE',
                    'value' => '%thesis%',
                ];
            }

            if (strpos($lowerValue, 'dissert') !== false) {
                return [
                    'op' => 'ILIKE',
                    'value' => '%dissertation%',
                ];
            }

            if ($effectiveMatchPolicy === 'exact_phrase') {
                return [
                    'op' => 'ILIKE',
                    'value' => $normalizedValue,
                ];
            }

            return [
                'op' => 'ILIKE',
                'value' => '%' . $normalizedValue . '%',
            ];
        }

        if ($slotName === 'location_code') {
            $locationCodes = self::normalizeDelimitedLocationCodes($normalizedValue);
            if (count($locationCodes) > 1) {
                return [
                    'op' => 'IN',
                    'value' => implode(',', $locationCodes),
                ];
            }

            return [
                'op' => 'ILIKE',
                'value' => $locationCodes[0] ?? strtoupper($normalizedValue),
            ];
        }

        if ($slotName === 'campus' && self::isCanonicalCampusName($normalizedValue)) {
            return [
                'op' => 'ILIKE',
                'value' => $normalizedValue,
            ];
        }

        if ($slotName === 'library' || $slotName === 'location') {
            return [
                'op' => 'ILIKE',
                'value' => '%' . $normalizedValue . '%',
            ];
        }

        if ($effectiveMatchPolicy === 'exact_phrase') {
            return [
                'op' => 'ILIKE',
                'value' => $normalizedValue,
            ];
        }

        return [
            'op' => 'ILIKE',
            'value' => '%' . $normalizedValue . '%',
        ];
    }

    private static function normalizeLocationScopeLabel(string $value): string
    {
        $normalized = trim((string) preg_replace('/\s+(collection|location)\s*$/i', '', $value));
        return $normalized === '' ? $value : $normalized;
    }

    private static function normalizeDelimitedLocationCodes(string $value): array
    {
        preg_match_all('/\b[A-Z0-9]{3,10}\b/', strtoupper($value), $matches);

        $codes = [];
        foreach (($matches[0] ?? []) as $code) {
            if (in_array($code, ['AND', 'OR'], true)) {
                continue;
            }

            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        if ($codes !== []) {
            return $codes;
        }

        $fallback = strtoupper(trim($value));
        return $fallback === '' ? [] : [$fallback];
    }

    private static function promptRequiresExactPhrase(string $prompt, array $slots, $campus = null): bool
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return false;
        }

        $candidateValues = [];
        foreach (['contributor_name', 'campus', 'contributor_name_type'] as $slotName) {
            $value = trim((string)($slots[$slotName] ?? ''));
            if ($value !== '') {
                $candidateValues[] = $value;
            }
        }

        if (is_string($campus) && trim($campus) !== '') {
            $candidateValues[] = trim($campus);
        }

        foreach (self::extractQuotedPhrases($prompt) as $quotedPhrase) {
            foreach ($candidateValues as $candidateValue) {
                if (strcasecmp($quotedPhrase, $candidateValue) === 0) {
                    return true;
                }
            }
        }

        foreach ($candidateValues as $candidateValue) {
            if (self::promptUsesExactNameMarker($prompt, $candidateValue)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeScalarSlotValue($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string)$value);
        return $normalized === '' ? null : $normalized;
    }

    private static function isEmptyOmittableSlotValue(string $slotName, $value): bool
    {
        if ($slotName === 'year_buckets') {
            return false;
        }

        return is_scalar($value) && trim((string)$value) === '';
    }

    private static function locationCodeScopeSatisfiesListingRequirement($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        return count(self::normalizeDelimitedLocationCodes((string)$value)) > 1;
    }

    private static function normalizeSlotValue(string $slotName, $value)
    {
        if ($slotName === 'year_buckets') {
            return self::normalizeYearBuckets($value);
        }

        return self::normalizeScalarSlotValue($value);
    }

    private static function isGenericPlaceholderSlotValue(string $slotName, $value): bool
    {
        if ($slotName === 'year_buckets') {
            $normalizedYearBucketPlaceholder = null;

            if (is_scalar($value)) {
                $normalizedYearBucketPlaceholder = strtolower(trim((string)$value));
            } elseif (is_array($value) && count($value) === 1 && is_scalar($value[0] ?? null)) {
                $normalizedYearBucketPlaceholder = strtolower(trim((string)$value[0]));
            }

            if ($normalizedYearBucketPlaceholder === null || $normalizedYearBucketPlaceholder === '') {
                return false;
            }

            $normalizedYearBucketPlaceholder = preg_replace('/[^a-z0-9]+/', ' ', $normalizedYearBucketPlaceholder);
            $normalizedYearBucketPlaceholder = trim((string)$normalizedYearBucketPlaceholder);

            return in_array($normalizedYearBucketPlaceholder, [
                'selected year',
                'selected years',
                'required year',
                'required years',
                'specified year',
                'specified years',
                'chosen year',
                'chosen years',
            ], true);
        }

        if (!is_scalar($value)) {
            return false;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = trim((string)$normalized);
        if ($normalized === '') {
            return false;
        }

        if ($slotName === 'material_type') {
            return in_array($normalized, [
                'document type',
                'document types',
                'material type',
                'material types',
                'item type',
                'item types',
                'resource type',
                'resource types',
                'format',
                'formats',
                'type',
                'types',
            ], true);
        }

        if ($slotName === 'grouping_dimension') {
            return in_array($normalized, [
                'grouping dimension',
                'grouping dimensions',
                'group dimension',
                'group dimensions',
                'dimension',
                'dimensions',
            ], true);
        }

        if ($slotName === 'library') {
            return in_array($normalized, [
                'library',
                'libraries',
                'specific library',
                'the library',
            ], true);
        }

        if ($slotName === 'location') {
            return in_array($normalized, [
                'location',
                'locations',
                'specific location',
                'the location',
            ], true);
        }

        if ($slotName === 'location_code') {
            return in_array($normalized, [
                'location code',
                'location codes',
                'specific location code',
                'the location code',
            ], true);
        }

        if ($slotName !== 'contributor_name') {
            return false;
        }

        return in_array($normalized, [
            'author',
            'authors',
            'other author',
            'other authors',
            'author name',
            'author names',
            'contributor',
            'contributors',
            'contributor name',
            'contributor names',
            'the author',
            'the authors',
            'the contributor',
            'the contributors',
        ], true);
    }

    private static function normalizeYearBuckets($value): ?array
    {
        if (!is_array($value) || empty($value)) {
            return null;
        }

        $normalizedYears = [];
        foreach ($value as $yearValue) {
            if (!is_scalar($yearValue)) {
                return null;
            }

            $normalizedYear = trim((string)$yearValue);
            if (!preg_match('/^\d{4}$/', $normalizedYear)) {
                return null;
            }

            if (!in_array($normalizedYear, $normalizedYears, true)) {
                $normalizedYears[] = $normalizedYear;
            }
        }

        return $normalizedYears === [] ? null : $normalizedYears;
    }

    private static function extractQuotedPhrases(string $prompt): array
    {
        preg_match_all('/["\']([^"\']+)["\']/', $prompt, $matches);
        $phrases = [];
        foreach ($matches[1] ?? [] as $phrase) {
            $phrase = trim((string)$phrase);
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    private static function promptUsesExactNameMarker(string $prompt, string $candidateValue): bool
    {
        $normalizedPrompt = strtolower($prompt);
        $normalizedCandidate = strtolower($candidateValue);
        $markers = [
            'named',
            'listed as',
            'called',
        ];

        foreach ($markers as $marker) {
            $offset = 0;
            while (($position = strpos($normalizedPrompt, $marker, $offset)) !== false) {
                $window = substr(
                    $normalizedPrompt,
                    $position,
                    strlen($marker) + 80 + strlen($normalizedCandidate)
                );
                if (strpos($window, $normalizedCandidate) !== false) {
                    return true;
                }
                $offset = $position + strlen($marker);
            }
        }

        return false;
    }

    private static function stripWildcards(string $value): string
    {
        return trim(str_replace('%', '', $value));
    }

    private static function isCanonicalCampusName(string $value): bool
    {
        static $campusNames = [
            'Smith College' => true,
            'Amherst College' => true,
            'Mount Holyoke College' => true,
            'University Of Massachusetts' => true,
            'Hampshire College' => true,
            'Five Colleges Collections' => true,
            'National Yiddish Book Center' => true,
        ];

        return isset($campusNames[$value]);
    }

    private static function err(string $path, string $code, string $message): array
    {
        return [
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }

    private static function sortedUniqueStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }
}