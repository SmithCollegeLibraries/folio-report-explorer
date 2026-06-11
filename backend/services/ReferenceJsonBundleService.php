<?php

namespace app\services;

use Yii;

require_once __DIR__ . '/ReferenceTextNormalizerService.php';

/**
 * Owns the approved local JSON reference bundle used before NL2SQL generation.
 */
class ReferenceJsonBundleService
{
    const DEFAULT_BUNDLE_ALIAS = '@app/data/reference_cache.json';
    const DEFAULT_MAX_AGE_SECONDS = 604800;

    private static $approvedTables = [
        'inventory.location__t',
        'inventory.loclibrary__t',
        'inventory.loccampus__t',
        'inventory.locinstitution__t',
        'inventory.service_point__t',
        'inventory.material_type__t',
        'inventory.loan_type__t',
        'inventory.holdings_type__t',
        'inventory.call_number_type__t',
        'inventory.instance_type__t',
        'inventory.instance_format__t',
        'inventory.instance_status__t',
        'inventory.instance_note_type__t',
        'inventory.holdings_note_type__t',
        'inventory.item_note_type__t',
        'inventory.item_damaged_status__t',
        'inventory.contributor_type__t',
        'inventory.contributor_name_type__t',
        'inventory.identifier_type__t',
        'inventory.classification_type__t',
        'inventory.electronic_access_relationship__t',
        'inventory.ill_policy__t',
        'inventory.statistical_code__t',
        'inventory.statistical_code_type__t',
        'inventory.subject_sources__t',
        'inventory.subject_types__t',
        'inventory.alternative_title_type__t',
        'inventory.mode_of_issuance__t',
        'inventory.nature_of_content_term__t',
        'finance.fund__t',
        'finance.ledger__t',
        'finance.fiscal_year__t',
        'finance.fund_type__t',
        'finance.expense_class__t',
        'finance.groups__t',
        'orders.acquisitions_unit__t',
        'invoice.batch_groups__t',
        'circulation.cancellation_reason__t',
        'circulation.loan_policy__t',
        'circulation.request_policy__t',
        'circulation.patron_notice_policy__t',
        'circulation.fixed_due_date_schedule__t',
        'circulation.staff_slips__t',
        'courses.coursereserves_copyrightstates__t',
        'courses.coursereserves_coursetypes__t',
        'courses.coursereserves_departments__t',
        'courses.coursereserves_processingstates__t',
        'courses.coursereserves_terms__t',
        'feesfines.lost_item_fee_policy__t',
        'feesfines.overdue_fine_policy__t',
        'feesfines.waives__t',
    ];

    private static $excludedTables = [
        'inventory.item__t',
        'inventory.instance__t',
        'inventory.holdings_record__t',
    ];

    public static function approvedTables(): array
    {
        return self::$approvedTables;
    }

    public static function excludedTables(): array
    {
        return self::$excludedTables;
    }

    public static function isApprovedTable(string $sourceTable): bool
    {
        $sourceTable = strtolower(trim($sourceTable));
        if (in_array($sourceTable, self::$excludedTables, true)) {
            return false;
        }

        return in_array($sourceTable, self::$approvedTables, true);
    }

    public static function normalizeText(string $text, bool $stripCampusPrefix = false): string
    {
        return $stripCampusPrefix
            ? ReferenceTextNormalizerService::normalizeWithoutCampusPrefix($text)
            : ReferenceTextNormalizerService::normalize($text);
    }

