<?php

namespace app\services;

use Yii;
use yii\db\Connection;

/**
 * Configures the FOLIO connection for an export attempt.
 */
class FolioExportSessionService
{
    /**
     * Apply per-attempt timeout, cancellation tag, and read-only session mode.
     *
     * @param Connection $db
     * @param string $jobId
     */
    public static function configure(Connection $db, $jobId)
    {
        $db->createCommand('SET statement_timeout = ' . (int) Yii::$app->params['queryTimeoutMs'])->execute();
        $db->createCommand(
            "SELECT set_config('application_name', :application_name, false)",
            [':application_name' => QueryJobCancellationService::applicationName($jobId)]
        )->queryScalar();
        $db->createCommand('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY')->execute();
    }
}
