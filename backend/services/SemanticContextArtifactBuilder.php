<?php

namespace app\services;

/**
 * Builds a deterministic semantic context artifact from the current schema,
 * curated domain hints, compressed value semantics, and derived-table metadata.
 */
class SemanticContextArtifactBuilder
{
    const ARTIFACT_VERSION = 2;

    /**
     * @param array $schemaColumns [tableName => ColumnDef[]]
     * @param array $domainHints ['tableDescriptions' => [], 'vocabulary' => [], 'examples' => []]
     * @param array $dataPatterns [tableName => ['columnWarnings' => [], 'sampleValues' => [], 'preferredApproach' => []]]
     * @param array $derivedData ['tables' => [derivedTableName => [...]]]
     * @param string|null $generatedAt
     * @return array
     */
    public static function build(array $schemaColumns, array $domainHints, array $dataPatterns, array $derivedData = [], ?string $generatedAt = null): array
    {
        $tableDescriptions = self::normalizePromptStringMap($domainHints['tableDescriptions'] ?? []);
        $vocabulary = self::normalizePromptStringMap($domainHints['vocabulary'] ?? []);
        $examples = self::normalizeExamples($domainHints['examples'] ?? []);
        $dataPatterns = self::sanitizeDataPatterns($dataPatterns);
        $patternCards = self::buildPatternCards($examples);
        $derivedTables = self::normalizeDerivedTables($derivedData, $schemaColumns);

        $tables = [];

        foreach ($tableDescriptions as $tableName => $description) {
            self::ensureTable($tables, $tableName, $schemaColumns);
            $tables[$tableName]['description'] = $description;
        }

        foreach ($dataPatterns as $tableName => $info) {
            self::ensureTable($tables, (string)$tableName, $schemaColumns);

            foreach (($info['columnWarnings'] ?? []) as $columnName => $warning) {
                $columnName = (string)$columnName;
                $warning = trim((string)$warning);
                if ($columnName === '' || $warning === '') {
                    continue;
                }
                self::ensureColumnSemantic($tables[(string)$tableName], $columnName);
                $tables[(string)$tableName]['columnSemantics'][$columnName]['warnings'][] = $warning;
            }

            foreach (($info['sampleValues'] ?? []) as $columnName => $values) {
                $columnName = (string)$columnName;
                if ($columnName === '' || !is_array($values)) {
                    continue;
                }
                self::ensureColumnSemantic($tables[(string)$tableName], $columnName);
                $normalizedValues = self::sortedUniqueStrings($values);
                $tables[(string)$tableName]['columnSemantics'][$columnName]['sampleValues'] = $normalizedValues;
                $tables[(string)$tableName]['columnSemantics'][$columnName]['valueSemantics'] = ValueSemanticSamplingService::describeColumn(
                    (string)$tableName,
                    $columnName,
                    $normalizedValues
                );
            }

            foreach (($info['preferredApproach'] ?? []) as $approach) {
                $approach = trim((string)$approach);
                if ($approach !== '') {
                    $tables[(string)$tableName]['preferredApproach'][] = $approach;
                }
            }
        }

        foreach ($derivedTables as $derivedTableName => $info) {
            foreach (($info['mappedSourceTables'] ?? []) as $tableName) {
                self::ensureTable($tables, $tableName, $schemaColumns);

                foreach (($info['columnComments'] ?? []) as $columnName => $comment) {
                    if (!isset($tables[$tableName]['knownColumns'][$columnName])) {
                        continue;
                    }

                    self::ensureColumnSemantic($tables[$tableName], $columnName);
                    $tables[$tableName]['columnSemantics'][$columnName]['derivedComments'][] = $comment;
                    $tables[$tableName]['columnSemantics'][$columnName]['derivedFrom'][] = $derivedTableName;
                }
            }
        }

        $normalizedVocabulary = [];
        foreach ($vocabulary as $term => $mapping) {
            $refs = self::extractReferences($mapping);
            $normalizedVocabulary[$term] = [
                'mapping' => $mapping,
                'tableRefs' => $refs['tableRefs'],
                'columnRefs' => $refs['columnRefs'],
            ];

            foreach ($refs['tableRefs'] as $tableName) {
                self::ensureTable($tables, $tableName, $schemaColumns);
                $tables[$tableName]['terms'][] = $term;
            }

            foreach ($refs['columnRefs'] as $columnRef) {
                $lastDot = strrpos($columnRef, '.');
                if ($lastDot === false) {
                    continue;
                }
                $tableName = substr($columnRef, 0, $lastDot);
                $columnName = substr($columnRef, $lastDot + 1);
                self::ensureTable($tables, $tableName, $schemaColumns);
                self::ensureColumnSemantic($tables[$tableName], $columnName);
                $tables[$tableName]['columnSemantics'][$columnName]['terms'][] = $term;
            }
        }

        self::sortTables($tables);
        self::sortDerivedTables($derivedTables);

        ksort($normalizedVocabulary, SORT_STRING);

        return [
            'metadata' => [
                'artifactVersion' => self::ARTIFACT_VERSION,
                'generatedAt' => $generatedAt ?: gmdate('c'),
                'sourceCounts' => [
                    'schemaTables' => count($schemaColumns),
                    'tableDescriptions' => count($tableDescriptions),
                    'vocabularyTerms' => count($normalizedVocabulary),
                    'examples' => count($examples),
                    'patternCards' => count($patternCards),
                    'dataPatternTables' => count($dataPatterns),
                    'derivedTables' => count($derivedTables),
                    'semanticTables' => count($tables),
                ],
            ],
            'tables' => $tables,
            'derivedTables' => $derivedTables,
            'vocabulary' => $normalizedVocabulary,
            'examples' => $examples,
            'patternCards' => $patternCards,
        ];
    }

