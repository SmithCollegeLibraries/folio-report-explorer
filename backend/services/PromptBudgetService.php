<?php

namespace app\services;

class PromptBudgetService
{
    const DEFAULT_TOTAL_MAX_BYTES = 300000;
    const DEFAULT_TOTAL_WARNING_BYTES = 270000;

    private const DEFAULT_SECTION_THRESHOLDS = [
        'header' => ['maxBytes' => 5000, 'warningBytes' => 3500],
        'tables' => ['maxBytes' => 220000, 'warningBytes' => 200000],
        'subtables' => ['maxBytes' => 30000, 'warningBytes' => 24000],
        'data_patterns' => ['maxBytes' => 40000, 'warningBytes' => 32000],
        'location_naming' => ['maxBytes' => 4000, 'warningBytes' => 3000],
        'vocabulary' => ['maxBytes' => 25000, 'warningBytes' => 20000],
        'pattern_cards' => ['maxBytes' => 25000, 'warningBytes' => 20000],
        'examples' => ['maxBytes' => 30000, 'warningBytes' => 24000],
        'local_tables' => ['maxBytes' => 4000, 'warningBytes' => 3000],
    ];

    public static function buildBudgetReport(array $sections, array $thresholds = [], string $separator = "\n"): array
    {
        $resolvedThresholds = self::resolveThresholds($thresholds);
        $normalizedSections = [];
        $sectionTexts = [];
        $breachedSections = [];
        $warningSections = [];

        foreach ($sections as $sectionKey => $section) {
            $sectionKey = trim((string)$sectionKey);
            if ($sectionKey === '') {
                continue;
            }

            $text = trim((string)($section['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $sectionThresholds = $resolvedThresholds['sections'][$sectionKey] ?? [];
            $bytes = strlen($text);
            $maxBytes = isset($sectionThresholds['maxBytes']) ? (int)$sectionThresholds['maxBytes'] : null;
            $warningBytes = isset($sectionThresholds['warningBytes']) ? (int)$sectionThresholds['warningBytes'] : null;
            $withinBudget = $maxBytes === null ? true : $bytes <= $maxBytes;
            $warning = $warningBytes !== null && $bytes >= $warningBytes;

            if (!$withinBudget) {
                $breachedSections[] = $sectionKey;
            }

            if ($warning) {
                $warningSections[] = $sectionKey;
            }

            $normalizedSections[$sectionKey] = [
                'label' => trim((string)($section['label'] ?? $sectionKey)),
                'bytes' => $bytes,
                'itemCount' => isset($section['itemCount']) ? (int)$section['itemCount'] : null,
                'maxBytes' => $maxBytes,
                'warningBytes' => $warningBytes,
                'withinBudget' => $withinBudget,
                'warning' => $warning,
            ];
            $sectionTexts[$sectionKey] = $text;
        }

        $joinedText = self::joinSectionTexts($sectionTexts, $separator);
        $totalBytes = strlen($joinedText);
        $totalMaxBytes = (int)$resolvedThresholds['total']['maxBytes'];
        $totalWarningBytes = (int)$resolvedThresholds['total']['warningBytes'];
        $withinBudget = $totalBytes <= $totalMaxBytes && empty($breachedSections);

        if ($totalBytes >= $totalWarningBytes) {
            $warningSections[] = 'total';
        }

        return [
            'totalBytes' => $totalBytes,
            'totalMaxBytes' => $totalMaxBytes,
            'totalWarningBytes' => $totalWarningBytes,
            'withinBudget' => $withinBudget,
            'warningSections' => self::sortedUniqueStrings($warningSections),
            'breachedSections' => self::sortedUniqueStrings($breachedSections),
            'sections' => $normalizedSections,
        ];
    }

    public static function joinSectionTexts(array $sectionTexts, string $separator = "\n"): string
    {
        $parts = [];
        foreach ($sectionTexts as $text) {
            $text = trim((string)$text);
            if ($text === '') {
                continue;
            }
            $parts[] = $text;
        }

        return implode($separator, $parts);
    }

    private static function resolveThresholds(array $thresholds): array
    {
        $total = $thresholds['total'] ?? [];
        $sections = $thresholds['sections'] ?? [];

        return [
            'total' => [
                'maxBytes' => isset($total['maxBytes']) ? (int)$total['maxBytes'] : self::DEFAULT_TOTAL_MAX_BYTES,
                'warningBytes' => isset($total['warningBytes']) ? (int)$total['warningBytes'] : self::DEFAULT_TOTAL_WARNING_BYTES,
            ],
            'sections' => array_replace_recursive(self::DEFAULT_SECTION_THRESHOLDS, is_array($sections) ? $sections : []),
        ];
    }

    private static function sortedUniqueStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }
}