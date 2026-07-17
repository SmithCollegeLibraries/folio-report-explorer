<?php

namespace yii\web {
    class Controller { public function __construct($id = null, $module = null, $config = []) {} public function behaviors() { return []; } }
    class Response { public const FORMAT_JSON = 'json'; }
}

namespace app\models {
    class SavedQuery
    {
        public static array $saved = [];
        public $id = 7, $name, $user_id, $description, $query_definition, $generated_sql, $source,
            $nl_prompt, $is_pinned, $is_global = 0, $last_job_id, $created_at = 'now', $updated_at = 'now', $errors = [];
        public function save(): bool { self::$saved[] = clone $this; return true; }
    }
    class QueryLog {} class QueryJob {} class ReportTemplate {} class AcrlStatistic {} class ExpenseAllocation {}
    class User {} class DummyIdentity {}
}

namespace app\services {
    class BuilderQueryDefinitionNormalizerService
    {
        public static function normalize(array $definition): array
        {
            foreach (is_array($definition['joins'] ?? null) ? $definition['joins'] : [] as $join) {
                if (isset($join['from_table']) || !isset($join['relationship_id'])) {
                    throw new \InvalidArgumentException('Each canonical join must contain a relationship_id.');
                }
                if ($join['relationship_id'] === 'unknown') {
                    throw new \InvalidArgumentException('Unknown Builder relationship: unknown');
                }
            }
            $normalized = $definition;
            unset($normalized['schemaIdentity']);
            $normalized['joins'] = [[
                'from_table' => 'inventory_items', 'from_column' => 'effective_location_id',
                'to_table' => 'inventory_locations', 'to_column' => 'id',
            ]];
            return $normalized;
        }
    }
    class SqlBuilderService
    {
        public static bool $fail = false;
        public static function build(array $definition): array
        {
            if (self::$fail) throw new \InvalidArgumentException('build failed');
            return ['sql' => "SELECT ii.id\nFROM inventory.item__t ii\nJOIN inventory.location__t il\n  ON il.id = ii.effective_location_id", 'params' => []];
        }
        public static function validateSafety($sql): void {}
        public static function validateTablePolicy($sql): void {}
    }
    class BuilderSchemaService {} class FolioSchemaService {} class GeminiService { public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry'; public static function isAiTimeoutMessage($m): bool { return false; } }
    class SettingsService {} class DatabaseRetryService {} class IndexRecommendationService {} class Nl2sqlRuntimePreflightService {}
    class PreviousSuccessfulQueryReuseService {} class ReferenceCacheRefreshService {} class ReferenceJsonBundleService {} class SqlPreflightService {}
}

namespace Firebase\JWT { class JWT {} }

namespace {
    if (!defined('YII_ENV')) define('YII_ENV', 'test');
    class Yii { public static $app; public static function warning($message, $category = 'application') {} }
    final class CanonicalSaveRequest
    {
        public function __construct(private array $body) {}
        public function getBodyParams(): array { return $this->body; }
        public function get($name, $default = null) { return $default; }
    }
    function expectCanonicalSave(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
    }

    require_once __DIR__ . '/../services/SqlSelectStructureService.php';
    require_once __DIR__ . '/../controllers/FolioQueryController.php';
    Yii::$app = (object)['request' => null, 'response' => (object)['statusCode' => 200], 'user' => (object)['isGuest' => true, 'id' => null]];
    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $definition = [
        'schemaIdentity' => 'ldlite', 'tables' => ['inventory.item__t', 'inventory.location__t'],
        'columns' => [['table' => 'inventory.item__t', 'column' => 'id']], 'filters' => [], 'orderBy' => [], 'limit' => 100,
        'joins' => 'auto',
    ];
    $trustedSql = "SELECT ii.id\nFROM inventory.item__t ii\nJOIN inventory.location__t il\n  ON il.id = ii.effective_location_id";

    Yii::$app->request = new CanonicalSaveRequest(['name' => 'Valid', 'queryDefinition' => $definition, 'generatedSql' => $trustedSql]);
    $valid = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 201 && count(\app\models\SavedQuery::$saved) === 1, 'Valid canonical save must persist one server-verified query.');

