<?php

namespace app\services;

use Yii;

class CanonicalQueryGraphService
{
    const ARTIFACT_PATH = '@app/data/canonical_query_graph.json';

    public static function getArtifactPath(): string
    {
        return Yii::getAlias(self::ARTIFACT_PATH);
    }

    public static function buildArtifact(?string $generatedAt = null): array
    {
        $artifact = CanonicalQueryGraphArtifactBuilder::build([], [], [], [], [], $generatedAt);
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

        $entities = $artifact['entities'] ?? null;
        if (!is_array($entities) || $entities === []) {
            throw new \RuntimeException('Canonical query graph artifact must contain entities.');
        }

        $edges = $artifact['edges'] ?? null;
        if (!is_array($edges)) {
            throw new \RuntimeException('Canonical query graph artifact must contain an edges array.');
        }

        $contractKeyToSqlTable = $artifact['contractKeyToSqlTable'] ?? null;
        $sqlTableToContractKey = $artifact['sqlTableToContractKey'] ?? null;
        if (!is_array($contractKeyToSqlTable) || !is_array($sqlTableToContractKey)) {
            throw new \RuntimeException('Canonical query graph artifact must contain both lookup maps.');
        }

        foreach ($entities as $contractKey => $entity) {
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
                throw new \RuntimeException('Unsupported canonical query graph entity kind: ' . $entityKind);
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

        foreach ($edges as $edge) {
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

            if (!isset($entities[$from]) || !isset($entities[$to])) {
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