    private static function normalizePromptStringMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            $key = trim((string)$key);
            $value = trim((string)$value);
            if ($key === '' || $value === '') {
                continue;
            }
            if (self::containsBlockedPromptSurface($key) || self::containsBlockedPromptSurface($value)) {
                continue;
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function normalizeStringMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            $key = trim((string)$key);
            $value = trim((string)$value);
            if ($key === '' || $value === '') {
                continue;
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function normalizeExamples(array $examples): array
    {
        $normalized = [];
        foreach ($examples as $example) {
            if (!is_array($example)) {
                continue;
            }
            $question = trim((string)($example['question'] ?? ''));
            $sql = trim((string)($example['sql'] ?? ''));
            if ($question === '' || $sql === '') {
                continue;
            }
            if (self::containsBlockedPromptSurface($question) || self::containsBlockedPromptSurface($sql)) {
                continue;
            }
            $normalized[] = [
                'question' => $question,
                'sql' => $sql,
            ];
        }

        usort($normalized, function ($left, $right) {
            $questionCompare = strcmp($left['question'], $right['question']);
            if ($questionCompare !== 0) {
                return $questionCompare;
            }
            return strcmp($left['sql'], $right['sql']);
        });

        return $normalized;
    }

    private static function sanitizeDataPatterns(array $dataPatterns): array
    {
        $sanitized = [];
        foreach ($dataPatterns as $tableName => $info) {
            $tableName = (string)$tableName;
            if ($tableName === '' || self::containsBlockedPromptSurface($tableName) || !is_array($info)) {
                continue;
            }

            $columnWarnings = [];
            foreach (($info['columnWarnings'] ?? []) as $columnName => $warning) {
                $columnName = trim((string)$columnName);
                $warning = trim((string)$warning);
                if (
                    $columnName === '' ||
                    $warning === '' ||
                    self::containsBlockedPromptSurface($columnName) ||
                    self::containsBlockedPromptSurface($warning)
                ) {
                    continue;
                }
                $columnWarnings[$columnName] = $warning;
            }

            $sampleValues = [];
            foreach (($info['sampleValues'] ?? []) as $columnName => $values) {
                $columnName = trim((string)$columnName);
                if ($columnName === '' || self::containsBlockedPromptSurface($columnName) || !is_array($values)) {
                    continue;
                }

                $filteredValues = [];
                foreach ($values as $value) {
                    $value = trim((string)$value);
                    if ($value === '' || self::containsBlockedPromptSurface($value)) {
                        continue;
                    }
                    $filteredValues[] = $value;
                }

                if (!empty($filteredValues)) {
                    $sampleValues[$columnName] = $filteredValues;
                }
            }

            $preferredApproach = [];
            foreach (($info['preferredApproach'] ?? []) as $approach) {
                $approach = trim((string)$approach);
                if ($approach === '' || self::containsBlockedPromptSurface($approach)) {
                    continue;
                }
                $preferredApproach[] = $approach;
            }

            $sanitized[$tableName] = [
                'columnWarnings' => $columnWarnings,
                'sampleValues' => $sampleValues,
                'preferredApproach' => $preferredApproach,
            ];
        }

        return $sanitized;
    }

    private static function buildPatternCards(array $examples): array
    {
        $cards = [];

        foreach ($examples as $example) {
            $question = (string)($example['question'] ?? '');
            $sql = (string)($example['sql'] ?? '');
            if ($question === '' || $sql === '') {
                continue;
            }

            $card = self::inferPatternCard($question, $sql);
            $cardKey = $card['key'];
            if (!isset($cards[$cardKey])) {
                $cards[$cardKey] = [
                    'title' => $card['title'],
                    'summary' => $card['summary'],
                    'promptSignals' => [],
                    'tableRefs' => [],
                    'guidance' => [],
                    'exampleQuestions' => [],
                ];
            }

            $cards[$cardKey]['promptSignals'] = array_merge($cards[$cardKey]['promptSignals'], $card['promptSignals']);
            $cards[$cardKey]['tableRefs'] = array_merge($cards[$cardKey]['tableRefs'], $card['tableRefs']);
            $cards[$cardKey]['guidance'] = array_merge($cards[$cardKey]['guidance'], $card['guidance']);
            $cards[$cardKey]['exampleQuestions'][] = $question;
        }

        ksort($cards, SORT_STRING);
        foreach ($cards as &$card) {
            $card['promptSignals'] = self::sortedUniqueStrings($card['promptSignals']);
            $card['tableRefs'] = self::sortedUniqueStrings($card['tableRefs']);
            $card['guidance'] = self::sortedUniqueStrings($card['guidance']);
            $card['exampleQuestions'] = self::sortedUniqueStrings($card['exampleQuestions']);
        }
        unset($card);

        return $cards;
    }

    private static function inferPatternCard(string $question, string $sql): array
    {
        $questionLower = strtolower($question);
        $sqlLower = strtolower($sql);
        $tableRefs = self::extractSqlTableRefs($sql);

        if (
            self::containsAny($questionLower, ['vendor', 'vendors', 'supplier', 'suppliers']) &&
            self::containsAny($questionLower, ['order', 'orders', 'ordered', 'purchase', 'purchased']) &&
            self::containsAny($questionLower, ['fiscal year']) &&
            self::containsAll($tableRefs, ['orders.purchase_order__t', 'organizations.organizations__t'])
        ) {
            return [
                'key' => 'acquisitions_vendor_orders_by_fiscal_year',
                'title' => 'Vendor Orders By Fiscal Year',
                'summary' => 'Resolve vendor-order prompts through purchase orders, vendor organizations, and fiscal-year boundaries instead of broad acquisitions hint dumps.',
                'promptSignals' => ['fiscal year', 'order', 'ordered', 'vendor'],
                'tableRefs' => self::sortedUniqueStrings(array_merge($tableRefs, ['finance.fiscal_year__t'])),
                'guidance' => [
                    'Join orders.purchase_order__t to organizations.organizations__t for vendor names.',
                    'Use purchase_order__t.date_ordered with finance.fiscal_year__t boundaries for fiscal-year filters.',
                    'Group by organizations.organizations__t.name when the user asks which vendors we ordered from.',
                ],
            ];
        }

        if (
            self::containsAny($questionLower, ['vendor', 'vendors', 'supplier', 'suppliers']) &&
            self::containsAny($questionLower, ['spent', 'spend']) &&
            self::containsAny($questionLower, ['fiscal year']) &&
            self::containsAll($tableRefs, ['invoice.invoices__t', 'organizations.organizations__t'])
        ) {
            return [
                'key' => 'acquisitions_vendor_spend_by_fiscal_year',
                'title' => 'Vendor Spend By Fiscal Year',
                'summary' => 'Answer fiscal-year vendor spend prompts through invoice totals, vendor organizations, and fiscal-year boundaries.',
                'promptSignals' => ['fiscal year', 'spent', 'spend', 'vendor'],
                'tableRefs' => self::sortedUniqueStrings(array_merge($tableRefs, ['finance.fiscal_year__t'])),
                'guidance' => [
                    'Use invoice.invoices__t totals for spend prompts rather than purchase-order line quantities.',
                    'Join invoice.invoices__t to organizations.organizations__t for vendor names.',
                    'Filter through finance.fiscal_year__t boundaries when the user asks for this fiscal year.',
                ],
            ];
        }

        if (
            self::containsAny($questionLower . ' ' . $sqlLower, ['marc', 'field 300', 'field']) &&
            self::containsAny($questionLower . ' ' . $sqlLower, ['missing', 'without', 'not have', 'does not have'])
        ) {
            $fieldCode = self::extractMarcFieldCode($question . ' ' . $sql);
            $signals = ['field', 'marc', 'missing'];
            if ($fieldCode !== '') {
                $signals[] = $fieldCode;
            }

            $guidance = [
                'Scope instances first, then use source-record content already exposed in SCHEMA for MARC checks.',
                'When the prompt asks for missing MARC content, prefer folio_source_record.records__t and explicit field-aware predicates over blocked internal schemas.',
            ];
            if ($fieldCode !== '') {
                $guidance[] = 'For field ' . $fieldCode . ' audits, filter against source-record content or other schema-exposed field extractions rather than internal MARC helper tables.';
            }

            return [
                'key' => 'marc_missing_field_check',
                'title' => 'MARC Missing Field Check',
                'summary' => 'Handle MARC field-audit prompts with per-field MARC tables and explicit missing-field logic.',
                'promptSignals' => $signals,
                'tableRefs' => self::sortedUniqueStrings(array_merge($tableRefs, ['folio_source_record.records__t'])),
                'guidance' => $guidance,
            ];
        }

        if (
            self::containsAny($questionLower, ['budget']) &&
            self::containsAll($tableRefs, ['finance.budget__t', 'finance.fiscal_year__t'])
        ) {
            return [
                'key' => 'finance_budget_by_fiscal_year',
                'title' => 'Budget By Fiscal Year',
                'summary' => 'Resolve budget trend prompts through budget rows joined to fiscal years rather than ad hoc fund hints.',
                'promptSignals' => ['budget', 'fiscal year', 'year'],
                'tableRefs' => $tableRefs,
                'guidance' => [
                    'Use finance.budget__t for allocation totals and finance.fiscal_year__t for year labels.',
                    'Group by fiscal year when the prompt asks for multi-year budget reporting.',
                ],
            ];
        }

        if (
            self::containsAny($questionLower, ['consortial']) &&
            self::containsAny($questionLower, ['circulation', 'lending', 'borrowing']) &&
            self::containsAny($tableRefs, ['circulation.loan__t', 'circulation.audit_loan__t'])
        ) {
            return [
                'key' => 'circulation_consortial_activity',
                'title' => 'Consortial Circulation Activity',
                'summary' => 'Handle Five Colleges consortial circulation prompts through service-point proxies and item ownership, not patron-campus joins.',
                'promptSignals' => ['borrowing', 'circulation', 'consortial', 'lending'],
                'tableRefs' => $tableRefs,
                'guidance' => [
                    'Use checkout_service_point_id and checkin_service_point_id as transaction-location proxies.',
                    'Determine item ownership from the item location and campus chain, not users.users__t.',
                    'Filter physical materials explicitly when the prompt asks for physical consortial activity.',
                ],
            ];
        }

        $fallbackSignals = self::extractSignalTerms($question, 4);
        $fallbackDomain = self::inferPatternDomain($tableRefs);

        return [
            'key' => $fallbackDomain . '_' . implode('_', $fallbackSignals),
            'title' => ucwords(str_replace('_', ' ', $fallbackDomain)) . ' Workflow',
            'summary' => 'Compact workflow card derived from an existing canonical example in the semantic artifact.',
            'promptSignals' => $fallbackSignals,
            'tableRefs' => $tableRefs,
            'guidance' => [
                'Start from the highest-signal tables already present in this workflow card.',
                'Use the selected semantic tables before falling back to broader prompt examples.',
            ],
        ];
    }

    private static function extractSqlTableRefs(string $sql): array
    {
        preg_match_all('/[a-z_]+\.[a-z0-9_]+__t(?:__[a-z0-9_]+)?/i', $sql, $matches);
        return self::sortedUniqueStrings($matches[0] ?? []);
    }

    private static function containsBlockedPromptSurface(string $value): bool
    {
        return preg_match('/\bmarctab\b/i', $value) === 1;
    }

    private static function extractMarcFieldCode(string $text): string
    {
        if (preg_match('/\bfield\s+([0-9]{3})\b/i', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bmt([0-9]{3})\b/i', $text, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private static function extractSignalTerms(string $text, int $limit): array
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        $phrases = [];
        foreach (['fiscal year', 'call number', 'marc field', 'document type'] as $phrase) {
            if (strpos($normalized, $phrase) !== false) {
                $phrases[] = $phrase;
                $normalized = str_replace($phrase, ' ', $normalized);
            }
        }

        $parts = preg_split('/[^a-z0-9_]+/', $normalized);
        $stopwords = [
            'the', 'and', 'for', 'with', 'from', 'that', 'this', 'show', 'list',
            'count', 'what', 'which', 'where', 'when', 'have', 'has', 'into',
            'also', 'only', 'your', 'our', 'are', 'was', 'were', 'how', 'many',
            'get', 'give', 'use', 'using', 'about', 'over', 'under', 'than',
            'did', 'does', 'them', 'they', 'their', 'there', 'been', 'smith',
            'college', 'all', 'between', 'last', 'current', 'each', 'through',
        ];
        $stopLookup = array_flip($stopwords);

        $signals = $phrases;
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3 || isset($stopLookup[$part])) {
                continue;
            }
            $signals[] = $part;
            if (count($signals) >= $limit) {
                break;
            }
        }

        return self::sortedUniqueStrings(array_slice($signals, 0, $limit));
    }

    private static function inferPatternDomain(array $tableRefs): string
    {
        foreach ($tableRefs as $tableRef) {
            if (strpos($tableRef, 'orders.') === 0 || strpos($tableRef, 'invoice.') === 0 || strpos($tableRef, 'organizations.') === 0) {
                return 'acquisitions';
            }
            if (strpos($tableRef, 'circulation.') === 0) {
                return 'circulation';
            }
            if (strpos($tableRef, 'finance.') === 0) {
                return 'finance';
            }
            if (strpos($tableRef, 'marctab.') === 0 || strpos($tableRef, 'folio_source_record.') === 0) {
                return 'marc';
            }
            if (strpos($tableRef, 'inventory.') === 0) {
                return 'inventory';
            }
        }

        return 'general';
    }

    private static function containsAny($haystack, array $needles): bool
    {
        if (is_array($haystack)) {
            foreach ($needles as $needle) {
                if (in_array($needle, $haystack, true)) {
                    return true;
                }
            }
            return false;
        }

        foreach ($needles as $needle) {
            if (strpos((string)$haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function containsAll(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!in_array($needle, $haystack, true)) {
                return false;
            }
        }

        return true;
    }

    private static function ensureTable(array &$tables, string $tableName, array $schemaColumns): void
    {
        if (isset($tables[$tableName])) {
            return;
        }

        $knownColumns = [];
        foreach (($schemaColumns[$tableName] ?? []) as $columnDef) {
            $columnName = trim((string)($columnDef['name'] ?? ''));
            if ($columnName === '') {
                continue;
            }
            $knownColumns[$columnName] = [
                'type' => (string)($columnDef['type'] ?? ''),
            ];
        }
        ksort($knownColumns, SORT_STRING);

        $tables[$tableName] = [
            'description' => '',
            'terms' => [],
            'columnSemantics' => [],
            'preferredApproach' => [],
            'knownColumns' => $knownColumns,
        ];
    }

    private static function ensureColumnSemantic(array &$tableEntry, string $columnName): void
    {
        if (!isset($tableEntry['columnSemantics'][$columnName])) {
            $tableEntry['columnSemantics'][$columnName] = [
                'terms' => [],
                'warnings' => [],
                'sampleValues' => [],
                'valueSemantics' => [],
                'derivedComments' => [],
                'derivedFrom' => [],
            ];
        }
    }

    private static function normalizeDerivedTables(array $derivedData, array $schemaColumns): array
    {
        $knownTableNames = array_fill_keys(array_keys($schemaColumns), true);
        $normalized = [];

        foreach (($derivedData['tables'] ?? []) as $derivedTableName => $info) {
            $derivedTableName = trim((string)$derivedTableName);
            if ($derivedTableName === '') {
                continue;
            }

            $sourceTables = self::sortedUniqueStrings($info['source_tables'] ?? []);
            $mappedSourceTables = [];
            foreach ($sourceTables as $sourceTable) {
                foreach (self::mapDerivedSourceTable($sourceTable, $knownTableNames) as $mappedTable) {
                    $mappedSourceTables[] = $mappedTable;
                }
            }

            $normalized[$derivedTableName] = [
                'sourceTables' => $sourceTables,
                'mappedSourceTables' => self::sortedUniqueStrings($mappedSourceTables),
                'outputColumns' => self::sortedUniqueStrings($info['output_columns'] ?? []),
                'columnComments' => self::normalizeStringMap($info['column_comments'] ?? []),
            ];
        }

        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function mapDerivedSourceTable(string $sourceTable, array $knownTableNames): array
    {
        $sourceTable = trim($sourceTable);
        if ($sourceTable === '') {
            return [];
        }

        if (isset($knownTableNames[$sourceTable])) {
            return [$sourceTable];
        }

        $parts = explode('.', $sourceTable, 2);
        if (count($parts) !== 2) {
            return [];
        }

        [$schema, $table] = $parts;
        $schema = strpos($schema, 'folio_') === 0 ? substr($schema, 6) : $schema;
        $candidates = [
            $schema . '.' . $table . '__t',
            $schema . '.' . $table,
        ];

        $mapped = [];
        foreach ($candidates as $candidate) {
            if (isset($knownTableNames[$candidate])) {
                $mapped[] = $candidate;
            }
        }

        return self::sortedUniqueStrings($mapped);
    }

    private static function extractReferences(string $mapping): array
    {
        preg_match_all('/[a-z_]+\.[a-z0-9_]+__t(?:__[a-z0-9_]+)?(?:\.[a-z0-9_]+)?/i', $mapping, $matches);

        $tableRefs = [];
        $columnRefs = [];
        foreach ($matches[0] ?? [] as $ref) {
            $parts = explode('.', $ref);
            if (count($parts) >= 3) {
                $tableName = $parts[0] . '.' . $parts[1];
                $columnName = $parts[2];
                $columnRefs[$tableName . '.' . $columnName] = true;
                $tableRefs[$tableName] = true;
                continue;
            }

            if (count($parts) === 2) {
                $tableRefs[$parts[0] . '.' . $parts[1]] = true;
            }
        }

        $tableNames = array_keys($tableRefs);
        $columnNames = array_keys($columnRefs);
        sort($tableNames, SORT_STRING);
        sort($columnNames, SORT_STRING);

        return [
            'tableRefs' => $tableNames,
            'columnRefs' => $columnNames,
        ];
    }

    private static function sortTables(array &$tables): void
    {
        ksort($tables, SORT_STRING);

        foreach ($tables as &$tableInfo) {
            $tableInfo['terms'] = self::sortedUniqueStrings($tableInfo['terms']);
            $tableInfo['preferredApproach'] = self::sortedUniqueStrings($tableInfo['preferredApproach']);

            ksort($tableInfo['columnSemantics'], SORT_STRING);
            foreach ($tableInfo['columnSemantics'] as &$columnInfo) {
                $columnInfo['terms'] = self::sortedUniqueStrings($columnInfo['terms']);
                $columnInfo['warnings'] = self::sortedUniqueStrings($columnInfo['warnings']);
                $columnInfo['sampleValues'] = self::sortedUniqueStrings($columnInfo['sampleValues']);
                if (!empty($columnInfo['valueSemantics'])) {
                    ksort($columnInfo['valueSemantics'], SORT_STRING);
                }
                $columnInfo['derivedComments'] = self::sortedUniqueStrings($columnInfo['derivedComments']);
                $columnInfo['derivedFrom'] = self::sortedUniqueStrings($columnInfo['derivedFrom']);
            }
            unset($columnInfo);

            ksort($tableInfo['knownColumns'], SORT_STRING);
        }
        unset($tableInfo);
    }

    private static function sortDerivedTables(array &$derivedTables): void
    {
        ksort($derivedTables, SORT_STRING);

        foreach ($derivedTables as &$derivedTable) {
            $derivedTable['sourceTables'] = self::sortedUniqueStrings($derivedTable['sourceTables']);
            $derivedTable['mappedSourceTables'] = self::sortedUniqueStrings($derivedTable['mappedSourceTables']);
            $derivedTable['outputColumns'] = self::sortedUniqueStrings($derivedTable['outputColumns']);
            $derivedTable['columnComments'] = self::normalizeStringMap($derivedTable['columnComments']);
        }
        unset($derivedTable);
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