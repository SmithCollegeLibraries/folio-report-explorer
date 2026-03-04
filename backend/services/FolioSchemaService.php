<?php

namespace app\services;

use Yii;

/**
 * FolioSchemaService — loads the scraped JSON schema files and provides
 * table/column lookup, FK relationship queries, and BFS path-finding.
 */
class FolioSchemaService
{
    /** @var array|null Cached schema data */
    private static $schema = null;

    /** @var array|null Cached derived tables data */
    private static $derived = null;

    /** @var array|null Cached LDP1→MetaDB table name mapping */
    private static $metadbMap = null;

    /** @var array|null Discovered LDP1→actual database table mapping */
    private static $discoveredMap = null;

    /** @var array|null Discovered columns from actual database [ldp1Name => ColumnDef[]] */
    private static $discoveredColumns = null;

    /** @var array|null Discovered subtables (schema.parent__t__child) with columns */
    private static $discoveredSubtables = null;

    /** @var array|null Cached domain hints data */
    private static $domainHints = null;

    /**
     * Clear cached domain hints so next call reloads from DB.
     */
    public static function clearDomainHintsCache()
    {
        self::$domainHints = null;
    }

    /** Cache TTL in seconds (24 hours) */
    const CACHE_TTL = 86400;

    /**
     * Schemas to completely exclude from direct MetaDB discovery.
     * These are either internal MetaDB/system schemas, test data, or
     * patron-privacy schemas that must not appear in the table list.
     */
    const EXCLUDED_SCHEMAS = [
        'pg_catalog',
        'information_schema',
        'dbsystem',
        'ldlite_system',
        'configuration',
        'public',   // test tables only (user_update_testing, users__testing)
        'sst',      // no access permissions — consortium internal
        'marctab',  // 1,002 mt000–mt999 tables; handled via training hint instead
        'perms',    // permission tables — not needed for reporting
    ];

    /**
     * Specific MetaDB tables to exclude from direct discovery (patron PII).
     * Note: The LDP1 backward-compat entries for these may still exist in the
     * mapping for Query Builder use, but they are excluded from the AI prompt
     * and from new MetaDB identity entries.
     */
    const EXCLUDED_TABLES = [
        'users.users__t',
        'users.proxyfor__t',
        'users.addresstype__t',
        'feesfines.accounts__t',
        'feesfines.feefineactions__t',
        'feesfines.manualblocks__t',
        'feesfines.comments__t',
        'feesfines.actual_cost_record__t',
        'audit.circulation_logs__t',
    ];

