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
        if (QueryFamilySchemaManifestService::hasManifest($familyKey)) {
            QueryFamilySchemaManifestService::validateFamilyReady($familyKey, $slots, $graph);
        }
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
            return self::buildCollectionAgeSql($queryDef, $normalizedPayload['slots']);
        }

        if ($normalizedPayload['familyKey'] === 'inventory_library_location_listing') {
            if (self::isCampusScopedItemFilterListing($normalizedPayload['slots'])) {
                return self::buildCampusScopedItemFilterListingSql($normalizedPayload['slots']);
            }

            if (!empty($normalizedPayload['slots']['only_holding_location'])) {
                return self::buildInventoryLibraryLocationListingSql($queryDef, $normalizedPayload['slots']);
            }
        }

        return SqlBuilderService::build(self::translateQueryDefinitionForSqlBuilder($queryDef));
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

        if (
            in_array($familyKey, ['inventory_contributor_campus_item_barcode', 'inventory_library_location_listing', 'circulation_top_items'], true)
            && (
                !empty($slots['material_type'])
                || (
                    $familyKey === 'inventory_library_location_listing'
                    && in_array('material_type', (array)($slots['requested_outputs'] ?? []), true)
                )
            )
        ) {
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
                case 'call_number':
                    $columns[] = ['table' => 'inventory_holdings', 'column' => 'call_number', 'alias' => 'call_number'];
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
                case 'material_type':
                    $columns[] = ['table' => 'inventory_material_types', 'column' => 'name', 'alias' => 'material_type'];
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
            if (!empty($slots['library'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter('library', 'inventory_libraries', 'name', $slots['library'], $slots['match_policy']);
            }
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

            if (!empty($slots['material_type'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter(
                    'material_type',
                    'inventory_material_types',
                    'name',
                    $slots['material_type'],
                    $slots['match_policy']
                );
            }

            if (!empty($slots['item_status'])) {
                $filters[] = QueryFamilySlotService::buildSlotFilter(
                    'item_status',
                    'inventory_items',
                    'status__name',
                    $slots['item_status'],
                    $slots['match_policy']
                );
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

        if (
            !empty($slots['material_type'])
            || in_array('material_type', (array)($slots['requested_outputs'] ?? []), true)
        ) {
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

        if (
            !empty($slots['material_type'])
            || in_array('material_type', (array)($slots['requested_outputs'] ?? []), true)
        ) {
            $joins[] = [
                'from_table' => 'inventory_items',
                'from_column' => 'material_type_id',
                'to_table' => 'inventory_material_types',
                'to_column' => 'id',
            ];
        }

        return $joins;
    }

    private static function buildInventoryLibraryLocationListingSql(array $queryDef, array $slots): array
    {
        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : ['title'];
        $materialTypeFilterKey = self::filterKey('inventory_material_types', 'name');
        $needsMaterialTypeJoin = isset($filters[$materialTypeFilterKey])
            || in_array('material_type', $requestedOutputs, true);
        $requiresItemOutput = false;
        $selectColumns = [];

        foreach ($requestedOutputs as $outputField) {
            switch ($outputField) {
                case 'barcode':
                    $requiresItemOutput = true;
                    $selectColumns[] = 'it.barcode AS barcode';
                    break;
                case 'call_number':
                    $selectColumns[] = 'th.call_number AS call_number';
                    break;
                case 'contributor_name':
                    $requiresItemOutput = true;
                    $selectColumns[] = 'itc.contributors__name AS contributor_name';
                    break;
                case 'author':
                    $requiresItemOutput = true;
                    $selectColumns[] = 'itc.contributors__name AS author';
                    break;
                case 'instance_hrid':
                    $selectColumns[] = 'ii.hrid';
                    break;
                case 'instance_number':
                    $selectColumns[] = 'ii.hrid AS instance_number';
                    break;
                case 'item_id':
                    $requiresItemOutput = true;
                    $selectColumns[] = 'it.id AS item_id';
                    break;
                case 'material_type':
                    $requiresItemOutput = true;
                    $selectColumns[] = 'imt.name AS material_type';
                    break;
                case 'publication_date':
                    $selectColumns[] = 'ii.dates__date1 AS publication_date';
                    break;
                case 'pub_date':
                    $selectColumns[] = 'ii.dates__date1 AS pub_date';
                    break;
                case 'title':
                    $selectColumns[] = 'ii.title AS title';
                    break;
            }
        }

        if ($selectColumns === []) {
            throw new 
                InvalidArgumentException('unsupported_inventory_listing_output: Inventory location listing requires at least one supported output.');
        }

        $scopeWhere = [];
        $outerWhere = [];
        $params = [];
        $parameterIndex = 0;

        foreach ([
            ['inventory_locations', 'name', 'tl.name'],
            ['inventory_locations', 'code', 'tl.code'],
        ] as $filterSpec) {
            $key = self::filterKey($filterSpec[0], $filterSpec[1]);
            if (!isset($filters[$key])) {
                continue;
            }

            $filter = $filters[$key];
            self::appendFilterPredicate($filter['op'] ?? '=', $filterSpec[2], $filter['value'] ?? null, $scopeWhere, $params, $parameterIndex);
        }

        foreach ([
            ['inventory_libraries', 'name', 'il.name'],
            ['inventory_campuses', 'name', 'ic.name'],
            ['inventory_material_types', 'name', 'imt.name'],
        ] as $filterSpec) {
            $key = self::filterKey($filterSpec[0], $filterSpec[1]);
            if (!isset($filters[$key])) {
                continue;
            }

            $filter = $filters[$key];
            self::appendFilterPredicate($filter['op'] ?? '=', $filterSpec[2], $filter['value'] ?? null, $outerWhere, $params, $parameterIndex);
        }

        if ($scopeWhere === []) {
            throw new 
                InvalidArgumentException('missing_only_holding_scope: only_holding_location requires an explicit location scope.');
        }

        $targetLocationsWhere = implode("\n        AND ", $scopeWhere);
        $outerWhereSql = implode("\n  AND ", $outerWhere);
        if ($outerWhereSql !== '') {
            $outerWhereSql = 'WHERE ' . $outerWhereSql;
        }

        $ctes = [];
        $ctes[] = "target_locations AS (\n"
            . "    SELECT DISTINCT id, name\n"
            . "    FROM inventory.location__t tl\n"
            . "    WHERE {$targetLocationsWhere}\n"
            . ")";

        $ctes[] = "target_holdings AS (\n"
            . "    SELECT DISTINCT ih.instance_id, ih.id AS holdings_record_id, ih.call_number, ih.effective_location_id\n"
            . "    FROM inventory.holdings_record__t ih\n"
            . "    JOIN target_locations tl ON tl.id = ih.effective_location_id\n"
            . ")";

        $joinCatalog = [
            'location' => 'JOIN inventory.instance__t ii ON ii.id = th.instance_id',
            'library' => 'JOIN inventory.location__t tl ON tl.id = th.effective_location_id',
            'campus' => 'JOIN inventory.loclibrary__t il ON tl.library_id = il.id',
            'campus2' => 'JOIN inventory.loccampus__t ic ON il.campus_id = ic.id',
        ];

        $selectSql = implode(",\n    ", $selectColumns);
        $sql = "WITH " . implode(",\n", $ctes) . "\n"
            . "SELECT DISTINCT\n"
            . "    {$selectSql}\n"
            . "FROM target_holdings th\n"
            . "JOIN inventory.instance__t ii ON ii.id = th.instance_id\n"
            . "JOIN inventory.location__t tl ON tl.id = th.effective_location_id\n"
            . "JOIN inventory.loclibrary__t il ON tl.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . (($requiresItemOutput || $needsMaterialTypeJoin) ? "JOIN inventory.item__t it ON it.holdings_record_id = th.holdings_record_id\n" : '')
            . ($needsMaterialTypeJoin ? "JOIN inventory.material_type__t imt ON imt.id = it.material_type_id\n" : '')
            . self::inventoryListingContributorJoin($requestedOutputs)
            . "WHERE NOT EXISTS (\n"
            . "    SELECT 1\n"
            . "    FROM inventory.holdings_record__t other_hr\n"
            . "    WHERE other_hr.instance_id = th.instance_id\n"
            . "      AND other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)\n"
            . ")\n"
            . (trim($outerWhereSql) !== '' ? '  AND ' . str_replace('WHERE ', '', $outerWhereSql) . "\n" : '')
            . "LIMIT " . self::resolveRequestedLimit($slots);

        if (!$requiresItemOutput) {
            $sql = str_replace("\n            ", "\n", $sql);
        }

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function isCampusScopedItemFilterListing(array $slots): bool
    {
        if (trim((string)($slots['campus'] ?? '')) === '') {
            return false;
        }

        if (
            trim((string)($slots['library'] ?? '')) !== ''
            || trim((string)($slots['location'] ?? '')) !== ''
            || trim((string)($slots['location_code'] ?? '')) !== ''
            || !empty($slots['only_holding_location'])
        ) {
            return false;
        }

        return self::slotHasValue($slots['material_type'] ?? null)
            || trim((string)($slots['item_status'] ?? '')) !== '';
    }

    private static function buildCampusScopedItemFilterListingSql(array $slots): array
    {
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null)
            ? $slots['requested_outputs']
            : ['title', 'barcode', 'instance_number'];
        $requestedOutputs = self::orderCampusScopedItemFilterOutputs($requestedOutputs);
        $selectColumns = [];

        foreach ($requestedOutputs as $outputField) {
            switch ($outputField) {
                case 'barcode':
                    $selectColumns[] = 'ii.barcode';
                    break;
                case 'instance_hrid':
                case 'instance_number':
                    $selectColumns[] = 'inst.hrid AS instance_hrid';
                    break;
                case 'material_type':
                    $selectColumns[] = 'imt.name AS material_type_name';
                    break;
                case 'title':
                    $selectColumns[] = 'inst.title AS instance_title';
                    break;
            }
        }

        if ($selectColumns === []) {
            throw new \InvalidArgumentException('unsupported_inventory_item_filter_output: Campus-scoped item filter listings require at least one supported output.');
        }

        $params = [
            ':p0' => self::campusCodeForName((string)$slots['campus']),
        ];
        $where = ['camp.code = :p0'];
        $parameterIndex = 1;

        $materialType = $slots['material_type'] ?? null;
        $requiresMaterialTypeJoin = is_array($materialType)
            || in_array('material_type', $requestedOutputs, true);
        if (self::slotHasValue($materialType)) {
            if (is_array($materialType)) {
                $placeholders = [];
                foreach ($materialType as $value) {
                    $placeholder = ':p' . $parameterIndex++;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = strtolower(trim((string)$value));
                }
                $where[] = 'LOWER(imt.name) IN (' . implode(', ', $placeholders) . ')';
            } else {
                $placeholder = ':p' . $parameterIndex++;
                $params[$placeholder] = strtolower(trim((string)$materialType));
                $where[] = $requiresMaterialTypeJoin
                    ? "LOWER(imt.name) = {$placeholder}"
                    : "ii.material_type_id = (\n"
                        . "        SELECT id FROM inventory.material_type__t WHERE LOWER(name) = {$placeholder} LIMIT 1\n"
                        . "      )";
            }
        }

        $itemStatus = trim((string)($slots['item_status'] ?? ''));
        if ($itemStatus !== '') {
            $placeholder = ':p' . $parameterIndex++;
            // Canonicalize hyphen/underscore/case variants ("Checked-Out") to the
            // stored spaced form ("checked out") so LOWER(status__name) matches.
            $params[$placeholder] = self::normalizeItemStatusValue($itemStatus);
            $where[] = "LOWER(ii.status__name) = {$placeholder}";
        }

        $sql = "WITH filtered_items AS MATERIALIZED (\n"
            . "    SELECT\n"
            . "      " . implode(",\n      ", $selectColumns) . "\n"
            . "    FROM inventory.item__t ii\n"
            . "    JOIN inventory.holdings_record__t hr ON ii.holdings_record_id = hr.id\n"
            . "    JOIN inventory.instance__t inst ON hr.instance_id = inst.id\n"
            . "    JOIN inventory.location__t loc ON ii.effective_location_id = loc.id\n"
            . "    JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id\n"
            . "    JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id\n"
            . ($requiresMaterialTypeJoin ? "    JOIN inventory.material_type__t imt ON ii.material_type_id = imt.id\n" : '')
            . "    WHERE " . implode("\n      AND ", $where) . "\n"
            . "  )\n"
            . "  SELECT " . implode(', ', self::campusScopedItemFilterOutputAliases($requestedOutputs)) . "\n"
            . "  FROM filtered_items\n"
            . "  LIMIT " . self::resolveRequestedLimit($slots);

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private static function campusScopedItemFilterOutputAliases(array $requestedOutputs): array
    {
        $aliases = [];
        foreach ($requestedOutputs as $outputField) {
            switch ($outputField) {
                case 'barcode':
                    $aliases[] = 'barcode';
                    break;
                case 'instance_hrid':
                case 'instance_number':
                    $aliases[] = 'instance_hrid';
                    break;
                case 'material_type':
                    $aliases[] = 'material_type_name';
                    break;
                case 'title':
                    $aliases[] = 'instance_title';
                    break;
            }
        }

        return $aliases === [] ? ['instance_title'] : $aliases;
    }

    private static function orderCampusScopedItemFilterOutputs(array $requestedOutputs): array
    {
        $requested = array_fill_keys(array_map('strval', $requestedOutputs), true);
        $ordered = [];

        foreach (['title', 'material_type', 'barcode', 'instance_number', 'instance_hrid'] as $outputField) {
            if (isset($requested[$outputField])) {
                $ordered[] = $outputField;
            }
        }

        return $ordered === [] ? $requestedOutputs : $ordered;
    }

    private static function slotHasValue($value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return is_scalar($value) && trim((string)$value) !== '';
    }

    /**
     * Canonicalize a free-text item-status value to the lowercased, single-spaced
     * form FOLIO stores ("Checked out"), so hyphen/case variants compare equal.
     */
    private static function normalizeItemStatusValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string)preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim((string)preg_replace('/\s+/', ' ', $normalized));
    }

    private static function campusCodeForName(string $campus): string
    {
        $normalized = strtolower(trim($campus));
        $codes = [
            'smith college' => 'SC',
            'amherst college' => 'AC',
            'hampshire college' => 'HC',
            'mount holyoke college' => 'MH',
            'mt holyoke college' => 'MH',
            'university of massachusetts' => 'UM',
            'university of massachusetts amherst' => 'UM',
            'umass' => 'UM',
            'umass amherst' => 'UM',
            'yiddish book center' => 'YB',
            'five colleges' => 'RP',
            'five college' => 'RP',
        ];

        if (!isset($codes[$normalized])) {
            // Never guess a 2-char code: a phantom camp.code matches no row and
            // silently returns zero results. Surface it so the caller can route
            // to recovery/clarification instead.
            throw new \InvalidArgumentException(
                'unsupported_campus_scope: Unrecognized campus "' . $campus . '" cannot be mapped to a Five Colleges acquisitions code.'
            );
        }

        return $codes[$normalized];
    }

    private static function inventoryListingContributorJoin(array $requestedOutputs): string
    {
        if (!array_intersect($requestedOutputs, ['author', 'contributor_name'])) {
            return '';
        }

        return "LEFT JOIN inventory.instance__t__contributors itc ON itc.id = ii.id\n";
    }

    private static function appendFilterPredicate(string $operator, string $column, $value, array &$where, array &$params, int &$parameterIndex): void
    {
        $op = strtoupper(trim($operator));
        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            $where[] = $column . ' ' . $op;
            return;
        }

        if ($op === 'IN' || $op === 'NOT IN') {
            $values = is_array($value) ? $value : explode(',', (string)$value);
            if ($values === []) {
                return;
            }

            $placeholders = [];
            foreach ($values as $rawValue) {
                $placeholder = ':p' . $parameterIndex++;
                $placeholders[] = $placeholder;
                $params[$placeholder] = trim((string)$rawValue);
            }
            $where[] = $column . ' ' . $op . ' (' . implode(', ', $placeholders) . ')';
            return;
        }

        if ($value === null) {
            return;
        }

        $placeholder = ':p' . $parameterIndex++;
        $where[] = $column . ' ' . $op . ' ' . $placeholder;
        $params[$placeholder] = is_scalar($value) ? $value : (string)$value;
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

    private static function buildCollectionAgeSql(array $queryDef, array $slots): array
    {
        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $params = [];
        $scopeWhere = [];
        $parameterIndex = 0;
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : ['average_age_years'];

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

        $publicationDateIsValid = "iip.publication__date_of_publication IS NOT NULL AND iip.publication__date_of_publication ~ '^\\d{4}'";
        $publicationAgeYears = 'EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER)';
        $selectExpressions = [];
        if (in_array('item_count', $requestedOutputs, true)) {
            $selectExpressions[] = 'SUM(scoped_instances.item_count) AS item_count';
        }

        if (in_array('average_age_years', $requestedOutputs, true)) {
            if (in_array('item_count', $requestedOutputs, true)) {
                $selectExpressions[] = "SUM(CASE WHEN {$publicationDateIsValid} THEN scoped_instances.item_count * ({$publicationAgeYears}) ELSE 0 END) / NULLIF(SUM(CASE WHEN {$publicationDateIsValid} THEN scoped_instances.item_count ELSE 0 END), 0) AS average_age_years";
            } else {
                $selectExpressions[] = "SUM(scoped_instances.item_count * ({$publicationAgeYears})) / NULLIF(SUM(scoped_instances.item_count), 0) AS average_age_years";
            }
        }

        if ($selectExpressions === []) {
            throw new \InvalidArgumentException('unsupported_collection_age_output: Collection-age SQL requires at least one supported output.');
        }

        $sql = "WITH scoped_instances AS (\n"
            . "    SELECT iin.id AS instance_id,\n"
            . "           COUNT(*) AS item_count\n"
            . "    FROM inventory.item__t ii\n"
            . "    JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id\n"
            . "    JOIN inventory.instance__t iin ON ih.instance_id = iin.id\n"
            . "    JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id\n"
            . "    JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "    JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "    WHERE " . implode("\n      AND ", $scopeWhere) . "\n"
            . "    GROUP BY ih.instance_id, iin.id\n"
            . ")\n"
            . "SELECT " . implode(",\n       ", $selectExpressions) . "\n"
            . "FROM scoped_instances\n"
            . "LEFT JOIN inventory.instance__t__publication iip ON iip.id = scoped_instances.instance_id";

        if (!in_array('item_count', $requestedOutputs, true) && in_array('average_age_years', $requestedOutputs, true)) {
            $sql .= "\nWHERE iip.publication__date_of_publication IS NOT NULL\n  AND iip.publication__date_of_publication ~ '^\\d{4}'";
        }

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
        $scopeWhere = [];
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
            return self::buildDetailedCirculationTopItemsSql($queryDef, $slots);
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

    private static function buildDetailedCirculationTopItemsSql(array $queryDef, array $slots): array
    {
        $requiredOutputs = [
            'call_number',
            'checkout_count',
            'most_recent_checkout_date',
            'publication_year',
            'title',
        ];
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null)
            ? array_values($slots['requested_outputs'])
            : [];
        sort($requestedOutputs, SORT_STRING);
        if ($requestedOutputs !== $requiredOutputs) {
            throw new \InvalidArgumentException('unsupported_top_items_output: Detailed top-items compilation requires title, call number, publication year, checkout count, and most recent checkout date.');
        }

        $lookbackYears = trim((string)($slots['lookback_years'] ?? ''));
        if ($lookbackYears === '' || preg_match('/^[1-9]\d?$/', $lookbackYears) !== 1) {
            throw new \InvalidArgumentException('invalid_top_items_lookback: Detailed top-items compilation requires a lookback_years value from 1 through 99.');
        }

        $filters = self::indexFilters($queryDef['filters'] ?? []);
        $params = [];
        $where = [];
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
            $where[] = $filterSpec[2] . ' ILIKE ' . $placeholder;
            $params[$placeholder] = (string)$filters[$key]['value'];
            $parameterIndex++;
        }

        if ($where === []) {
            throw new \InvalidArgumentException('missing_top_items_scope: Top-items compilation requires at least library scope filters.');
        }

        $where[] = "al.loan__action IN ('checkedout', 'checkedOutThroughOverride')";
        $where[] = "al.created_date >= CURRENT_DATE - INTERVAL '" . $lookbackYears . " years'";
        $callNumber = 'COALESCE(ii.effective_call_number_components__call_number, ih.call_number)';
        $limit = self::resolveRequestedLimit($slots);

        $sql = "SELECT\n"
            . "    inst.title AS title,\n"
            . "    " . $callNumber . " AS call_number,\n"
            . "    inst.dates__date1 AS publication_year,\n"
            . "    COUNT(*) AS checkout_count,\n"
            . "    MAX(al.created_date) AS most_recent_checkout_date\n"
            . "FROM circulation.audit_loan__t al\n"
            . "JOIN inventory.item__t ii ON al.loan__item_id = ii.id\n"
            . "JOIN inventory.holdings_record__t ih ON ii.holdings_record_id = ih.id\n"
            . "JOIN inventory.instance__t inst ON ih.instance_id = inst.id\n"
            . "JOIN inventory.material_type__t imt ON ii.material_type_id = imt.id\n"
            . "JOIN inventory.location__t ilo ON ii.effective_location_id = ilo.id\n"
            . "JOIN inventory.loclibrary__t il ON ilo.library_id = il.id\n"
            . "JOIN inventory.loccampus__t ic ON il.campus_id = ic.id\n"
            . "WHERE " . implode("\n  AND ", $where) . "\n"
            . "GROUP BY inst.id, inst.title, " . $callNumber . ", inst.dates__date1\n"
            . "ORDER BY checkout_count DESC, most_recent_checkout_date DESC, title ASC, call_number ASC, inst.id ASC\n"
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

    private static function translateQueryDefinitionForSqlBuilder(array $queryDef): array
    {
        $graph = CanonicalQueryGraphService::loadArtifact();
        $tableMap = is_array($graph['contractKeyToSqlTable'] ?? null) ? $graph['contractKeyToSqlTable'] : [];

        $queryDef['tables'] = array_map(
            static function ($tableName) use ($tableMap) {
                return self::resolveSqlBuilderTableName((string)$tableName, $tableMap);
            },
            $queryDef['tables'] ?? []
        );

        foreach (['columns', 'filters', 'groupBy', 'having', 'orderBy'] as $key) {
            if (!is_array($queryDef[$key] ?? null)) {
                continue;
            }

            foreach ($queryDef[$key] as $index => $entry) {
                if (!is_array($entry) || !isset($entry['table'])) {
                    continue;
                }

                $queryDef[$key][$index]['table'] = self::resolveSqlBuilderTableName((string)$entry['table'], $tableMap);
            }
        }

        if (is_array($queryDef['joins'] ?? null)) {
            foreach ($queryDef['joins'] as $index => $join) {
                if (!is_array($join)) {
                    continue;
                }

                if (isset($join['from_table'])) {
                    $queryDef['joins'][$index]['from_table'] = self::resolveSqlBuilderTableName((string)$join['from_table'], $tableMap);
                }

                if (isset($join['to_table'])) {
                    $queryDef['joins'][$index]['to_table'] = self::resolveSqlBuilderTableName((string)$join['to_table'], $tableMap);
                }
            }
        }

        return $queryDef;
    }

    private static function resolveSqlBuilderTableName(string $tableName, array $tableMap): string
    {
        $normalized = trim($tableName);
        if ($normalized === '') {
            return $tableName;
        }

        if (FolioSchemaService::fuzzyMatch($normalized) !== null) {
            return $normalized;
        }

        return $tableMap[$normalized] ?? $normalized;
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
