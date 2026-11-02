<?php

namespace app\services;

final class HistoryGroupKeyService
{
    public static function build($reportTemplateId, array $boundParams, $exportKind, $outputMode): string
    {
        ksort($boundParams, SORT_STRING);
        $encoded = json_encode([
            'reportTemplateId' => $reportTemplateId,
            'boundParams' => $boundParams,
            'exportKind' => $exportKind,
            'outputMode' => $outputMode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Report parameters could not be encoded for history grouping.');
        }
        return sha1($encoded);
    }

    public static function groupId($ownerId, $historyGroupKey): ?string
    {
        if ($ownerId === null || $historyGroupKey === null) {
            return null;
        }
        return sha1((string) $ownerId . ':' . (string) $historyGroupKey);
    }
}
