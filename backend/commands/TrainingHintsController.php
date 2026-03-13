<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\services\FolioSchemaService;

/**
 * Manage ai_training_hints: export to seed files, import from production dumps,
 * and sync domain_hints.json with the database.
 *
 * Usage:
 *   php yii training-hints/export               — export DB → seed files
 *   php yii training-hints/import               — import production dump → DB
 *   php yii training-hints/sync-json            — rebuild domain_hints.json from DB
 *   php yii training-hints/stats                — count hints by type
 */
class TrainingHintsController extends Controller
{
    /** Path to the MySQL seed SQL file (relative to project root) */
    const SEED_SQL_PATH = '@app/../mysql/seed_training_hints.sql';

    /** Path to the domain hints JSON file (fallback for when DB is unavailable) */
    const DOMAIN_HINTS_PATH = '@app/data/domain_hints.json';

    /** Path to the production dump to import from */
    const PROD_DUMP_PATH = '@app/data/ai_training_hints.sql';

    /**
     * Export the current ai_training_hints database table to:
     *   1. mysql/seed_training_hints.sql  (REPLACE INTO format for Docker re-seeding)
     *   2. backend/data/domain_hints.json (JSON fallback used when DB is unavailable)
     *
     * Both files are overwritten on each run.
     */
    public function actionExport()
    {
        $db = Yii::$app->db;

        $rows = $db->createCommand(
            'SELECT id, type, hint_key, hint_value, example_question, example_sql,
                    original_sql, notes, is_active, user_id, created_at, updated_at
             FROM ai_training_hints
             ORDER BY id ASC'
        )->queryAll();

        if (empty($rows)) {
            $this->stderr("No training hints found in database.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Exporting " . count($rows) . " training hints...\n");

        // --- 1. Write mysql/seed_training_hints.sql ---
        // Note: in Docker only the backend/ directory is mounted, so @app/../mysql/
        // may not be accessible. We skip gracefully and log a warning instead of
        // crashing so that sync-json still runs successfully in Docker.
        $sqlPath = Yii::getAlias(self::SEED_SQL_PATH);
        $sqlDir  = dirname($sqlPath);
        if (is_writable($sqlDir) || (is_dir($sqlDir) && is_writable($sqlDir))) {
            $this->writeSeedSql($rows, $sqlPath);
            $this->stdout("  → " . realpath($sqlPath) . " (" . count($rows) . " rows)\n");
        } else {
            $this->stdout("  ⚠ Skipping seed SQL (target directory not accessible: {$sqlDir})\n");
            $this->stdout("    Regenerate from host with: php /tmp/gen_seed.php\n");
        }

        // --- 2. Write domain_hints.json ---
        $jsonPath = Yii::getAlias(self::DOMAIN_HINTS_PATH);
        $this->writeDomainHintsJson($rows, $jsonPath);
        $this->stdout("  → " . realpath($jsonPath) . "\n");

        // Clear in-memory cache so next AI request picks up latest hints
        FolioSchemaService::clearDomainHintsCache();

        $this->stdout("Done!\n");
        return ExitCode::OK;
    }

    /**
     * Import training hints from the production SQL dump into the local database.
     * Uses REPLACE INTO so it is safe to run multiple times.
     *
     * The dump file (backend/data/ai_training_hints.sql) should be obtained from
     * production via Sequel Ace or mysqldump and placed in that path.
     */
    public function actionImport()
    {
        $dumpPath = Yii::getAlias(self::PROD_DUMP_PATH);

        if (!file_exists($dumpPath)) {
            $this->stderr("Production dump not found: {$dumpPath}\n");
            $this->stderr("Export it from production and save to backend/data/ai_training_hints.sql\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Reading dump: {$dumpPath}\n");
        $rawSql = file_get_contents($dumpPath);

        // Detect format: Sequel Ace produces a single multi-row INSERT; the
        // seed file produces individual REPLACE INTO statements per row.
        //
        // For single-statement format (Sequel Ace), we locate the INSERT block
        // by finding the start of the INSERT INTO and the UNLOCK TABLES line
        // that follows it, then execute the whole block as one REPLACE INTO.
        //
        // For per-row format (seed file), we extract each REPLACE INTO line.
        //
        // NOTE: we cannot use [^;]+ to find the end of an INSERT statement
        // because SQL example values often contain embedded semicolons.

        $db = Yii::$app->db;
        $imported = 0;
        $errors = 0;

        // --- Attempt 1: per-row REPLACE INTO lines (seed file format) ---
        preg_match_all('/^REPLACE INTO\s+`?ai_training_hints`?[^\n]+;/im', $rawSql, $perRow);

        if (!empty($perRow[0])) {
            $this->stdout("Found " . count($perRow[0]) . " per-row statements to import...\n");
            foreach ($perRow[0] as $stmt) {
                try {
                    $db->createCommand($stmt)->execute();
                    $imported++;
                } catch (\Exception $e) {
                    $this->stderr("  Error: " . $e->getMessage() . "\n");
                    $errors++;
                }
            }
        } else {
            // --- Attempt 2: multi-row INSERT block (Sequel Ace format) ---
            // Find the INSERT INTO...VALUES block and execute it as REPLACE INTO.
            $insertStart = stripos($rawSql, 'INSERT INTO');
            $unlockPos   = stripos($rawSql, 'UNLOCK TABLES', $insertStart !== false ? $insertStart : 0);

            if ($insertStart === false || $unlockPos === false) {
                $this->stderr("No INSERT/REPLACE statements found in dump.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            // Extract from INSERT INTO up to (but not including) UNLOCK TABLES
            $block = rtrim(substr($rawSql, $insertStart, $unlockPos - $insertStart));
            // Convert INSERT INTO → REPLACE INTO
            $block = preg_replace('/^INSERT INTO/i', 'REPLACE INTO', $block);

            $this->stdout("Importing multi-row INSERT block (" . strlen($block) . " bytes)...\n");
            try {
                $db->createCommand($block)->execute();
                // Count affected rows by querying the table after import
                $imported = (int) $db->createCommand('SELECT COUNT(*) FROM ai_training_hints')->queryScalar();
                $this->stdout("Import succeeded — {$imported} total rows now in table.\n");
            } catch (\Exception $e) {
                $this->stderr("  Error executing block: " . $e->getMessage() . "\n");
                $errors++;
            }
        }

        $this->stdout("Imported: {$imported}, Errors: {$errors}\n");

        if ($imported > 0) {
            // Rebuild seed file and JSON from the newly imported data
            $this->stdout("Rebuilding seed files...\n");
            return $this->actionExport();
        }

        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Rebuild backend/data/domain_hints.json from the database.
     * This is the fallback file used when MySQL is unavailable.
     */
    public function actionSyncJson()
    {
        $db = Yii::$app->db;
        $rows = $db->createCommand(
            'SELECT type, hint_key, hint_value, example_question, example_sql
             FROM ai_training_hints WHERE is_active = 1 ORDER BY id ASC'
        )->queryAll();

        $jsonPath = Yii::getAlias(self::DOMAIN_HINTS_PATH);
        $this->writeDomainHintsJson($rows, $jsonPath);

        FolioSchemaService::clearDomainHintsCache();

        $this->stdout("domain_hints.json updated with " . count($rows) . " active hints → {$jsonPath}\n");
        return ExitCode::OK;
    }

    /**
     * Print hint counts by type.
     */
    public function actionStats()
    {
        $db = Yii::$app->db;
        $counts = $db->createCommand(
            'SELECT type, is_active, COUNT(*) AS cnt FROM ai_training_hints GROUP BY type, is_active ORDER BY type, is_active DESC'
        )->queryAll();

        $this->stdout("ai_training_hints stats:\n");
        foreach ($counts as $row) {
            $activeLabel = $row['is_active'] ? 'active' : 'inactive';
            $this->stdout("  {$row['type']} ({$activeLabel}): {$row['cnt']}\n");
        }

        $total = $db->createCommand('SELECT COUNT(*) FROM ai_training_hints WHERE is_active = 1')->queryScalar();
        $this->stdout("\nTotal active: {$total}\n");

        return ExitCode::OK;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Write REPLACE INTO seed SQL file.
     *
     * @param array  $rows    Rows from ai_training_hints
     * @param string $outPath Absolute path to write SQL file
     */
    private function writeSeedSql(array $rows, string $outPath): void
    {
        $now = date('Y-m-d H:i:s T');
        $lines = [];
        $lines[] = "-- ai_training_hints seed data";
        $lines[] = "-- Generated: {$now}";
        $lines[] = "-- DO NOT EDIT MANUALLY — regenerate with: php yii training-hints/export";
        $lines[] = "";
        $lines[] = "LOCK TABLES `ai_training_hints` WRITE;";
        $lines[] = "/*!40000 ALTER TABLE `ai_training_hints` DISABLE KEYS */;";
        $lines[] = "";

        foreach ($rows as $row) {
            $lines[] = $this->buildReplaceStatement($row);
        }

        $lines[] = "";
        $lines[] = "/*!40000 ALTER TABLE `ai_training_hints` ENABLE KEYS */;";
        $lines[] = "UNLOCK TABLES;";

        file_put_contents($outPath, implode("\n", $lines) . "\n");
    }

    /**
     * Build a single REPLACE INTO statement for a training hint row.
     *
     * @param array $row Database row
     * @return string SQL statement
     */
    private function buildReplaceStatement(array $row): string
    {
        $cols = ['`id`', '`type`', '`hint_key`', '`hint_value`', '`example_question`',
                 '`example_sql`', '`original_sql`', '`notes`', '`is_active`',
                 '`user_id`', '`created_at`', '`updated_at`'];

        $vals = [
            $row['id'],
            $this->sqlQuote($row['type']),
            $this->sqlQuote($row['hint_key']),
            $this->sqlQuote($row['hint_value']),
            $this->sqlQuote($row['example_question']),
            $this->sqlQuote($row['example_sql']),
            $this->sqlQuote($row['original_sql']),
            $this->sqlQuote($row['notes']),
            (int)$row['is_active'],
            $row['user_id'] !== null ? (int)$row['user_id'] : 'NULL',
            $this->sqlQuote($row['created_at']),
            $this->sqlQuote($row['updated_at']),
        ];

        return "REPLACE INTO `ai_training_hints` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");";
    }

    /**
     * Write domain_hints.json from database rows.
     * Reconstructs the three-section structure (tableDescriptions, vocabulary, examples).
     *
     * @param array  $rows    Rows from ai_training_hints (may omit inactive rows)
     * @param string $outPath Absolute path to write JSON file
     */
    private function writeDomainHintsJson(array $rows, string $outPath): void
    {
        $tableDescriptions = [];
        $vocabulary = [];
        $examples = [];

        foreach ($rows as $row) {
            if (!($row['is_active'] ?? 1)) {
                continue;
            }
            switch ($row['type']) {
                case 'table_description':
                    if ($row['hint_key'] && $row['hint_value']) {
                        $tableDescriptions[$row['hint_key']] = $row['hint_value'];
                    }
                    break;
                case 'vocabulary':
                    if ($row['hint_key'] && $row['hint_value']) {
                        $vocabulary[$row['hint_key']] = $row['hint_value'];
                    }
                    break;
                case 'example':
                case 'correction':
                    if ($row['example_question'] && $row['example_sql']) {
                        $examples[] = [
                            'question' => $row['example_question'],
                            'sql' => $row['example_sql'],
                        ];
                    }
                    break;
            }
        }

        $data = [
            'tableDescriptions' => $tableDescriptions,
            'vocabulary' => $vocabulary,
            'examples' => $examples,
        ];

        file_put_contents($outPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * SQL-escape a value for inclusion in a REPLACE INTO statement.
     *
     * @param string|null $value
     * @return string NULL or a quoted and escaped string
     */
    private function sqlQuote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        // Escape backslashes first, then single quotes
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        return "'{$escaped}'";
    }
}
