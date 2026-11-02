<?php

namespace app\services;

/** Contract stub for the visualization tests round. */
class VisualizationDataService
{
    public function __construct($folioDb, $historyDb, $cache, $validator = null, ?string $today = null)
    {
        throw new \RuntimeException('not implemented');
    }

    public function options(): array
    {
        throw new \RuntimeException('not implemented');
    }

    public function get(string $acqUnitId, int $years = 10, $materialFiscalYearEnd = null): array
    {
        throw new \RuntimeException('not implemented');
    }

    public static function categoryForMaterial(?string $materialType): string
    {
        throw new \RuntimeException('not implemented');
    }

    public static function categoryPercentages(float $electronic, float $physical, float $other): array
    {
        throw new \RuntimeException('not implemented');
    }

    public static function cacheKey(string $acqUnitId, int $years, $materialFiscalYearEnd, int $historyVersion): string
    {
        throw new \RuntimeException('not implemented');
    }
}