    /**
     * Local supplementary tables stored in MySQL.
     */
    const LOCAL_TABLES = [
        'acrl_statistics' => [
            'remarks' => 'Historical institutional statistics reported to ACRL',
            'columns' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'category', 'type' => 'varchar'],
                ['name' => 'subcategory', 'type' => 'varchar'],
                ['name' => 'year', 'type' => 'int'],
                ['name' => 'value', 'type' => 'decimal'],
                ['name' => 'notes', 'type' => 'varchar'],
                ['name' => 'created_at', 'type' => 'datetime'],
                ['name' => 'updated_at', 'type' => 'datetime'],
            ],
        ],
        'report_expense_allocations' => [
            'remarks' => 'Per-fiscal-year expense class allocations for subject librarians',
            'columns' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'fiscal_year', 'type' => 'int'],
                ['name' => 'expense_class_code', 'type' => 'varchar'],
                ['name' => 'allocation_amount', 'type' => 'decimal'],
                ['name' => 'created_at', 'type' => 'datetime'],
                ['name' => 'updated_at', 'type' => 'datetime'],
            ],
        ],
    ];

    /**
     * Check if a JSON cache file is still valid (exists and within TTL).
     * @param string $path Absolute path to cache file
     * @return array|null Parsed cache data if valid, null if expired/missing
     */
    private static function loadCacheIfValid($path)
    {
        if (!file_exists($path)) {
            return null;
        }
        $cache = json_decode(file_get_contents($path), true);
        if (!$cache || !isset($cache['_discovered_at'])) {
            return null;
        }
        $age = time() - strtotime($cache['_discovered_at']);
        if ($age > self::CACHE_TTL) {
            return null; // expired
        }
        return $cache;
    }

    /**
     * Load and cache the full LDP1 schema from JSON.
     * @return array
     */
    public static function loadSchema()
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $cacheKey = 'folio_schema_data';
        $cache = Yii::$app->cache;
        $data = $cache ? $cache->get($cacheKey) : false;

        if ($data === false) {
            $path = Yii::$app->params['schemaPath'];
            if (!file_exists($path)) {
                throw new \RuntimeException("Schema file not found: $path");
            }
            $json = file_get_contents($path);
            $data = json_decode($json, true);
            if ($data === null) {
                throw new \RuntimeException("Failed to parse schema JSON");
            }
            if ($cache) {
                $cache->set($cacheKey, $data, 3600);
            }
        }

        self::$schema = $data;
        return $data;
    }

    /**
     * Load and cache the derived tables JSON.
     * @return array
     */
    public static function loadDerived()
    {
        if (self::$derived !== null) {
            return self::$derived;
        }

        $path = Yii::$app->params['derivedPath'];
        if (!file_exists($path)) {
            return ['tables' => [], 'schema_mapping' => [], 'ldp1_to_metadb' => []];
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);
        self::$derived = $data ?: [];
        return self::$derived;
    }

    /**
     * Get table list with summary info.
     * @param array|null $filter Optional list of table names to include
     * @return array
     */
    /**
     * Derive the display domain (group) for a table.
     * Uses the MetaDB schema name when available (e.g. "orders" from "orders.po_line__t").
     * Falls back to the LDP1 underscore prefix for legacy table names.
     */
    private static function deriveDomain($ldp1Name, $metadbName)
    {
        if ($metadbName && strpos($metadbName, '.') !== false) {
            return explode('.', $metadbName)[0];
        }
        // Fallback: first segment before underscore
        $parts = explode('_', $ldp1Name);
        return $parts[0] ?? $ldp1Name;
    }

    public static function getTables($filter = null)
    {
        $schema = self::loadSchema();
        $tables = $schema['tables'] ?? [];
        $relationships = $schema['relationships'] ?? [];
        $allCols = self::discoverAllColumns(); // keyed by MetaDB name
        $metadbMap = self::discoverTableMapping();
        $result = [];

        // --- LDP1-schema tables (from folio_schema.json, enriched with real columns) ---
        foreach ($tables as $name => $info) {
            if ($filter !== null && !in_array($name, $filter)) {
                continue;
            }
            $rels = $relationships[$name] ?? ['parents' => [], 'children' => []];
            // Look up column count via MetaDB name (column cache is MetaDB-keyed now)
            $metadbName = $metadbMap[$name] ?? null;
            $colCount = ($metadbName && isset($allCols[$metadbName]))
                ? count($allCols[$metadbName])
                : count($info['columns'] ?? []);
            $result[$name] = [
                'name' => $name,
                'type' => $info['type'] ?? 'TABLE',
                'primary_key' => $info['primary_key'] ?? null,
                'remarks' => $info['remarks'] ?? null,
                'column_count' => $colCount,
                'parent_count' => count($rels['parents']),
                'child_count' => count($rels['children']),
                'domain' => self::deriveDomain($name, $metadbMap[$name] ?? null),
            ];
        }

        // --- MetaDB-direct tables not in the LDP1 schema ---
        // These are new tables (agreements, licenses, new finance/inventory tables)
        // that have no LDP1 counterpart in folio_schema.json.
        $coveredMetadbs = [];
        foreach ($tables as $ldp1 => $info) {
            $metadb = $metadbMap[$ldp1] ?? null;
            if ($metadb) {
                $coveredMetadbs[$metadb] = true;
            }
        }
        foreach ($metadbMap as $key => $metadb) {
            if ($key !== $metadb) {
                continue; // Skip LDP1 alias entries
            }
            if (isset($coveredMetadbs[$metadb]) || isset($result[$metadb])) {
                continue; // Already covered via LDP1
            }
            if ($filter !== null && !in_array($metadb, $filter)) {
                continue;
            }
            $colCount = isset($allCols[$metadb]) ? count($allCols[$metadb]) : 0;
            $metadbRels = $relationships[$metadb] ?? ['parents' => [], 'children' => []];
            $result[$metadb] = [
                'name' => $metadb,
                'type' => 'TABLE',
                'primary_key' => 'id',
                'remarks' => null,
                'column_count' => $colCount,
                'parent_count' => count($metadbRels['parents']),
                'child_count' => count($metadbRels['children']),
                'domain' => explode('.', $metadb)[0],
            ];
        }

        // Append discovered subtables (flattened array/object columns)
        $subtables = self::discoverSubtables();
        // Build reverse lookup: MetaDB name -> result key used by frontend list.
        // Example: orders.po_line__t -> po_lines
        $metadbToResultKey = [];
        foreach ($metadbMap as $key => $metadb) {
            if ($key !== $metadb && isset($result[$key])) {
                $metadbToResultKey[$metadb] = $key;
            }
        }

        foreach ($subtables as $fullName => $info) {
            if ($filter !== null && !in_array($fullName, $filter)) {
                continue;
            }
            if (isset($result[$fullName])) {
                continue; // already present from static schema
            }

            // Parent from discovery is always MetaDB name; translate to the
            // table key returned in this payload when an LDP1 alias exists.
            $rawParent = $info['parent'] ?? null;
            $parentKey = $rawParent;
            if ($rawParent && isset($metadbToResultKey[$rawParent])) {
                $parentKey = $metadbToResultKey[$rawParent];
            }

            $result[$fullName] = [
                'name' => $fullName,
                'type' => 'SUBTABLE',
                'primary_key' => 'id',
                'remarks' => 'Flattened array/object column from ' . ($parentKey ?? 'unknown'),
                'column_count' => count($info['columns'] ?? []),
                'parent_count' => 1,
                'child_count' => 0,
                'parent_table' => $parentKey,
                'domain' => explode('.', $fullName)[0],
            ];
        }

        // Append local supplementary MySQL tables
        foreach (self::LOCAL_TABLES as $name => $info) {
            if ($filter !== null && !in_array($name, $filter)) {
                continue;
            }
            $result[$name] = [
                'name' => $name,
                'type' => 'LOCAL_TABLE',
                'primary_key' => 'id',
                'remarks' => $info['remarks'] ?? null,
                'column_count' => count($info['columns'] ?? []),
                'parent_count' => 0,
                'child_count' => 0,
                'domain' => 'local',
                'source' => 'local',
            ];
        }

        return $result;
    }

    /**
     * Get full detail for a single table.
     * @param string $name
     * @return array|null
     */
    public static function getTable($name)
    {
        $schema = self::loadSchema();
        $name = self::fuzzyMatch($name);
        if ($name === null) {
            return null;
        }

        $table = $schema['tables'][$name] ?? null;

        if ($table !== null) {
            // LDP1-schema table — replace stale LDP1 columns with actual database columns
            $realCols = self::discoverColumnsFor($name);
            if (!empty($realCols)) {
                $table['columns'] = $realCols;
            }
            $rels = $schema['relationships'][$name] ?? ['parents' => [], 'children' => []];
            return [
                'name' => $name,
                'table' => $table,
                'relationships' => $rels,
            ];
        }

        // Check MetaDB-direct tables (new tables not in LDP1 schema)
        // fuzzyMatch returns the MetaDB name directly for these
        $metadbMap = self::discoverTableMapping();
        if (isset($metadbMap[$name]) && $metadbMap[$name] === $name) {
            $realCols = self::discoverColumnsFor($name);
            $table = [
                'type' => 'TABLE',
                'primary_key' => 'id',
                'remarks' => null,
                'columns' => $realCols,
            ];
            return [
                'name' => $name,
                'table' => $table,
                'relationships' => $schema['relationships'][$name] ?? ['parents' => [], 'children' => []],
            ];
        }

        // Local supplementary table metadata
        if (isset(self::LOCAL_TABLES[$name])) {
            $local = self::LOCAL_TABLES[$name];
            return [
                'name' => $name,
                'table' => [
                    'type' => 'LOCAL_TABLE',
                    'primary_key' => 'id',
                    'remarks' => $local['remarks'] ?? null,
                    'columns' => $local['columns'] ?? [],
                ],
                'relationships' => ['parents' => [], 'children' => []],
            ];
        }

        // Check discovered subtables
        $subtables = self::discoverSubtables();
        $subInfo = $subtables[$name] ?? null;
        if ($subInfo === null) {
            return null;
        }

        $parentTable = $subInfo['parent'] ?? 'unknown';
        $table = [
            'type' => 'SUBTABLE',
            'primary_key' => 'id',
            'remarks' => 'Flattened array/object column from ' . $parentTable,
            'columns' => $subInfo['columns'] ?? [],
        ];

        // Synthesize parent FK relationship
        $rels = [
            'parents' => [
                [
                    'parent_table' => $parentTable,
                    'local_column' => 'id',
                    'parent_column' => 'id',
                ],
            ],
            'children' => [],
        ];

        return [
            'name' => $name,
            'table' => $table,
            'relationships' => $rels,
        ];
    }

    /**
     * Get relationships for a table.
     * @param string $name
     * @return array
     */
    public static function getRelationships($name)
    {
        $schema = self::loadSchema();
        $name = self::fuzzyMatch($name) ?: $name;
        return $schema['relationships'][$name] ?? ['parents' => [], 'children' => []];
    }

    /**
     * Fuzzy match a user-supplied table name against the schema.
     * Tries: exact → case-insensitive → suffix → contains.
     * @param string $input
     * @return string|null Matched table name or null
     */
    public static function fuzzyMatch($input)
    {
        $schema = self::loadSchema();
        $tables = array_keys($schema['tables'] ?? []);

        // Include discovered subtable names
        $subtableNames = array_keys(self::discoverSubtables());

        // Include MetaDB-direct names (agreements.sas__t, licenses.license__t, etc.)
        $metadbMap = self::discoverTableMapping();
        $metadbDirectNames = [];
        foreach ($metadbMap as $key => $value) {
            if ($key === $value) {
                $metadbDirectNames[] = $key;
            }
        }

        $localNames = array_keys(self::LOCAL_TABLES);

        $allNames = array_unique(array_merge($tables, $subtableNames, $metadbDirectNames, $localNames));

        // Exact match
        if (in_array($input, $allNames)) {
            return $input;
        }

        // Case-insensitive
        $lower = strtolower($input);
        foreach ($allNames as $t) {
            if (strtolower($t) === $lower) {
                return $t;
            }
        }

        // Suffix match (e.g. "items" → "inventory_items")
        $suffixMatches = [];
        foreach ($allNames as $t) {
            if (substr($t, -(strlen($input) + 1)) === '_' . $input || $t === $input) {
                $suffixMatches[] = $t;
            }
        }
        if (count($suffixMatches) === 1) {
            return $suffixMatches[0];
        }

        // Contains match
        $containsMatches = [];
        foreach ($allNames as $t) {
            if (stripos($t, $input) !== false) {
                $containsMatches[] = $t;
            }
        }
        if (count($containsMatches) === 1) {
            return $containsMatches[0];
        }

        return null;
    }

    /**
     * Build an undirected adjacency list from FK relationships.
     * Each edge: [from_table, from_col, to_table, to_col, fk_name]
     * @return array
     */
    public static function buildAdjacency()
    {
        $schema = self::loadSchema();
        $relationships = $schema['relationships'] ?? [];
        $adj = [];
        $seen = [];

        foreach ($relationships as $tname => $rel) {
            foreach ($rel['parents'] as $p) {
                $key = $tname . '.' . $p['local_column'] . '->' .
                       $p['parent_table'] . '.' . $p['parent_column'];
                $rev = $p['parent_table'] . '.' . $p['parent_column'] . '->' .
                       $tname . '.' . $p['local_column'];

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $seen[$rev] = true;

                    $fk = $p['foreign_key'] ?? '';

                    $adj[$tname][] = [
                        $tname, $p['local_column'],
                        $p['parent_table'], $p['parent_column'], $fk,
                    ];
                    $adj[$p['parent_table']][] = [
                        $p['parent_table'], $p['parent_column'],
                        $tname, $p['local_column'], $fk,
                    ];
                }
            }
        }

        return $adj;
    }

    /**
     * BFS shortest FK path from $start to $end.
     * @param string $start
     * @param string $end
     * @return array|null Array of edges, or null if no path
     */
    public static function findShortestPath($start, $end)
    {
        $start = self::fuzzyMatch($start);
        $end = self::fuzzyMatch($end);
        if ($start === null || $end === null) {
            return null;
        }
        if ($start === $end) {
            return [];
        }

        $adj = self::buildAdjacency();
        $queue = [[$start, []]];
        $visited = [$start => true];

        while (!empty($queue)) {
            list($current, $path) = array_shift($queue);

            foreach ($adj[$current] ?? [] as $edge) {
                $neighbour = $edge[2]; // to_table
                if (isset($visited[$neighbour])) {
                    continue;
                }
                $newPath = array_merge($path, [$edge]);
                if ($neighbour === $end) {
                    return $newPath;
                }
                $visited[$neighbour] = true;
                $queue[] = [$neighbour, $newPath];
            }
        }

        return null;
    }

    /**
     * DFS all FK paths from $start to $end up to $maxDepth hops.
     * @param string $start
     * @param string $end
     * @param int $maxDepth
     * @return array Array of paths (each path is array of edges)
     */
    public static function findAllPaths($start, $end, $maxDepth = 6)
    {
        $start = self::fuzzyMatch($start);
        $end = self::fuzzyMatch($end);
        if ($start === null || $end === null) {
            return [];
        }
        if ($start === $end) {
            return [];
        }

        $adj = self::buildAdjacency();
        $results = [];

        self::dfs($adj, $start, $end, [], [$start => true], $maxDepth, $results);

        // Sort by path length
        usort($results, function ($a, $b) {
            return count($a) - count($b);
        });

        return $results;
    }

    /**
     * DFS recursive helper.
     */
    private static function dfs($adj, $current, $end, $path, $visited, $maxDepth, &$results)
    {
        if (count($path) > $maxDepth) {
            return;
        }
        if ($current === $end && !empty($path)) {
            $results[] = $path;
            return;
        }
        foreach ($adj[$current] ?? [] as $edge) {
            $neighbour = $edge[2];
            if (!isset($visited[$neighbour])) {
                $visited[$neighbour] = true;
                $path[] = $edge;
                self::dfs($adj, $neighbour, $end, $path, $visited, $maxDepth, $results);
                array_pop($path);
                unset($visited[$neighbour]);
            }
        }
    }

    /**
     * Format a path as structured join data for the frontend.
     * @param array $path Array of edges
     * @param string $start Starting table
     * @return array
     */
    public static function formatPath($path, $start)
    {
        if (empty($path)) {
            return [
                'chain' => [$start],
                'hops' => 0,
                'joins' => [],
                'sql_fragment' => '',
            ];
        }

        $chain = [$start];
        $joins = [];
        $sqlParts = [];
        $metadbMap = self::discoverTableMapping();

        foreach ($path as $edge) {
            list($fromTable, $fromCol, $toTable, $toCol, $fk) = $edge;
            $chain[] = $toTable;
            $joins[] = [
                'from_table' => $fromTable,
                'from_column' => $fromCol,
                'to_table' => $toTable,
                'to_column' => $toCol,
                'foreign_key' => $fk,
            ];
            // Use MetaDB names in the SQL fragment
            $toMetadb = $metadbMap[$toTable] ?? $toTable;
            $fromMetadb = $metadbMap[$fromTable] ?? $fromTable;
            $sqlParts[] = "JOIN {$toMetadb}\n  ON {$toMetadb}.{$toCol} = {$fromMetadb}.{$fromCol}";
        }

        return [
            'chain' => $chain,
            'hops' => count($path),
            'joins' => $joins,
            'sql_fragment' => implode("\n", $sqlParts),
        ];
    }

    /**
     * Get the LDP1→MetaDB table name mapping (from JSON file).
     * @return array [ldp1Name => metadbName]
     */
    public static function getMetadbMapping()
    {
        if (self::$metadbMap !== null) {
            return self::$metadbMap;
        }

        $derived = self::loadDerived();
        self::$metadbMap = $derived['ldp1_to_metadb'] ?? [];
        return self::$metadbMap;
    }

    /**
     * Discover the actual table mapping by querying the database.
     * Uses the static ldp1_to_metadb mapping as a guide, but verifies
     * against the actual database and handles LDLite naming differences
     * (e.g. folio_inventory → inventory, holding__t → holdings_record__t).
     *
     * Caches the result to data/table_mapping_cache.json.
     *
     * @return array [ldp1Name => actualSchemaQualifiedName]
     */
    public static function discoverTableMapping()
    {
        if (self::$discoveredMap !== null) {
            return self::$discoveredMap;
        }

        // Try loading from cache file (with TTL)
        $cachePath = Yii::getAlias('@app/data/table_mapping_cache.json');
        $cache = self::loadCacheIfValid($cachePath);
        if ($cache && isset($cache['mapping'])) {
            self::$discoveredMap = $cache['mapping'];
            return self::$discoveredMap;
        }

        // Build dynamically from the database
        try {
            $db = Yii::$app->folioDb;

            // Build SQL exclusion list for schemas from the constant
            $excludedSchemaSql = implode(',', array_map(function ($s) use ($db) {
                return $db->quoteValue($s);
            }, self::EXCLUDED_SCHEMAS));

            // Get all __t base tables, excluding noise/privacy/internal schemas,
            // MetaDB catalog tables (__tcatalog), and legacy hyphenated tables (loc-*)
            $rows = $db->createCommand(
                "SELECT table_schema, table_name FROM information_schema.tables
                 WHERE table_name ~ '__t\$'
                 AND table_name !~ 'catalog\$'
                 AND table_name NOT LIKE 'loc-%'
                 AND table_schema NOT IN ({$excludedSchemaSql})
                 ORDER BY table_schema, table_name"
            )->queryAll();

            // Build lookup: $bySchema[schema] = [table_name => true]
            $bySchema = [];
            $allActual = []; // full_name => true
            foreach ($rows as $r) {
                $bySchema[$r['table_schema']][$r['table_name']] = true;
                $allActual[$r['table_schema'] . '.' . $r['table_name']] = true;
            }

            // For each static mapping entry, resolve against actual database
            $staticMap = self::getMetadbMapping();
            $mapping = [];

            // Known LDP1 → actual overrides for tables that can't be matched heuristically.
            // FIXED mappings are annotated with their previous incorrect value.
            $knownOverrides = [
                'course_copyrightstatuses'           => 'courses.coursereserves_copyrightstates__t',
                'course_courses'                     => 'courses.coursereserves_courses__t',   // was: coursereserves_terms__t
                'course_processingstatuses'          => 'courses.coursereserves_processingstates__t',
                'course_reserves'                    => 'courses.coursereserves_reserves__t',  // was: coursereserves_terms__t
                'feesfines_lost_item_fees_policies'  => 'feesfines.lost_item_fee_policy__t',
                'feesfines_overdue_fines_policies'   => 'feesfines.overdue_fine_policy__t',
                'finance_group_fund_fiscal_years'    => 'finance.group_fund_fy__t',            // was: fiscal_year__t
                'inventory_holdings'                 => 'inventory.holdings_record__t',
                'inventory_modes_of_issuance'        => 'inventory.mode_of_issuance__t',
                'inventory_service_points_users'     => 'inventory.service_point_user__t',    // was: service_point__t
                'notes'                              => 'notes.note_data__t',
                'organization_categories'            => 'organizations.categories__t',
                'po_order_invoice_relns'             => 'orders.order_invoice_relationship__t',
                'user_proxiesfor'                    => 'users.proxyfor__t',
            ];

            foreach ($staticMap as $ldp1 => $metadb) {
                // Check known overrides first (verified against actual db)
                if (isset($knownOverrides[$ldp1]) && isset($allActual[$knownOverrides[$ldp1]])) {
                    $mapping[$ldp1] = $knownOverrides[$ldp1];
                    continue;
                }
                $parts = explode('.', $metadb, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $metadbSchema = $parts[0];
                $metadbTable = $parts[1];

                // Build candidate schemas (in priority order)
                $schemaCandidates = [$metadbSchema];
                if (strpos($metadbSchema, 'folio_') === 0) {
                    $schemaCandidates[] = substr($metadbSchema, 6);
                }
                // Known aliases
                $schemaAliases = [
                    'folio_permissions' => 'perms',
                    'folio_email' => 'circulation',
                ];
                if (isset($schemaAliases[$metadbSchema])) {
                    $schemaCandidates[] = $schemaAliases[$metadbSchema];
                }

                $found = false;
                foreach ($schemaCandidates as $schema) {
                    if (!isset($bySchema[$schema])) {
                        continue;
                    }

                    // 1. Try exact table name
                    if (isset($bySchema[$schema][$metadbTable])) {
                        $mapping[$ldp1] = $schema . '.' . $metadbTable;
                        $found = true;
                        break;
                    }

                    // 2. Try common variations
                    $stem = preg_replace('/__t$/', '', $metadbTable);
                    $variations = [
                        $stem . 's__t',              // pluralize: account → accounts
                        $stem . '__t',               // original
                        rtrim($stem, 's') . '__t',   // depluralize: accounts → account
                    ];

                    foreach ($variations as $var) {
                        if (isset($bySchema[$schema][$var])) {
                            $mapping[$ldp1] = $schema . '.' . $var;
                            $found = true;
                            break 2;
                        }
                    }

                    // 3. Try substring match: find tables that contain the stem
                    $bestMatch = null;
                    $bestScore = 0;
                    if (strlen($stem) > 0) {
                        foreach (array_keys($bySchema[$schema]) as $actualTable) {
                            $actualStem = preg_replace('/__t$/', '', $actualTable);
                            if (strlen($actualStem) === 0) {
                                continue;
                            }
                            // Check if actual table contains our stem or vice versa
                            if ($actualStem === $stem) {
                                $bestMatch = $actualTable;
                                break;
                            }
                            if (strpos($actualStem, $stem) !== false) {
                                $score = strlen($stem) / strlen($actualStem);
                                // Boost if stem appears at the start (e.g. "holding" → "holdings_record")
                                if (strpos($actualStem, $stem) === 0) {
                                    $score += 0.1;
                                }
                                // Prefer record/data tables over type/status lookup tables
                                // when the LDP1 name doesn't suggest a type/status table
                                if (strpos($ldp1, 'type') === false && strpos($ldp1, 'status') === false) {
                                    if (preg_match('/_(type|status|category)s?$/', $actualStem)) {
                                        $score -= 0.2;
                                    }
                                    if (strpos($actualStem, '_record') !== false) {
                                        $score += 0.15;
                                    }
                                }
                                if ($score > $bestScore) {
                                    $bestScore = $score;
                                    $bestMatch = $actualTable;
                                }
                            }
                            if (strpos($stem, $actualStem) !== false && strlen($actualStem) > 2) {
                                $score = strlen($actualStem) / strlen($stem);
                                if ($score > $bestScore) {
                                    $bestScore = $score;
                                    $bestMatch = $actualTable;
                                }
                            }
                        }
                    }

                    if ($bestMatch) {
                        $mapping[$ldp1] = $schema . '.' . $bestMatch;
                        $found = true;
                        break;
                    }
                }
            }

            // --- Part 2: Direct MetaDB identity entries for all remaining tables ---
            // Any table that was discovered in the DB but has no LDP1 mapping gets a
            // MetaDB→MetaDB identity entry so it can be discovered and queried directly.
            // This covers: agreements, licenses, new finance tables, new inventory tables, etc.
            $coveredMetadbs = array_flip(array_values($mapping)); // metadb => true
            foreach ($allActual as $fullName => $_) {
                // Skip tables that are in the privacy exclusion list
                if (in_array($fullName, self::EXCLUDED_TABLES)) {
                    continue;
                }
                // Only add identity entry if not already covered by an LDP1 mapping
                if (!isset($coveredMetadbs[$fullName])) {
                    $mapping[$fullName] = $fullName;
                }
            }

            // Cache to file
            @file_put_contents($cachePath, json_encode([
                '_note' => 'LDP1 backward-compat + direct MetaDB table mapping',
                '_discovered_at' => date('c'),
                '_ldp1_entries' => count($staticMap),
                '_metadb_direct' => count(array_filter($mapping, function ($v, $k) { return $k === $v; }, ARRAY_FILTER_USE_BOTH)),
                '_total' => count($mapping),
                'mapping' => $mapping,
            ], JSON_PRETTY_PRINT));

            self::$discoveredMap = $mapping;
        } catch (\Exception $e) {
            // Fall back to static mapping with folio_ prefix stripping
            Yii::warning('Table discovery failed: ' . $e->getMessage());
            self::$discoveredMap = [];
            foreach (self::getMetadbMapping() as $ldp1 => $metadb) {
                $parts = explode('.', $metadb, 2);
                if (count($parts) === 2 && strpos($parts[0], 'folio_') === 0) {
                    self::$discoveredMap[$ldp1] = substr($parts[0], 6) . '.' . $parts[1];
                } else {
                    self::$discoveredMap[$ldp1] = $metadb;
                }
            }
        }

        return self::$discoveredMap;
    }

    /**
     * Translate an LDP1 table name to its actual database table name.
     * Uses runtime discovery to handle LDLite vs MetaDB naming differences.
     * Returns a SQL-safe name (quoting identifiers with special chars).
     *
     * @param string $ldp1Name The LDP1 table name
     * @return string The actual schema-qualified name, or original if no mapping
     */
    public static function translateToMetadb($ldp1Name)
    {
        $map = self::discoverTableMapping();
        $actual = $map[$ldp1Name] ?? $ldp1Name;

        // Quote identifiers containing special characters (e.g. hyphens)
        if (strpos($actual, '.') !== false) {
            $parts = explode('.', $actual, 2);
            $schema = $parts[0];
            $table = $parts[1];
            // Quote table/schema if they contain non-standard chars
            if (preg_match('/[^a-z0-9_]/', $schema)) {
                $schema = '"' . $schema . '"';
            }
            if (preg_match('/[^a-z0-9_]/', $table)) {
                $table = '"' . $table . '"';
            }
            return $schema . '.' . $table;
        }

        return $actual;
    }

    /**
     * Discover actual columns for ALL mapped tables from the database.
     * Queries information_schema.columns once and groups by LDP1 table name.
     * Caches to data/column_cache.json.
     *
     * @return array [ldp1Name => ColumnDef[]]
     */
    public static function discoverAllColumns()
    {
        if (self::$discoveredColumns !== null) {
            return self::$discoveredColumns;
        }

        // Try loading from cache (with TTL)
        $cachePath = Yii::getAlias('@app/data/column_cache.json');
        $cache = self::loadCacheIfValid($cachePath);
        if ($cache && isset($cache['columns'])) {
            self::$discoveredColumns = $cache['columns'];
            return self::$discoveredColumns;
        }

        // Build the set of schemas to query from the discovered table mapping.
        // We use MetaDB names as keys in the column cache (not LDP1 names),
        // so new tables like agreements.sas__t are discovered automatically.
        $tableMapping = self::discoverTableMapping();
        $schemasUsed = [];
        foreach (array_values($tableMapping) as $actual) {
            $parts = explode('.', $actual, 2);
            if (count($parts) === 2) {
                $schemasUsed[$parts[0]] = true;
            }
        }

        if (empty($schemasUsed)) {
            self::$discoveredColumns = [];
            return self::$discoveredColumns;
        }

        try {
            $db = Yii::$app->folioDb;

            // Query all columns for __t base tables in all relevant schemas.
            // Keys in the result are MetaDB names (schema.table__t) not LDP1 names.
            $schemaList = implode(',', array_map(function ($s) use ($db) {
                return $db->quoteValue($s);
            }, array_keys($schemasUsed)));

            $rows = $db->createCommand(
                "SELECT table_schema, table_name, column_name,
                        data_type, character_maximum_length,
                        is_nullable, column_default, ordinal_position
                 FROM information_schema.columns
                 WHERE table_schema IN ({$schemaList})
                   AND table_name ~ '__t\$'
                   AND table_name !~ 'catalog\$'
                   AND table_name NOT LIKE 'loc-%'
                 ORDER BY table_schema, table_name, ordinal_position"
            )->queryAll();

            $columns = [];
            foreach ($rows as $r) {
                // Key by MetaDB name directly (schema.table__t)
                $fullName = $r['table_schema'] . '.' . $r['table_name'];

                if (!isset($columns[$fullName])) {
                    $columns[$fullName] = [];
                }

                $displayType = self::mapPgType($r['data_type']);

                $columns[$fullName][] = [
                    'name' => $r['column_name'],
                    'type' => $displayType,
                    'size' => $r['character_maximum_length'] ? (int)$r['character_maximum_length'] : null,
                    'nullable' => $r['is_nullable'] === 'YES',
                    'auto_updated' => false,
                    'default' => $r['column_default'],
                    'digits' => 0,
                ];
            }

            // Cache to file
            @file_put_contents($cachePath, json_encode([
                '_note' => 'Auto-discovered columns keyed by MetaDB table name (schema.table__t)',
                '_discovered_at' => date('c'),
                '_tables_with_columns' => count($columns),
                'columns' => $columns,
            ], JSON_PRETTY_PRINT));

            self::$discoveredColumns = $columns;
        } catch (\Exception $e) {
            Yii::warning('Column discovery failed: ' . $e->getMessage());
            self::$discoveredColumns = [];
        }

        return self::$discoveredColumns;
    }

    /**
     * Discover actual columns for a single table.
     * Accepts either a MetaDB name ('agreements.sas__t') or an LDP1 name ('inventory_items').
     *
     * @param string $name MetaDB schema.table__t name or LDP1 name
     * @return array ColumnDef[] or empty
     */
    public static function discoverColumnsFor($name)
    {
        $all = self::discoverAllColumns();
        // Try direct MetaDB key first (e.g. 'agreements.sas__t')
        if (isset($all[$name])) {
            return $all[$name];
        }
        // Translate LDP1 name → MetaDB name, then look up
        $map = self::discoverTableMapping();
        $metadb = $map[$name] ?? null;
        if ($metadb && isset($all[$metadb])) {
            return $all[$metadb];
        }
        return [];
    }

    /**
     * Discover subtables (e.g. invoice.invoice_lines__t__fund_distributions).
     * These are flattened array/object tables created by LDLite from nested JSON.
     * Pattern: schema.parent__t__child — parent FK is parent__t.id.
     *
     * Caches to data/subtable_cache.json with TTL.
     *
     * @return array [schemaQualifiedName => ['columns' => ColumnDef[], 'parent' => parentTable]]
     */
    public static function discoverSubtables()
    {
        if (self::$discoveredSubtables !== null) {
            return self::$discoveredSubtables;
        }

        // Try loading from cache (with TTL)
        $cachePath = Yii::getAlias('@app/data/subtable_cache.json');
        $cache = self::loadCacheIfValid($cachePath);
        if ($cache && isset($cache['subtables'])) {
            self::$discoveredSubtables = $cache['subtables'];
            return self::$discoveredSubtables;
        }

        try {
            $db = Yii::$app->folioDb;

            // Find all subtables: contain __t__ but exclude __tcatalog metadata tables
            $excludedSchemaSql = implode(',', array_map(function ($s) use ($db) {
                return $db->quoteValue($s);
            }, self::EXCLUDED_SCHEMAS));

            $rows = $db->createCommand(
                "SELECT table_schema, table_name
                 FROM information_schema.tables
                 WHERE table_name ~ '__t__'
                   AND table_name !~ 'catalog$'
                   AND table_schema NOT IN ({$excludedSchemaSql})
                 ORDER BY table_schema, table_name"
            )->queryAll();

            if (empty($rows)) {
                self::$discoveredSubtables = [];
                return self::$discoveredSubtables;
            }

            // Build schema set and table list for column query
            $schemasUsed = [];
            $tableList = [];
            foreach ($rows as $r) {
                $schemasUsed[$r['table_schema']] = true;
                $tableList[$r['table_schema'] . '.' . $r['table_name']] = true;
            }

            $schemaList = implode(',', array_map(function ($s) use ($db) {
                return $db->quoteValue($s);
            }, array_keys($schemasUsed)));

            // Query columns for all subtables at once
            $colRows = $db->createCommand(
                "SELECT table_schema, table_name, column_name,
                        data_type, character_maximum_length,
                        is_nullable, ordinal_position
                 FROM information_schema.columns
                 WHERE table_schema IN ({$schemaList})
                   AND table_name ~ '__t__'
                   AND table_name !~ 'catalog$'
                 ORDER BY table_schema, table_name, ordinal_position"
            )->queryAll();

            $subtables = [];
            foreach ($colRows as $r) {
                $fullName = $r['table_schema'] . '.' . $r['table_name'];
                if (!isset($tableList[$fullName])) {
                    continue;
                }

                if (!isset($subtables[$fullName])) {
                    // Infer parent table: schema.parent__t__child → schema.parent__t
                    $parts = explode('__t__', $r['table_name'], 2);
                    $parentTable = $r['table_schema'] . '.' . $parts[0] . '__t';
                    $subtables[$fullName] = [
                        'parent' => $parentTable,
                        'columns' => [],
                    ];
                }

                $subtables[$fullName]['columns'][] = [
                    'name' => $r['column_name'],
                    'type' => self::mapPgType($r['data_type']),
                    'size' => $r['character_maximum_length'] ? (int)$r['character_maximum_length'] : null,
                    'nullable' => $r['is_nullable'] === 'YES',
                ];
            }

            // Cache to file
            @file_put_contents($cachePath, json_encode([
                '_note' => 'Auto-discovered subtables (flattened array columns) from actual database',
                '_discovered_at' => date('c'),
                '_subtable_count' => count($subtables),
                'subtables' => $subtables,
            ], JSON_PRETTY_PRINT));

            self::$discoveredSubtables = $subtables;
        } catch (\Exception $e) {
            Yii::warning('Subtable discovery failed: ' . $e->getMessage());
            self::$discoveredSubtables = [];
        }

        return self::$discoveredSubtables;
    }

    /**
     * Map PostgreSQL data_type values to simpler display types.
     *
     * @param string $pgType
     * @return string
     */
    private static function mapPgType($pgType)
    {
        $map = [
            'character varying' => 'varchar',
            'character' => 'char',
            'text' => 'text',
            'integer' => 'int4',
            'bigint' => 'int8',
            'smallint' => 'int2',
            'boolean' => 'bool',
            'double precision' => 'float8',
            'real' => 'float4',
            'numeric' => 'numeric',
            'date' => 'date',
            'timestamp with time zone' => 'timestamptz',
            'timestamp without time zone' => 'timestamp',
            'uuid' => 'uuid',
            'jsonb' => 'jsonb',
            'json' => 'json',
            'bytea' => 'bytea',
            'ARRAY' => 'array',
        ];
        return $map[$pgType] ?? $pgType;
    }

    /**
     * Clear the discovered mapping cache (e.g. after settings change).
     */
    public static function clearMappingCache()
    {
        self::$discoveredMap = null;
        self::$discoveredColumns = null;
        self::$discoveredSubtables = null;
        $cachePath = Yii::getAlias('@app/data/table_mapping_cache.json');
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
        $colCachePath = Yii::getAlias('@app/data/column_cache.json');
        if (file_exists($colCachePath)) {
            @unlink($colCachePath);
        }
        $subCachePath = Yii::getAlias('@app/data/subtable_cache.json');
        if (file_exists($subCachePath)) {
            @unlink($subCachePath);
        }
    }

    /**
     * Get schema metadata.
     * @return array
     */
    public static function getMetadata()
    {
        $schema = self::loadSchema();
        return $schema['metadata'] ?? [];
    }

    /**
     * Load and cache domain hints from ai_training_hints DB table.
     * Falls back to domain_hints.json if DB table doesn't exist yet.
     * @return array
     */
    public static function loadDomainHints()
    {
        if (self::$domainHints !== null) {
            return self::$domainHints;
        }

        try {
            $db = \Yii::$app->db;
            $rows = $db->createCommand(
                'SELECT type, hint_key, hint_value, example_question, example_sql FROM ai_training_hints WHERE is_active = 1'
            )->queryAll();

            $tableDescriptions = [];
            $vocabulary = [];
            $examples = [];

            foreach ($rows as $row) {
                switch ($row['type']) {
                    case 'table_description':
                        if ($row['hint_key'] && $row['hint_value']) {
                            $tableDescriptions[$row['hint_key']] = $row['hint_value'];
                        }
                        break;
                    case 'vocabulary':
                        if ($row['hint_key'] && $row['hint_value']) {
                            $vocabulary[$row['hint_key']] = $row['hint_value'];
                        }
                        break;
                    case 'example':
                    case 'correction':
                        if ($row['example_question'] && $row['example_sql']) {
                            $examples[] = [
                                'question' => $row['example_question'],
                                'sql' => $row['example_sql'],
                            ];
                        }
                        break;
                }
            }

            self::$domainHints = [
                'tableDescriptions' => $tableDescriptions,
                'vocabulary' => $vocabulary,
                'examples' => $examples,
            ];
        } catch (\Exception $e) {
            // Fall back to JSON file if DB table doesn't exist yet
            $path = __DIR__ . '/../data/domain_hints.json';
            if (!file_exists($path)) {
                self::$domainHints = ['tableDescriptions' => [], 'vocabulary' => [], 'examples' => []];
                return self::$domainHints;
            }

            $data = json_decode(file_get_contents($path), true);
            if (!$data || !is_array($data)) {
                self::$domainHints = ['tableDescriptions' => [], 'vocabulary' => [], 'examples' => []];
                return self::$domainHints;
            }

            self::$domainHints = $data;
        }

        return self::$domainHints;
    }

    /**
     * Get all table names.
     * @return array
     */
    public static function getTableNames()
    {
        $schema = self::loadSchema();
        $base = array_keys($schema['tables'] ?? []);
        $subtables = array_keys(self::discoverSubtables());
        $local = array_keys(self::LOCAL_TABLES);
        return array_values(array_unique(array_merge($base, $subtables, $local)));
    }

    /**
     * Build a compressed schema context string for LLM prompts.
     * Uses MetaDB table names so Gemini generates executable SQL.
     * @return string
     */
    public static function buildSchemaContext()
    {
        $schema = self::loadSchema();
        $tables = $schema['tables'] ?? [];
        $rels = $schema['relationships'] ?? [];
        $metadbMap = self::discoverTableMapping();
        $allCols = self::discoverAllColumns();
        $subtables = self::discoverSubtables();
        $domainHints = self::loadDomainHints();
        $tableDescs = $domainHints['tableDescriptions'] ?? [];
        $vocabulary = $domainHints['vocabulary'] ?? [];
        $examples = $domainHints['examples'] ?? [];

        // Load derived table column comments for enriching key columns
        $derivedData = self::loadDerived();
        $derivedComments = [];
        foreach (($derivedData['tables'] ?? []) as $dtName => $dtInfo) {
            foreach (($dtInfo['column_comments'] ?? []) as $colName => $comment) {
                $derivedComments[$colName] = $comment;
            }
        }

        // Build a reverse map from MetaDB name back to LDP1 name for FK lookups.
        // (FK relationships in folio_schema.json are keyed by LDP1 names.)
        $reverseMetadb = [];
        foreach ($metadbMap as $ldp1 => $metadb) {
            if (!isset($reverseMetadb[$metadb])) {
                $reverseMetadb[$metadb] = $ldp1;
            }
        }

        // Build the ordered, deduplicated list of MetaDB tables to emit.
        // LDP1-schema tables come first (preserving existing order), then
        // MetaDB-direct tables for newly discovered schemas.
        $coveredInLdp1 = [];
        $orderedMetadbs = [];
        foreach ($tables as $ldp1 => $tinfo) {
            $metadb = $metadbMap[$ldp1] ?? null;
            if ($metadb && !isset($coveredInLdp1[$metadb])) {
                $coveredInLdp1[$metadb] = true;
                $orderedMetadbs[] = $metadb;
            }
        }
        foreach ($metadbMap as $key => $metadb) {
            if ($key === $metadb && !isset($coveredInLdp1[$metadb])) {
                $orderedMetadbs[] = $metadb;
            }
        }

        // Deduplicate preserving first occurrence
        $orderedMetadbs = array_values(array_unique($orderedMetadbs));

        $lines = [];

        $totalTables = count($orderedMetadbs) + count($subtables);
        $lines[] = "=== FOLIO MetaDB/LDLite Database Schema ===";
        $lines[] = "Database: PostgreSQL, {$totalTables} tables (including " . count($subtables) . " subtables)";
        $lines[] = "IMPORTANT: Table names are schema-qualified (e.g. inventory.item__t).";
        $lines[] = "Always use the full schema.table name in FROM and JOIN clauses.";
        $lines[] = "SUBTABLES: Tables matching pattern schema.parent__t__child are flattened array/object columns.";
        $lines[] = "  They join to their parent on parent__t.id = parent__t__child.id.";
        $lines[] = "  ALWAYS prefer subtables over JSONB queries — e.g. use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions'.\n";

        foreach ($orderedMetadbs as $metadbName) {
            // Skip privacy-excluded tables from the AI prompt entirely
            if (in_array($metadbName, self::EXCLUDED_TABLES)) {
                continue;
            }
            // Skip perms schema (privacy) from the AI prompt
            $schemaPrefix = explode('.', $metadbName, 2)[0];
            if (in_array($schemaPrefix, ['perms', 'users']) &&
                !in_array($metadbName, ['users.groups__t'])) {
                continue;
            }

            // Columns: from MetaDB-keyed column cache
            $colSource = $allCols[$metadbName] ?? [];
            // Fallback for LDP1 tables: use static column definitions
            if (empty($colSource)) {
                $ldp1 = $reverseMetadb[$metadbName] ?? null;
                if ($ldp1 && isset($tables[$ldp1]['columns'])) {
                    $colSource = $tables[$ldp1]['columns'];
                }
            }

            // Add table description if available
            $desc = $tableDescs[$metadbName] ?? '';
            if ($desc) {
                $lines[] = "TABLE {$metadbName} — {$desc}";
            } else {
                $lines[] = "TABLE {$metadbName}";
            }

            // Column list — annotate key ID/FK columns with derived comments
            $annotatedCols = [];
            foreach ($colSource as $col) {
                $colStr = $col['name'] . ':' . $col['type'];
                $cname = $col['name'];
                $shouldAnnotate = (
                    substr($cname, -3) === '_id' ||
                    substr($cname, -5) === '_date' ||
                    $cname === 'name' ||
                    $cname === 'code' ||
                    $cname === 'status' ||
                    $cname === 'barcode'
                );
                if ($shouldAnnotate && isset($derivedComments[$cname])) {
                    $colStr .= ' -- ' . $derivedComments[$cname];
                }
                $annotatedCols[] = $colStr;
            }
            $lines[] = "  Columns: " . implode(', ', $annotatedCols);

            // FK relationships — look up via reverse map to find LDP1 name in $rels
            $ldp1ForRel = $reverseMetadb[$metadbName] ?? null;
            foreach ($rels[$ldp1ForRel]['parents'] ?? [] as $p) {
                $parentMetadb = $metadbMap[$p['parent_table']] ?? $p['parent_table'];
                $lines[] = "  FK: {$metadbName}.{$p['local_column']} → {$parentMetadb}.{$p['parent_column']}";
            }
        }

        // Append discovered subtables
        if (!empty($subtables)) {
            $lines[] = "\n--- Subtables (flattened array/object columns) ---";
            foreach ($subtables as $fullName => $info) {
                $cols = [];
                foreach ($info['columns'] as $col) {
                    $cols[] = $col['name'] . ':' . $col['type'];
                }
                $lines[] = "SUBTABLE {$fullName} (" . implode(', ', $cols) . ")";
                $lines[] = "  PARENT: {$info['parent']} (JOIN ON {$info['parent']}.id = {$fullName}.id)";
            }
        }

        // Append Five Colleges location naming schema
        $lines[] = "\n--- Five Colleges Location Naming Schema ---";
        $lines[] = "This FOLIO instance is shared by the Five Colleges of Western Massachusetts.";
        $lines[] = "Location hierarchy: loc-institution__t ('Five Colleges') → loc-campus__t (7 campuses) → loc-library__t (33 libraries) → location__t (354 locations).";
        $lines[] = "";
        $lines[] = "Campus abbreviation codes prefix ALL library and location names:";
        $lines[] = "  SC = Smith College";
        $lines[] = "  AC = Amherst College";
        $lines[] = "  MH = Mount Holyoke College";
        $lines[] = "  UM = University Of Massachusetts";
        $lines[] = "  HC = Hampshire College";
        $lines[] = "  RP = Five Colleges Collections";
        $lines[] = "  YB = National Yiddish Book Center";
        $lines[] = "";
        $lines[] = "Naming convention examples:";
        $lines[] = "  'Neilson Library' is stored as 'SC Neilson Library' (Smith College)";
        $lines[] = "  'Frost Library' is stored as 'AC Frost Library' (Amherst College)";
        $lines[] = "  'W.E.B. Du Bois Library' is stored as 'UM W.E.B. Du Bois Library' (UMass)";
        $lines[] = "  'Main Library' at Mount Holyoke is stored as 'MH Main Library'";
        $lines[] = "";
        $lines[] = "When a user mentions a library or location by name WITHOUT a campus prefix:";
        $lines[] = "  1. Use ILIKE with a wildcard: WHERE name ILIKE '%Neilson%'";
        $lines[] = "  2. Or if the campus is known, use the full prefixed name: WHERE name ILIKE 'SC Neilson%'";
        $lines[] = "When a user asks about a specific campus (e.g. 'Smith College items'), filter by campus code prefix";
        $lines[] = "  or join to loccampus__t on the campus name.";

        // Append domain vocabulary
        if (!empty($vocabulary)) {
            $lines[] = "\n--- Domain Vocabulary ---";
            $lines[] = "Use these mappings to resolve ambiguous business terms to the correct tables:";
            foreach ($vocabulary as $term => $mapping) {
                $lines[] = "  \"{$term}\" → {$mapping}";
            }
        }

        // Append few-shot examples
        if (!empty($examples)) {
            $lines[] = "\n--- Example Queries ---";
            $lines[] = "Use these as reference for correct table/column choices:";
            foreach ($examples as $ex) {
                $lines[] = "Q: {$ex['question']}";
                $lines[] = "SQL: {$ex['sql']}";
                $lines[] = "";
            }
        }

        $lines[] = "\n--- Local Supplementary Tables (MySQL) ---";
        $lines[] = "These local tables are in a separate MySQL database and support institutional reporting.";
        $lines[] = "When a user asks about ACRL statistics or local budget allocations, use these tables.";
        $lines[] = "IMPORTANT: Queries that reference ONLY local tables should be executed with data_source=local.";
        $lines[] = "";
        $lines[] = "TABLE acrl_statistics";
        $lines[] = "  Columns: id:int, category:varchar, subcategory:varchar, year:int, value:decimal, notes:varchar";
        $lines[] = "  Notes: Historical annual ACRL data; one row per category/subcategory/year.";
        $lines[] = "";
        $lines[] = "TABLE report_expense_allocations";
        $lines[] = "  Columns: id:int, fiscal_year:int, expense_class_code:varchar, allocation_amount:decimal, created_at:datetime, updated_at:datetime";
        $lines[] = "  Notes: Allocation amounts by fiscal year and expense class code.";

        return implode("\n", $lines);
    }
}
