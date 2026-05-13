<?php

namespace app\services;

use Yii;

class QueryFamilySchemaManifestService
{
    const ARTIFACT_PATH = '@app/data/query_family_schema_manifests.json';
    const ARTIFACT_VERSION = 1;

    public static function getArtifactPath(): string
    {
        return Yii::getAlias(self::ARTIFACT_PATH);
    }

    public static function hasManifest(string $familyKey): bool
    {
        $artifact = self::loadArtifact();
        return isset($artifact['families'][$familyKey]);
    }

    public static function loadArtifact(): array
    {
        $path = self::getArtifactPath();
        if (!file_exists($path)) {
            return self::buildEmptyArtifact();
        }

        $artifact = self::decodeJsonFile($path);
        self::validateArtifact($artifact);
        return $artifact;
    }

    public static function validateFamilyReady(string $familyKey, array $slots = [], ?array $graph = null): void
    {
        self::validateFamilyReadyFromArtifacts(
            $familyKey,
            self::loadArtifact(),
            $graph ?? CanonicalQueryGraphService::loadArtifact(),
            self::loadColumnCache(),
            self::loadSubtableCache(),
            $slots
        );
    }

    public static function validateFamilyReadyFromArtifacts(
        string $familyKey,
        array $artifact,
        array $graph,
        array $columnCache,
        array $subtableCache,
        array $slots = []
    ): void {
        $families = self::validateArtifact($artifact);
        $manifest = $families[$familyKey] ?? null;

        if (!is_array($manifest)) {
            throw new \RuntimeException('Missing query family schema manifest: ' . $familyKey . '.');
        }

        $availableColumns = self::buildAvailableColumns($columnCache, $subtableCache);
        $requirements = self::collectRequirements($manifest, $slots);

        foreach ($requirements['requiredEntities'] as $entityName) {
            self::assertEntityExists((string)$entityName, $graph, $availableColumns);
        }

        foreach ($requirements['requiredColumns'] as $columnRequirement) {
            if (!is_array($columnRequirement)) {
                throw new \RuntimeException('Query family schema manifest requiredColumns entries must be objects.');
            }

            $tableName = trim((string)($columnRequirement['table'] ?? ''));
            $columnName = trim((string)($columnRequirement['column'] ?? ''));
            $expectedType = trim((string)($columnRequirement['type'] ?? ''));
            if ($tableName === '' || $columnName === '' || $expectedType === '') {
                throw new \RuntimeException('Query family schema manifest requiredColumns entries must define table, column, and type.');
            }

            $sqlTable = self::resolveSqlTableName($tableName, $graph, $availableColumns);
            $actualType = $availableColumns[$sqlTable][$columnName] ?? null;
            if ($actualType === null) {
                throw new \RuntimeException(
                    'schema_manifest_drift: Missing required column ' . $tableName . '.' . $columnName
                );
            }

            if (self::normalizeType($actualType) !== self::normalizeType($expectedType)) {
                throw new \RuntimeException(
                    'schema_manifest_drift: Expected ' . $tableName . '.' . $columnName
                    . ' to have type ' . $expectedType . ', found ' . $actualType
                );
            }
        }

        foreach ($requirements['requiredEdges'] as $edgeRequirement) {
            if (!is_array($edgeRequirement)) {
                throw new \RuntimeException('Query family schema manifest requiredEdges entries must be objects.');
            }

            $fromTable = trim((string)($edgeRequirement['fromTable'] ?? ''));
            $fromColumn = trim((string)($edgeRequirement['fromColumn'] ?? ''));
            $toTable = trim((string)($edgeRequirement['toTable'] ?? ''));
            $toColumn = trim((string)($edgeRequirement['toColumn'] ?? ''));
            if ($fromTable === '' || $fromColumn === '' || $toTable === '' || $toColumn === '') {
                throw new \RuntimeException('Query family schema manifest requiredEdges entries must define fromTable, fromColumn, toTable, and toColumn.');
            }

            self::assertDeterministicEdgeExists($graph, $fromTable, $fromColumn, $toTable, $toColumn);
        }
    }

    private static function buildEmptyArtifact(): array
    {
        return [
            'metadata' => [
                'artifactVersion' => self::ARTIFACT_VERSION,
                'generatedAt' => null,
                'familyCount' => 0,
            ],
            'families' => [],
        ];
    }

