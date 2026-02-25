<?php
/**
 * Seed ai_training_hints table from domain_hints.json.
 * Run inside Docker: docker compose exec php php /app/backend/data/seed_training_hints.php
 *
 * This is idempotent — checks for existing rows before inserting.
 */

// Bootstrap Yii to get DB connection
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';
new yii\web\Application($config);

$db = Yii::$app->db;

// Check if table exists and already has data
$existingCount = $db->createCommand('SELECT COUNT(*) FROM ai_training_hints')->queryScalar();
if ($existingCount > 0) {
    echo "ai_training_hints already has {$existingCount} rows. Skipping seed.\n";
    echo "To re-seed, first run: DELETE FROM ai_training_hints;\n";
    exit(0);
}

// Load domain_hints.json
$path = __DIR__ . '/domain_hints.json';
if (!file_exists($path)) {
    echo "ERROR: domain_hints.json not found at {$path}\n";
    exit(1);
}

$data = json_decode(file_get_contents($path), true);
if (!$data) {
    echo "ERROR: Failed to parse domain_hints.json\n";
    exit(1);
}

$inserted = 0;

// Seed table descriptions
$tableDescs = $data['tableDescriptions'] ?? [];
echo "Seeding " . count($tableDescs) . " table descriptions...\n";
foreach ($tableDescs as $tableName => $description) {
    $db->createCommand()->insert('ai_training_hints', [
        'type' => 'table_description',
        'hint_key' => $tableName,
        'hint_value' => $description,
        'is_active' => 1,
    ])->execute();
    $inserted++;
}

// Seed vocabulary
$vocabulary = $data['vocabulary'] ?? [];
echo "Seeding " . count($vocabulary) . " vocabulary terms...\n";
foreach ($vocabulary as $term => $mapping) {
    $db->createCommand()->insert('ai_training_hints', [
        'type' => 'vocabulary',
        'hint_key' => $term,
        'hint_value' => $mapping,
        'is_active' => 1,
    ])->execute();
    $inserted++;
}

// Seed examples
$examples = $data['examples'] ?? [];
echo "Seeding " . count($examples) . " examples...\n";
foreach ($examples as $ex) {
    $db->createCommand()->insert('ai_training_hints', [
        'type' => 'example',
        'example_question' => $ex['question'],
        'example_sql' => $ex['sql'],
        'is_active' => 1,
    ])->execute();
    $inserted++;
}

echo "Done! Inserted {$inserted} training hints.\n";

// Verify
$counts = $db->createCommand(
    'SELECT type, COUNT(*) as cnt FROM ai_training_hints GROUP BY type ORDER BY type'
)->queryAll();
echo "\nVerification:\n";
foreach ($counts as $row) {
    echo "  {$row['type']}: {$row['cnt']}\n";
}
