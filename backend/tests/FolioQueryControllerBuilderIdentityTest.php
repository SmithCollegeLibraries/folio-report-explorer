<?php

namespace yii\web {
    class Controller
    {
        public function __construct($id = null, $module = null, $config = []) {}
        public function behaviors() { return []; }
    }
    class Response { public const FORMAT_JSON = 'json'; }
}

namespace app\models {
    class SavedQuery {}
    class QueryLog {}
    class QueryJob {}
    class ReportTemplate {}
    class AcrlStatistic {}
    class ExpenseAllocation {}
    class User {}
    class DummyIdentity {}
}

namespace app\services {
    class FolioSchemaService
    {
        public static function getTables(?array $filter = null): array
        {
            return ['source' => 'legacy', 'tables' => []];
        }

        public static function getMetadata(): array
        {
            return ['source' => 'legacy'];
        }

        public static function getTable(string $table): ?array
        {
            return ['source' => 'legacy', 'table' => $table];
        }

        public static function fuzzyMatch(string $table): ?string
        {
            return $table;
        }

        public static function findShortestPath(string $from, string $to): array
        {
            return [];
        }

        public static function findAllPaths(string $from, string $to, int $maxDepth): array
        {
            return [[]];
        }

        public static function formatPath(array $path, string $from): array
        {
            return ['source' => 'legacy', 'joins' => []];
        }
    }

    class BuilderSchemaService
    {
        public static function getTables(?array $filter = null): array
        {
            return ['source' => 'ldlite', 'tables' => []];
        }

        public static function getTable(string $table): ?array
        {
            return ['source' => 'ldlite', 'table' => $table];
        }

        public static function findShortestPath(string $from, string $to): array
        {
            return [
                'hops' => 1,
                'joins' => [[
                    'relationship_id' => 'inventory.item__t:effective_location_id->inventory.location__t:id',
                    'is_default' => true,
                ]],
            ];
        }

        public static function findAllPaths(string $from, string $to, int $maxDepth): array
        {
            return [self::findShortestPath($from, $to)];
        }
    }

    class SqlBuilderService {}
    class GeminiService
    {
        public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';
        public static function isAiTimeoutMessage($message): bool { return false; }
    }
    class SettingsService {}
    class DatabaseRetryService {}
    class IndexRecommendationService {}
    class Nl2sqlRuntimePreflightService {}
    class PreviousSuccessfulQueryReuseService {}
    class ReferenceCacheRefreshService {}
    class ReferenceJsonBundleService {}
    class SqlPreflightService {}
}

namespace Firebase\JWT {
    class JWT {}
}

namespace {
    if (!defined('YII_ENV')) {
        define('YII_ENV', 'test');
    }

    class Yii
    {
        public static $app;
        public static function warning($message, $category = 'application') {}
    }

    final class FakeRequest
    {
        private $query;

        public function __construct(array $query)
        {
            $this->query = $query;
        }

        public function get($name, $default = null)
        {
            return array_key_exists($name, $this->query) ? $this->query[$name] : $default;
        }
    }

    function assertIdentityRoute($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    require_once __DIR__ . '/../controllers/FolioQueryController.php';

    Yii::$app = (object) [
        'request' => new FakeRequest([]),
        'response' => (object) ['statusCode' => 200, 'format' => null],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);

    Yii::$app->request = new FakeRequest([]);
    $legacySchema = $controller->actionSchema();
    assertIdentityRoute(
        $legacySchema['tables']['source'] === 'legacy',
        'Schema without identity must retain the legacy service.'
    );

    Yii::$app->request = new FakeRequest(['identity' => 'ldlite']);
    $canonicalSchema = $controller->actionSchema();
    assertIdentityRoute(
        $canonicalSchema['tables']['source'] === 'ldlite',
        'Schema with identity=ldlite must use BuilderSchemaService.'
    );

    $canonicalDetail = $controller->actionSchemaDetail('inventory.item__t');
    assertIdentityRoute(
        $canonicalDetail['source'] === 'ldlite',
        'Schema detail with identity=ldlite must use BuilderSchemaService.'
    );

    Yii::$app->request = new FakeRequest([
        'identity' => 'ldlite',
        'from' => 'inventory.item__t',
        'to' => 'inventory.location__t',
    ]);
    $canonicalPath = $controller->actionPath();
    assertIdentityRoute(
        $canonicalPath['path']['joins'][0]['is_default'] === true,
        'Canonical path must return the default relationship.'
    );

    fwrite(STDOUT, "FolioQueryController Builder identity test passed\n");
}
