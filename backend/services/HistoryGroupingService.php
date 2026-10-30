<?php

namespace app\services;

/** Test-round stub; the build round replaces these methods with SQLite-compatible SQL. */
final class HistoryGroupingService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function list(array $options): array
    {
        throw new \RuntimeException('not implemented');
    }

    public function expand($jobId, array $options): array
    {
        throw new \RuntimeException('not implemented');
    }
}
