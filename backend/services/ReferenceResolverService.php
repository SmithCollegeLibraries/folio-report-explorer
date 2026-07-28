<?php

namespace app\services;

require_once __DIR__ . '/ResolverClarificationService.php';
require_once __DIR__ . '/ReferenceJsonBundleService.php';
require_once __DIR__ . '/ReferenceIntentService.php';
require_once __DIR__ . '/ReferenceTextNormalizerService.php';

/**
 * Resolves stable local FOLIO reference terms before model SQL generation.
 */
class ReferenceResolverService
{
    const DEFAULT_MAX_AUTO_ROWS = 10000;
    const DEFAULT_MAX_AUTO_BYTES = 26214400; // 25 MB
    const DEFAULT_MAX_MANUAL_ROWS = 100000;

    const CLASS_CACHEABLE = 'cacheable_reference';
    const CLASS_MANUAL = 'manual_review';
    const CLASS_DO_NOT_CACHE = 'do_not_cache';

    const CLARIFICATION_TYPE_BATCH = 'batch_local_reference_resolution';

    private static $blockedSchemas = [
        'audit',
        'perms',
        'users',
    ];

    private static $blockedTables = [
        'inventory.instance__t',
        'inventory.item__t',
        'inventory.holdings_record__t',
        'users.users__t',
        'audit.circulation_logs__t',
    ];

    private static $searchableColumns = [
        'name',
        'code',
        'label',
        'description',
        'group',
    ];

    private static $safeProbeTables = [
        'inventory.contributor__t' => [
            'name',
        ],
        'inventory.instance__t__contributors' => [
            'contributors__name',
        ],
        'inventory.instance__t' => [
            'title',
            'index_title',
            'hrid',
        ],
        'notes.note_data__t' => [
            'title',
            'content',
            'note',
            'details',
            'type',
        ],
    ];

    /**
     * @param array<string, mixed> $table
     * @return array<string, mixed>
     */
    public static function classifyDiscoveryCandidate(array $table): array
    {
        $schema = strtolower(trim((string)($table['schema'] ?? '')));
        $name = strtolower(trim((string)($table['table'] ?? '')));
        $fullName = $schema !== '' ? $schema . '.' . $name : $name;
        $estimatedRows = (int)($table['estimated_rows'] ?? 0);
        $totalBytes = (int)($table['total_bytes'] ?? 0);
        $columns = array_map('strtolower', array_map('strval', $table['columns'] ?? []));

        if (in_array($schema, self::$blockedSchemas, true) || in_array($fullName, self::$blockedTables, true)) {
            return self::candidateResult(self::CLASS_DO_NOT_CACHE, 'blocked_schema_or_table');
        }

        if ($estimatedRows > self::DEFAULT_MAX_MANUAL_ROWS || $totalBytes > self::DEFAULT_MAX_AUTO_BYTES * 4) {
            return self::candidateResult(self::CLASS_DO_NOT_CACHE, 'too_large');
        }

        $hasId = in_array('id', $columns, true);
        $hasSearchableColumn = count(array_intersect($columns, self::$searchableColumns)) > 0;

        if (!$hasId || !$hasSearchableColumn) {
            return self::candidateResult(self::CLASS_MANUAL, 'missing_id_or_searchable_column');
        }

        if ($estimatedRows <= self::DEFAULT_MAX_AUTO_ROWS && $totalBytes <= self::DEFAULT_MAX_AUTO_BYTES) {
            return self::candidateResult(self::CLASS_CACHEABLE, 'small_reference_shape');
        }

        return self::candidateResult(self::CLASS_MANUAL, 'reference_like_but_above_auto_threshold');
    }

    /**
     * Resolve a prompt against supplied references and aliases. This pure helper is used by tests and runtime.
     *
     * @param array<int, array<string, mixed>> $references
     * @param array<int, array<string, mixed>> $aliases
     * @param array<int, string> $acceptedClarificationKeys
     * @return array<string, mixed>
     */
    public static function resolvePromptAgainstReferences(
        string $prompt,
        array $references,
        array $aliases = [],
        array $acceptedClarificationKeys = []
    ): array {
        $normalizedPrompt = self::normalizeText($prompt);
        if ($normalizedPrompt === '') {
            return self::emptyResolution();
        }

        $typedResolution = self::resolveTypedIntents(
            ReferenceIntentService::extract($prompt),
            $references
        );
        if (!empty($typedResolution['outcome'])) {
            return $typedResolution['outcome'];
        }

        $guidanceLines = [];
        $resolvedFilters = $typedResolution['resolvedFilters'];
        $resolved = $typedResolution['resolvedReferences'];
        $legacyPrompt = self::removeConsumedPromptText(
            $prompt,
            $typedResolution['consumedSpans'],
            $typedResolution['consumedMaterialTerms']
        );
        $legacyNormalizedPrompt = self::normalizeText($legacyPrompt);
        foreach ($references as $reference) {
            $match = self::matchReference(
                $legacyNormalizedPrompt,
                $legacyPrompt,
                $reference
            );
            if ($match === null) {
                continue;
            }
            $resolved[] = $match;
        }

        $dedupedResolved = [];
        $seenResolved = [];
        foreach ($resolved as $match) {
            $key = (string)($match['source_table'] ?? '')
                . '|'
                . (string)($match['source_id'] ?? '');
            if ((string)($match['source_id'] ?? '') === '') {
                $key .= '|' . self::normalizeText((string)($match['name'] ?? ''));
            }
            if (isset($seenResolved[$key])) {
                continue;
            }
            $seenResolved[$key] = true;
            $dedupedResolved[] = $match;
        }
        $resolved = $dedupedResolved;

        usort($resolved, function ($left, $right) {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            return strcmp($left['source_table'] . $left['name'], $right['source_table'] . $right['name']);
        });

        $hasSpecificMatch = false;
        foreach ($resolved as $match) {
            if (!self::isGenericReferenceName((string)$match['name'])) {
                $hasSpecificMatch = true;
                break;
            }
        }
        if ($hasSpecificMatch) {
            $resolved = array_values(array_filter($resolved, function ($match) {
                return !self::isGenericReferenceName((string)$match['name']);
            }));
        }

        $resolved = self::filterResolvedReferenceConflicts($resolved);

        if (empty($resolved)) {
            $ambiguousLocationClarification = self::buildAmbiguousLocationClarification($normalizedPrompt, $references);
            if ($ambiguousLocationClarification !== null) {
                return $ambiguousLocationClarification;
            }
        }

        $seenTargets = [];
        foreach (array_slice($resolved, 0, 8) as $match) {
            $targetKey = $match['source_table'] . '|' . strtolower($match['name']);
            if (isset($seenTargets[$targetKey])) {
                continue;
            }
            $seenTargets[$targetKey] = true;
            $guidanceLines[] = self::buildReferenceGuidanceLine($match);
        }

        $clarificationItems = [];
        foreach ($aliases as $alias) {
            $aliasText = trim((string)($alias['alias'] ?? ''));
            $normalizedAlias = self::normalizeText($aliasText);
            if ($normalizedAlias === '') {
                continue;
            }
            if (!self::promptContainsNormalizedTerm($normalizedPrompt, $normalizedAlias)) {
                continue;
            }

            $clarificationKey = trim((string)($alias['clarification_key'] ?? ('reference_alias.' . str_replace(' ', '_', $normalizedAlias))));
            if (in_array($clarificationKey, $acceptedClarificationKeys, true) && !empty($alias['resolved_filter'])) {
                $guidanceLines[] = self::buildAliasGuidanceLine($alias);
                continue;
            }

            $clarificationItems[] = [
                'term' => $aliasText,
                'clarificationKey' => $clarificationKey,
                'question' => 'What should "' . $aliasText . '" mean for this report?',
                'confidence' => (string)($alias['confidence'] ?? 'ambiguous'),
                'reason' => (string)($alias['reason'] ?? 'local_alias_requires_confirmation'),
                'inputType' => 'single_choice',
                'freeTextAllowed' => true,
                'options' => array_values($alias['options'] ?? []),
            ];
        }

        if (!empty($clarificationItems)) {
            return [
                'needsClarification' => true,
                'clarificationType' => self::CLARIFICATION_TYPE_BATCH,
                'clarificationBatchId' => self::newBatchId(),
                'clarificationItems' => $clarificationItems,
                'question' => 'I found multiple local terms that need confirmation before generating SQL.',
                'route' => 'clarification',
                'routeReason' => 'reference_resolver_batch_clarification',
                'dataSource' => null,
            ];
        }

        return [
            'needsClarification' => false,
            'guidanceLines' => $guidanceLines,
            'resolvedReferences' => $resolved,
            'resolvedFilters' => $resolvedFilters,
            'routeReason' => !empty($guidanceLines) ? 'reference_resolver_guidance' : null,
        ];
    }

