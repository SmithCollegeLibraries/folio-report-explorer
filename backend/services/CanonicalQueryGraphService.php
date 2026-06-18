<?php

namespace app\services;

use Yii;

/**
 * Loads, validates, and rebuilds the canonical query graph artifact.
 */
class CanonicalQueryGraphService
{
    const ARTIFACT_PATH = '@app/data/canonical_query_graph.json';

    public static function getArtifactPath(): string
    {
        return Yii::getAlias(self::ARTIFACT_PATH);
    }

    public static function buildArtifact(?string $generatedAt = null): array
    {
        $schema = FolioSchemaService::loadSchema();
        $artifact = CanonicalQueryGraphArtifactBuilder::build(
            $schema['tables'] ?? [],
            $schema['relationships'] ?? [],
            self::loadTableMappingSnapshot(),
            self::loadSubtableSnapshot(),
            self::loadSemanticContextSnapshot(),
            $generatedAt
        );

        self::validateArtifact($artifact);
        return $artifact;
    }

    public static function loadArtifact(): array
    {
        $path = self::getArtifactPath();
        if (!file_exists($path)) {
            return self::buildArtifact();
        }

        $data = self::decodeJsonFile($path);
        self::validateArtifact($data);
        return $data;
    }

    public static function validateArtifact(array $artifact): void
    {
        if (!is_array($artifact['metadata'] ?? null)) {
            throw new \RuntimeException('Canonical query graph artifact is missing metadata.');
        }

        if (($artifact['metadata']['artifactVersion'] ?? null) !== CanonicalQueryGraphArtifactBuilder::ARTIFACT_VERSION) {
            throw new \RuntimeException('Canonical query graph artifact version is invalid.');
        }

        if (!is_array($artifact['entities'] ?? null) || empty($artifact['entities'])) {
            throw new \RuntimeException('Canonical query graph artifact must contain entities.');
        }

        if (!is_array($artifact['edges'] ?? null)) {
            throw new \RuntimeException('Canonical query graph artifact must contain an edges array.');
        }

        $contractKeyToSqlTable = $artifact['contractKeyToSqlTable'] ?? [];
        $sqlTableToContractKey = $artifact['sqlTableToContractKey'] ?? [];
        if (!is_array($contractKeyToSqlTable) || !is_array($sqlTableToContractKey)) {
            throw new \RuntimeException('Canonical query graph artifact must contain both lookup maps.');
        }

        foreach ($artifact['entities'] as $contractKey => $entity) {
            if (!is_array($entity)) {
                throw new \RuntimeException('Canonical query graph entities must be arrays.');
            }

            if (($entity['contractKey'] ?? null) !== $contractKey) {
                throw new \RuntimeException('Canonical query graph entity contract keys must match their map keys.');
            }

            $sqlTable = trim((string)($entity['sqlTable'] ?? ''));
            if ($sqlTable === '') {
                throw new \RuntimeException('Canonical query graph entities must define a sqlTable.');
            }

            $entityKind = trim((string)($entity['entityKind'] ?? ''));
            if (!in_array($entityKind, ['base', 'subtable', 'lookup', 'bridge', 'local'], true)) {
                throw new \RuntimeException("Unsupported canonical query graph entity kind: {$entityKind}");
            }

            if (($contractKeyToSqlTable[$contractKey] ?? null) !== $sqlTable) {
                throw new \RuntimeException('Canonical query graph contractKeyToSqlTable map is out of sync.');
            }

            if (($sqlTableToContractKey[$sqlTable] ?? null) !== $contractKey) {
                throw new \RuntimeException('Canonical query graph sqlTableToContractKey map is out of sync.');
            }

            if ($entityKind === 'subtable' && trim((string)($entity['parentContractKey'] ?? '')) === '') {
                throw new \RuntimeException('Canonical query graph subtable entities must define a parentContractKey.');
            }
        }

        foreach ($artifact['edges'] as $edge) {
            if (!is_array($edge)) {
                throw new \RuntimeException('Canonical query graph edges must be arrays.');
            }

            $from = trim((string)($edge['from'] ?? ''));
            $to = trim((string)($edge['to'] ?? ''));
            $localColumn = trim((string)($edge['localColumn'] ?? ''));
            $targetColumn = trim((string)($edge['targetColumn'] ?? ''));
            if ($from === '' || $to === '' || $localColumn === '' || $targetColumn === '') {
                throw new \RuntimeException('Canonical query graph edges must define from/to/localColumn/targetColumn.');
            }

            if (!isset($artifact['entities'][$from]) || !isset($artifact['entities'][$to])) {
                throw new \RuntimeException('Canonical query graph edges must reference known entities.');
            }

            $confidence = trim((string)($edge['confidence'] ?? ''));
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                throw new \RuntimeException('Canonical query graph edges must declare a supported confidence tier.');
            }

            if (!array_key_exists('supportsDeterministicCompilation', $edge)) {
                throw new \RuntimeException('Canonical query graph edges must declare deterministic-compilation support.');
            }

            $typeCompatibility = trim((string)($edge['typeCompatibility'] ?? ''));
            if (!in_array($typeCompatibility, ['exact', 'assumed_compatible', 'cast_required', 'unknown'], true)) {
                throw new \RuntimeException('Canonical query graph edges must declare a supported type compatibility value.');
            }
        }
    }

    private static function loadTableMappingSnapshot(): array
    {
        $path = Yii::getAlias('@app/data/table_mapping_cache.json');
        if (!file_exists($path)) {
            return FolioSchemaService::discoverTableMapping();
        }

        $data = self::decodeJsonFile($path);
        return is_array($data['mapping'] ?? null) ? $data['mapping'] : [];
    }

    private static function loadSubtableSnapshot(): array
    {
        $path = Yii::getAlias('@app/data/subtable_cache.json');
        if (!file_exists($path)) {
            return FolioSchemaService::discoverSubtables();
        }

        $data = self::decodeJsonFile($path);
        return is_array($data['subtables'] ?? null) ? $data['subtables'] : [];
    }

    private static function loadSemanticContextSnapshot(): array
    {
        $path = Yii::getAlias('@app/data/semantic_context.json');
        if (!file_exists($path)) {
            return FolioSchemaService::buildSemanticContextArtifact();
        }

        return self::decodeJsonFile($path);
    }

    private static function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read {$path}");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Failed to decode JSON from {$path}");
        }

        return $data;
    }
}