    Yii::$app->response->statusCode = 200;
    Yii::$app->request = new CanonicalSaveRequest(['name' => 'Tampered', 'queryDefinition' => $definition, 'generatedSql' => str_replace('effective_location_id', 'permanent_location_id', $trustedSql)]);
    $tampered = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 422 && isset($tampered['error']), 'Canonical SQL/definition mismatch must be rejected.');
    expectCanonicalSave(count(\app\models\SavedQuery::$saved) === 1, 'Tampered SQL must not persist.');

    Yii::$app->response->statusCode = 200;
    Yii::$app->request = new CanonicalSaveRequest([
        'name' => 'Edited alternate', 'queryDefinition' => $definition,
        'generatedSql' => str_replace('effective_location_id', 'permanent_location_id', $trustedSql),
        'sqlEdited' => true,
    ]);
    $editedAlternate = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 422 && isset($editedAlternate['error']), 'Edited SQL cannot replace a trusted default table link.');

    foreach ([
        [['relationship_id' => 'unknown'], 'Unknown relationship IDs must be rejected.'],
        [['from_table' => 'inventory.item__t', 'from_column' => 'permanent_location_id', 'to_table' => 'inventory.location__t', 'to_column' => 'id'], 'Raw join endpoints must be rejected.'],
    ] as [$joins, $message]) {
        Yii::$app->response->statusCode = 200;
        $badDefinition = $definition; $badDefinition['joins'] = [$joins];
        Yii::$app->request = new CanonicalSaveRequest(['name' => 'Bad', 'queryDefinition' => $badDefinition, 'generatedSql' => $trustedSql]);
        $bad = $controller->actionSave();
        expectCanonicalSave(Yii::$app->response->statusCode === 400 && isset($bad['error']), $message);
    }

    Yii::$app->response->statusCode = 200;
    \app\services\SqlBuilderService::$fail = true;
    Yii::$app->request = new CanonicalSaveRequest(['name' => 'Failure', 'queryDefinition' => $definition, 'generatedSql' => $trustedSql]);
    $failed = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 422 && isset($failed['error']), 'Canonical build failure must reject save.');
    \app\services\SqlBuilderService::$fail = false;

    Yii::$app->response->statusCode = 200;
    $editedSql = str_replace('SELECT ii.id', 'SELECT ii.id, il.name', $trustedSql);
    Yii::$app->request = new CanonicalSaveRequest(['name' => 'Edited', 'queryDefinition' => $definition, 'generatedSql' => $editedSql, 'sqlEdited' => true]);
    $edited = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 201 && end(\app\models\SavedQuery::$saved)->generated_sql === $editedSql, 'Safe edited SQL retaining trusted joins must be preserved.');

    $safeFormattedSql = "SELECT item_alias.id, location_alias.name\n"
        . "FROM inventory.item__t AS item_alias\n"
        . "INNER JOIN inventory.location__t AS location_alias\n"
        . "  ON ( location_alias.id= item_alias.effective_location_id )\n"
        . "WHERE item_alias.status = 'Available'\nORDER BY item_alias.id";
    Yii::$app->response->statusCode = 200;
    Yii::$app->request = new CanonicalSaveRequest(['name' => 'Formatted', 'queryDefinition' => $definition, 'generatedSql' => $safeFormattedSql, 'sqlEdited' => true]);
    $formatted = $controller->actionSave();
    expectCanonicalSave(Yii::$app->response->statusCode === 201, 'Alias renames, formatting, and safe select/filter/order edits must be accepted.');

    foreach ([
        ['sql' => str_replace('JOIN inventory.location__t', 'LEFT JOIN inventory.location__t', $trustedSql), 'message' => 'Changing the trusted join type must be rejected.'],
        ['sql' => str_replace('effective_location_id', 'permanent_location_id', $safeFormattedSql), 'message' => 'Changing the trusted relationship endpoint must be rejected.'],
        ['sql' => $trustedSql . "\n, users.users__t blocked", 'message' => 'An implicit-comma extra blocked table must be rejected.'],
        ['sql' => $trustedSql . "\n, inventory.holdings_record__t extra", 'message' => 'Any implicit-comma extra table must be rejected.'],
        ['sql' => str_replace('il.id = ii.effective_location_id', 'il.id = ii.effective_location_id AND il.code = ii.status', $trustedSql), 'message' => 'Compound join predicates must fail closed.'],
    ] as $case) {
        Yii::$app->response->statusCode = 200;
        Yii::$app->request = new CanonicalSaveRequest(['name' => 'Unsafe edit', 'queryDefinition' => $definition, 'generatedSql' => $case['sql'], 'sqlEdited' => true]);
        $unsafeEdit = $controller->actionSave();
        expectCanonicalSave(Yii::$app->response->statusCode === 422 && isset($unsafeEdit['error']), $case['message']);
    }

    fwrite(STDOUT, "FolioQueryController canonical save test passed\n");
}
