<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;

/** Validates the shared per-condition rules used by the MARC finder reports. */
final class CatalogingMarcConditionValidator
{
    private const CONTENT_RULES = [
        'any', 'contains', 'not_contains', 'equals', 'not_equals', 'begins',
        'not_begins', 'blank', 'not_blank', 'has_lowercase', 'has_non_alphanumeric',
    ];
    private const TEXT_CONTENT_RULES = [
        'contains', 'not_contains', 'equals', 'not_equals', 'begins', 'not_begins',
    ];

    /** @return array{marcTag:string,occurrenceCondition:string,firstIndicator:string,secondIndicator:string,subfieldCode:string,contentRule:string,searchValue:string,caseExact:string} */
    public static function validate(array $values, string $fieldPrefix = ''): array
    {
        $tag = $values['marcTag'] ?? null;
        if (!is_string($tag)
            || preg_match('/^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/D', $tag) !== 1) {
            self::fail($fieldPrefix, 'marcTag', 'MARC tag must be exactly three ASCII digits from 001 through 999.');
        }

        $occurrence = self::oneOf(
            $values['occurrenceCondition'] ?? null,
            ['has', 'missing'],
            $fieldPrefix,
            'occurrenceCondition',
            'Occurrence condition must be has or missing.'
        );
        $first = self::normalizeIndicator($values['firstIndicator'] ?? null, $fieldPrefix . 'firstIndicator');
        $second = self::normalizeIndicator($values['secondIndicator'] ?? null, $fieldPrefix . 'secondIndicator');

        $subfield = $values['subfieldCode'] ?? null;
        if (!is_string($subfield) || ($subfield !== '' && preg_match('/\A[A-Za-z0-9]\z/D', $subfield) !== 1)) {
            self::fail($fieldPrefix, 'subfieldCode', 'Subfield code must be blank or one ASCII alphanumeric character.');
        }

        $rule = self::oneOf(
            $values['contentRule'] ?? null,
            self::CONTENT_RULES,
            $fieldPrefix,
            'contentRule',
            'A supported content rule is required.'
        );
        $searchValue = $values['searchValue'] ?? null;
        if (!is_string($searchValue)) {
            self::fail($fieldPrefix, 'searchValue', 'Search text must be a string.');
        }
        $usesText = in_array($rule, self::TEXT_CONTENT_RULES, true);
        if ($usesText && $searchValue === '') {
            self::fail($fieldPrefix, 'searchValue', 'Search text is required for this content rule.');
        }
        if (!$usesText && $searchValue !== '') {
            self::fail($fieldPrefix, 'searchValue', 'Search text must be empty for this content rule.');
        }

        $caseExact = self::oneOf(
            $values['caseExact'] ?? null,
            ['true', 'false'],
            $fieldPrefix,
            'caseExact',
            'Case matching must be true or false.'
        );
        if (!$usesText) {
            $caseExact = 'false';
        }

        return [
            'marcTag' => $tag,
            'occurrenceCondition' => $occurrence,
            'firstIndicator' => $first,
            'secondIndicator' => $second,
            'subfieldCode' => $subfield,
            'contentRule' => $rule,
            'searchValue' => $searchValue,
            'caseExact' => $caseExact,
        ];
    }

    private static function normalizeIndicator($value, string $field): string
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Indicator must be any, blank, or char: plus one character.');
        }
        if ($value === 'any' || $value === 'blank') {
            return $value;
        }
        if (preg_match('/\Achar:(.)\z/us', $value, $matches) !== 1) {
            throw new ReportParameterValidationException($field, 'Indicator must be any, blank, or char: plus one character.');
        }
        if ($matches[1] === '\\' || trim($matches[1]) === '') {
            return 'blank';
        }
        return $value;
    }

    private static function oneOf($value, array $allowed, string $prefix, string $field, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::fail($prefix, $field, $message);
        }
        return $value;
    }

    private static function fail(string $prefix, string $field, string $message): void
    {
        throw new ReportParameterValidationException($prefix . $field, $message);
    }
}
