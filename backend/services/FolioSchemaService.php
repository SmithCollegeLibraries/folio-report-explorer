<?php

namespace app\services;

use Yii;

require_once __DIR__ . '/ClarificationService.php';
require_once __DIR__ . '/ReferenceJsonBundleService.php';

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

    /** Max prompt terms used for relevance scoring */
    const MAX_PROMPT_TERMS = 12;

    /** Context caps for prompt payload stability */
    const MAX_TABLE_DESCRIPTION_HINTS = 140;
    const MAX_VOCABULARY_HINTS = 180;
    const MAX_EXAMPLES = 20;

    const LOCAL_LOCATION_ALIASES = [
        [
            'alias' => 'MRBC',
            'meaning' => 'SC Rare Book Collection',
            'terms' => ['mrbc', 'mortimer', 'mortimer rare book collection'],
            'table' => 'inventory.location__t',
            'filter_hint' => "loc.name ILIKE 'SC Rare Book Collection' or loc.code = 'MRBC' if that code is present",
            'campus_code' => 'SC',
            'campus_name' => 'Smith College',
        ],
    ];

    const CLASSIFICATION_TYPE_NAMES = [
        'UDC',
        'LC',
        'LC (local)',
        'NLM',
        'SUDOC',
        'National Agriculture Library',
        'GDC',
        'Canadian Classification',
        'Additional Dewey',
        'Dewey',
    ];

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
     * Return the read-only inputs used to build the canonical Query Builder schema.
     */
    public static function getBuilderSchemaInputs(): array
    {
        $schema = self::loadSchema();
        return [
            'legacy_relationships' => $schema['relationships'] ?? [],
            'mapping' => self::discoverTableMapping(),
            'columns_by_physical_table' => self::discoverAllColumns(),
        ];
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
                'sql_name' => $metadbName ?: $name,
                'alias_name' => $metadbName && $metadbName !== $name ? $name : null,
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
                'sql_name' => $metadb,
                'alias_name' => null,
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
                'sql_name' => $fullName,
                'alias_name' => null,
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
                'sql_name' => $name,
                'alias_name' => null,
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
            $metadbMap = self::discoverTableMapping();
            $sqlName = $metadbMap[$name] ?? $name;
            return [
                'name' => $name,
                'sql_name' => $sqlName,
                'alias_name' => $sqlName !== $name ? $name : null,
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
                'sql_name' => $name,
                'alias_name' => null,
                'table' => $table,
                'relationships' => $schema['relationships'][$name] ?? ['parents' => [], 'children' => []],
            ];
        }

        // Local supplementary table metadata
        if (isset(self::LOCAL_TABLES[$name])) {
            $local = self::LOCAL_TABLES[$name];
            return [
                'name' => $name,
                'sql_name' => $name,
                'alias_name' => null,
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
            'sql_name' => $name,
            'alias_name' => null,
            'table' => $table,
            'relationships' => $rels,
        ];
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

        if (!isset(Yii::$app->folioDb)) {
            self::$discoveredMap = [];
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
     * Discover the live column type for a table/column pair.
     *
     * @param string $tableName MetaDB schema.table__t name or LDP1 name
     * @param string $columnName Column name
     * @return string|null
     */
    public static function discoverColumnType($tableName, $columnName)
    {
        foreach (self::discoverColumnsFor($tableName) as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return (string)($column['type'] ?? '');
            }
        }

        return null;
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

        if (!isset(Yii::$app->folioDb)) {
            self::$discoveredSubtables = [];
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
     * Clear the data patterns cache so the next request regenerates it.
     * Call after running: php yii data-patterns/generate
     */
    public static function clearDataPatternsCache(): void
    {
        $path = Yii::getAlias('@app/data/data_patterns.json');
        if (file_exists($path)) {
            @unlink($path);
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
                'SELECT id, type, hint_key, hint_value, example_question, example_sql
                 FROM ai_training_hints
                 WHERE is_active = 1
                 ORDER BY type ASC, COALESCE(hint_key, \'\') ASC, id DESC'
            )->queryAll();

            $tableDescriptions = [];
            $vocabulary = [];
            $examples = [];

            foreach ($rows as $row) {
                switch ($row['type']) {
                    case 'table_description':
                        if ($row['hint_key'] && $row['hint_value']) {
                            // Query ordering is deterministic; keep first match so latest
                            // active hint for a key wins.
                            if (!isset($tableDescriptions[$row['hint_key']])) {
                                $tableDescriptions[$row['hint_key']] = $row['hint_value'];
                            }
                        }
                        break;
                    case 'vocabulary':
                        if ($row['hint_key'] && $row['hint_value']) {
                            if (!isset($vocabulary[$row['hint_key']])) {
                                $vocabulary[$row['hint_key']] = $row['hint_value'];
                            }
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
     * Extract deterministic relevance terms from a user prompt.
     *
     * @param string $prompt
     * @return array
     */
    private static function extractPromptTerms($prompt): array
    {
        $normalized = strtolower((string)$prompt);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^a-z0-9_]+/', $normalized);
        $stopwords = [
            'the', 'and', 'for', 'with', 'from', 'that', 'this', 'show', 'list',
            'count', 'what', 'which', 'where', 'when', 'have', 'has', 'into',
            'also', 'only', 'your', 'our', 'are', 'was', 'were', 'how', 'many',
            'get', 'give', 'use', 'using', 'about', 'over', 'under', 'than',
        ];
        $stop = array_flip($stopwords);

        $terms = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3) {
                continue;
            }
            if (isset($stop[$part])) {
                continue;
            }
            $terms[$part] = true;
        }

        $result = array_keys($terms);
        sort($result, SORT_STRING);
        return array_slice($result, 0, self::MAX_PROMPT_TERMS);
    }

    /**
     * Score relevance of a key/value hint pair against prompt terms.
     */
    private static function scoreHint($key, $value, array $terms): int
    {
        if (empty($terms)) {
            return 0;
        }

        $keyText = strtolower((string)$key);
        $valueText = strtolower((string)$value);
        $score = 0;

        foreach ($terms as $term) {
            if (strpos($keyText, $term) !== false) {
                $score += 12;
            }
            if (strpos($valueText, $term) !== false) {
                $score += 4;
            }
        }

        return $score;
    }

    /**
     * Select bounded, deterministic map hints by relevance.
     *
     * @param array $map
     * @param array $terms
     * @param int $limit
     * @return array
     */
    private static function selectRelevantMapHints(array $map, array $terms, int $limit): array
    {
        if (empty($map) || $limit <= 0) {
            return [];
        }

        $items = [];
        foreach ($map as $key => $value) {
            $items[] = [
                'key' => (string)$key,
                'value' => (string)$value,
                'score' => self::scoreHint($key, $value, $terms),
            ];
        }

        usort($items, function ($a, $b) use ($terms) {
            if (!empty($terms) && $a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['key'], $b['key']);
        });

        $selected = array_slice($items, 0, $limit);
        $result = [];
        foreach ($selected as $item) {
            $result[$item['key']] = $item['value'];
        }

        return $result;
    }

    /**
     * Select bounded, deterministic examples by relevance.
     *
     * @param array $examples
     * @param array $terms
     * @param int $limit
     * @return array
     */
    private static function selectRelevantExamples(array $examples, array $terms, int $limit): array
    {
        if (empty($examples) || $limit <= 0) {
            return [];
        }

        $scored = [];
        foreach ($examples as $ex) {
            $question = (string)($ex['question'] ?? '');
            $sql = (string)($ex['sql'] ?? '');
            if ($question === '' || $sql === '') {
                continue;
            }

            $score = 0;
            if (!empty($terms)) {
                $q = strtolower($question);
                $s = strtolower($sql);
                foreach ($terms as $term) {
                    if (strpos($q, $term) !== false) {
                        $score += 10;
                    }
                    if (strpos($s, $term) !== false) {
                        $score += 3;
                    }
                }
            }

            $scored[] = [
                'question' => $question,
                'sql' => $sql,
                'score' => $score,
            ];
        }

        usort($scored, function ($a, $b) use ($terms) {
            if (!empty($terms) && $a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $questionCmp = strcmp($a['question'], $b['question']);
            if ($questionCmp !== 0) {
                return $questionCmp;
            }
            return strcmp($a['sql'], $b['sql']);
        });

        $selected = array_slice($scored, 0, $limit);
        return array_map(function ($item) {
            return [
                'question' => $item['question'],
                'sql' => $item['sql'],
            ];
        }, $selected);
    }

    /**
     * Remove prompt guidance that references schemas/tables the SQL safety layer blocks.
     */
    private static function containsBlockedPromptReference(string $text): bool
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        foreach (self::EXCLUDED_TABLES as $blockedTable) {
            if (strpos($normalized, strtolower($blockedTable)) !== false) {
                return true;
            }
        }

        foreach (self::EXCLUDED_SCHEMAS as $blockedSchema) {
            $blockedSchema = strtolower($blockedSchema);
            if ($blockedSchema === 'marctab' && preg_match('/\bmarctab\.mt[0-9]{3}\b/i', $normalized) === 1) {
                continue;
            }
            if (strpos($normalized, $blockedSchema . '.') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip blocked-schema guidance while preserving adjacent supported guidance.
     */
    private static function isJsonLikeColumnType(?string $columnType): bool
    {
        $normalized = strtolower(trim((string)$columnType));
        return $normalized === 'jsonb' || $normalized === 'json';
    }

    /**
     * Normalize parsed_record__content guidance to match the live column type.
     */
    private static function normalizeParsedRecordPromptText(string $text, ?string $parsedRecordContentType): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $recordsHasStateColumn = self::discoverColumnType('folio_source_record.records__t', 'state') !== null;
        $recordsHasDeletedColumn = self::discoverColumnType('folio_source_record.records__t', 'deleted') !== null;
        $recordsHasExternalIdColumn = self::discoverColumnType('folio_source_record.records__t', 'external_id') !== null;
        $recordsHasInstanceIdColumn = self::discoverColumnType('folio_source_record.records__t', 'external_ids_holder__instance_id') !== null;

        if (!$recordsHasExternalIdColumn && $recordsHasInstanceIdColumn && stripos($text, 'external_id') !== false) {
            $text = preg_replace(
                '/\b([A-Za-z_][A-Za-z0-9_]*)\.external_id\b/i',
                '$1.external_ids_holder__instance_id',
                $text
            );
            $text = str_ireplace('records__t.external_id', 'records__t.external_ids_holder__instance_id', $text);
            if (stripos($text, 'records__t') !== false || stripos($text, 'parsed_record__content') !== false) {
                $text = preg_replace('/\bexternal_id\b/i', 'external_ids_holder__instance_id', $text);
            }
        }

        if (!$recordsHasStateColumn && $recordsHasDeletedColumn) {
            $text = preg_replace(
                '/\b([A-Za-z_][A-Za-z0-9_]*)\.state\s*=\s*([\'"])ACTUAL\2/i',
                'COALESCE($1.deleted, false) = false',
                $text
            );
            $text = preg_replace(
                '/\brecords__t\.state\s*=\s*([\'"])ACTUAL\1/i',
                'COALESCE(records__t.deleted, false) = false',
                $text
            );
            $text = preg_replace('/\bstate\s*=\s*([\'"])ACTUAL\1/i', 'COALESCE(deleted, false) = false', $text);
            $text = preg_replace('/\bstate\s*=\s*ACTUAL\b/i', 'COALESCE(deleted, false) = false', $text);
            $text = str_ireplace('with state = ACTUAL', 'with COALESCE(deleted, false) = false', $text);
        }

        if (!self::isJsonLikeColumnType($parsedRecordContentType)) {
            return $text;
        }

        $text = preg_replace(
            '/((?:\b[A-Za-z_][A-Za-z0-9_]*\.)?parsed_record__content)(?!::text)(\s+(?:NOT\s+ILIKE|ILIKE)\b)/i',
            '$1::text$2',
            $text
        );

        $cleanLines = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (stripos($line, 'parsed_record__content') !== false) {
                if (
                    preg_match('/\b(?:MARC field|field-level MARC|field presence|field exists|field does NOT exist|subfield value)\b/i', $line) === 1
                    && preg_match('/\b(?:ILIKE|NOT ILIKE|jsonb_|->|->>)\b/i', $line) === 1
                ) {
                    continue;
                }

                if (
                    preg_match('/\bTEXT\b/i', $line) ||
                    stripos($line, 'NOT jsonb') !== false ||
                    stripos($line, 'cast to ::jsonb') !== false ||
                    stripos($line, 'cast it to JSONB') !== false ||
                    stripos($line, 'cast it to jsonb') !== false ||
                    stripos($line, 'never cast it to jsonb') !== false
                ) {
                    continue;
                }
            }

            $cleanLines[] = $line;
        }

        return trim(implode("\n", $cleanLines));
    }

    /**
     * Strip blocked-schema guidance while preserving adjacent supported guidance.
     */
    private static function sanitizePromptText(string $text, ?string $parsedRecordContentType = null): string
    {
        $text = self::normalizeParsedRecordPromptText(trim($text), $parsedRecordContentType);
        if ($text === '' || !self::containsBlockedPromptReference($text)) {
            return $text;
        }

        $cleanLines = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (!self::containsBlockedPromptReference($line)) {
                $cleanLines[] = $line;
                continue;
            }

            $cleanSentences = [];
            $sentences = preg_split('/(?<=[.!?])\s+/', $line) ?: [];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '' || self::containsBlockedPromptReference($sentence)) {
                    continue;
                }
                $cleanSentences[] = $sentence;
            }

            if (!empty($cleanSentences)) {
                $cleanLines[] = implode(' ', $cleanSentences);
            }
        }

        return trim(implode("\n", $cleanLines));
    }

    /**
     * Sanitize keyed prompt hints so blocked-schema guidance never reaches the model.
     */
    private static function sanitizePromptHintMap(array $map, ?string $parsedRecordContentType = null): array
    {
        $clean = [];
        foreach ($map as $key => $value) {
            if (self::containsBlockedPromptReference((string)$key)) {
                continue;
            }

            $sanitized = self::sanitizePromptText((string)$value, $parsedRecordContentType);
            if ($sanitized === '') {
                continue;
            }

            $clean[(string)$key] = $sanitized;
        }

        return $clean;
    }

    /**
     * Drop few-shot examples that would be rejected by SQL table-policy validation.
     */
    private static function sanitizePromptExamples(array $examples, ?string $parsedRecordContentType = null): array
    {
        $clean = [];
        foreach ($examples as $example) {
            $question = self::sanitizePromptText((string)($example['question'] ?? ''), $parsedRecordContentType);
            $sql = self::sanitizePromptText((string)($example['sql'] ?? ''), $parsedRecordContentType);
            if ($question === '' || $sql === '') {
                continue;
            }

            if (self::containsBlockedPromptReference($question) || self::containsBlockedPromptReference($sql)) {
                continue;
            }

            $clean[] = [
                'question' => $question,
                'sql' => $sql,
            ];
        }

        return $clean;
    }

    /**
     * Sanitize data-pattern guidance so blocked schemas are never recommended.
     */
    private static function sanitizePromptDataPatterns(array $patterns, ?string $parsedRecordContentType = null): array
    {
        $clean = [];
        foreach ($patterns as $tableName => $info) {
            if (self::containsBlockedPromptReference((string)$tableName)) {
                continue;
            }

            $columnWarnings = [];
            foreach (($info['columnWarnings'] ?? []) as $column => $warning) {
                $sanitized = self::sanitizePromptText((string)$warning, $parsedRecordContentType);
                if ($sanitized === '') {
                    continue;
                }
                $columnWarnings[(string)$column] = $sanitized;
            }

            $preferredApproach = [];
            foreach (($info['preferredApproach'] ?? []) as $approach) {
                $sanitized = self::sanitizePromptText((string)$approach, $parsedRecordContentType);
                if ($sanitized === '') {
                    continue;
                }
                $preferredApproach[] = $sanitized;
            }

            if ((string)$tableName === 'folio_source_record.records__t' && self::isJsonLikeColumnType($parsedRecordContentType)) {
                $columnWarnings['parsed_record__content'] = 'JSONB type. Cast to ::text before using ILIKE/NOT ILIKE pattern matching; direct string operators on jsonb will fail.';

                $jsonbApproach = "For exact MARC field presence/absence checks, use the per-field marctab.mtNNN table scoped by instance_hrid, for example EXISTS (SELECT 1 FROM marctab.mt300 m WHERE m.instance_hrid = inst.hrid). Do not scan parsed_record__content for field-level MARC checks.";
                if (!in_array($jsonbApproach, $preferredApproach, true)) {
                    array_unshift($preferredApproach, $jsonbApproach);
                }
            }

            $sampleValues = $info['sampleValues'] ?? [];
            if (empty($columnWarnings) && empty($preferredApproach) && empty($sampleValues)) {
                continue;
            }

            $clean[(string)$tableName] = [
                'columnWarnings' => $columnWarnings,
                'sampleValues' => $sampleValues,
                'preferredApproach' => $preferredApproach,
            ];
        }

        return $clean;
    }

    /**
     * Load data patterns from data_patterns.json.
     * Returns column type warnings, sample values, and preferred query approaches
     * for tables that commonly cause AI-generated SQL errors.
     *
     * Generated by: php yii data-patterns/generate
     *
     * @return array [tableName => ['columnWarnings' => [...], 'sampleValues' => [...], 'preferredApproach' => [...]]]
     */
    public static function loadDataPatterns(): array
    {
        $path = Yii::getAlias('@app/data/data_patterns.json');
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return $data['tables'] ?? [];
    }

    /**
     * Load local location/library/campus reference names for prompt-time disambiguation.
     *
     * @return array<int, array<string, string>>
     */
    public static function loadLocationReferenceCache(): array
    {
        $jsonReferences = ReferenceJsonBundleService::loadReferences();
        $jsonLocationReferences = [];
        foreach ($jsonReferences as $reference) {
            $tableName = trim((string)($reference['source_table'] ?? ''));
            $name = trim((string)($reference['name'] ?? ''));
            if ($tableName === '' || $name === '') {
                continue;
            }
            if (!in_array($tableName, ['inventory.location__t', 'inventory.loclibrary__t', 'inventory.loccampus__t'], true)) {
                continue;
            }
            $metadata = is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
            $jsonLocationReferences[] = [
                'table' => $tableName,
                'name' => $name,
                'code' => trim((string)($reference['code'] ?? '')),
                'library_name' => trim((string)($metadata['library_name'] ?? '')),
                'campus_name' => trim((string)($metadata['campus_name'] ?? '')),
                'campus_code' => trim((string)($metadata['campus_code'] ?? '')),
            ];
        }

        if (!empty($jsonLocationReferences)) {
            return $jsonLocationReferences;
        }

        $paths = [];
        try {
            $paths[] = Yii::getAlias('@runtime/cache/location_reference_cache.json');
        } catch (\Exception $e) {
            // Runtime alias is unavailable in lightweight test harnesses.
        }

        $paths[] = Yii::getAlias('@app/data/location_reference_cache.json');
        $data = null;

        foreach (array_unique($paths) as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $decoded = json_decode(file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
                break;
            }
        }

        if (!is_array($data)) {
            return [];
        }

        $references = $data['references'] ?? [];
        if (empty($references) && isset($data['tables']) && is_array($data['tables'])) {
            foreach ($data['tables'] as $tableName => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row['table'] = (string)$tableName;
                    $references[] = $row;
                }
            }
        }

        $clean = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $tableName = trim((string)($reference['table'] ?? ''));
            $name = trim((string)($reference['name'] ?? ''));
            if ($tableName === '' || $name === '') {
                continue;
            }
            if (!in_array($tableName, ['inventory.location__t', 'inventory.loclibrary__t', 'inventory.loccampus__t'], true)) {
                continue;
            }

            $clean[] = [
                'table' => $tableName,
                'name' => $name,
                'code' => trim((string)($reference['code'] ?? '')),
                'library_name' => trim((string)($reference['library_name'] ?? '')),
                'campus_name' => trim((string)($reference['campus_name'] ?? '')),
                'campus_code' => trim((string)($reference['campus_code'] ?? '')),
            ];
        }

        return $clean;
    }

    private static function normalizeLocationReferenceText(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));
        return $normalized;
    }

    private static function stripCampusPrefixFromLocationName(string $normalizedName): string
    {
        return trim((string)preg_replace('/^[a-z]{2}\s+/', '', $normalizedName));
    }

    private static function promptContainsLocationReference(string $normalizedPrompt, string $normalizedCandidate): bool
    {
        if ($normalizedCandidate === '' || strlen($normalizedCandidate) < 6) {
            return false;
        }

        return strpos($normalizedPrompt, $normalizedCandidate) !== false;
    }

    /**
     * Resolve local shorthand names that are not reliably present as exact FOLIO names/codes.
     *
     * @return array<int, array<string, string>>
     */
    private static function resolvePromptLocationAliases(string $prompt): array
    {
        $normalizedPrompt = self::normalizeLocationReferenceText($prompt);
        if ($normalizedPrompt === '') {
            return [];
        }

        $matches = [];
        foreach (self::LOCAL_LOCATION_ALIASES as $alias) {
            if (
                ($alias['alias'] ?? '') === 'MRBC'
                && preg_match('/\bmrbc\s+(?:reference|ref)\b/', $normalizedPrompt) === 1
            ) {
                continue;
            }

            foreach ($alias['terms'] as $term) {
                $normalizedTerm = self::normalizeLocationReferenceText((string)$term);
                if ($normalizedTerm === '') {
                    continue;
                }

                if (preg_match('/\b' . preg_quote($normalizedTerm, '/') . '\b/', $normalizedPrompt) !== 1) {
                    continue;
                }

                $matches[] = $alias;
                break;
            }
        }

        return $matches;
    }

    /**
     * Resolve local location hierarchy names mentioned in the prompt.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function resolvePromptLocationReferences(string $prompt): array
    {
        $normalizedPrompt = self::normalizeLocationReferenceText($prompt);
        if ($normalizedPrompt === '') {
            return [];
        }

        $references = self::loadLocationReferenceCache();
        if (empty($references)) {
            return [];
        }

        $matches = [];
        $seen = [];
        $collectionWordPresent = preg_match('/\b(collection|collections|browsing|reference)\b/i', $prompt) === 1;

        foreach ($references as $reference) {
            $name = (string)$reference['name'];
            $normalizedName = self::normalizeLocationReferenceText($name);
            $normalizedNameWithoutPrefix = self::stripCampusPrefixFromLocationName($normalizedName);
            $normalizedCode = self::normalizeLocationReferenceText((string)$reference['code']);
            $score = 0;
            $matchedBy = '';

            if (self::promptContainsLocationReference($normalizedPrompt, $normalizedName)) {
                $score = 1000 + strlen($normalizedName);
                $matchedBy = 'name';
            } elseif (self::promptContainsLocationReference($normalizedPrompt, $normalizedNameWithoutPrefix)) {
                $score = 700 + strlen($normalizedNameWithoutPrefix);
                $matchedBy = 'name_without_prefix';
            } elseif ($normalizedCode !== '' && strlen($normalizedCode) >= 3 && preg_match('/\b' . preg_quote($normalizedCode, '/') . '\b/', $normalizedPrompt) === 1) {
                $score = 500 + strlen($normalizedCode);
                $matchedBy = 'code';
            }

            if ($score === 0) {
                continue;
            }

            if ($collectionWordPresent && $reference['table'] === 'inventory.location__t') {
                $score += 150;
            }
            if ($reference['table'] === 'inventory.location__t') {
                $score += 25;
            }

            $key = $reference['table'] . '|' . strtolower($name);
            if (isset($seen[$key]) && $seen[$key] >= $score) {
                continue;
            }
            $seen[$key] = $score;

            $reference['score'] = $score;
            $reference['matched_by'] = $matchedBy;
            $matches[] = $reference;
        }

        usort($matches, function ($left, $right) {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            return strlen((string)$right['name']) <=> strlen((string)$left['name']);
        });

        return array_slice($matches, 0, 8);
    }

    private static function quotePromptLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Build prompt lines for exact local location hierarchy matches.
     *
     * @return array<int, string>
     */
    private static function buildResolvedLocationReferenceLines(string $prompt): array
    {
        $matches = self::resolvePromptLocationReferences($prompt);
        $aliasMatches = self::resolvePromptLocationAliases($prompt);
        if (empty($matches) && empty($aliasMatches)) {
            return [];
        }

        $lines = [
            "\n--- Resolved Location References ---",
            'Local location hierarchy cache or alias matches found in the user prompt. Use these exact table/column targets before inferring scope from generic wording like collection, library, or institution.',
        ];

        foreach ($aliasMatches as $alias) {
            $lines[] = '- Local alias: ' . $alias['alias'] . ' means ' . $alias['meaning'] . '; filter ' . $alias['table'] . ' using ' . $alias['filter_hint'] . ' (campus code ' . self::quotePromptLiteral((string)$alias['campus_code']) . ', campus ' . self::quotePromptLiteral((string)$alias['campus_name']) . ').';
            $lines[] = 'Do not filter inventory.instance__t.hrid for ' . $alias['alias'] . '; ' . $alias['alias'] . ' is a local collection/location alias, not an instance HRID prefix.';
        }

        $firstMatchByTable = [];

        foreach ($matches as $match) {
            if (!isset($firstMatchByTable[$match['table']])) {
                $firstMatchByTable[$match['table']] = $match;
            }

            $line = '- ' . $match['table'] . '.name = ' . self::quotePromptLiteral((string)$match['name']);
            $details = [];
            if ($match['code'] !== '') {
                $details[] = 'code ' . self::quotePromptLiteral((string)$match['code']);
            }
            if ($match['library_name'] !== '') {
                $details[] = 'library ' . self::quotePromptLiteral((string)$match['library_name']);
            }
            if ($match['campus_code'] !== '') {
                $details[] = 'campus code ' . self::quotePromptLiteral((string)$match['campus_code']);
            } elseif ($match['campus_name'] !== '') {
                $details[] = 'campus ' . self::quotePromptLiteral((string)$match['campus_name']);
            }
            if (!empty($details)) {
                $line .= ' (' . implode('; ', $details) . ')';
            }
            $lines[] = $line;
        }

        if (isset($firstMatchByTable['inventory.location__t'])) {
            $lines[] = 'For resolved inventory.location__t matches, filter the location alias, for example loc.name ILIKE ' . self::quotePromptLiteral((string)$firstMatchByTable['inventory.location__t']['name']) . ' or loc.code when a code is available. Do not move that predicate to inventory.loclibrary__t.';
        }
        if (isset($firstMatchByTable['inventory.loclibrary__t'])) {
            $lines[] = 'For resolved inventory.loclibrary__t matches, filter the library alias, for example lib.name ILIKE ' . self::quotePromptLiteral((string)$firstMatchByTable['inventory.loclibrary__t']['name']) . '.';
        }
        if (isset($firstMatchByTable['inventory.loccampus__t'])) {
            $lines[] = 'For resolved inventory.loccampus__t matches, filter the campus alias, for example camp.name ILIKE ' . self::quotePromptLiteral((string)$firstMatchByTable['inventory.loccampus__t']['name']) . '.';
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private static function buildHoldingsOnlyLocationPolicyLines(string $prompt): array
    {
        $normalized = self::normalizeLocationReferenceText($prompt);
        if (preg_match('/\bonly\s+(?:holding\s+)?location\b/', $normalized) !== 1) {
            return [];
        }
        if (preg_match('/\b(?:5|five)\s+colleg(?:e|es|se)\b/', $normalized) !== 1) {
            return [];
        }

        return [
            "\n--- Holdings-Only Location Rule ---",
            "For 'only holding location in the Five Colleges', first scope target holdings by inventory.holdings_record__t joined to inventory.location__t on holdings_record__t.effective_location_id, then exclude any other holdings for the same instance whose effective_location_id is not in the target location ID set.",
            "Use NOT EXISTS against inventory.holdings_record__t for the same instance_id; do not use HAVING COUNT(holdings.id) = 1 after joining inventory.item__t, because item joins multiply holdings rows and incorrectly remove multi-item holdings.",
            "Do not join inventory.item__t unless the user asks for item-level fields such as barcode, item status, material type, or item effective call number.",
            "For title + holdings call number reports, use inventory.instance__t.title and inventory.holdings_record__t.call_number. For instance numbers, use inventory.instance__t.hrid AS instance_number, not inventory.instance__t.id.",
            "Pattern: WHERE NOT EXISTS (SELECT 1 FROM inventory.holdings_record__t other_hr WHERE other_hr.instance_id = target_holdings.instance_id AND other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)).",
            "Canonical shape: WITH target_locations AS MATERIALIZED (SELECT id, name FROM inventory.location__t WHERE name ILIKE '<resolved location>'), target_holdings AS MATERIALIZED (SELECT DISTINCT hr.instance_id, hr.call_number, hr.effective_location_id FROM inventory.holdings_record__t hr JOIN target_locations tl ON tl.id = hr.effective_location_id) SELECT inst.title, inst.hrid AS instance_number, target_holdings.call_number FROM target_holdings JOIN inventory.instance__t inst ON inst.id = target_holdings.instance_id WHERE NOT EXISTS (SELECT 1 FROM inventory.holdings_record__t other_hr WHERE other_hr.instance_id = target_holdings.instance_id AND other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)) LIMIT 100.",
        ];
    }

    private static function promptMentionsInstanceClassification(string $prompt): bool
    {
        if (trim($prompt) === '') {
            return false;
        }

        return preg_match('/\b(classification\s+number|classification\s+numbers|classifications?|dewey|additional\s+dewey|lc\s+local|sudoc|udc|nlm)\b/i', $prompt) === 1;
    }

    /**
     * Build naming guidance for instance classification types.
     *
     * @return array<int, string>
     */
    private static function buildClassificationTypeReferenceLines(string $prompt): array
    {
        if (!self::promptMentionsInstanceClassification($prompt)) {
            return [];
        }

        return [
            "\n--- Instance Classification Type Naming Rule ---",
            'For bibliographic/instance classification-number prompts, use inventory.instance__t__classifications joined to inventory.classification_type__t.',
            'Classification numbers live in instance__t__classifications.classifications__classification_number.',
            'inventory.classification_type__t.name values: ' . implode(', ', self::CLASSIFICATION_TYPE_NAMES),
            "Use ct.name = 'Dewey' for Dewey classification-number prompts. Do not use 'Dewey Decimal classification' with inventory.classification_type__t; that wording belongs to inventory.call_number_type__t.",
        ];
    }

    private static function extractPromptMarcFieldRequest(string $prompt): ?array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return null;
        }

        $mentionsMarc = preg_match('/\bmarc\b|\bsubfield\b|\$[a-z0-9]\b|\b[0-9]xx\s+fields?\b/i', $prompt) === 1;
        $tag = null;
        $tagPattern = null;

        if (preg_match('/\b(?:marc\s+)?(?:field|tag)\s*([0-9]{3})\b/i', $prompt, $matches) === 1) {
            $tag = $matches[1];
        } elseif ($mentionsMarc && preg_match('/\b([0-9]{3})\b/i', $prompt, $matches) === 1) {
            $tag = $matches[1];
        } elseif (preg_match('/\b([0-9])xx\s+fields?\b/i', $prompt, $matches) === 1) {
            $tagPattern = '^' . $matches[1] . '[0-9][0-9]$';
        }

        if ($tag === null && $tagPattern === null) {
            return null;
        }

        $subfield = null;
        if (preg_match('/\bsubfield\s+([a-z0-9])\b/i', $prompt, $matches) === 1) {
            $subfield = strtolower($matches[1]);
        } elseif (preg_match('/\$([a-z0-9])\b/i', $prompt, $matches) === 1) {
            $subfield = strtolower($matches[1]);
        }

        $ind1 = null;
        $ind2 = null;
        if ($tag !== null) {
            $tagPatternQuoted = preg_quote($tag, '/');
            if (preg_match('/\b(?:marc\s+)?(?:field|tag)\s*' . $tagPatternQuoted . '\s+([0-9])\b/i', $prompt, $matches) === 1) {
                $ind2 = $matches[1];
            } elseif (preg_match('/\b' . $tagPatternQuoted . '\s+([0-9])\s+(?:subfield|\$)/i', $prompt, $matches) === 1) {
                $ind2 = $matches[1];
            }
        }

        return [
            'tag' => $tag,
            'tagPattern' => $tagPattern,
            'subfield' => $subfield,
            'ind1' => $ind1,
            'ind2' => $ind2,
        ];
    }

    /**
     * Build source-record guidance for MARC field extraction/aggregation prompts.
     *
     * @return array<int, string>
     */
    private static function buildMarcFieldSourceRecordLines(
        string $prompt,
        ?string $parsedRecordContentType,
        bool $recordsHasStateColumn,
        bool $recordsHasDeletedColumn,
        bool $recordsHasExternalIdColumn,
        bool $recordsHasInstanceIdColumn
    ): array {
        $marcFieldRequest = self::extractPromptMarcFieldRequest($prompt);
        if ($marcFieldRequest === null) {
            return [];
        }

        $tag = $marcFieldRequest['tag'];
        $tagPattern = $marcFieldRequest['tagPattern'];
        $subfield = $marcFieldRequest['subfield'];
        $ind1 = $marcFieldRequest['ind1'];
        $ind2 = $marcFieldRequest['ind2'];
        $marcTable = $tag !== null ? "marctab.mt{$tag}" : 'folio_source_record.marctab';
        $tagPredicate = $tag !== null
            ? null
            : "m.field ~ '{$tagPattern}'";

        $predicates = [];
        if ($tagPredicate !== null) {
            $predicates[] = $tagPredicate;
        }
        if ($ind1 !== null) {
            $predicates[] = "m.ind1 = '{$ind1}'";
        }
        if ($ind2 !== null) {
            $predicates[] = "m.ind2 = '{$ind2}'";
        }
        if ($subfield !== null) {
            $predicates[] = "m.sf = '{$subfield}'";
        }

        $lines = [
            "\n--- MARC Field Source Record Rule ---",
            "This is bibliographic/source-record work. Treat 'records' as inventory/source records, not patron or staff user records.",
            "For any exact MARC field, tag, indicator, subfield, control-number, or source-record request, scope inventory.instance__t first with holdings/location predicates, then join the matching per-field marctab.mtNNN table; do not touch MARC rows before the holdings/location scope.",
            "For 'holdings are only Smith College', require Smith-scoped holdings and exclude non-Smith holdings with a NOT EXISTS over inventory.holdings_record__t joined through location/library/campus.",
            "For a named location such as 'SC Internet', filter inventory.location__t in the target instance CTE using the resolved location name/code.",
            "Use per-field marctab.mtNNN tables for MARC field/subfield extraction and aggregation. They have one row per MARC field/subfield with columns instance_hrid, field, ind1, ind2, ord, sf, and content.",
            "Do not parse folio_source_record.records__t.parsed_record__content with jsonb_array_elements for field/subfield reports. Use records__t.parsed_record__content only when the user asks for the complete raw MARC/source record.",
        ];

        if ($tag !== null) {
            $lines[] = "For this prompt, use {$marcTable}; the table already restricts rows to MARC field {$tag}, so do not add an m.field = '{$tag}' predicate.";
        } else {
            $lines[] = "For MARC field-family prompts such as 6xx, use folio_source_record.marctab only after target_instances is materialized, because a field-family request spans multiple MARC tags.";
        }

        $lines[] = "For MARC field/subfield extraction or aggregation, use this shape after target_instances is materialized:";
        $lines[] = "WITH target_instances AS MATERIALIZED (SELECT DISTINCT inst.id, inst.hrid FROM inventory.instance__t inst WHERE ... holdings/location scope ... )";
        $lines[] = "SELECT m.content AS marc_value, COUNT(DISTINCT ti.id) AS record_count";
        $lines[] = "FROM target_instances ti";
        $lines[] = "JOIN {$marcTable} m ON m.instance_hrid = ti.hrid";
        if (!empty($predicates)) {
            $lines[] = "WHERE " . implode(' AND ', $predicates);
        }
        $lines[] = "When the prompt says a tag followed by one indicator digit, such as '035 9', treat that digit as the second indicator: m.ind2 = '9'.";
        $lines[] = "GROUP BY m.content ORDER BY record_count DESC;";

        return $lines;
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
    public static function buildSchemaContext($prompt = '')
    {
        $schema = self::loadSchema();
        $tables = $schema['tables'] ?? [];
        $rels = $schema['relationships'] ?? [];
        $metadbMap = self::discoverTableMapping();
        $allCols = self::discoverAllColumns();
        $parsedRecordContentType = self::discoverColumnType('folio_source_record.records__t', 'parsed_record__content');
        $recordsHasStateColumn = self::discoverColumnType('folio_source_record.records__t', 'state') !== null;
        $recordsHasDeletedColumn = self::discoverColumnType('folio_source_record.records__t', 'deleted') !== null;
        $recordsHasExternalIdColumn = self::discoverColumnType('folio_source_record.records__t', 'external_id') !== null;
        $recordsHasInstanceIdColumn = self::discoverColumnType('folio_source_record.records__t', 'external_ids_holder__instance_id') !== null;
        $subtables = self::discoverSubtables();
        $domainHints = self::loadDomainHints();
        $tableDescs = self::sanitizePromptHintMap($domainHints['tableDescriptions'] ?? [], $parsedRecordContentType);
        $vocabulary = self::sanitizePromptHintMap($domainHints['vocabulary'] ?? [], $parsedRecordContentType);
        $examples = self::sanitizePromptExamples($domainHints['examples'] ?? [], $parsedRecordContentType);

        // Keep prompt context size bounded and deterministic.
        $promptTerms = self::extractPromptTerms($prompt);
        $tableDescs = self::selectRelevantMapHints(
            $tableDescs,
            $promptTerms,
            self::MAX_TABLE_DESCRIPTION_HINTS
        );
        $vocabulary = self::selectRelevantMapHints(
            $vocabulary,
            $promptTerms,
            self::MAX_VOCABULARY_HINTS
        );
        $examples = self::selectRelevantExamples(
            $examples,
            $promptTerms,
            self::MAX_EXAMPLES
        );

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
        $lines[] = "  They join only to their immediate parent on parent__t.id = parent__t__child.id.";
        $lines[] = "  Do not join unrelated tables by matching id columns. For example, invoice.invoice_lines__t__fund_distributions.id is the invoice line id, not the PO line id.";
        $lines[] = "  To connect invoice fund distributions to PO lines, join invoice.invoice_lines__t__fund_distributions.po_line_id to orders.po_line__t.id.";
        $lines[] = "  ALWAYS prefer subtables over JSONB queries — e.g. use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions'.\n";
        $lines[] = "Acquisitions standing-order rule: Standing orders are purchase orders where orders.purchase_order__t.order_type = 'Ongoing'. Do not use orders.po_line__t.order_format or orders.po_line__t.payment_status to identify standing orders. order_format is the material/resource format, e.g. Physical Resource. For subscriptions, use order_type = 'Ongoing' plus ongoing__is_subscription; for non-subscription standing orders, use order_type = 'Ongoing' with ongoing__is_subscription false/null.\n";
        $lines[] = "Inventory title rule: Titles live on inventory.instance__t.title; inventory.item__t has no title column. When joining items to bibliographic titles, use inventory.item__t -> inventory.holdings_record__t -> inventory.instance__t and select the instance alias title, e.g. inst.title.";
        $lines[] = "GROUP BY correctness rule: If a query uses GROUP BY, every selected non-aggregate expression must also appear in GROUP BY. For title lists, prefer SELECT DISTINCT inst.id, inst.hrid, inst.title unless the user explicitly asks for counts or grouped totals.\n";

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

        if (self::isJsonLikeColumnType($parsedRecordContentType)) {
            $lines[] = "\n--- MARC Source Record Rule ---";
            $lines[] = "folio_source_record.records__t.parsed_record__content is {$parsedRecordContentType} in this environment.";
            $lines[] = "For exact MARC field checks, use the matching marctab.mtNNN per-field table filtered by instance_hrid. Use parsed_record__content only for complete raw MARC/source-record output.";
            if ($recordsHasInstanceIdColumn && !$recordsHasExternalIdColumn) {
                $lines[] = "Join folio_source_record.records__t to inventory.instance__t via external_ids_holder__instance_id = instance__t.id.";
            }
            if (!$recordsHasStateColumn && $recordsHasDeletedColumn) {
                $lines[] = "folio_source_record.records__t has no state column in this environment. Use COALESCE(deleted, false) = false when you need current, non-deleted source records.";
            }
        }

        foreach (ClarificationService::buildPromptGuidance((string)$prompt) as $line) {
            $lines[] = $line;
        }

        foreach (self::buildHoldingsOnlyLocationPolicyLines((string)$prompt) as $line) {
            $lines[] = $line;
        }

        $marcFieldSourceRecordLines = self::buildMarcFieldSourceRecordLines(
            (string)$prompt,
            $parsedRecordContentType,
            $recordsHasStateColumn,
            $recordsHasDeletedColumn,
            $recordsHasExternalIdColumn,
            $recordsHasInstanceIdColumn
        );
        foreach ($marcFieldSourceRecordLines as $line) {
            $lines[] = $line;
        }

        // Append data patterns — column type warnings and sample values
        $dataPatterns = self::sanitizePromptDataPatterns(self::loadDataPatterns(), $parsedRecordContentType);
        if (!empty($dataPatterns)) {
            $lines[] = "\n--- COLUMN TYPE WARNINGS & SAMPLE VALUES ---";
            $lines[] = "CRITICAL: Read this section BEFORE writing column expressions for these tables.";
            $lines[] = "These are verified against the live database — they override assumptions.\n";

            foreach ($dataPatterns as $tblName => $info) {
                $hasContent = !empty($info['columnWarnings']) || !empty($info['sampleValues']) || !empty($info['preferredApproach']);
                if (!$hasContent) {
                    continue;
                }
                $lines[] = "{$tblName}:";

                foreach ($info['columnWarnings'] ?? [] as $col => $warning) {
                    $lines[] = "  ⚠ {$col} — {$warning}";
                }

                foreach ($info['sampleValues'] ?? [] as $col => $vals) {
                    $quoted = array_map(function ($v) { return "'{$v}'"; }, array_slice($vals, 0, 15));
                    $more = count($vals) > 15 ? " (+ " . (count($vals) - 15) . " more)" : '';
                    $lines[] = "  {$col} values: [" . implode(', ', $quoted) . "]{$more}";
                }

                foreach ($info['preferredApproach'] ?? [] as $approach) {
                    $lines[] = "  PREFER: {$approach}";
                }

                $lines[] = '';
            }
        }

        foreach (self::buildClassificationTypeReferenceLines((string)$prompt) as $line) {
            $lines[] = $line;
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

        $locationReferenceLines = self::buildResolvedLocationReferenceLines((string)$prompt);
        foreach ($locationReferenceLines as $line) {
            $lines[] = $line;
        }

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
