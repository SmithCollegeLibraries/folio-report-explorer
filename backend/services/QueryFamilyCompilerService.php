<?php

namespace app\services;

class QueryFamilyCompilerService
{
    const DEFAULT_LIMIT = 100;
    const FORMER_CIRCULATION_NOTE_TYPE_ID = 'f765f19f-9f1c-4688-8c79-ec366a730842';
    const SUPPORTED_FAMILIES = [
        'circulation_top_items',
        'circulation_trends_matrix',
        'inventory_collection_age',
        'inventory_contributor_campus_item_barcode',
        'inventory_library_location_listing',
    ];

    public static function compileToQueryDefinition(array $payload): array
    {
        $normalizedPayload = self::normalizePayload($payload);
        $familyKey = $normalizedPayload['familyKey'];
        $slots = $normalizedPayload['slots'];
        $contract = self::loadFamilyContract($familyKey);

        $graph = CanonicalQueryGraphService::loadArtifact();
        $joins = self::buildDeterministicJoins($familyKey, $slots, $graph);

        return [
            'tables' => self::buildTables($familyKey, $contract, $slots),
            'columns' => self::buildColumns($familyKey, $slots['requested_outputs'] ?? []),
            'filters' => self::buildFilters($familyKey, $slots),
            'joins' => $joins,
            'orderBy' => [],
            'groupBy' => [],
            'having' => [],
            'distinct' => false,
            'limit' => self::resolveRequestedLimit($slots),
        ];
    }

    public static function compileToSql(array $payload): array
    {
        $normalizedPayload = self::normalizePayload($payload);
        $queryDef = self::compileToQueryDefinition($normalizedPayload);

        if ($normalizedPayload['familyKey'] === 'circulation_trends_matrix') {
            return self::buildCirculationTrendMatrixSql($queryDef, $normalizedPayload['slots']);
        }

        if ($normalizedPayload['familyKey'] === 'circulation_top_items') {
            return self::buildCirculationTopItemsSql($queryDef, $normalizedPayload['slots']);
        }

        if ($normalizedPayload['familyKey'] === 'inventory_collection_age') {
            return self::buildCollectionAgeSql($queryDef);
        }

        return SqlBuilderService::build($queryDef);
    }

    private static function normalizePayload(array $payload): array
    {
        $validation = QueryFamilySlotService::validateFamilyPayload($payload);
        if (!$validation['valid']) {
            $firstError = $validation['errors'][0] ?? ['message' => 'Invalid query family slot payload.'];
            throw new \InvalidArgumentException((string)($firstError['message'] ?? 'Invalid query family slot payload.'));
        }

        return $validation['normalizedPayload'];
    }

    private static function loadFamilyContract(string $familyKey): array
    {
        if (!in_array($familyKey, self::SUPPORTED_FAMILIES, true)) {
            throw new \InvalidArgumentException('Unsupported query family compiler: ' . $familyKey . '.');
        }

        $contracts = QueryFamilyContractService::loadContracts();
        $contract = $contracts[$familyKey] ?? null;
        if (!is_array($contract)) {
            throw new \RuntimeException('Missing query family contract: ' . $familyKey . '.');
        }

        $scopeRule = (string)($contract['scopeRule'] ?? '');
        if (!in_array($scopeRule, ['outputs_via_qualifying_holdings', 'inventory_listing_via_holdings_scope', 'collection_age_via_holdings_publication_year', 'circulation_trends_by_call_number_class', 'circulation_top_items_by_total_circulation'], true)) {
            throw new \RuntimeException('Unsupported scope rule for deterministic family compiler: ' . (string)($contract['scopeRule'] ?? '') . '.');
        }

        return $contract;
    }

