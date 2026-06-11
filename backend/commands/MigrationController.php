<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\services\MigrationService;

require_once __DIR__ . '/../services/MigrationService.php';

class MigrationController extends Controller
{
    /**
     * @var string Directory containing SQL migration files.
     */
    public $path = '';

    /**
     * @var bool Preview changes without applying migrations.
     */
    public $dryRun = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['path', 'dryRun']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), ['n' => 'dryRun']);
    }

    public function actionAudit()
    {
        MigrationService::ensureSchemaTable(Yii::$app->db);
        $audit = MigrationService::auditDirectory($this->migrationPath(), MigrationService::loadAppliedRows(Yii::$app->db));

        $this->stdout("Migration audit\n");
        $this->stdout("  Files: " . count($audit['files']) . "\n");
        $this->stdout("  Unapplied: " . count($audit['unapplied']) . "\n");
        $this->stdout("  Changed checksums: " . count($audit['changedChecksums']) . "\n");
        $this->stdout("  Duplicate numbers: " . count($audit['duplicateNumbers']) . "\n");
        $this->stdout("  Non-idempotent risks: " . count($audit['nonIdempotentRisks']) . "\n");

        if (!empty($audit['duplicateNumbers'])) {
            $this->stderr("Duplicate migration numbers: " . json_encode($audit['duplicateNumbers']) . "\n");
            return ExitCode::DATAERR;
        }

        if (!empty($audit['changedChecksums'])) {
            $this->stderr("Changed applied migration checksums: " . json_encode($audit['changedChecksums']) . "\n");
            return ExitCode::DATAERR;
        }

        foreach ($audit['nonIdempotentRisks'] as $risk) {
            $this->stdout("  Risk {$risk['filename']}: " . implode('; ', $risk['reasons']) . "\n");
        }

        return ExitCode::OK;
    }

    public function actionRun()
    {
        $result = MigrationService::run(Yii::$app->db, $this->migrationPath(), $this->dryRun);

        $this->stdout($this->dryRun ? "Migration dry run\n" : "Migration run\n");
        $this->stdout("  Applied: " . count($result['applied']) . "\n");
        $this->stdout("  Skipped: " . count($result['skipped']) . "\n");
        $this->stdout("  Baselined: " . count($result['baselined']) . "\n");

        foreach ($result['applied'] as $filename) {
            $this->stdout("  Applied {$filename}\n");
        }
        foreach ($result['baselined'] as $filename) {
            $this->stdout("  Baselined {$filename}\n");
        }

        return ExitCode::OK;
    }

    private function migrationPath(): string
    {
        return $this->path !== '' ? $this->path : MigrationService::DEFAULT_MIGRATION_DIR;
    }
}