    public static function loadBundle(?string $path = null): array
    {
        $path = $path ?: self::bundlePath();
        if ($path === '' || !file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function bundleStatus(?string $path = null, int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS): array
    {
        $path = $path ?: self::bundlePath();
        if ($path === '' || !file_exists($path)) {
            return [
                'usable' => false,
                'status' => 'missing',
                'stale' => false,
                'path' => $path,
                'generatedAt' => null,
                'ageSeconds' => null,
            ];
        }

        $bundle = self::loadBundle($path);
        $tables = $bundle['tables'] ?? null;
        if (!is_array($bundle) || !is_array($tables) || empty($tables)) {
            return [
                'usable' => false,
                'status' => 'invalid',
                'stale' => false,
                'path' => $path,
                'generatedAt' => null,
                'ageSeconds' => null,
            ];
        }

        $generatedAt = (string)($bundle['generated_at'] ?? '');
        $timestamp = $generatedAt !== '' ? strtotime($generatedAt) : false;
        $ageSeconds = $timestamp === false ? null : max(0, time() - (int)$timestamp);
        $stale = $ageSeconds === null || $ageSeconds > $maxAgeSeconds;

        return [
            'usable' => true,
            'status' => $stale ? 'stale' : 'fresh',
            'stale' => $stale,
            'path' => $path,
            'generatedAt' => $generatedAt !== '' ? $generatedAt : null,
            'ageSeconds' => $ageSeconds,
        ];
    }

    public static function loadReferences(?string $path = null): array
    {
        $bundle = self::loadBundle($path);
        $tables = $bundle['tables'] ?? [];
        if (!is_array($tables)) {
            return [];
        }

        $references = [];
        foreach ($tables as $sourceTable => $rows) {
            $sourceTable = strtolower(trim((string)$sourceTable));
            if (!self::isApprovedTable($sourceTable) || !is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $references[] = [
                    'source_table' => $sourceTable,
                    'source_id' => (string)($row['id'] ?? ($row['source_id'] ?? '')),
                    'name' => $name,
                    'code' => (string)($row['code'] ?? ''),
                    'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                    'normalized_name' => (string)($row['normalized_name'] ?? self::normalizeText($name)),
                    'normalized_name_without_prefix' => (string)($row['normalized_name_without_prefix'] ?? self::normalizeText($name, true)),
                    'normalized_code' => (string)($row['normalized_code'] ?? self::normalizeText((string)($row['code'] ?? ''))),
                    'search_tokens' => is_array($row['search_tokens'] ?? null) ? $row['search_tokens'] : [],
                ];
            }
        }

        return $references;
    }

    public function buildBundle($folioDb): array
    {
        $tables = [];
        foreach (self::approvedTables() as $sourceTable) {
            if (in_array($sourceTable, self::$excludedTables, true)) {
                continue;
            }

            $tables[$sourceTable] = $this->loadRowsForTable($folioDb, $sourceTable);
        }

        return [
            'generated_at' => date('c'),
            'approved_tables' => self::approvedTables(),
            'excluded_tables' => self::excludedTables(),
            'tables' => $tables,
        ];
    }

    public function writeBundle($folioDb, ?string $path = null): int
    {
        $path = $path ?: self::bundlePath();
        $bundle = $this->buildBundle($folioDb);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Failed to create reference bundle directory: {$directory}");
        }

        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Failed to write reference bundle: {$path}");
        }

        $count = 0;
        foreach (($bundle['tables'] ?? []) as $rows) {
            $count += is_array($rows) ? count($rows) : 0;
        }

        return $count;
    }

    private static function bundlePath(): string
    {
        try {
            return Yii::getAlias(self::DEFAULT_BUNDLE_ALIAS);
        } catch (\Throwable $e) {
            return __DIR__ . '/../data/reference_cache.json';
        }
    }

    private function loadRowsForTable($folioDb, string $sourceTable): array
    {
        switch ($sourceTable) {
            case 'inventory.location__t':
                return $this->loadLocationRows($folioDb);
            case 'inventory.loclibrary__t':
                return $this->loadLibraryRows($folioDb);
            case 'inventory.loccampus__t':
                return $this->loadCampusRows($folioDb);
            default:
                return $this->loadGenericRows($folioDb, $sourceTable);
        }
    }

    private function loadLocationRows($db): array
    {
        $sql = "SELECT loc.id::text AS id, loc.name::text AS name, loc.code::text AS code,
                    loc.description::text AS description,
                    lib.id::text AS library_id, lib.name::text AS library_name, lib.code::text AS library_code,
                    camp.id::text AS campus_id, camp.name::text AS campus_name, camp.code::text AS campus_code,
                    inst.id::text AS institution_id, inst.name::text AS institution_name, inst.code::text AS institution_code
                FROM inventory.location__t loc
                LEFT JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id
                LEFT JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id
                LEFT JOIN inventory.locinstitution__t inst ON loc.institution_id = inst.id
                WHERE loc.name IS NOT NULL AND loc.name <> ''
                ORDER BY loc.name";

        return $this->normalizeRows($db->createCommand($sql)->queryAll());
    }