    private static function buildTables(string $familyKey, array $contract, array $slots): array
    {
        $tables = self::orderedUniqueStrings($contract['graph']['canonicalPath'] ?? []);

        foreach (($contract['graph']['requiredEntities'] ?? []) as $table) {
            $table = trim((string)$table);
            if ($table !== '') {
                $tables[] = $table;
            }
        }

        if (self::familyOutputsNeedContributorJoin($familyKey, $slots)) {
            array_splice($tables, 1, 0, ['inventory_instance__t__contributors']);
        }

        if (in_array($familyKey, ['inventory_contributor_campus_item_barcode', 'circulation_top_items'], true) && !empty($slots['material_type'])) {
            $tables[] = 'inventory_material_types';
        }

        return self::orderedUniqueStrings($tables);
    }

    private static function buildColumns(string $familyKey, array $requestedOutputs): array
    {
        if (in_array($familyKey, ['inventory_collection_age', 'circulation_top_items', 'circulation_trends_matrix'], true)) {
            return [];
        }

        $columns = [];

        foreach ($requestedOutputs as $outputField) {
            switch ($outputField) {
                case 'barcode':
                    $columns[] = ['table' => 'inventory_items', 'column' => 'barcode'];
                    break;
                case 'contributor_name':
                    $columns[] = [
                        'table' => 'inventory_instance__t__contributors',
                        'column' => 'contributors__name',
                        'alias' => 'contributor_name',
                    ];
                    break;
                case 'author':
                    $columns[] = [
                        'table' => 'inventory_instance__t__contributors',
                        'column' => 'contributors__name',
                        'alias' => 'author',
                    ];
                    break;
                case 'instance_hrid':
                    $columns[] = ['table' => 'inventory_instances', 'column' => 'hrid'];
                    break;
                case 'instance_number':
                    $columns[] = ['table' => 'inventory_instances', 'column' => 'hrid', 'alias' => 'instance_number'];
                    break;
                case 'item_id':
                    $columns[] = ['table' => 'inventory_items', 'column' => 'id', 'alias' => 'item_id'];
                    break;
                case 'publication_date':
                    $columns[] = ['table' => 'inventory_instances', 'column' => 'dates__date1', 'alias' => 'publication_date'];
                    break;
                case 'pub_date':
                    $columns[] = ['table' => 'inventory_instances', 'column' => 'dates__date1', 'alias' => 'pub_date'];
                    break;
                case 'title':
                    $columns[] = ['table' => 'inventory_instances', 'column' => 'title'];
                    break;
            }
        }

        return $columns;
    }

