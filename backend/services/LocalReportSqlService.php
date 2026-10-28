<?php

namespace app\services;

use Yii;
use app\exceptions\PolicyViolationException;
use app\services\DatabaseRetryService;
use yii\base\InvalidConfigException;
use yii\db\Connection;

/**
 * Executes local report SQL through the dedicated SELECT-only database account.
 */
class LocalReportSqlService
{
    const POLICY_MESSAGE = 'This query is blocked by reporting data policy.';
    const NOT_CONFIGURED_MESSAGE = "Local reports aren't configured on this server.";

    /**
     * Validate SQL before queueing it or passing it to the local reporting connection.
     *
     * @param string $sql Normalized SQL that will be executed.
     * @param string|null $rawSql Original request text, checked before normalization.
     */
    public static function validateSql($sql, $rawSql = null)
    {
        self::assertRawSql($rawSql === null ? $sql : $rawSql);

        try {
            SqlBuilderService::validateSafety((string) $sql);
        } catch (\InvalidArgumentException $exception) {
            throw new PolicyViolationException(self::POLICY_MESSAGE, 0, $exception);
        }
    }

    /**
     * Execute a local reporting statement and return all rows.
     */
    public static function queryAll($sql, array $params = [])
    {
        $connection = self::getConnection();
        return DatabaseRetryService::runWithReconnectRetry(
            $connection,
            function () use ($sql, $params) {
                return self::withStatement($sql, $params, function ($statement) {
                    return $statement->fetchAll(\PDO::FETCH_ASSOC);
                });
            },
            'local-report.query'
        );
    }

    /**
     * Execute a local reporting statement and let the callback consume its cursor.
     */
    public static function withStatement($sql, array $params, callable $callback)
    {
        $statement = self::openStatement($sql, $params);

        try {
            return call_user_func($callback, $statement);
        } catch (\Throwable $exception) {
            throw self::mapDatabaseException($exception);
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Open a local reporting statement for a streaming consumer.
     * The caller must close the cursor on every exit path.
     */
    public static function openStatement($sql, array $params = [])
    {
        self::validateSql($sql);
        $connection = self::getConnection();
        $statement = null;

        try {
            $statement = $connection->getMasterPdo()->prepare((string) $sql);
            foreach ($params as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->execute();
            return $statement;
        } catch (\Throwable $exception) {
            if ($statement instanceof \PDOStatement) {
                $statement->closeCursor();
            }
            throw self::mapDatabaseException($exception);
        }
    }

    /**
     * Open a streaming statement with the same one-reconnect retry as other workers.
     */
    public static function openStatementWithReconnectRetry($sql, array $params = [], $context = 'local-report.stream')
    {
        $connection = self::getConnection();
        return DatabaseRetryService::runWithReconnectRetry(
            $connection,
            function () use ($sql, $params) {
                return self::openStatement($sql, $params);
            },
            $context
        );
    }

    /**
     * Close the worker's local report session after a failed job.
     */
    public static function resetConnection()
    {
        if (isset(Yii::$app) && Yii::$app->has('localReportDb', true)) {
            Yii::$app->get('localReportDb')->close();
        }
    }

    private static function assertRawSql($sql)
    {
        $sql = (string) $sql;
        if (
            preg_match('/\/\*(?:!|M!)/i', $sql)
            || preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $sql)
        ) {
            throw new PolicyViolationException(self::POLICY_MESSAGE);
        }
    }

    /**
     * @throws InvalidConfigException when the dedicated account is absent.
     */
    private static function getConnection(): Connection
    {
        $user = getenv('LOCAL_REPORT_DB_USER');
        $password = getenv('LOCAL_REPORT_DB_PASS');
        if (
            !is_string($user)
            || trim($user) === ''
            || !is_string($password)
            || trim($password) === ''
            || !isset(Yii::$app)
            || !Yii::$app->has('localReportDb')
        ) {
            throw new InvalidConfigException(self::NOT_CONFIGURED_MESSAGE);
        }

        $connection = Yii::$app->get('localReportDb');
        if (!$connection instanceof Connection) {
            throw new InvalidConfigException(self::NOT_CONFIGURED_MESSAGE);
        }

        return $connection;
    }

    private static function mapDatabaseException(\Throwable $exception): \Throwable
    {
        if ($exception instanceof PolicyViolationException) {
            return $exception;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $errorInfo = isset($current->errorInfo) && is_array($current->errorInfo)
                ? $current->errorInfo
                : [];
            $driverCode = isset($errorInfo[1]) ? (int) $errorInfo[1] : (int) $current->getCode();
            if (in_array($driverCode, [1044, 1142, 1143], true)) {
                return new PolicyViolationException(self::POLICY_MESSAGE, 0, $exception);
            }
        }

        return $exception;
    }
}