    private function loadLibraryRows($db): array
    {
        $sql = "SELECT lib.id::text AS id, lib.name::text AS name, lib.code::text AS code,
                    camp.id::text AS campus_id, camp.name::text AS campus_name, camp.code::text AS campus_code
                FROM inventory.loclibrary__t lib
                LEFT JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id
                WHERE lib.name IS NOT NULL AND lib.name <> ''
                ORDER BY lib.name";

        return $this->normalizeRows($db->createCommand($sql)->queryAll());
    }

    private function loadCampusRows($db): array
    {
        $sql = "SELECT camp.id::text AS id, camp.name::text AS name, camp.code::text AS code
                FROM inventory.loccampus__t camp
                WHERE camp.name IS NOT NULL AND camp.name <> ''
                ORDER BY camp.name";

        return $this->normalizeRows($db->createCommand($sql)->queryAll());
    }

    private function loadGenericRows($db, string $sourceTable): array
    {
        [$schema, $table] = $this->splitSourceTable($sourceTable);
        $columns = $this->loadExistingColumns($db, $schema, $table);
        if (!isset($columns['id'])) {
            return [];
        }

        $nameColumn = $this->firstExistingColumn($columns, ['name', 'label', 'value', 'display_name', 'description']);
        if ($nameColumn === '') {
            return [];
        }
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'key', 'slug']);
        $hasDescription = isset($columns['description']) && $nameColumn !== 'description';

        $select = ['id::text AS id', $this->quoteIdent($nameColumn) . '::text AS name'];
        $select[] = $codeColumn !== '' ? $this->quoteIdent($codeColumn) . '::text AS code' : "NULL::text AS code";
        $select[] = $hasDescription ? 'description::text AS description' : "NULL::text AS description";

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdent($schema) . '.' . $this->quoteIdent($table)
            . ' WHERE ' . $this->quoteIdent($nameColumn) . " IS NOT NULL AND " . $this->quoteIdent($nameColumn) . " <> ''"
            . ' ORDER BY ' . $this->quoteIdent($nameColumn);

        return $this->normalizeRows($db->createCommand($sql)->queryAll());
    }

    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $code = trim((string)($row['code'] ?? ''));
            $metadata = [];
            foreach ($row as $key => $value) {
                if (in_array($key, ['id', 'name', 'code'], true)) {
                    continue;
                }
                $value = trim((string)$value);
                if ($value !== '') {
                    $metadata[(string)$key] = $value;
                }
            }

            $searchText = implode(' ', array_filter([
                $name,
                $code,
                $metadata['library_name'] ?? '',
                $metadata['library_code'] ?? '',
                $metadata['campus_name'] ?? '',
                $metadata['campus_code'] ?? '',
                $metadata['institution_name'] ?? '',
                $metadata['institution_code'] ?? '',
            ]));

            $normalized[] = [
                'id' => (string)($row['id'] ?? ''),
                'name' => $name,
                'code' => $code,
                'normalized_name' => self::normalizeText($name),
                'normalized_name_without_prefix' => self::normalizeText($name, true),
                'normalized_code' => $code !== '' ? self::normalizeText($code) : '',
                'search_tokens' => $this->tokensForText($searchText),
                'metadata' => $metadata,
            ];
        }

        return $normalized;
    }

    private function tokensForText(string $text): array
    {
        return ReferenceTextNormalizerService::tokens($text);
    }

    private function loadExistingColumns($db, string $schema, string $table): array
    {
        $rows = $db->createCommand(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name = :table',
            [':schema' => $schema, ':table' => $table]
        )->queryColumn();

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row] = true;
        }

        return $columns;
    }

    private function firstExistingColumn(array $columns, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }

    private function splitSourceTable(string $sourceTable): array
    {
        $parts = explode('.', $sourceTable, 2);
        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['public', $sourceTable];
    }

    private function quoteIdent(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