    /**
     * Runtime DB-backed resolution. Returns an empty resolution if the local cache is unavailable.
     *
     * @return array<string, mixed>
     */
    public static function resolvePrompt(string $prompt, $userId = null): array
    {
        $references = self::loadEnabledReferenceValues();
        $aliases = self::loadReferenceAliases($userId);
        $acceptedKeys = self::loadAcceptedClarificationKeys($userId);
        $resolution = self::resolvePromptAgainstReferences($prompt, $references, $aliases, $acceptedKeys);
        if (!empty($resolution['needsClarification']) || !empty($resolution['guidanceLines'])) {
            return $resolution;
        }

        $safeProbeClarification = self::buildSafeProbeClarification($prompt);
        return $safeProbeClarification ?: $resolution;
    }

    /**
     * Extract capitalized local terms that are good candidates for bounded safe probes.
     *
     * @return array<int, array{term: string, trigger: string}>
     */
    public static function extractSafeProbeTerms(string $prompt): array
    {
        $terms = [];
        if (preg_match_all(
            '/\b([A-Z][A-Za-z0-9\'&.-]*(?:\s+[A-Z][A-Za-z0-9\'&.-]*){0,4})\s+(collection|collections|location|locations|library|libraries)\b/',
            $prompt,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $term = trim((string)$match[1]);
                $trigger = strtolower(trim((string)$match[2]));
                $trigger = rtrim($trigger, 's');
                if ($term === '' || strlen($term) < 3) {
                    continue;
                }
                $key = strtolower($term . '|' . $trigger);
                $terms[$key] = [
                    'term' => $term,
                    'trigger' => $trigger,
                ];
            }
        }

        return array_values($terms);
    }

    /**
     * @param array<string, mixed> $resolution
     */
    public static function appendGuidanceToPrompt(string $prompt, array $resolution): string
    {
        $lines = $resolution['guidanceLines'] ?? [];
        if (empty($lines) || !is_array($lines)) {
            return $prompt;
        }

        return rtrim($prompt) . "\n\nReference resolver guidance:\n" . implode("\n", array_map('strval', $lines));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadEnabledReferenceValues(): array
    {
        $bundleStatus = ReferenceJsonBundleService::bundleStatus();
        if (empty($bundleStatus['usable'])) {
            if (class_exists('\Yii')) {
                \Yii::warning('JSON reference bundle is unavailable; falling back to MySQL reference cache: ' . json_encode($bundleStatus), __METHOD__);
            }
        } elseif (!empty($bundleStatus['stale']) && class_exists('\Yii')) {
            \Yii::warning('JSON reference bundle is stale; continuing with stale bundle: ' . json_encode($bundleStatus), __METHOD__);
        }

        $jsonReferences = ReferenceJsonBundleService::loadReferences();
        if (!empty($jsonReferences)) {
            return $jsonReferences;
        }

        if (!class_exists('\Yii')) {
            return [];
        }

        try {
            $rows = \Yii::$app->db->createCommand(
                'SELECT source_table, source_id, name, code, metadata_json
                 FROM folio_reference_values
                 WHERE is_active = 1
                 ORDER BY source_table, name, code
                 LIMIT 5000'
            )->queryAll();
        } catch (\Throwable $e) {
            return [];
        }

        $references = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string)$row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $references[] = [
                'source_table' => (string)($row['source_table'] ?? ''),
                'source_id' => (string)($row['source_id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'metadata' => $metadata,
            ];
        }

        return $references;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadReferenceAliases($userId): array
    {
        if (!class_exists('\Yii')) {
            return [];
        }

        try {
            $rows = \Yii::$app->db->createCommand(
                'SELECT alias, source_table, source_id, resolved_value, confidence, metadata_json
                 FROM folio_reference_aliases
                 WHERE alias_scope = "global"
                    OR (alias_scope = "user" AND user_id = :user_id)
                 ORDER BY alias_scope DESC, alias',
                [':user_id' => $userId === null || $userId === '' ? 0 : (int)$userId]
            )->queryAll();
        } catch (\Throwable $e) {
            return [];
        }

        $aliases = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string)$row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }

            $resolvedValue = (string)($row['resolved_value'] ?? '');
            $sourceTable = (string)($row['source_table'] ?? '');
            $aliases[] = [
                'alias' => (string)($row['alias'] ?? ''),
                'clarification_key' => 'reference_alias.' . self::normalizeKey((string)($row['alias'] ?? '')),
                'confidence' => (string)($row['confidence'] ?? 'learned'),
                'resolved_filter' => [
                    'table' => $sourceTable,
                    'column' => 'name',
                    'operator' => '=',
                    'value' => $resolvedValue,
                ],
                'options' => [
                    [
                        'id' => self::normalizeKey($resolvedValue),
                        'label' => $resolvedValue,
                        'recommended' => true,
                        'clarifiedPromptSuffix' => 'Use ' . $sourceTable . '.name = ' . $resolvedValue . '.',
                        'resolvedFilter' => [
                            'table' => $sourceTable,
                            'column' => 'name',
                            'operator' => '=',
                            'value' => $resolvedValue,
                        ],
                    ],
                ],
                'metadata' => $metadata,
            ];
        }

