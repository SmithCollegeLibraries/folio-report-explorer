<?php

namespace app\services;

require_once __DIR__ . '/ExploratorySqlAnalysisService.php';

/**
 * Extracts only explicitly labelled report values and checks their SQL coverage.
 */
final class ExplicitReportRequestService
{
    private const MAX_IDENTIFIERS = 500;

    // An identifier label only introduces values when the request frames it as a
    // filter ("for barcode X") or marks the values explicitly ("barcodes are X").
    // A bare label is an output field name ("include the title, barcode, ...")
    // and must not swallow the words that follow it.
    private const IDENTIFIER_FILTER_LEAD_INS = '(?:for|where|with|using|matching|filtered\s+by)';

    private const IDENTIFIER_VALUE_MARKERS = '(?:are|is|of|:|=)';

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
                self::anchorPattern('instance\s*(?:numbers?|hrids?)'),
                '/^in[0-9][a-z0-9_-]*$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'item_barcode',
            self::extractAnchoredValues(
                $prompt,
                self::anchorPattern('(?:item\s+)?barcodes?'),
                '/^[a-z0-9_-]+$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'instance_id',
            self::extractAnchoredValues(
                $prompt,
                self::anchorPattern('instance\s+ids?'),
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'
            )
        );
        self::appendIdentifiers(
            $identifiers,
            'item_id',
            self::extractAnchoredValues(
                $prompt,
                self::anchorPattern('item\s+ids?'),
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

    public static function validateCandidate(string $sql, array $request): array
    {
        $analysis = ExploratorySqlAnalysisService::analyze($sql);
        $missingIdentifiers = [];
        $unexpectedIdentifiers = [];
        foreach (($request['identifiers'] ?? []) as $kind => $values) {
            if (!is_array($values)) {
                continue;
            }
            $requestedValues = self::uniqueNormalizedIdentifiers($values);
            $actualValues = self::identifierValuesForKind($analysis, (string)$kind);
            foreach ($requestedValues as $value) {
                if (!in_array($value, $actualValues, true)) {
                    $missingIdentifiers[] = $value;
                }
            }
            foreach ($actualValues as $value) {
                if (!in_array($value, $requestedValues, true)) {
                    $unexpectedIdentifiers[] = $value;
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
            'valid' => $missingIdentifiers === []
                && $unexpectedIdentifiers === []
                && $missingFields === []
                && $limitValid,
            'missingIdentifiers' => $missingIdentifiers,
            'unexpectedIdentifiers' => $unexpectedIdentifiers,
            'missingFields' => $missingFields,
            'limitValid' => $limitValid,
        ];
    }

    private static function anchorPattern(string $label): string
    {
        $article = '(?:an?\s+|the\s+)?';

        return '/(?:'
            // "for barcode X", "filtered by instance number Y"
            . '\b' . self::IDENTIFIER_FILTER_LEAD_INS . '\s+' . $article . $label . '\b\s*'
            . self::IDENTIFIER_VALUE_MARKERS . '?'
            // "instance IDs X and item IDs Y" — a conjunction continues an
            // identifier phrase, but not a comma-separated output list
            // ("title, call number, and barcode, ...").
            . '|(?<![,;])\s+and\s+' . $article . $label . '\b\s*' . self::IDENTIFIER_VALUE_MARKERS . '?'
            // "barcodes are X, Y", "instance HRIDs: X"
            . '|\b' . $label . '\b\s*' . self::IDENTIFIER_VALUE_MARKERS
            . ')\s*/i';
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

    private static function identifierValuesForKind(array $analysis, string $kind): array
    {
        $values = [];
        foreach (self::analysisScopes($analysis) as $scope) {
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                if (!self::predicateMatchesIdentifierKind($scope, $predicate, $kind)) {
                    continue;
                }
                foreach (($predicate['values'] ?? []) as $candidateValue) {
                    $values[] = (string)$candidateValue;
                }
            }
            $values = array_merge($values, self::unqualifiedIdentifierValues($scope, $kind));
        }
        return self::uniqueNormalizedIdentifiers($values);
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

    private static function unqualifiedIdentifierValues(array $scope, string $kind): array
    {
        $expected = [
            'instance_hrid' => ['table' => 'inventory.instance__t', 'column' => 'hrid'],
            'item_barcode' => ['table' => 'inventory.item__t', 'column' => 'barcode'],
            'instance_id' => ['table' => 'inventory.instance__t', 'column' => 'id'],
            'item_id' => ['table' => 'inventory.item__t', 'column' => 'id'],
        ][$kind] ?? null;
        if ($expected === null || !self::scopeHasOnlyExpectedTable($scope, $expected['table'])) {
            return [];
        }

        $where = (string)($scope['predicates']['where'] ?? '');
        $column = preg_quote($expected['column'], '/');
        $literal = "'((?:''|[^'])*)'";
        $values = [];

        preg_match_all(
            '/(?<!\.)\b' . $column . '\s*=\s*' . $literal . '/i',
            $where,
            $equalities,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        foreach ($equalities as $equality) {
            if (self::isNegatedUnqualifiedPredicate($where, (int)$equality[0][1])) {
                continue;
            }
            $values[] = str_replace("''", "'", (string)$equality[1][0]);
        }

        preg_match_all(
            '/(?<!\.)\b' . $column . '\s+IN\s*\(([^)]*)\)/i',
            $where,
            $memberships,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        foreach ($memberships as $membership) {
            if (self::isNegatedUnqualifiedPredicate($where, (int)$membership[0][1])) {
                continue;
            }
            $list = (string)$membership[1][0];
            if (preg_match('/\A\s*' . $literal . '(?:\s*,\s*' . $literal . ')*\s*\z/', $list) !== 1) {
                continue;
            }
            preg_match_all('/' . $literal . '/', $list, $literals);
            foreach ($literals[1] as $value) {
                $values[] = str_replace("''", "'", (string)$value);
            }
        }

        return self::uniqueNormalizedIdentifiers($values);
    }

    private static function isNegatedUnqualifiedPredicate(string $where, int $offset): bool
    {
        $prefix = substr($where, 0, $offset);
        return preg_match('/\bNOT\s*$/i', $prefix) === 1;
    }

    private static function uniqueNormalizedIdentifiers(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = self::normalizeIdentifier((string)$value);
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        return $normalized;
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