    private static function validateArtifact(array $artifact): array
    {
        $metadata = $artifact['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new \RuntimeException('Query family schema manifest artifact is missing metadata.');
        }

        if (($metadata['artifactVersion'] ?? null) !== self::ARTIFACT_VERSION) {
            throw new \RuntimeException('Query family schema manifest artifact version is invalid.');
        }

        $families = $artifact['families'] ?? null;
        if (!is_array($families)) {
            throw new \RuntimeException('Query family schema manifest artifact must contain a families map.');
        }

        foreach ($families as $familyKey => $manifest) {
            if (!is_array($manifest)) {
                throw new \RuntimeException('Query family schema manifest entries must be objects.');
            }

            if (($manifest['familyKey'] ?? null) !== $familyKey) {
                throw new \RuntimeException('Query family schema manifest family keys must match their map keys.');
            }

            foreach (['requiredEntities', 'requiredColumns', 'requiredEdges'] as $fieldName) {
                if (!is_array($manifest[$fieldName] ?? null)) {
                    throw new \RuntimeException('Query family schema manifest ' . $familyKey . ' must define ' . $fieldName . '.');
                }
            }

            if (isset($manifest['conditionalRequirements']) && !is_array($manifest['conditionalRequirements'])) {
                throw new \RuntimeException('Query family schema manifest conditionalRequirements must be an array when provided.');
            }
        }

        return $families;
    }

    private static function loadColumnCache(): array
    {
        $data = self::decodeJsonFile(Yii::getAlias('@app/data/column_cache.json'));
        $columns = $data['columns'] ?? null;
        if (!is_array($columns)) {
            throw new \RuntimeException('Column cache must contain a columns map.');
        }

        return $columns;
    }

    private static function loadSubtableCache(): array
    {
        $data = self::decodeJsonFile(Yii::getAlias('@app/data/subtable_cache.json'));
        $subtables = $data['subtables'] ?? null;
        if (!is_array($subtables)) {
            throw new \RuntimeException('Subtable cache must contain a subtables map.');
        }

        return $subtables;
    }

    private static function collectRequirements(array $manifest, array $slots): array
    {
        $requirements = [
            'requiredEntities' => array_values($manifest['requiredEntities'] ?? []),
            'requiredColumns' => array_values($manifest['requiredColumns'] ?? []),
            'requiredEdges' => array_values($manifest['requiredEdges'] ?? []),
        ];

        foreach (($manifest['conditionalRequirements'] ?? []) as $conditionalRequirement) {
            if (!is_array($conditionalRequirement) || !self::conditionApplies($conditionalRequirement, $slots)) {
                continue;
            }

            foreach (['requiredEntities', 'requiredColumns', 'requiredEdges'] as $fieldName) {
                $requirements[$fieldName] = self::mergeUniqueEntries(
                    $requirements[$fieldName],
                    is_array($conditionalRequirement[$fieldName] ?? null) ? $conditionalRequirement[$fieldName] : []
                );
            }
        }

        return $requirements;
    }

    private static function conditionApplies(array $conditionalRequirement, array $slots): bool
    {
        $matches = [];

        $slotName = trim((string)($conditionalRequirement['slot'] ?? ''));
        if ($slotName !== '') {
            $matches[] = self::slotHasValue($slots[$slotName] ?? null);
        }

        $requestedOutputsAnyOf = $conditionalRequirement['requestedOutputsAnyOf'] ?? null;
        if (is_array($requestedOutputsAnyOf)) {
            $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
            $requestedLookup = [];
            foreach ($requestedOutputs as $outputField) {
                $requestedLookup[(string)$outputField] = true;
            }

            $outputMatch = false;
            foreach ($requestedOutputsAnyOf as $outputField) {
                if (isset($requestedLookup[(string)$outputField])) {
                    $outputMatch = true;
                    break;
                }
            }

            $matches[] = $outputMatch;
        }

        if ($matches === []) {
            return false;
        }

        foreach ($matches as $match) {
            if ($match !== true) {
                return false;
            }
        }

        return true;
    }

    private static function slotHasValue($value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return trim((string)$value) !== '';
    }

