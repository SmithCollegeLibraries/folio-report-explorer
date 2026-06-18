<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\services\FolioSchemaService;

/**
 * Generates the canonical semantic context artifact used by NL2SQL prompts.
 *
 * Usage:
 *   php yii semantic-context/generate
 */
class SemanticContextController extends Controller
{
    /**
     * Generate backend/data/semantic_context.json from the current schema and hints.
     */
    public function actionGenerate()
    {
        $artifact = FolioSchemaService::buildSemanticContextArtifact();
        $path = Yii::getAlias('@app/data/semantic_context.json');
        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->stderr("Failed to encode semantic context artifact.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (file_put_contents($path, $json . PHP_EOL) === false) {
            $this->stderr("Failed to write {$path}.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $tableCount = count($artifact['tables'] ?? []);
        $termCount = count($artifact['vocabulary'] ?? []);
        $this->stdout("Generated {$path} with {$tableCount} semantic tables and {$termCount} vocabulary terms.\n");

        return ExitCode::OK;
    }
}