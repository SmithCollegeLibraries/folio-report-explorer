<?php

namespace app\services;

use Yii;
use yii\db\Connection;

/**
 * DatabaseRetryService
 *
 * Provides a small reconnect-and-retry wrapper for transient database
 * connection failures (for example, Postgres SSL EOF disconnects).
 */
class DatabaseRetryService
{
    const DEFAULT_MAX_ATTEMPTS = 2;

    /**
     * Run a DB operation with one reconnect retry for transient errors.
     *
     * @param Connection $db
     * @param callable $operation
     * @param string $context
     * @param int $maxAttempts
     * @return mixed
     * @throws \Throwable
     */
    public static function runWithReconnectRetry(Connection $db, callable $operation, $context = 'db.operation', $maxAttempts = self::DEFAULT_MAX_ATTEMPTS)
    {
        $attempt = 1;
        $maxAttempts = max(1, (int)$maxAttempts);

        while (true) {
            try {
                return $operation($attempt);
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts || !self::isTransientConnectionError($e)) {
                    throw $e;
                }

                Yii::warning(
                    "Transient DB error in {$context} (attempt {$attempt}/{$maxAttempts}): " . $e->getMessage(),
                    'db.retry'
                );

                self::reconnect($db, $context);
                $attempt++;
            }
        }
    }

    /**
     * Detect transient disconnect errors that are safe to retry once.
     *
     * @param \Throwable $e
     * @return bool
     */
    public static function isTransientConnectionError(\Throwable $e)
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof \yii\db\Exception || $current instanceof \PDOException) {
                $errorInfo = $current->errorInfo;
                $driverCode = is_array($errorInfo) && isset($errorInfo[1])
                    ? (int)$errorInfo[1]
                    : null;
                if (in_array($driverCode, [2006, 2013], true)) {
                    return true;
                }
            }

            $current = $current->getPrevious();
        }

        $message = strtolower((string)$e->getMessage());
        $markers = [
            'mysql server has gone away',
            'lost connection to mysql server',
            'ssl syscall error: eof detected',
            'server closed the connection unexpectedly',
            'connection reset by peer',
            'broken pipe',
            'no connection to the server',
            'could not receive data from server',
            'sqlstate[08006]',
            'sqlstate[08003]',
            'sqlstate[57p01]',
        ];

        foreach ($markers as $marker) {
            if (strpos($message, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Close and reopen the DB connection.
     *
     * @param Connection $db
     * @param string $context
     */
    private static function reconnect(Connection $db, $context)
    {
        try {
            $db->close();
        } catch (\Throwable $e) {
            Yii::warning(
                "DB close during reconnect failed in {$context}: " . $e->getMessage(),
                'db.retry'
            );
        }

        $db->open();

        if (!$db->isActive) {
            throw new \RuntimeException("Failed to reopen DB connection in {$context}");
        }
    }
}