    private static function mergeUniqueEntries(array $current, array $additional): array
    {
        $seen = [];
        $merged = [];

        foreach (array_merge($current, $additional) as $entry) {
            $key = is_array($entry)
                ? json_encode($entry, JSON_UNESCAPED_SLASHES)
                : (string)$entry;
            if ($key === false || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $entry;
        }

        return $merged;
    }

    private static function buildAvailableColumns(array $columnCache, array $subtableCache): array
    {
        $availableColumns = [];

        foreach (self::extractBaseColumnTables($columnCache) as $sqlTable => $columns) {
            $availableColumns[(string)$sqlTable] = self::indexColumns($columns);
        }

        foreach (self::extractSubtableColumnTables($subtableCache) as $sqlTable => $subtableInfo) {
            $availableColumns[(string)$sqlTable] = self::indexColumns($subtableInfo['columns'] ?? []);
        }

        return $availableColumns;
    }

    private static function extractBaseColumnTables(array $columnCache): array
    {
        if (isset($columnCache['columns']) && is_array($columnCache['columns'])) {
            return $columnCache['columns'];
        }

        return $columnCache;
    }

    private static function extractSubtableColumnTables(array $subtableCache): array
    {
        if (isset($subtableCache['subtables']) && is_array($subtableCache['subtables'])) {
            return $subtableCache['subtables'];
        }

        return $subtableCache;
    }

    private static function indexColumns(array $columns): array
    {
        $indexedColumns = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = trim((string)($column['name'] ?? ''));
            $type = trim((string)($column['type'] ?? ''));
            if ($name === '' || $type === '') {
                continue;
            }

            $indexedColumns[$name] = $type;
        }

        return $indexedColumns;
    }

    private static function assertEntityExists(string $entityName, array $graph, array $availableColumns): void
    {
        $sqlTable = self::resolveSqlTableName($entityName, $graph, $availableColumns, false);
        if ($sqlTable === null || !isset($availableColumns[$sqlTable])) {
            throw new \RuntimeException(
                'schema_manifest_drift: Missing required entity ' . $entityName
                . ($sqlTable !== null ? ' (' . $sqlTable . ')' : '')
            );
        }
    }

    private static function resolveSqlTableName(string $entityName, array $graph, array $availableColumns, bool $throwOnMissing = true): ?string
    {
        $contractKeyToSqlTable = is_array($graph['contractKeyToSqlTable'] ?? null) ? $graph['contractKeyToSqlTable'] : [];

        if (isset($contractKeyToSqlTable[$entityName])) {
            return (string)$contractKeyToSqlTable[$entityName];
        }

        if (isset($availableColumns[$entityName])) {
            return $entityName;
        }

        if ($throwOnMissing) {
            throw new \RuntimeException('schema_manifest_drift: Missing required entity ' . $entityName);
        }

        return null;
    }

    private static function assertDeterministicEdgeExists(
        array $graph,
        string $fromTable,
        string $fromColumn,
        string $toTable,
        string $toColumn
    ): void {
        $fromContractKey = self::resolveContractKeyName($fromTable, $graph);
        $toContractKey = self::resolveContractKeyName($toTable, $graph);

        if ($fromContractKey !== null && $toContractKey !== null) {
            foreach (($graph['edges'] ?? []) as $edge) {
                if (!is_array($edge) || empty($edge['supportsDeterministicCompilation'])) {
                    continue;
                }

                $forwardMatch = ($edge['from'] ?? null) === $fromContractKey
                    && ($edge['localColumn'] ?? null) === $fromColumn
                    && ($edge['to'] ?? null) === $toContractKey
                    && ($edge['targetColumn'] ?? null) === $toColumn;

                $reverseMatch = ($edge['from'] ?? null) === $toContractKey
                    && ($edge['localColumn'] ?? null) === $toColumn
                    && ($edge['to'] ?? null) === $fromContractKey
                    && ($edge['targetColumn'] ?? null) === $fromColumn;

                if ($forwardMatch || $reverseMatch) {
                    return;
                }
            }

            if (self::isKnownParentSubtableIdentityJoin($fromContractKey, $fromColumn, $toContractKey, $toColumn)) {
                return;
            }
        }

        throw new \RuntimeException(
            'schema_manifest_drift: Missing required deterministic edge '
            . $fromTable . '.' . $fromColumn . ' <-> ' . $toTable . '.' . $toColumn
        );
    }

    private static function resolveContractKeyName(string $tableName, array $graph): ?string
    {
        $contractKeyToSqlTable = is_array($graph['contractKeyToSqlTable'] ?? null) ? $graph['contractKeyToSqlTable'] : [];
        if (isset($contractKeyToSqlTable[$tableName])) {
            return $tableName;
        }

        $sqlTableToContractKey = is_array($graph['sqlTableToContractKey'] ?? null) ? $graph['sqlTableToContractKey'] : [];
        if (isset($sqlTableToContractKey[$tableName])) {
            return (string)$sqlTableToContractKey[$tableName];
        }

        return null;
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

    private static function normalizeType(string $type): string
    {
        return strtolower(trim($type));
    }

    private static function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read ' . $path);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Failed to decode JSON from ' . $path);
        }

        return $data;
    }
}