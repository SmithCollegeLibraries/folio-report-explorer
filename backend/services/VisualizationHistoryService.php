<?php

namespace app\services;

/** Contract stub for the visualization tests round; unit codes are supplied from unit options. */
class VisualizationHistoryService
{
    public function __construct($db, $cache, array $existingUnitCodes)
    {
        throw new \RuntimeException('not implemented');
    }

    public function list(string $acqUnitCode): array
    {
        throw new \RuntimeException('not implemented');
    }

    /** Invalid input returns ['errors' => [field => [message, ...]]]. */
    public function upsert(array $input, int $actorId): array
    {
        throw new \RuntimeException('not implemented');
    }

    public function delete(int $id, int $actorId): array
    {
        throw new \RuntimeException('not implemented');
    }

    public function version(): int
    {
        throw new \RuntimeException('not implemented');
    }
}
