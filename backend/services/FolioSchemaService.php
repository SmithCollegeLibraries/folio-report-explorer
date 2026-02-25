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
    public static function getTables($filter = null)
    {
        $schema = self::loadSchema();
        $tables = $schema['tables'] ?? [];
        $relationships = $schema['relationships'] ?? [];
        $allCols = self::discoverAllColumns();
        $result = [];

        foreach ($tables as $name => $info) {
            if ($filter !== null && !in_array($name, $filter)) {
                continue;
            }
            $rels = $relationships[$name] ?? ['parents' => [], 'children' => []];
            // Use discovered column count if available, fall back to static
            $colCount = isset($allCols[$name])
                ? count($allCols[$name])
                : count($info['columns'] ?? []);
            $result[$name] = [
                'name' => $name,
                'type' => $info['type'] ?? 'TABLE',
                'primary_key' => $info['primary_key'] ?? null,
                'remarks' => $info['remarks'] ?? null,
                'column_count' => $colCount,
                'parent_count' => count($rels['parents']),
                'child_count' => count($rels['children']),
            ];
        }

        // Append discovered subtables (flattened array/object columns)
        $subtables = self::discoverSubtables();
        foreach ($subtables as $fullName => $info) {
            if ($filter !== null && !in_array($fullName, $filter)) {
                continue;
            }
            if (isset($result[$fullName])) {
                continue; // already present from static schema
            }
            $result[$fullName] = [
                'name' => $fullName,
                'type' => 'SUBTABLE',
                'primary_key' => 'id',
                'remarks' => 'Flattened array/object column from ' . ($info['parent'] ?? 'unknown'),
                'column_count' => count($info['columns'] ?? []),
                'parent_count' => 1,
                'child_count' => 0,
                'parent_table' => $info['parent'] ?? null,
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
            // Static table — replace stale LDP1 columns with actual database columns
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

        // Also include discovered subtable names
        $subtableNames = array_keys(self::discoverSubtables());
        $allNames = array_merge($tables, $subtableNames);

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

            // Get all __t tables grouped by schema
            $rows = $db->createCommand(
                "SELECT table_schema, table_name FROM information_schema.tables
                 WHERE table_name ~ '__t$'
                 AND table_schema NOT IN ('information_schema', 'pg_catalog', 'public')
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

            // Known LDP1 → actual overrides for tables that can't be matched heuristically
            $knownOverrides = [
                'course_copyrightstatuses'      => 'courses.coursereserves_copyrightstates__t',
                'course_processingstatuses'      => 'courses.coursereserves_processingstates__t',
                'feesfines_lost_item_fees_policies' => 'feesfines.lost_item_fee_policy__t',
                'feesfines_overdue_fines_policies'  => 'feesfines.overdue_fine_policy__t',
                'inventory_holdings'             => 'inventory.holdings_record__t',
                'inventory_modes_of_issuance'    => 'inventory.mode_of_issuance__t',
                'notes'                          => 'notes.note_data__t',
                'organization_categories'        => 'organizations.categories__t',
                'po_order_invoice_relns'         => 'orders.order_invoice_relationship__t',
                'user_proxiesfor'                => 'users.proxyfor__t',
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

            // Cache to file
            @file_put_contents($cachePath, json_encode([
                '_note' => 'Auto-discovered LDP1 to actual database table mapping',
                '_discovered_at' => date('c'),
                '_matched' => count($mapping),
                '_total' => count($staticMap),
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

        // Build reverse mapping: "schema.table" => ldp1Name
        $tableMapping = self::discoverTableMapping();
        $reverse = [];
        $schemasUsed = [];
        foreach ($tableMapping as $ldp1 => $actual) {
            $reverse[$actual] = $ldp1;
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

            // Query all columns for __t tables in relevant schemas
            $schemaList = implode(',', array_map(function ($s) use ($db) {
                return $db->quoteValue($s);
            }, array_keys($schemasUsed)));

            $rows = $db->createCommand(
                "SELECT table_schema, table_name, column_name,
                        data_type, character_maximum_length,
                        is_nullable, column_default, ordinal_position
                 FROM information_schema.columns
                 WHERE table_schema IN ({$schemaList})
                   AND table_name ~ '__t$'
                 ORDER BY table_schema, table_name, ordinal_position"
            )->queryAll();

            $columns = [];
            foreach ($rows as $r) {
                $fullName = $r['table_schema'] . '.' . $r['table_name'];
                $ldp1 = $reverse[$fullName] ?? null;
                if ($ldp1 === null) {
                    continue;
                }

                if (!isset($columns[$ldp1])) {
                    $columns[$ldp1] = [];
                }

                // Map Postgres types to simpler display types
                $pgType = $r['data_type'];
                $displayType = self::mapPgType($pgType);

                $columns[$ldp1][] = [
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
                '_note' => 'Auto-discovered columns from actual database',
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
     *
     * @param string $ldp1Name
     * @return array ColumnDef[] or empty
     */
    public static function discoverColumnsFor($ldp1Name)
    {
        $all = self::discoverAllColumns();
        return $all[$ldp1Name] ?? [];
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
            $rows = $db->createCommand(
                "SELECT table_schema, table_name
                 FROM information_schema.tables
                 WHERE table_name ~ '__t__'
                   AND table_name !~ 'catalog$'
                   AND table_schema NOT IN ('information_schema', 'pg_catalog', 'public')
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
        return array_keys($schema['tables'] ?? []);
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

        $lines = [];

        $totalTables = count($tables) + count($subtables);
        $lines[] = "=== FOLIO MetaDB/LDLite Database Schema ===";
        $lines[] = "Database: PostgreSQL, {$totalTables} tables (including " . count($subtables) . " subtables)";
        $lines[] = "IMPORTANT: Table names are schema-qualified (e.g. inventory.item__t).";
        $lines[] = "Always use the full schema.table name in FROM and JOIN clauses.";
        $lines[] = "SUBTABLES: Tables matching pattern schema.parent__t__child are flattened array/object columns.";
        $lines[] = "  They join to their parent on parent__t.id = parent__t__child.id.";
        $lines[] = "  ALWAYS prefer subtables over JSONB queries — e.g. use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions'.\n";

        foreach ($tables as $tname => $tinfo) {
            $metadbName = $metadbMap[$tname] ?? $tname;
            // Use discovered columns if available, else fall back to static
            $colSource = $allCols[$tname] ?? $tinfo['columns'] ?? [];

            // Add table description if available
            $desc = $tableDescs[$metadbName] ?? '';
            if ($desc) {
                $lines[] = "TABLE {$metadbName} — {$desc}";
            } else {
                $lines[] = "TABLE {$metadbName}";
            }

            // Column list — annotate key ID/FK columns with derived comments (limit to avoid bloating prompt)
            $annotatedCols = [];
            foreach ($colSource as $col) {
                $colStr = $col['name'] . ':' . $col['type'];
                // Only annotate columns that match specific useful patterns
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

            // FK relationships — also translate table names
            foreach ($rels[$tname]['parents'] ?? [] as $p) {
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

        return implode("\n", $lines);
    }
}
