<?php

namespace app\commands;

use app\services\CanonicalQueryGraphService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Generates the canonical query graph artifact used by upcoming NL2SQL family contracts.
 *
 * Usage:
 *   php yii canonical-query-graph/generate
 */
class CanonicalQueryGraphController extends Controller
{
    /**
     * Generate backend/data/canonical_query_graph.json from the checked-in schema snapshots.
     */
    public function actionGenerate()
    {
        $artifact = CanonicalQueryGraphService::buildArtifact();
        $path = CanonicalQueryGraphService::getArtifactPath();
        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->stderr("Failed to encode canonical query graph artifact.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (file_put_contents($path, $json . PHP_EOL) === false) {
            $this->stderr("Failed to write {$path}.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entityCount = count($artifact['entities'] ?? []);
        $edgeCount = count($artifact['edges'] ?? []);
        $this->stdout("Generated {$path} with {$entityCount} entities and {$edgeCount} edges.\n");

        return ExitCode::OK;
    }
}