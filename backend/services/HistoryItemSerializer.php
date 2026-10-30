<?php

namespace app\services;

/** Test-round stub; the build round replaces this method with the history item contract. */
final class HistoryItemSerializer
{
    public static function serialize(array $job, $userId = null, bool $isAdmin = false, array $advisory = []): array
    {
        throw new \RuntimeException('not implemented');
    }
}
