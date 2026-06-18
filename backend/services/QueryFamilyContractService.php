<?php

namespace app\services;

class QueryFamilyContractService
{
    const ARTIFACT_VERSION = 1;

    public static function getArtifactPath(): string
    {
        if (class_exists('Yii') && method_exists('Yii', 'getAlias')) {
            return \Yii::getAlias('@app/data/query_family_contracts.json');
        }

        return dirname(__DIR__) . '/data/query_family_contracts.json';
    }

    public static function loadContracts(): array
    {
        $path = self::getArtifactPath();
        if (!file_exists($path)) {
            throw new \RuntimeException("Query family contract artifact is missing: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read query family contract artifact: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Query family contract artifact is not valid JSON: {$path}");
        }

        return self::validateContracts($decoded);
    }

    public static function validateContracts(array $artifact): array
    {
        $metadata = $artifact['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new \RuntimeException('Query family contract artifact is missing metadata.');
        }

        if (($metadata['artifactVersion'] ?? null) !== self::ARTIFACT_VERSION) {
            throw new \RuntimeException('Query family contract artifact version is invalid.');
        }

        $contracts = $artifact['contracts'] ?? null;
        if (!is_array($contracts) || empty($contracts)) {
            throw new \RuntimeException('Query family contract artifact must contain contracts.');
        }

        foreach ($contracts as $contractKey => $contract) {
            if (!is_array($contract)) {
                throw new \RuntimeException('Query family contracts must be arrays.');
            }

            if (($contract['familyKey'] ?? null) !== $contractKey) {
                throw new \RuntimeException('Query family contract keys must match familyKey.');
            }

            if (!is_array($contract['graph']['requiredEntities'] ?? null) || empty($contract['graph']['requiredEntities'])) {
                throw new \RuntimeException("Query family contract {$contractKey} must define graph.requiredEntities.");
            }

            if (!is_array($contract['slots']['supported'] ?? null) || empty($contract['slots']['supported'])) {
                throw new \RuntimeException("Query family contract {$contractKey} must define slots.supported.");
            }

            if (!is_array($contract['outputs']['allowed'] ?? null) || empty($contract['outputs']['allowed'])) {
                throw new \RuntimeException("Query family contract {$contractKey} must define outputs.allowed.");
            }

            if (trim((string)($contract['scopeRule'] ?? '')) === '') {
                throw new \RuntimeException("Query family contract {$contractKey} must define scopeRule.");
            }
        }

        ksort($contracts, SORT_STRING);
        return $contracts;
    }

    public static function selectContract(array $selection): array
    {
        $contracts = self::loadContracts();
        $availableEntityKeys = self::sortedUniqueStrings($selection['availableEntityKeys'] ?? []);
        $slotNames = self::sortedUniqueStrings($selection['slotNames'] ?? []);
        $outputFields = self::sortedUniqueStrings($selection['outputFields'] ?? []);

        foreach ($contracts as $contractKey => $contract) {
            $requiredEntities = self::sortedUniqueStrings($contract['graph']['requiredEntities'] ?? []);
            $requiredSlots = self::sortedUniqueStrings($contract['slots']['required'] ?? []);
            $supportedSlots = self::sortedUniqueStrings($contract['slots']['supported'] ?? []);
            $allowedOutputs = self::sortedUniqueStrings($contract['outputs']['allowed'] ?? []);

            if (!self::containsAll($availableEntityKeys, $requiredEntities)) {
                continue;
            }

            if (!self::containsAll($slotNames, $requiredSlots)) {
                continue;
            }

            if (!self::containsAll($supportedSlots, $slotNames)) {
                continue;
            }

            if (!self::containsAll($allowedOutputs, $outputFields)) {
                continue;
            }

            return [
                'matched' => true,
                'contractKey' => $contractKey,
                'contract' => $contract,
            ];
        }

        return [
            'matched' => false,
            'reason' => 'unsupported_family',
        ];
    }

    private static function containsAll(array $haystack, array $needles): bool
    {
        $lookup = array_fill_keys($haystack, true);
        foreach ($needles as $needle) {
            if (!isset($lookup[$needle])) {
                return false;
            }
        }

        return true;
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
}