        return $aliases;
    }

    /**
     * @return array<int, string>
     */
    private static function loadAcceptedClarificationKeys($userId): array
    {
        if (!class_exists('\Yii') || $userId === null || $userId === '') {
            return [];
        }

        try {
            $rows = \Yii::$app->db->createCommand(
                'SELECT DISTINCT clarification_key
                 FROM ai_clarification_events
                 WHERE user_id = :user_id
                   AND resolved_filter_json IS NOT NULL
                 ORDER BY clarification_key',
                [':user_id' => (int)$userId]
            )->queryColumn();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? array_values(array_filter(array_map('strval', $rows))) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildSafeProbeClarification(string $prompt)
    {
        if (!class_exists('\Yii')) {
            return null;
        }

        $terms = self::extractSafeProbeTerms($prompt);
        if (empty($terms)) {
            return null;
        }

        $items = [];
        foreach ($terms as $termInfo) {
            $probeResult = self::runSafeProbeForTerm($termInfo['term']);
            $items[] = [
                'term' => $termInfo['term'],
                'trigger' => $termInfo['trigger'],
                'options' => $probeResult['options'],
                'searchedCategories' => $probeResult['searchedCategories'],
                'matchedCategories' => $probeResult['matchedCategories'],
            ];
        }

        if (empty($items)) {
            return null;
        }

        return self::buildSafeProbeClarificationFromOptions($prompt, $items);
    }

    /**
     * @param array<int, array<string, mixed>> $probeItems
     * @return array<string, mixed>
     */
    public static function buildSafeProbeClarificationFromOptions(string $prompt, array $probeItems): array
    {
        $items = [];
        $trace = [];
        foreach ($probeItems as $probeItem) {
            $term = trim((string)($probeItem['term'] ?? ''));
            $trigger = trim((string)($probeItem['trigger'] ?? 'term'));
            $options = array_values(array_filter($probeItem['options'] ?? [], 'is_array'));
            if ($term === '') {
                continue;
            }

            $acceptedGuidance = self::buildAcceptedSafeProbeGuidance($prompt, $term, $options);
            if (!empty($acceptedGuidance)) {
                return [
                    'needsClarification' => false,
                    'guidanceLines' => $acceptedGuidance,
                    'resolvedReferences' => [],
                    'routeReason' => 'reference_resolver_accepted_safe_probe_guidance',
                ];
            }

            $trace[] = [
                'label' => self::lookupTraceLabel($term),
                'status' => 'no_match',
            ];

            $normalizedOptions = [];
            $matchedCategories = [];
            foreach ($options as $option) {
                $filter = $option['resolvedFilter'] ?? [];
                $table = is_array($filter) ? (string)($filter['table'] ?? '') : '';
                $column = is_array($filter) ? (string)($filter['column'] ?? '') : '';
                $description = trim((string)($option['description'] ?? ''));
                $fieldLabel = self::probeFieldLabel($table, $column);

                if ($table !== '' && $column !== '') {
                    $matchedCategories[$fieldLabel] = true;
                    $trace[] = [
                        'label' => 'Found possible match in ' . $fieldLabel,
                        'status' => 'found',
                        'detail' => $description,
                        'technicalDetail' => $table . '.' . $column,
                    ];
                }

                if ($fieldLabel !== '') {
                    $option['label'] = 'Search ' . $fieldLabel . ' for "' . $term . '"';
                }
                $option['description'] = $description;
                $normalizedOptions[] = $option;
            }

            $searchedCategories = [];
            foreach (($probeItem['searchedCategories'] ?? []) as $category) {
                $category = trim((string)$category);
                if ($category !== '') {
                    $searchedCategories[$category] = true;
                }
            }
            foreach (($probeItem['matchedCategories'] ?? []) as $category) {
                $category = trim((string)$category);
                if ($category !== '') {
                    $matchedCategories[$category] = true;
                }
            }
            foreach (array_keys($searchedCategories) as $category) {
                if (isset($matchedCategories[$category])) {
                    continue;
                }
                $trace[] = [
                    'label' => 'Checked ' . $category . ' for "' . $term . '"',
                    'status' => 'no_match',
                ];
            }

            if (empty($normalizedOptions)) {
                $trace[] = [
                    'label' => 'Checked contributor/author, title, instance number, identifier, and notes fields for "' . $term . '"',
                    'status' => 'no_match',
                ];
            }

            $items[] = [
                'term' => $term,
                'clarificationKey' => 'safe_probe.' . self::normalizeKey($term) . '.' . self::normalizeKey($trigger),
                'question' => empty($normalizedOptions)
                    ? 'I could not find "' . $term . '" in local reference data or searchable report fields. What should this term mean for this report?'
                    : 'I did not find "' . $term . '" in local reference data, but found possible matches in searchable report fields. Where should I search?',
                'confidence' => empty($normalizedOptions) ? 'unresolved_named_term' : 'safe_probe_match',
                'reason' => empty($normalizedOptions)
                    ? 'unresolved_named_reference_term_requires_clarification'
                    : 'unresolved_reference_term_found_in_safe_probe',
                'inputType' => 'single_choice',
                'freeTextAllowed' => true,
                'options' => $normalizedOptions,
            ];
        }

        if (empty($items)) {
            return self::emptyResolution();
        }

        $deterministic = [
            'needsClarification' => true,
            'clarificationType' => self::CLARIFICATION_TYPE_BATCH,
            'clarificationBatchId' => self::newBatchId(),
            'clarificationItems' => $items,
            'question' => 'I checked local reference data and searchable report fields before generating SQL.',
            'message' => 'These terms were not exact local reference matches. Confirm the intended search target so I can avoid guessing.',
            'resolverTrace' => self::dedupeResolverTrace($trace),
            'route' => 'clarification',
            'routeReason' => 'reference_resolver_safe_probe_clarification',
            'dataSource' => null,
        ];

        return (new ResolverClarificationService())->buildClarification($prompt, $deterministic);
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<int, string>
     */
    private static function buildAcceptedSafeProbeGuidance(string $prompt, string $term, array $options): array
    {
        $guidance = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $filter = $option['resolvedFilter'] ?? [];
            if (!is_array($filter)) {
                continue;
            }
            $table = trim((string)($filter['table'] ?? ''));
            $column = trim((string)($filter['column'] ?? ''));
            if ($table === '' || $column === '') {
                continue;
            }

            $needle = 'search ' . strtolower($table . '.' . $column) . ' for ' . strtolower($term);
            if (strpos(strtolower($prompt), $needle) === false) {
                continue;
            }

            $guidance[] = '- User clarified local term: search ' . $table . '.' . $column
                . ' ILIKE ' . self::quoteLiteral('%' . $term . '%')
                . ' for ' . self::quoteLiteral($term) . '. Do not ask for this clarification again.';
        }

        return array_values(array_unique($guidance));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function runSafeProbeForTerm(string $term): array
    {
        $options = [];
        $searchedCategories = [];
        $matchedCategories = [];
        $categoryCounts = [];
        foreach (self::$safeProbeTables as $sourceTable => $candidateColumns) {
            [$schema, $table] = self::splitSourceTable($sourceTable);
            $columns = self::existingFolioColumns($schema, $table, $candidateColumns);
            foreach ($columns as $column) {
                $category = self::probeFieldLabel($sourceTable, $column);
                if ($category !== '') {
                    $searchedCategories[$category] = true;
                }
                foreach (self::querySafeProbeColumn($schema, $table, $column, $term) as $row) {
                    if (($categoryCounts[$category] ?? 0) >= 2) {
                        continue;
                    }
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                    if ($category !== '') {
                        $matchedCategories[$category] = true;
                    }
                    $optionId = self::normalizeKey($sourceTable . ' ' . $column . ' ' . ($row['source_id'] ?? $term));
                    $options[$optionId] = [
                        'id' => $optionId,
                        'label' => 'Search ' . $category . ' for "' . $term . '"',
                        'description' => (string)($row['preview'] ?? ''),
                        'clarifiedPromptSuffix' => 'Search ' . $sourceTable . '.' . $column . ' for ' . $term . '.',
                        'resolvedFilter' => [
                            'table' => $sourceTable,
                            'column' => $column,
                            'operator' => 'ILIKE',
                            'value' => '%' . $term . '%',
                            'sourceId' => (string)($row['source_id'] ?? ''),
                        ],
                    ];
                }
            }
        }

        return [
            'options' => array_slice(array_values($options), 0, 8),
            'searchedCategories' => array_keys($searchedCategories),
            'matchedCategories' => array_keys($matchedCategories),
        ];
    }

    private static function lookupTraceLabel(string $term): string
    {
        return 'Checked locations, libraries, campuses, funds, material types, and other report filters for "' . $term . '"';
    }

    private static function probeFieldLabel(string $sourceTable, string $column): string
    {
        if ($sourceTable === 'inventory.contributor__t' || $sourceTable === 'inventory.instance__t__contributors') {
            return 'contributor/author fields';
        }

        if ($sourceTable === 'inventory.instance__t' && in_array($column, ['title', 'index_title'], true)) {
            return 'title fields';
        }

        if ($sourceTable === 'inventory.instance__t' && $column === 'hrid') {
            return 'instance number fields';
        }

        if ($sourceTable === 'notes.note_data__t') {
            return 'notes fields';
        }

        return trim($sourceTable . '.' . $column, '.');
    }

    /**
     * @param array<int, array<string, string>> $trace
     * @return array<int, array<string, string>>
     */
    private static function dedupeResolverTrace(array $trace): array
    {
        $deduped = [];
        $seen = [];
        foreach ($trace as $entry) {
            $label = (string)($entry['label'] ?? '');
            $status = (string)($entry['status'] ?? '');
            if ($label === '' || $status === '') {
                continue;
            }
            $key = $label . '|' . $status . '|' . (string)($entry['detail'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $entry;
        }

        return $deduped;
    }

    /**
     * @param array<int, string> $candidateColumns
     * @return array<int, string>
     */
    private static function existingFolioColumns(string $schema, string $table, array $candidateColumns): array
    {
        $quotedCandidates = array_map(function ($column) {
            return "'" . str_replace("'", "''", (string)$column) . "'";
        }, $candidateColumns);

        try {
            $rows = \Yii::$app->folioDb->createCommand(
                'SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = :schema
                   AND table_name = :table
                   AND column_name IN (' . implode(',', $quotedCandidates) . ')',
                [':schema' => $schema, ':table' => $table]
            )->queryColumn();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? array_values(array_filter(array_map('strval', $rows))) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function querySafeProbeColumn(string $schema, string $table, string $column, string $term): array
    {
        $sql = 'SELECT id::text AS source_id, SUBSTRING(' . self::quoteIdentifier($column) . '::text, 1, 160) AS preview'
            . ' FROM ' . self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table)
            . ' WHERE ' . self::quoteIdentifier($column) . '::text ILIKE :pattern'
            . ' LIMIT 3';

        try {
            return \Yii::$app->folioDb->createCommand($sql, [':pattern' => '%' . $term . '%'])->queryAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $intents
     * @param array<int, array<string, mixed>> $references
     * @return array<string, mixed>
     */
    private static function resolveTypedIntents(array $intents, array $references): array
    {
        $filters = [];
        $resolved = [];
        $consumedSpans = [];
        $consumedMaterialTerms = [];
        $seenReferences = [];

        foreach ($intents as $intent) {
            $dimension = (string)($intent['dimension'] ?? '');
            $sourceTable = ReferenceIntentService::tableForDimension($dimension);
            if ($sourceTable === null) {
                continue;
            }

            $span = trim((string)($intent['span'] ?? ''));
            if ($span !== '') {
                $consumedSpans[] = $span;
            }

            $tableReferences = self::referencesForTable($references, $sourceTable);
            if ($dimension === 'material_type') {
                foreach (($intent['terms'] ?? []) as $term) {
                    $term = self::normalizeText((string)$term);
                    if ($term !== '') {
                        $consumedMaterialTerms[] = $term;
                    }
                }
                $materialResolution = self::resolveMaterialIntent($intent, $tableReferences);
                if (!empty($materialResolution['outcome'])) {
                    return [
                        'resolvedFilters' => [],
                        'resolvedReferences' => [],
                        'consumedSpans' => $consumedSpans,
                        'consumedMaterialTerms' => $consumedMaterialTerms,
                        'outcome' => $materialResolution['outcome'],
                    ];
                }
                $matches = $materialResolution['matches'];
            } else {
                $matches = self::matchNamedIntent($intent, $tableReferences);
                if (count($matches) > 1) {
                    return [
                        'resolvedFilters' => [],
                        'resolvedReferences' => [],
                        'consumedSpans' => $consumedSpans,
                        'consumedMaterialTerms' => $consumedMaterialTerms,
                        'outcome' => self::buildTypedIntentAmbiguityOutcome(
                            $intent,
                            $matches
                        ),
                    ];
                }
            }

            if (empty($matches)) {
                continue;
            }

            $dedupedMatches = [];
            foreach ($matches as $match) {
                $referenceKey = (string)($match['source_table'] ?? '')
                    . '|'
                    . (string)($match['source_id'] ?? '');
                if ((string)($match['source_id'] ?? '') === '') {
                    $referenceKey .= '|' . self::normalizeText((string)($match['name'] ?? ''));
                }
                if (isset($seenReferences[$referenceKey])) {
                    continue;
                }
                $seenReferences[$referenceKey] = true;
                $dedupedMatches[] = $match;
                $resolved[] = $match;
            }

            if (!empty($dedupedMatches)) {
                $filters[] = self::buildResolvedFilter($intent, $dedupedMatches);
            }
        }

        return [
            'resolvedFilters' => $filters,
            'resolvedReferences' => $resolved,
            'consumedSpans' => array_values(array_unique($consumedSpans)),
            'consumedMaterialTerms' => array_values(array_unique($consumedMaterialTerms)),
            'outcome' => null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $references
     * @return array<int, array<string, mixed>>
     */
    private static function referencesForTable(array $references, string $sourceTable): array
    {
        return array_values(array_filter(
            $references,
            function (array $reference) use ($sourceTable): bool {
                return trim((string)($reference['source_table'] ?? ($reference['table'] ?? '')))
                    === $sourceTable;
            }
        ));
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $references
     * @return array<int, array<string, mixed>>
     */
    private static function matchNamedIntent(array $intent, array $references): array
    {
        $dimension = (string)($intent['dimension'] ?? '');
        $rawSpan = trim((string)($intent['span'] ?? ''));
        $normalizedSpan = self::normalizeNamedIntentSpan($rawSpan, $dimension);
        if ($normalizedSpan === '') {
            return [];
        }

        $matches = [];
        foreach ($references as $reference) {
            $match = self::matchReference(
                $normalizedSpan,
                $rawSpan,
                $reference,
                $dimension
            );
            if ($match !== null) {
                $matches[] = $match;
            }
        }
        if (!empty($matches)) {
            $bestScore = max(array_column($matches, 'score'));
            return array_values(array_filter(
                $matches,
                function (array $match) use ($bestScore): bool {
                    return (int)$match['score'] === (int)$bestScore;
                }
            ));
        }

        if ($dimension !== 'library') {
            return [];
        }

        $intentTokens = self::distinctiveNamedTokens($normalizedSpan);
        if (count($intentTokens) !== 1 || strlen($intentTokens[0]) < 5) {
            return [];
        }

        $distinctiveMatches = [];
        foreach ($references as $reference) {
            $normalizedName = self::normalizeText((string)($reference['name'] ?? ''));
            if (!self::promptContainsNormalizedTerm($normalizedName, $intentTokens[0])) {
                continue;
            }
            $distinctiveMatches[] = self::referenceAsMatch(
                $reference,
                680 + strlen($intentTokens[0]),
                'typed_distinctive_name'
            );
        }

        return $distinctiveMatches;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $references
     * @return array<string, mixed>
     */
    private static function resolveMaterialIntent(array $intent, array $references): array
    {
        $matches = [];
        $canonicalNames = ReferenceIntentService::canonicalNamesForMaterialIntent($intent);
        $missingNames = [];

        foreach ($canonicalNames as $canonicalName) {
            $normalizedCanonicalName = self::normalizeText((string)$canonicalName);
            $canonicalMatches = array_values(array_filter(
                $references,
                function (array $reference) use ($normalizedCanonicalName): bool {
                    return self::normalizeText((string)($reference['name'] ?? ''))
                        === $normalizedCanonicalName;
                }
            ));
            if (empty($canonicalMatches)) {
                $missingNames[] = (string)$canonicalName;
                continue;
            }
            if (count($canonicalMatches) > 1) {
                return [
                    'matches' => [],
                    'outcome' => self::buildTypedIntentAmbiguityOutcome(
                        $intent,
                        $canonicalMatches
                    ),
                ];
            }
            $matches[] = self::referenceAsMatch(
                $canonicalMatches[0],
                1900,
                'typed_material_selector'
            );
        }

        if (!empty($missingNames)) {
            return [
                'matches' => [],
                'outcome' => self::buildUnavailableReferenceOutcome(
                    $intent,
                    $missingNames
                ),
            ];
        }

        foreach (($intent['terms'] ?? []) as $term) {
            $normalizedTerm = self::normalizeText((string)$term);
            if (
                $normalizedTerm === ''
                || isset(ReferenceIntentService::MATERIAL_SELECTORS[$normalizedTerm])
            ) {
                continue;
            }

            $exactMatches = array_values(array_filter(
                $references,
                function (array $reference) use ($normalizedTerm): bool {
                    return self::normalizeText((string)($reference['name'] ?? ''))
                        === $normalizedTerm;
                }
            ));
            $responsibleMatches = $exactMatches;
            if (empty($responsibleMatches)) {
                $responsibleMatches = array_values(array_filter(
                    $references,
                    function (array $reference) use ($normalizedTerm): bool {
                        return self::promptContainsNormalizedTerm(
                            self::normalizeText((string)($reference['name'] ?? '')),
                            $normalizedTerm
                        );
                    }
                ));
            }

            if (count($responsibleMatches) !== 1) {
                return [
                    'matches' => [],
                    'outcome' => self::buildTypedIntentAmbiguityOutcome(
                        $intent,
                        $responsibleMatches
                    ),
                ];
            }

            $matches[] = self::referenceAsMatch(
                $responsibleMatches[0],
                1850,
                'typed_material_name'
            );
        }

        return [
            'matches' => $matches,
            'outcome' => null,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, mixed>
     */
    private static function buildResolvedFilter(array $intent, array $matches): array
    {
        $values = [];
        $valueMetadata = [];
        foreach ($matches as $match) {
            $name = (string)($match['name'] ?? '');
            if ($name === '' || in_array($name, $values, true)) {
                continue;
            }
            $values[] = $name;
            $valueMetadata[$name] = is_array($match['metadata'] ?? null)
                ? $match['metadata']
                : [];
        }

        return [
            'dimension' => (string)($intent['dimension'] ?? ''),
            'source_table' => (string)($matches[0]['source_table'] ?? ''),
            'column' => 'name',
            'values' => $values,
            'value_metadata' => $valueMetadata,
            'provenance' => (string)($intent['provenance'] ?? 'explicit_prompt'),
            'vocabulary_terms' => ($intent['dimension'] ?? null) === 'material_type'
                ? array_values($intent['terms'] ?? [])
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, string> $missingNames
     * @return array<string, mixed>
     */
    private static function buildUnavailableReferenceOutcome(
        array $intent,
        array $missingNames
    ): array {
        return [
            'needsClarification' => true,
            'clarificationType' => 'reference_value_unavailable',
            'question' => 'I could not find the required video format in the current library reference data.',
            'options' => [],
            'route' => 'clarification',
            'routeReason' => 'reference_value_unavailable',
            'dataSource' => null,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, mixed>
     */
    private static function buildTypedIntentAmbiguityOutcome(
        array $intent,
        array $matches
    ): array {
        $dimension = (string)($intent['dimension'] ?? 'reference');
        $span = trim((string)($intent['span'] ?? ''));
        $label = str_replace('_', ' ', $dimension);
        $options = [];
        foreach ($matches as $match) {
            $reference = isset($match['score'])
                ? $match
                : self::referenceAsMatch($match, 0, 'typed_ambiguous');
            $name = trim((string)($reference['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $options[] = [
                'id' => (string)($reference['source_id'] ?? ''),
                'label' => $name,
                'sourceTable' => (string)($reference['source_table'] ?? ''),
                'sourceId' => (string)($reference['source_id'] ?? ''),
                'resolvedFilter' => [
                    'table' => (string)($reference['source_table'] ?? ''),
                    'column' => 'name',
                    'operator' => '=',
                    'value' => $name,
                ],
            ];
        }

        $hasMatches = !empty($matches);
        $confidence = $hasMatches
            ? 'ambiguous_' . $dimension . '_reference'
            : 'unresolved_' . $dimension . '_reference';
        $reason = $hasMatches
            ? 'multiple_' . $dimension . '_matches'
            : 'no_' . $dimension . '_matches';
        $question = $hasMatches
            ? 'I found multiple possible ' . $label . ' values.'
            : 'I could not identify that ' . $label . ' from the current library reference data.';

        return [
            'needsClarification' => true,
            'clarificationType' => self::CLARIFICATION_TYPE_BATCH,
            'clarificationBatchId' => self::newBatchId(),
            'clarificationItems' => [
                [
                    'term' => $span,
                    'clarificationKey' => 'reference_' . $dimension . '_ambiguous.'
                        . self::normalizeKey($span),
                    'question' => 'Which ' . $label . ' should "' . $span . '" mean?',
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'inputType' => 'single_choice',
                    'freeTextAllowed' => true,
                    'options' => $options,
                ],
            ],
            'question' => $question,
            'route' => 'clarification',
            'routeReason' => 'reference_resolver_ambiguous_' . $dimension,
            'dataSource' => null,
        ];
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private static function referenceAsMatch(
        array $reference,
        int $score,
        string $matchedBy
    ): array {
        return [
            'source_table' => trim((string)($reference['source_table'] ?? ($reference['table'] ?? ''))),
            'source_id' => (string)($reference['source_id'] ?? ($reference['id'] ?? '')),
            'name' => trim((string)($reference['name'] ?? '')),
            'code' => trim((string)($reference['code'] ?? '')),
            'metadata' => is_array($reference['metadata'] ?? null)
                ? $reference['metadata']
                : [],
            'score' => $score,
            'matched_by' => $matchedBy,
        ];
    }

    private static function normalizeNamedIntentSpan(
        string $span,
        string $dimension
    ): string {
        $normalized = self::normalizeText($span);
        $qualifiers = [
            'library' => 'librar(?:y|ies)',
            'location' => '(?:locations?|collections?|stacks?|rooms?|shelving)',
            'campus' => 'campus(?:es)?',
            'institution' => 'institutions?',
            'service_point' => 'service points?',
        ];
        if (!isset($qualifiers[$dimension])) {
            return $normalized;
        }

        $qualifier = $qualifiers[$dimension];
        $normalized = preg_replace('/^(?:' . $qualifier . ')\s+/', '', $normalized);
        $normalized = preg_replace('/\s+(?:' . $qualifier . ')$/', '', (string)$normalized);

        return trim((string)$normalized);
    }

    /**
     * @return array<int, string>
     */
    private static function distinctiveNamedTokens(string $normalizedSpan): array
    {
        $ignored = [
            'sc' => true,
            'ac' => true,
            'hc' => true,
            'mh' => true,
            'um' => true,
            'rp' => true,
            'yb' => true,
            'library' => true,
            'libraries' => true,
            'art' => true,
            'the' => true,
        ];
        $tokens = [];
        foreach (explode(' ', $normalizedSpan) as $token) {
            if ($token === '' || isset($ignored[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param array<int, string> $spans
     * @param array<int, string> $materialTerms
     */
    private static function removeConsumedPromptText(
        string $prompt,
        array $spans,
        array $materialTerms
    ): string {
        foreach ($spans as $span) {
            $span = trim((string)$span);
            if ($span === '') {
                continue;
            }
            $prompt = (string)preg_replace(
                '/' . preg_quote($span, '/') . '/iu',
                str_repeat(' ', strlen($span)),
                $prompt,
                1
            );
        }

        $knownPatterns = [
            'vhs' => '\bvhs(?:\s+tapes?)?\b',
            'dvd' => '\b(?:dvds?|dvd\s*\/\s*blu[\s-]?rays?|blu[\s-]?rays?)\b',
            'film' => '\bfilms?\b',
        ];
        foreach ($materialTerms as $term) {
            $term = self::normalizeText((string)$term);
            if ($term === '') {
                continue;
            }
            $pattern = $knownPatterns[$term]
                ?? '\b' . str_replace('\ ', '\s+', preg_quote($term, '/')) . '\b';
            $prompt = (string)preg_replace('/' . $pattern . '/iu', ' ', $prompt);
        }

        return $prompt;
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>|null
     */
    private static function matchReference(
        string $normalizedPrompt,
        string $rawPrompt,
        array $reference,
        ?string $intentDimension = null
    ) {
        $name = trim((string)($reference['name'] ?? ''));
        $code = trim((string)($reference['code'] ?? ''));
        $sourceTable = trim((string)($reference['source_table'] ?? ($reference['table'] ?? '')));
        if ($name === '' || $sourceTable === '') {
            return null;
        }

        $normalizedName = self::normalizeText($name);
        $normalizedNameWithoutPrefix = (string)($reference['normalized_name_without_prefix'] ?? self::stripCampusPrefix($normalizedName));
        $normalizedCode = self::normalizeText($code);
        $score = 0;
        $matchedBy = '';
        $effectiveIntentDimension = $intentDimension;
        if (
            $effectiveIntentDimension === null
            && $sourceTable === 'inventory.location__t'
            && strpos($normalizedNameWithoutPrefix, ' ') !== false
            && preg_match(
                '/\b(?:in|at)\s+(?:the\s+)?'
                    . preg_quote($normalizedNameWithoutPrefix, '/')
                    . '\b/',
                $normalizedPrompt
            ) === 1
        ) {
            $effectiveIntentDimension = 'location';
        }

        if (self::promptContainsNormalizedTerm($normalizedPrompt, $normalizedName)) {
            $score = 1000 + strlen($normalizedName);
            $matchedBy = 'name';
        } elseif ($normalizedNameWithoutPrefix !== $normalizedName
            && self::canMatchNameWithoutPrefix(
                $sourceTable,
                $normalizedNameWithoutPrefix,
                $effectiveIntentDimension
            )
            && self::promptContainsNormalizedTerm($normalizedPrompt, $normalizedNameWithoutPrefix)
        ) {
            $score = 700 + strlen($normalizedNameWithoutPrefix);
            $matchedBy = 'name_without_prefix';
        } elseif ($code !== '' && self::promptContainsCaseSensitiveCode($rawPrompt, $code)) {
            $score = 500 + strlen($normalizedCode);
            $matchedBy = 'code';
        } elseif (self::isLocationHierarchyTable($sourceTable)) {
            $partialScore = self::scoreLocationHierarchyPartialMatch($normalizedPrompt, $reference, $normalizedNameWithoutPrefix);
            $hasTypedContext = $intentDimension !== null
                && ReferenceIntentService::tableForDimension($intentDimension) === $sourceTable;
            $isSafeLegacyLocationMatch = $intentDimension === null
                && $sourceTable === 'inventory.location__t'
                && $partialScore >= 650;
            if ($partialScore > 0 && ($hasTypedContext || $isSafeLegacyLocationMatch)) {
                $score = $partialScore;
                $matchedBy = 'location_hierarchy_partial';
            }
        }

        if ($score === 0) {
            return null;
        }

        return self::referenceAsMatch($reference, $score, $matchedBy);
    }

    /**
     * @param array<string, mixed> $match
     */
    private static function buildReferenceGuidanceLine(array $match): string
    {
        $table = (string)$match['source_table'];
        $name = (string)$match['name'];
        $parts = [];
        if ((string)$match['code'] !== '') {
            $parts[] = 'code ' . self::quoteLiteral((string)$match['code']);
        }
        foreach (($match['metadata'] ?? []) as $key => $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $parts[] = str_replace('_', ' ', (string)$key) . ' ' . self::quoteLiteral((string)$value);
            }
        }
        // When the match itself comes from the location hierarchy, the
        // "do not apply to library/campus columns" guard and the "do not add
        // code filters" guard contradict the correct behavior (camp.code is the
        // canonical campus filter). Only emit those guards for non-location
        // references (material types, funds, etc.).
        $isLocationHierarchy = self::isLocationHierarchyTable($table);

        $line = '- Resolved local reference: use exactly ' . $table . '.name = ' . self::quoteLiteral($name) . '.';
        if (!$isLocationHierarchy) {
            $line .= ' Do not apply this value to library or campus name columns.';
        }
        if (!empty($parts)) {
            if ($isLocationHierarchy) {
                $line .= ' Available reference attributes: ' . implode('; ', array_slice($parts, 0, 4))
                    . '. The code is a valid filter column for this scope.';
            } else {
                $line .= ' Metadata for display only, not extra filters: ' . implode('; ', array_slice($parts, 0, 4)) . '.';
            }
        }
        if (!$isLocationHierarchy) {
            $line .= ' Do not add code filters unless the user explicitly asks to filter by code.';
        }
        return $line;
    }

    /**
     * @param array<string, mixed> $alias
     */
    private static function buildAliasGuidanceLine(array $alias): string
    {
        $filter = $alias['resolved_filter'] ?? [];
        if (!is_array($filter)) {
            return '';
        }

        return '- Learned local alias: ' . ($alias['alias'] ?? '') . ' means '
            . ($filter['table'] ?? '') . '.' . ($filter['column'] ?? 'name')
            . ' = ' . self::quoteLiteral((string)($filter['value'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidateResult(string $classification, string $reason): array
    {
        return [
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyResolution(): array
    {
        return [
            'needsClarification' => false,
            'guidanceLines' => [],
            'resolvedReferences' => [],
            'resolvedFilters' => [],
            'routeReason' => null,
        ];
    }

    private static function normalizeText(string $text): string
    {
        return ReferenceTextNormalizerService::normalize($text);
    }

    private static function normalizeKey(string $text): string
    {
        return ReferenceTextNormalizerService::key($text);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitSourceTable(string $sourceTable): array
    {
        $parts = explode('.', $sourceTable, 2);
        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['public', $sourceTable];
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function stripCampusPrefix(string $normalizedName): string
    {
        return ReferenceTextNormalizerService::normalizeWithoutCampusPrefix($normalizedName);
    }

    private static function isLocationHierarchyTable(string $table): bool
    {
        return in_array($table, [
            'inventory.location__t',
            'inventory.loclibrary__t',
            'inventory.loccampus__t',
            'inventory.locinstitution__t',
            'inventory.service_point__t',
        ], true);
    }

    /**
     * Location prompts often omit campus/library prefixes while still naming a
     * real row, e.g. "treasure folio" for "SC Josten Treasure Folio". This
     * matcher only applies to the approved location hierarchy and requires
     * distinctive row tokens to be present in the prompt.
     *
     * @param array<string, mixed> $reference
     */
    private static function scoreLocationHierarchyPartialMatch(
        string $normalizedPrompt,
        array $reference,
        string $normalizedNameWithoutPrefix
    ): int {
        $tokens = self::referenceMatchTokens($reference, $normalizedNameWithoutPrefix);
        if (empty($tokens)) {
            return 0;
        }

        $promptTokens = array_fill_keys(explode(' ', $normalizedPrompt), true);
        $matched = [];
        foreach ($tokens as $token) {
            if (isset($promptTokens[$token])) {
                $matched[] = $token;
            }
        }

        if (count($matched) >= 2 && count($matched) === count($tokens)) {
            return 650 + count($matched) * 10 + strlen($normalizedNameWithoutPrefix);
        }

        if (count($tokens) === 1 && count($matched) === 1 && strlen($tokens[0]) >= 6) {
            return 430 + strlen($tokens[0]);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<int, string>
     */
    private static function referenceMatchTokens(array $reference, string $normalizedNameWithoutPrefix): array
    {
        $tokens = is_array($reference['search_tokens'] ?? null)
            ? array_map('strval', $reference['search_tokens'])
            : explode(' ', $normalizedNameWithoutPrefix);

        $nameTokens = explode(' ', $normalizedNameWithoutPrefix);
        if (count($nameTokens) >= 3 && in_array($nameTokens[0], ['josten', 'neilson', 'hillyer', 'young', 'alumnae'], true)) {
            array_shift($nameTokens);
        }

        $tokens = count($nameTokens) >= 1 ? $nameTokens : $tokens;
        $ignored = [
            'sc' => true,
            'ac' => true,
            'hc' => true,
            'mh' => true,
            'um' => true,
            'rp' => true,
            'yb' => true,
            'library' => true,
            'libraries' => true,
            'location' => true,
            'locations' => true,
            'collection' => true,
            'collections' => true,
            'josten' => true,
            'neilson' => true,
            'hillyer' => true,
            'young' => true,
            'alumnae' => true,
            'the' => true,
            'and' => true,
            'or' => true,
        ];

        $clean = [];
        foreach ($tokens as $token) {
            $token = self::normalizeText((string)$token);
            if ($token === '' || strlen($token) < 3 || isset($ignored[$token])) {
                continue;
            }
            $clean[$token] = true;
        }

        return array_keys($clean);
    }

    /**
     * @param array<int, array<string, mixed>> $resolved
     * @return array<int, array<string, mixed>>
     */
    private static function filterResolvedReferenceConflicts(array $resolved): array
    {
        $locationMatches = array_values(array_filter($resolved, function ($match) {
            return (string)($match['source_table'] ?? '') === 'inventory.location__t';
        }));
        if (empty($locationMatches)) {
            return $resolved;
        }

        $locationNames = array_map(function ($match) {
            return self::normalizeText((string)($match['name'] ?? ''));
        }, $locationMatches);

        return array_values(array_filter($resolved, function ($match) use ($locationNames) {
            $table = (string)($match['source_table'] ?? '');
            $matchedBy = (string)($match['matched_by'] ?? '');
            $name = self::normalizeText((string)($match['name'] ?? ''));

            if ($table === 'inventory.location__t') {
                return true;
            }

            if (in_array($table, ['inventory.loclibrary__t', 'inventory.loccampus__t', 'inventory.locinstitution__t', 'inventory.service_point__t'], true)) {
                return $matchedBy !== 'location_hierarchy_partial';
            }

            if ($matchedBy === 'name' && strpos($name, ' ') === false) {
                foreach ($locationNames as $locationName) {
                    if ($name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/', $locationName) === 1) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $references
     * @return array<string, mixed>|null
     */
    private static function buildAmbiguousLocationClarification(string $normalizedPrompt, array $references)
    {
        if (preg_match(
            '/\b(?:location|locations|library|libraries|campus|campuses|service point|service points|collection|collections|holdings|shelved|room|branch)\b|\b(?:in|at)\s+[a-z0-9]/',
            $normalizedPrompt
        ) !== 1) {
            return null;
        }

        $promptTokens = array_values(array_filter(explode(' ', $normalizedPrompt), function ($token) {
            return strlen((string)$token) >= 4;
        }));
        if (empty($promptTokens)) {
            return null;
        }

        $matchesByToken = [];
        foreach ($references as $reference) {
            $table = (string)($reference['source_table'] ?? ($reference['table'] ?? ''));
            if (!self::isLocationHierarchyTable($table)) {
                continue;
            }

            $name = trim((string)($reference['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $referenceTokens = is_array($reference['search_tokens'] ?? null)
                ? array_map('strval', $reference['search_tokens'])
                : explode(' ', self::normalizeText($name));
            $referenceTokenMap = array_fill_keys($referenceTokens, true);

            foreach ($promptTokens as $token) {
                if (!isset($referenceTokenMap[$token])) {
                    continue;
                }

                $matchesByToken[$token][$table . '|' . strtolower($name)] = [
                    'id' => (string)($reference['source_id'] ?? ($reference['id'] ?? '')),
                    'label' => $name,
                    'sourceTable' => $table,
                    'sourceId' => (string)($reference['source_id'] ?? ($reference['id'] ?? '')),
                    'resolvedFilter' => [
                        'table' => $table,
                        'column' => 'name',
                        'operator' => '=',
                        'value' => $name,
                    ],
                ];
            }
        }

        foreach ($matchesByToken as $token => $matches) {
            if (count($matches) < 2) {
                continue;
            }

            return [
                'needsClarification' => true,
                'clarificationType' => self::CLARIFICATION_TYPE_BATCH,
                'clarificationBatchId' => self::newBatchId(),
                'clarificationItems' => [
                    [
                        'term' => $token,
                        'clarificationKey' => 'reference_location_ambiguous.' . self::normalizeKey($token),
                        'question' => 'Which local location, library, campus, or service point should "' . $token . '" mean?',
                        'confidence' => 'ambiguous_location_reference',
                        'reason' => 'multiple_location_hierarchy_matches',
                        'inputType' => 'single_choice',
                        'freeTextAllowed' => true,
                        'options' => array_values(array_slice($matches, 0, 8)),
                    ],
                ],
                'question' => 'I found multiple local location hierarchy matches before generating SQL.',
                'route' => 'clarification',
                'routeReason' => 'reference_resolver_ambiguous_reference',
                'dataSource' => null,
            ];
        }

        return null;
    }

    private static function isGenericReferenceName(string $name): bool
    {
        $generic = [
            'contributor' => true,
            'note' => true,
            'other' => true,
            'unknown' => true,
            'unspecified' => true,
            'general' => true,
        ];

        return isset($generic[self::normalizeText($name)]);
    }

    private static function promptContainsNormalizedTerm(string $normalizedPrompt, string $normalizedTerm): bool
    {
        if ($normalizedTerm === '' || strlen($normalizedTerm) < 3) {
            return false;
        }
        return preg_match('/\b' . preg_quote($normalizedTerm, '/') . '\b/', $normalizedPrompt) === 1;
    }

    private static function canMatchNameWithoutPrefix(
        string $sourceTable,
        string $normalizedName,
        ?string $intentDimension = null
    ): bool {
        if ($sourceTable === 'inventory.location__t') {
            return $intentDimension === 'location'
                && strpos($normalizedName, ' ') !== false;
        }

        if ($sourceTable === 'inventory.loclibrary__t') {
            return $intentDimension === 'library';
        }

        return !self::isLocationHierarchyTable($sourceTable)
            && strpos($normalizedName, ' ') !== false;
    }

    /**
     * Match a reference code against the RAW prompt, case-sensitively, as a
     * standalone alphanumeric token. Reference codes are distinct identifiers
     * (e.g. 'ART', 'SC', 'SC-GEN-001'); matching them case-insensitively against
     * normalized text let ordinary lowercase words ("art", "gen") trigger
     * spurious reference filters.
     */
    private static function promptContainsCaseSensitiveCode(string $rawPrompt, string $code): bool
    {
        $code = trim($code);
        if (strlen($code) < 2) {
            return false;
        }

        return preg_match('/(?<![A-Za-z0-9])' . preg_quote($code, '/') . '(?![A-Za-z0-9])/', $rawPrompt) === 1;
    }

    private static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function newBatchId(): string
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        return uniqid('refbatch-', true);
    }
}
