<?php

namespace app\services;

/** Test-round stub; the build round replaces these methods with the key contract. */
final class HistoryGroupKeyService
{
    public static function build($reportTemplateId, array $boundParams, $exportKind, $outputMode): string
    {
        throw new \RuntimeException('not implemented');
    }

    public static function groupId($ownerId, $historyGroupKey): ?string
    {
        throw new \RuntimeException('not implemented');
    }
}