    private static function buildFilters(string $familyKey, array $slots): array
    {
        if ($familyKey === 'circulation_trends_matrix') {
            $filters = [];
            if (!empty($slots['campus'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
            }
            if (!empty($slots['library'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('library', 'inventory_libraries', 'name', $slots['library'], $slots['match_policy']);
            }
            if (!empty($slots['location'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('location', 'inventory_locations', 'name', $slots['location'], $slots['match_policy']);
            }

            return $filters;
        }

        if ($familyKey === 'inventory_collection_age') {
            $filters = [];
            if (!empty($slots['campus'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
            }
            $filters[] = QueryFamilySlotService::buildSlotFilter('library', 'inventory_libraries', 'name', $slots['library'], $slots['match_policy']);
            if (!empty($slots['location'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('location', 'inventory_locations', 'name', $slots['location'], $slots['match_policy']);
            }

            return $filters;
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $filters = [];
            if (!empty($slots['campus'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
            }

            if (!empty($slots['library'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('library', 'inventory_libraries', 'name', $slots['library'], $slots['match_policy']);
            }

            if (!empty($slots['location'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('location', 'inventory_locations', 'name', $slots['location'], $slots['match_policy']);
            }

            if (!empty($slots['location_code'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('location_code', 'inventory_locations', 'code', $slots['location_code'], $slots['match_policy']);
            }

            return $filters;
        }

        if ($familyKey === 'circulation_top_items') {
            $filters = [];
            if (!empty($slots['campus'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
            }
            $filters[] = QueryFamilySlotService::buildSlotFilter('library', 'inventory_libraries', 'name', $slots['library'], $slots['match_policy']);
            $filters[] = QueryFamilySlotService::buildSlotFilter(
                'material_type',
                'inventory_material_types',
                'name',
                $slots['material_type'],
                $slots['match_policy']
            );
            if (!empty($slots['location'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('location', 'inventory_locations', 'name', $slots['location'], $slots['match_policy']);
            }

            return $filters;
        }

        $filters = [];
        $filters[] = QueryFamilySlotService::buildSlotFilter('campus', 'inventory_campuses', 'name', $slots['campus'], $slots['match_policy']);
        $filters[] = QueryFamilySlotService::buildSlotFilter(
            'contributor_name',
            'inventory_instance__t__contributors',
            'contributors__name',
            $slots['contributor_name'],
            $slots['match_policy']
        );

        if (!empty($slots['contributor_name_type'])) {
            $filters[] = QueryFamilySlotService::buildSlotFilter(
                'contributor_name_type',
                'inventory_contributor_name_types',
                'name',
                $slots['contributor_name_type'],
                $slots['match_policy']
            );
        }

        if (!empty($slots['material_type'])) {
            $filters[] = QueryFamilySlotService::buildSlotFilter(
                'material_type',
                'inventory_material_types',
                'name',
                $slots['material_type'],
                $slots['match_policy']
            );
        }

        return $filters;
    }

    private static function buildDeterministicJoins(string $familyKey, array $slots, array $graph): array
    {
        if ($familyKey === 'circulation_trends_matrix') {
            return self::buildCirculationTrendMatrixJoins();
        }

        if ($familyKey === 'circulation_top_items') {
            return self::buildCirculationTopItemsJoins($graph, $slots);
        }

        if ($familyKey === 'inventory_collection_age') {
            return self::buildCollectionAgeJoins($graph);
        }

        if ($familyKey === 'inventory_library_location_listing') {
            return self::buildInventoryLibraryLocationListingJoins($graph, $slots);
        }

        $joins = [];

        $joinSpecs = [
            ['inventory_instances', 'id', 'inventory_instance__t__contributors', 'id'],
            ['inventory_instance__t__contributors', 'contributors__contributor_name_type_id', 'inventory_contributor_name_types', 'id'],
            ['inventory_instances', 'id', 'inventory_holdings', 'instance_id'],
            ['inventory_holdings', 'id', 'inventory_items', 'holdings_record_id'],
            ['inventory_items', 'effective_location_id', 'inventory_locations', 'id'],
            ['inventory_locations', 'library_id', 'inventory_libraries', 'id'],
            ['inventory_libraries', 'campus_id', 'inventory_campuses', 'id'],
        ];

        foreach ($joinSpecs as $spec) {
            self::assertDeterministicGraphConnection($graph, $spec[0], $spec[1], $spec[2], $spec[3]);
            $joins[] = [
                'from_table' => $spec[0],
                'from_column' => $spec[1],
                'to_table' => $spec[2],
                'to_column' => $spec[3],
            ];
        }

        if (!empty($slots['material_type'])) {
            $joins[] = [
                'from_table' => 'inventory_items',
                'from_column' => 'material_type_id',
                'to_table' => 'inventory_material_types',
                'to_column' => 'id',
            ];
        }

        return $joins;
    }

    private static function buildCirculationTopItemsJoins(array $graph, array $slots): array
    {
        $joins = [];
        $joinSpecs = [
            ['inventory_instances', 'id', 'inventory_holdings', 'instance_id'],
            ['inventory_holdings', 'id', 'inventory_items', 'holdings_record_id'],
            ['inventory_items', 'effective_location_id', 'inventory_locations', 'id'],
            ['inventory_locations', 'library_id', 'inventory_libraries', 'id'],
            ['inventory_libraries', 'campus_id', 'inventory_campuses', 'id'],
        ];

        foreach ($joinSpecs as $spec) {
            self::assertDeterministicGraphConnection($graph, $spec[0], $spec[1], $spec[2], $spec[3]);
            $joins[] = [
                'from_table' => $spec[0],
                'from_column' => $spec[1],
                'to_table' => $spec[2],
                'to_column' => $spec[3],
            ];
        }

        if (!empty($slots['material_type'])) {
            $joins[] = [
                'from_table' => 'inventory_items',
                'from_column' => 'material_type_id',
                'to_table' => 'inventory_material_types',
                'to_column' => 'id',
            ];
        }

        return $joins;
    }

    private static function buildCollectionAgeJoins(array $graph): array
    {
        $joins = [];
        $joinSpecs = [
            ['inventory_items', 'holdings_record_id', 'inventory_holdings', 'id'],
            ['inventory_holdings', 'instance_id', 'inventory_instances', 'id'],
            ['inventory_instances', 'id', 'inventory_instance__t__publication', 'id'],
            ['inventory_items', 'effective_location_id', 'inventory_locations', 'id'],
            ['inventory_locations', 'library_id', 'inventory_libraries', 'id'],
            ['inventory_libraries', 'campus_id', 'inventory_campuses', 'id'],
        ];

        foreach ($joinSpecs as $spec) {
            self::assertDeterministicGraphConnection($graph, $spec[0], $spec[1], $spec[2], $spec[3]);
            $joins[] = [
                'from_table' => $spec[0],
                'from_column' => $spec[1],
                'to_table' => $spec[2],
                'to_column' => $spec[3],
            ];
        }

        return $joins;
    }

    private static function buildInventoryLibraryLocationListingJoins(array $graph, array $slots): array
    {
        $joins = [];

        if (self::familyOutputsNeedContributorJoin('inventory_library_location_listing', $slots)) {
            $spec = ['inventory_instances', 'id', 'inventory_instance__t__contributors', 'id'];
            self::assertDeterministicGraphConnection($graph, $spec[0], $spec[1], $spec[2], $spec[3]);
            $joins[] = [
                'from_table' => $spec[0],
                'from_column' => $spec[1],
                'to_table' => $spec[2],
                'to_column' => $spec[3],
            ];
        }

        $joinSpecs = [
            ['inventory_instances', 'id', 'inventory_holdings', 'instance_id'],
            ['inventory_holdings', 'id', 'inventory_items', 'holdings_record_id'],
            ['inventory_items', 'effective_location_id', 'inventory_locations', 'id'],
            ['inventory_locations', 'library_id', 'inventory_libraries', 'id'],
            ['inventory_libraries', 'campus_id', 'inventory_campuses', 'id'],
        ];

        foreach ($joinSpecs as $spec) {
            self::assertDeterministicGraphConnection($graph, $spec[0], $spec[1], $spec[2], $spec[3]);
            $joins[] = [
                'from_table' => $spec[0],
                'from_column' => $spec[1],
                'to_table' => $spec[2],
                'to_column' => $spec[3],
            ];
        }

        return $joins;
    }

    private static function buildCirculationTrendMatrixJoins(): array
    {
        return [
            [
                'from_table' => 'circulation_loans',
                'from_column' => 'item_id',
                'to_table' => 'inventory_items',
                'to_column' => 'id',
            ],
            [
                'from_table' => 'circulation_loans',
                'from_column' => 'item_effective_location_id_at_check_out',
                'to_table' => 'inventory_locations',
                'to_column' => 'id',
            ],
            [
                'from_table' => 'inventory_locations',
                'from_column' => 'library_id',
                'to_table' => 'inventory_libraries',
                'to_column' => 'id',
            ],
            [
                'from_table' => 'inventory_libraries',
                'from_column' => 'campus_id',
                'to_table' => 'inventory_campuses',
                'to_column' => 'id',
            ],
        ];
    }

    private static function buildCollectionAgeSql(array $queryDef): array
    {
        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $params = [];
        $scopeWhere = [];
        $parameterIndex = 0;

        foreach ([
            ['inventory_campuses', 'name', 'ic.name'],
            ['inventory_libraries', 'name', 'il.name'],
            ['inventory_locations', 'name', 'ilo.name'],
        ] as $filterSpec) {
            $key = self::filterKey($filterSpec[0], $filterSpec[1]);
            if (!isset($filters[$key])) {
                continue;
            }

            $placeholder = ':p' . $parameterIndex;
            $where[] = $filterSpec[2] . ' ILIKE ' . $placeholder;
            $params[$placeholder] = (string)$filters[$key]['value'];
            $parameterIndex++;
        }

        $where[] = 'iip.publication__date_of_publication IS NOT NULL';
        $where[] = "iip.publication__date_of_publication ~ '^\\d{4}'";

        $sql = "SELECT AVG(EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER)) AS average_age_years\n"
            . "FROM inventory.item__t ii\n"
            . "JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id\n"
            . "JOIN inventory.instance__t iin ON ih.instance_id = iin.id\n"
            . "LEFT JOIN inventory.instance__t__publication iip ON iip.id = iin.id\n"
            . "JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "WHERE " . implode("\n  AND ", $where) . "\n"
            . "LIMIT " . self::DEFAULT_LIMIT;

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function buildCirculationTrendMatrixSql(array $queryDef, array $slots): array
    {
        $groupingDimension = (string)($slots['grouping_dimension'] ?? '');
        if ($groupingDimension !== 'primary_call_number_class') {
            throw new \InvalidArgumentException('unsupported_grouping_dimension: The first trend-matrix compiler slice only supports primary_call_number_class grouping.');
        }

        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
        if ($requestedOutputs !== ['yearly_circulation_matrix']) {
            throw new \InvalidArgumentException('unsupported_trend_output: The first trend-matrix compiler slice only supports yearly_circulation_matrix output.');
        }

        $circulationSourcePolicy = (string)($slots['circulation_source_policy'] ?? '');
        if ($circulationSourcePolicy !== '' && !in_array($circulationSourcePolicy, ['current_loans_only', 'former_aleph_comparison', 'prior_year_comparison', 'cumulative_before_selected_years_comparison'], true)) {
            throw new \InvalidArgumentException('unsupported_circulation_source_policy: The current trend-matrix compiler slice only supports current_loans_only, former_aleph_comparison, prior_year_comparison, and cumulative_before_selected_years_comparison.');
        }

        $yearBuckets = is_array($slots['year_buckets'] ?? null) ? $slots['year_buckets'] : [];
        if ($yearBuckets === []) {
            throw new \InvalidArgumentException('missing_year_buckets: Trend-matrix compilation requires one or more year buckets.');
        }

        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $params = [];
        $where = [];
        $parameterIndex = 0;

        foreach ([
            ['inventory_campuses', 'name', 'ic.name'],
            ['inventory_libraries', 'name', 'il.name'],
            ['inventory_locations', 'name', 'ilo.name'],
        ] as $filterSpec) {
            $key = self::filterKey($filterSpec[0], $filterSpec[1]);
            if (!isset($filters[$key])) {
                continue;
            }

            $placeholder = ':p' . $parameterIndex;
            $scopeWhere[] = $filterSpec[2] . ' ILIKE ' . $placeholder;
            $params[$placeholder] = (string)$filters[$key]['value'];
            $parameterIndex++;
        }

        $where = $scopeWhere;
        $where[] = "cl.action IN ('checkedout', 'checkedOutThroughOverride')";

        $yearColumns = [];
        foreach ($yearBuckets as $year) {
            $safeYear = (string)$year;
            $yearColumns[] = 'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = ' . $safeYear . ' THEN 1 ELSE 0 END) AS circulation_' . $safeYear;
        }

        if ($circulationSourcePolicy === 'prior_year_comparison') {
            $comparisonYear = (string)(((int)$yearBuckets[0]) - 1);
            $yearColumns[] = 'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = ' . $comparisonYear . ' THEN 1 ELSE 0 END) AS previous_circulation';
        }

        if ($circulationSourcePolicy === 'cumulative_before_selected_years_comparison') {
            $earliestRequestedYear = (string)min(array_map('intval', $yearBuckets));
            $yearColumns[] = 'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) < ' . $earliestRequestedYear . ' THEN 1 ELSE 0 END) AS previous_circulation';
        }

        $callNumberClassSql = self::buildPrimaryCallNumberClassSql('ii.effective_call_number_components__call_number');

        if ($circulationSourcePolicy === 'former_aleph_comparison') {
            return self::buildFormerAlephComparisonTrendMatrixSql($scopeWhere, $where, $params, $yearBuckets, $yearColumns, $callNumberClassSql);
        }

        $sql = "SELECT " . $callNumberClassSql . " AS call_number_class,\n    "
            . implode(",\n    ", $yearColumns) . "\n"
            . "FROM circulation.loan__t cl\n"
            . "JOIN inventory.item__t ii ON cl.item_id = ii.id\n"
            . "JOIN inventory.location__t ilo ON cl.item_effective_location_id_at_check_out = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "WHERE " . implode("\n  AND ", $where) . "\n"
            . "GROUP BY call_number_class\n"
            . "ORDER BY call_number_class ASC\n"
            . "LIMIT " . self::DEFAULT_LIMIT;

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function buildCirculationTopItemsSql(array $queryDef, array $slots): array
    {
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
        if ($requestedOutputs !== ['ranked_circulation_items']) {
            throw new \InvalidArgumentException('unsupported_top_items_output: The first top-items compiler slice only supports ranked_circulation_items output.');
        }

        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $params = [];
        $scopeWhere = [];
        $parameterIndex = 0;

        foreach ([
            ['inventory_campuses', 'name', 'ic.name'],
            ['inventory_libraries', 'name', 'il.name'],
            ['inventory_material_types', 'name', 'imt.name'],
            ['inventory_locations', 'name', 'ilo.name'],
        ] as $filterSpec) {
            $key = self::filterKey($filterSpec[0], $filterSpec[1]);
            if (!isset($filters[$key])) {
                continue;
            }

            $placeholder = ':p' . $parameterIndex;
            $scopeWhere[] = $filterSpec[2] . ' ILIKE ' . $placeholder;
            $params[$placeholder] = (string)$filters[$key]['value'];
            $parameterIndex++;
        }

        if ($scopeWhere === []) {
            throw new \InvalidArgumentException('missing_top_items_scope: Top-items compilation requires at least library scope filters.');
        }

        $limit = self::resolveRequestedLimit($slots);

        $targetItemsSql = "SELECT ii.id AS item_id, ii.hrid, ii.barcode,\n"
            . "       ii.effective_call_number_components__call_number AS call_number,\n"
            . "       inst.title AS instance_title\n"
            . "FROM inventory.item__t ii\n"
            . "JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id\n"
            . "JOIN inventory.instance__t inst ON ih.instance_id = inst.id\n"
            . "JOIN inventory.material_type__t imt ON ii.material_type_id = imt.id\n"
            . "JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "WHERE " . implode("\n  AND ", $scopeWhere);

        $currentCirculationSql = "SELECT al.loan__item_id AS item_id,\n"
            . "       COUNT(*) AS current_circulation,\n"
            . "       MAX(al.loan__loan_date) AS last_circulation_date\n"
            . "FROM circulation.audit_loan__t al\n"
            . "JOIN target_items ti ON al.loan__item_id = ti.item_id\n"
            . "WHERE al.loan__action IN ('checkedout', 'checkedOutThroughOverride')\n"
            . "GROUP BY al.loan__item_id";

        $formerCirculationSql = "SELECT itn.hrid,\n"
            . "       CAST(COALESCE(NULLIF(REGEXP_REPLACE(itn.notes__note, '\\D', '', 'g'), ''), '0') AS BIGINT) AS former_circulation\n"
            . "FROM inventory.item__t__notes itn\n"
            . "JOIN target_items ti ON itn.hrid = ti.hrid\n"
            . "WHERE itn.notes__item_note_type_id = '" . self::FORMER_CIRCULATION_NOTE_TYPE_ID . "'";

        $sql = "WITH target_items AS (\n"
            . $targetItemsSql . "\n"
            . "),\ncurrent_circ AS (\n"
            . $currentCirculationSql . "\n"
            . "),\nformer_circ AS (\n"
            . $formerCirculationSql . "\n"
            . ")\nSELECT\n"
            . "    ti.instance_title,\n"
            . "    ti.barcode AS item_barcode,\n"
            . "    ti.call_number,\n"
            . "    COALESCE(cc.current_circulation, 0) AS current_circulation,\n"
            . "    COALESCE(fc.former_circulation, 0) AS former_circulation,\n"
            . "    COALESCE(cc.current_circulation, 0) + COALESCE(fc.former_circulation, 0) AS total_circulation,\n"
            . "    cc.last_circulation_date\n"
            . "FROM target_items ti\n"
            . "LEFT JOIN current_circ cc ON ti.item_id = cc.item_id\n"
            . "LEFT JOIN former_circ fc ON ti.hrid = fc.hrid\n"
            . "ORDER BY total_circulation DESC, ti.instance_title ASC\n"
            . "LIMIT " . $limit;

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function buildFormerAlephComparisonTrendMatrixSql(
        array $scopeWhere,
        array $where,
        array $params,
        array $yearBuckets,
        array $yearColumns,
        string $callNumberClassSql
    ): array {
        $outerYearColumns = [];
        foreach ($yearBuckets as $year) {
            $safeYear = (string)$year;
            $outerYearColumns[] = 'COALESCE(cm.circulation_' . $safeYear . ', 0) AS circulation_' . $safeYear;
        }

        $currentMatrixSql = "SELECT " . $callNumberClassSql . " AS call_number_class,\n    "
            . implode(",\n    ", $yearColumns) . "\n"
            . "FROM circulation.loan__t cl\n"
            . "JOIN inventory.item__t ii ON cl.item_id = ii.id\n"
            . "JOIN inventory.location__t ilo ON cl.item_effective_location_id_at_check_out = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "WHERE " . implode("\n  AND ", $where) . "\n"
            . "GROUP BY call_number_class";

        $formerCirculationSql = "SELECT " . $callNumberClassSql . " AS call_number_class,\n"
            . "    SUM(CAST(COALESCE(NULLIF(REGEXP_REPLACE(itn.notes__note, '\\D', '', 'g'), ''), '0') AS BIGINT)) AS former_circulation\n"
            . "FROM inventory.item__t ii\n"
            . "JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "LEFT JOIN inventory.item__t__notes itn ON itn.hrid = ii.hrid\n"
            . "  AND itn.notes__item_note_type_id = '" . self::FORMER_CIRCULATION_NOTE_TYPE_ID . "'\n"
            . "WHERE " . implode("\n  AND ", $scopeWhere) . "\n"
            . "GROUP BY call_number_class";

        $sql = "WITH current_matrix AS (\n"
            . $currentMatrixSql . "\n"
            . "),\nformer_circulation AS (\n"
            . $formerCirculationSql . "\n"
            . ")\nSELECT COALESCE(cm.call_number_class, fc.call_number_class) AS call_number_class,\n    "
            . implode(",\n    ", $outerYearColumns) . ",\n"
            . "    COALESCE(fc.former_circulation, 0) AS former_circulation\n"
            . "FROM current_matrix cm\n"
            . "FULL OUTER JOIN former_circulation fc ON cm.call_number_class = fc.call_number_class\n"
            . "ORDER BY call_number_class ASC\n"
            . "LIMIT " . self::DEFAULT_LIMIT;

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function buildPrimaryCallNumberClassSql(string $qualifiedColumn): string
    {
        return "CASE\n"
            . "    WHEN " . $qualifiedColumn . " ~ '^[A-Z]{1,3}[0-9]'\n"
            . "    THEN REGEXP_REPLACE(" . $qualifiedColumn . ", '^([A-Z]{1,3})[0-9].*', '\\1')\n"
            . "    WHEN " . $qualifiedColumn . " ~ '^[0-9]'\n"
            . "    THEN LPAD(\n"
            . "            CAST(\n"
            . "                FLOOR(CAST(REGEXP_REPLACE(" . $qualifiedColumn . ", '^([0-9]+).*', '\\1') AS NUMERIC) / 100) * 100\n"
            . "            AS TEXT),\n"
            . "        3, '0')\n"
            . "    ELSE 'Unknown'\n"
            . "END";
    }

    private static function indexFilters(array $filters): array
    {
        $indexed = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $table = trim((string)($filter['table'] ?? ''));
            $column = trim((string)($filter['column'] ?? ''));
            if ($table === '' || $column === '') {
                continue;
            }

            $indexed[self::filterKey($table, $column)] = $filter;
        }

        return $indexed;
    }

    private static function filterKey(string $table, string $column): string
    {
        return $table . '.' . $column;
    }

    private static function resolveRequestedLimit(array $slots): int
    {
        $rawLimit = $slots['limit'] ?? null;
        if (!is_scalar($rawLimit)) {
            return self::DEFAULT_LIMIT;
        }

        $normalizedLimit = (int)trim((string)$rawLimit);
        if ($normalizedLimit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($normalizedLimit, self::DEFAULT_LIMIT);
    }

    private static function assertDeterministicGraphConnection(array $graph, string $leftTable, string $leftColumn, string $rightTable, string $rightColumn): void
    {
        foreach (($graph['edges'] ?? []) as $edge) {
            if (!is_array($edge) || empty($edge['supportsDeterministicCompilation'])) {
                continue;
            }

            $forwardMatch = ($edge['from'] ?? null) === $leftTable
                && ($edge['localColumn'] ?? null) === $leftColumn
                && ($edge['to'] ?? null) === $rightTable
                && ($edge['targetColumn'] ?? null) === $rightColumn;

            $reverseMatch = ($edge['from'] ?? null) === $rightTable
                && ($edge['localColumn'] ?? null) === $rightColumn
                && ($edge['to'] ?? null) === $leftTable
                && ($edge['targetColumn'] ?? null) === $leftColumn;

            if ($forwardMatch || $reverseMatch) {
                return;
            }
        }

        if (self::isKnownParentSubtableIdentityJoin($leftTable, $leftColumn, $rightTable, $rightColumn)) {
            return;
        }

        throw new \RuntimeException(
            'Canonical query graph does not support deterministic compilation for join: '
            . $leftTable . '.' . $leftColumn . ' <-> ' . $rightTable . '.' . $rightColumn
        );
    }

    private static function isKnownParentSubtableIdentityJoin(string $leftTable, string $leftColumn, string $rightTable, string $rightColumn): bool
    {
        if ($leftColumn !== 'id' || $rightColumn !== 'id') {
            return false;
        }

        $supportedPairs = [
            ['inventory_instances', 'inventory_instance__t__contributors'],
            ['inventory_instances', 'inventory_instance__t__publication'],
        ];

        foreach ($supportedPairs as $pair) {
            $forwardMatch = $leftTable === $pair[0] && $rightTable === $pair[1];
            $reverseMatch = $leftTable === $pair[1] && $rightTable === $pair[0];
            if ($forwardMatch || $reverseMatch) {
                return true;
            }
        }

        return false;
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

    private static function orderedUniqueStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !isset($normalized[$value])) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function familyOutputsNeedContributorJoin(string $familyKey, array $slots): bool
    {
        if (!in_array($familyKey, ['inventory_contributor_campus_item_barcode', 'inventory_library_location_listing'], true)) {
            return false;
        }

        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
        foreach ($requestedOutputs as $outputField) {
            if (in_array($outputField, ['author', 'contributor_name'], true)) {
                return true;
            }
        }

        return $familyKey === 'inventory_contributor_campus_item_barcode';
    }
}