<?php

namespace app\services;

require_once __DIR__ . '/ExploratorySqlAnalysisService.php';

/**
 * Extracts only explicitly labelled report values and checks their SQL coverage.
 */
final class ExplicitReportRequestService
{
    private const MAX_IDENTIFIERS = 500;

    private const OUTPUT_PATTERNS = [
        'title' => '/\btitles?\b/i',
        'barcode' => '/\bbarcodes?\b/i',
        'publication_date' => '/\b(?:publication|pub)\s*(?:date|dates)\b|\bpublication_date\b/i',
    ];

    public static function extract(string $prompt): array
    {
        $identifiers = [];
        self::appendIdentifiers(
            $identifiers,
            'instance_hrid',
            self::extractAnchoredValues(
                $prompt,
                '/\binstance\s*(?:numbers?|hrids?)\b\s*(?:are|is|of|:|=)?\s*/i',
                '/^in[0-9][a-z0-9_-]*$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'item_barcode',
            self::extractAnchoredValues(
                $prompt,
                '/\b(?:item\s+)?barcodes?\b\s*(?:are|is|of|:|=)?\s*/i',
                '/^[a-z0-9_-]+$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'instance_id',
            self::extractAnchoredValues(
                $prompt,
                '/\binstance\s+ids?\b\s*(?:are|is|of|:|=)?\s*/i',
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'item_id',
            self::extractAnchoredValues(
                $prompt,
                '/\bitem\s+ids?\b\s*(?:are|is|of|:|=)?\s*/i',
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'
            )
        );

        $overflow = self::identifierCount($identifiers) > self::MAX_IDENTIFIERS;
        if ($overflow) {
            $identifiers = self::limitedIdentifiers($identifiers, self::MAX_IDENTIFIERS);
        }

        $requestedFields = self::requestedFields($prompt);
        $limit = self::extractLimit($prompt);

        return [
            'applicable' => $identifiers !== [] || $requestedFields !== [] || $limit !== null,
            'identifiers' => $identifiers,
            'requestedFields' => $requestedFields,
            'limit' => $limit,
            'needsClarification' => $overflow,
            'clarificationReason' => $overflow ? 'too_many_explicit_identifiers' : null,
        ];
    }

    public static function buildGuidance(array $request): string
    {
        if (empty($request['applicable'])) {
            return '';
        }

        $lines = ['EXPLICIT REPORT VALUES — preserve exactly:'];
        foreach (($request['identifiers'] ?? []) as $kind => $values) {
            if (!is_array($values) || $values === []) {
                continue;
            }
            $lines[] = $kind . ': ' . json_encode(array_values($values), JSON_UNESCAPED_SLASHES);
        }
        if (!empty($request['requestedFields'])) {
            $lines[] = 'requested_fields: ' . json_encode(array_values($request['requestedFields']), JSON_UNESCAPED_SLASHES);
        }
        if (($request['limit'] ?? null) !== null) {
            $lines[] = 'limit: ' . (int)$request['limit'];
        }
        $lines[] = 'Do not broaden, replace, or infer additional identifiers.';
        return implode("\n", $lines);
    }

    public static function appendGuidance(string $prompt, array $request): string
    {
        $guidance = self::buildGuidance($request);
        return $guidance === '' ? $prompt : $prompt . "\n\n" . $guidance;
    }

    public static function removeGuidance(string $prompt): string
    {
        $marker = "\n\nEXPLICIT REPORT VALUES — preserve exactly:\n";
        $position = strpos($prompt, $marker);
        return $position === false ? $prompt : substr($prompt, 0, $position);
    }

    public static function validateCandidate(string $sql, array $request): array
    {
        $analysis = ExploratorySqlAnalysisService::analyze($sql);
        $missingIdentifiers = [];
        foreach (($request['identifiers'] ?? []) as $kind => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (!self::hasIdentifierFilter($analysis, (string)$kind, (string)$value)) {
                    $missingIdentifiers[] = (string)$value;
                }
            }
        }

        $outputAliases = self::outputAliases($analysis);
        $missingFields = [];
        foreach (($request['requestedFields'] ?? []) as $field) {
            if (!in_array((string)$field, $outputAliases, true)) {
                $missingFields[] = (string)$field;
            }
        }

        $limitValid = self::limitMatches($sql, $request['limit'] ?? null);
        return [
            'valid' => $missingIdentifiers === [] && $missingFields === [] && $limitValid,
            'missingIdentifiers' => $missingIdentifiers,
            'missingFields' => $missingFields,
            'limitValid' => $limitValid,
        ];
    }

    private static function extractAnchoredValues(string $prompt, string $anchorPattern, string $valuePattern): array
    {
        if (preg_match_all($anchorPattern, $prompt, $anchors, PREG_OFFSET_CAPTURE) !== false) {
            $values = [];
            foreach ($anchors[0] as $anchor) {
                $tail = substr($prompt, $anchor[1] + strlen($anchor[0]));
                $values = array_merge($values, self::readValueSequence($tail, $valuePattern));
            }
            return $values;
        }
        return [];
    }

    private static function readValueSequence(string $tail, string $valuePattern): array
    {
        $values = [];
        $offset = 0;
        while (preg_match('/\G\s*(?:,|;|\band\b)?\s*(?:"([^"]+)"|\'([^\']+)\'|([a-z0-9_-]+))\s*/i', $tail, $match, 0, $offset) === 1) {
            $value = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
            if (self::isValueSequenceTerminator($value) || preg_match($valuePattern, $value) !== 1) {
                break;
            }
            $values[] = self::normalizeIdentifier($value);
            $offset += strlen($match[0]);
        }
        return $values;
    }

    private static function appendIdentifiers(array &$identifiers, string $kind, array $values): void
    {
        foreach ($values as $value) {
            if (!isset($identifiers[$kind])) {
                $identifiers[$kind] = [];
            }
            if (!in_array($value, $identifiers[$kind], true)) {
                $identifiers[$kind][] = $value;
            }
        }
    }

    private static function requestedFields(string $prompt): array
    {
        $positions = [];
        if (preg_match_all('/\b(?:show|include|return|display|provide|generate)\b([^.!?]*)/i', $prompt, $clauses, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($clauses[1] as $clause) {
                $outputText = self::outputTextBeforeIdentifierFilter($clause[0]);
                foreach (self::OUTPUT_PATTERNS as $field => $pattern) {
                    if (preg_match($pattern, $outputText, $match, PREG_OFFSET_CAPTURE) === 1) {
                        $position = $clause[1] + $match[0][1];
                        if (!isset($positions[$field]) || $position < $positions[$field]) {
                            $positions[$field] = $position;
                        }
                    }
                }
            }
        }
        asort($positions, SORT_NUMERIC);
        return array_keys($positions);
    }

    private static function outputTextBeforeIdentifierFilter(string $text): string
    {
        $pattern = '/\b(?:for|where|with|using|matching|filtered\s+by)\s+(?:an?\s+)?(?:instance\s*(?:numbers?|hrids?|ids?)|item\s+ids?|(?:item\s+)?barcodes?)\b/i';
        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $text;
        }
        return substr($text, 0, $match[0][1]);
    }

    private static function extractLimit(string $prompt): ?int
    {
        if (preg_match('/\blimit\s+([1-9][0-9]*)\b/i', $prompt, $match) !== 1) {
            return null;
        }
        return (int)$match[1];
    }

    private static function identifierCount(array $identifiers): int
    {
        $count = 0;
        foreach ($identifiers as $values) {
            $count += is_array($values) ? count($values) : 0;
        }
        return $count;
    }

    private static function limitedIdentifiers(array $identifiers, int $maximum): array
    {
        $limited = [];
        foreach ($identifiers as $kind => $values) {
            foreach ($values as $value) {
                if ($maximum <= 0) {
                    break 2;
                }
                $limited[$kind][] = $value;
                $maximum--;
            }
        }
        return $limited;
    }

    private static function normalizeIdentifier(string $value): string
    {
        return trim($value);
    }

    private static function isValueSequenceTerminator(string $value): bool
    {
        return preg_match('/^(?:and|show|include|return|display|provide|generate|list|limit|where|with)$/i', $value) === 1;
    }

    private static function outputAliases(array $analysis): array
    {
        $aliases = [];
        foreach (($analysis['selectItems'] ?? []) as $item) {
            $alias = strtolower(trim((string)($item['alias'] ?? '')));
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }
        return array_values(array_unique($aliases));
    }

    private static function hasIdentifierFilter(array $analysis, string $kind, string $value): bool
    {
        foreach (self::analysisScopes($analysis) as $scope) {
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                if (!self::predicateMatchesIdentifierKind($scope, $predicate, $kind)) {
                    continue;
                }
                foreach (($predicate['values'] ?? []) as $candidateValue) {
                    if (self::normalizeIdentifier((string)$candidateValue) === self::normalizeIdentifier($value)) {
                        return true;
                    }
                }
            }
            if (self::hasUnqualifiedIdentifierFilter($scope, $kind, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function predicateMatchesIdentifierKind(array $scope, array $predicate, string $kind): bool
    {
        $expected = [
            'instance_hrid' => ['table' => 'inventory.instance__t', 'column' => 'hrid'],
            'item_barcode' => ['table' => 'inventory.item__t', 'column' => 'barcode'],
            'instance_id' => ['table' => 'inventory.instance__t', 'column' => 'id'],
            'item_id' => ['table' => 'inventory.item__t', 'column' => 'id'],
        ][$kind] ?? null;
        if ($expected === null
            || !empty($predicate['negated'])
            || !in_array(strtoupper((string)($predicate['operator'] ?? '')), ['=', 'IN'], true)) {
            return false;
        }

        $parts = explode('.', strtolower((string)($predicate['column'] ?? '')));
        $column = array_pop($parts);
        $alias = array_pop($parts);
        if ($column !== $expected['column'] || $alias === null) {
            return false;
        }

        return strtolower((string)($scope['sourceAliases'][$alias]['source'] ?? '')) === $expected['table'];
    }

    private static function analysisScopes(array $analysis): array
    {
        $scopes = [$analysis];
        foreach (($analysis['ctes'] ?? []) as $cte) {
            if (is_array($cte)) {
                $scopes[] = $cte;
            }
        }
        return $scopes;
    }

    private static function hasUnqualifiedIdentifierFilter(array $scope, string $kind, string $value): bool
    {
        $expected = [
            'instance_hrid' => ['table' => 'inventory.instance__t', 'column' => 'hrid'],
            'item_barcode' => ['table' => 'inventory.item__t', 'column' => 'barcode'],
            'instance_id' => ['table' => 'inventory.instance__t', 'column' => 'id'],
            'item_id' => ['table' => 'inventory.item__t', 'column' => 'id'],
        ][$kind] ?? null;
        if ($expected === null || !self::scopeHasOnlyExpectedTable($scope, $expected['table'])) {
            return false;
        }

        $where = (string)($scope['predicates']['where'] ?? '');
        $column = preg_quote($expected['column'], '/');
        $literal = preg_quote(str_replace("'", "''", self::normalizeIdentifier($value)), '/');
        $equals = '/(?<!\.)\b' . $column . '\s*=\s*\'' . $literal . '\'/i';
        $inList = '/(?<!\.)\b' . $column . '\s+IN\s*\([^)]*\'' . $literal . '\'/i';
        return preg_match($equals, $where) === 1 || preg_match($inList, $where) === 1;
    }

    private static function scopeHasOnlyExpectedTable(array $scope, string $expectedTable): bool
    {
        $tables = [];
        foreach (($scope['sourceAliases'] ?? []) as $binding) {
            if (($binding['kind'] ?? '') === 'table') {
                $tables[] = strtolower((string)($binding['source'] ?? ''));
            }
        }
        $tables = array_values(array_unique($tables));
        return $tables === [$expectedTable];
    }

    private static function limitMatches(string $sql, $requestedLimit): bool
    {
        if ($requestedLimit === null) {
            return true;
        }
        $analysis = ExploratorySqlAnalysisService::analyze($sql);
        return (string)($analysis['limit'] ?? '') === (string)(int)$requestedLimit;
    }
}
