<?php

namespace yii\web {
    class Controller { public function __construct($id = null, $module = null, $config = []) {} public function behaviors() { return []; } }
    class Response { public const FORMAT_JSON = 'json'; }
}

namespace app\models {
    class ReportTemplate
    {
        public static $report;
        public $id = 38;
        public $slug = 'marc-bibliographic-records-missing-tag';
        public $name = 'MARC records missing tag';
        public $data_source = 'folio';
        public $parameters = [];
        public $bindCalls = 0;
        public $hasIdentifierCapability = true;
        public $executionConfig = ['configured' => true];
        public $compositeConfig;

        public static function findOne($condition) { return self::$report; }
        public static function resolveDefaultMacro($value) { return $value; }
        public function hasAttribute($name) { return in_array($name, ['data_source', 'execution_config'], true); }
        public function getDecodedParameters() { return $this->parameters; }
        public function bindParams($inputs, $sqlTemplate = null) {
            $this->bindCalls++;
            if ($this->slug === 'marc-bibliographic-records-missing-tag') {
                throw new \RuntimeException('The fixed MARC report must be compiled before binding.');
            }
            return ['sql' => 'SELECT ordinary_report', 'params' => [':ordinary' => 'value']];
        }
        public function getExecutionConfig() { return $this->executionConfig; }
        public function hasIdentifierExport() { return $this->hasIdentifierCapability; }
        public function getCompositeConfig() { return $this->compositeConfig; }
    }

    class QueryJob
    {
        public static $created = [];
        public $id = 'job-1';
        public $status = 'pending';
        public $progress_message = 'Queued';
        public $created_at = '2026-08-06 00:00:00';
        public $started_at;
        public $completed_at;
        public $output_mode = 'table';
        public $estimated_rows;
        public $estimated_cost;
        public $metadata;
        public $errors = [];
        public $sql_text;
        public $params;
        public $source;
        public $data_source;
        public $user_id;

        public static function createJob($sql, $params = [], $source = 'builder', $dataSource = 'folio', $metadata = null) {
            $job = new self();
            $job->sql_text = $sql;
            $job->params = $params;
            $job->source = $source;
            $job->data_source = $dataSource;
            $job->metadata = $metadata;
            self::$created[] = $job;
            return $job;
        }
        public function hasAttribute($name) { return in_array($name, ['output_mode', 'estimated_rows', 'estimated_cost', 'metadata'], true); }
        public function save($validate = true) { return true; }
    }

    class SavedQuery {}
    class QueryLog {}
    class AcrlStatistic {}
    class ExpenseAllocation {}
    class User {}
    class DummyIdentity { public function getId() { return 1; } }
}

namespace app\services {
    class GeminiService { public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry'; }
    class SqlBuilderService
    {
        public static $safetyCalls = [];
        public static $policyCalls = [];
        public static $safetyException;
        public static $policyException;
        public static function validateSafety($sql) { self::$safetyCalls[] = $sql; if (self::$safetyException) { throw self::$safetyException; } }
        public static function validateTablePolicy($sql) { self::$policyCalls[] = $sql; if (self::$policyException) { throw self::$policyException; } }
    }
    class DatabaseRetryService {}
    class FolioSchemaService {}
    class SettingsService {}
    class IndexRecommendationService {}
    class Nl2sqlRuntimePreflightService {}
    class PreviousSuccessfulQueryReuseService {}
    class QueryJobCancellationService {}
    class QueryHistoryDeletionService {}
    class ReferenceCacheRefreshService {}
    class ReferenceJsonBundleService {}
    class SqlPreflightService
    {
        public static $nextResult;
        public static $calls = [];
        public static function estimateQueryComplexity($db, string $sql, int $queryTimeoutMs, int $preflightTimeoutMs = 10000, array $params = []) {
            self::$calls[] = compact('sql', 'params');
            return self::$nextResult;
        }
    }
    class CatalogingMarcMissingTagReportService
    {
        public static $buildResult;
        public static $buildException;
        public static $buildCalls = [];
        public static function supports($report) { return $report->slug === 'marc-bibliographic-records-missing-tag'; }
        public static function build($report, array $inputs, $folioDb) {
            self::$buildCalls[] = $inputs;
            if (self::$buildException) { throw self::$buildException; }
            return self::$buildResult;
        }
    }
    class CatalogingMarcFieldFinderService
    {
        public static $buildResult;
        public static $buildException;
        public static $buildCalls = [];
        public static function supports($report) { return $report->slug === 'marc-field-indicator-content-finder'; }
        public static function build($report, array $inputs, $folioDb) {
            self::$buildCalls[] = $inputs;
            if (self::$buildException) { throw self::$buildException; }
            return self::$buildResult;
        }
    }
    class CatalogingReportCompilerService
    {
        public static function supports($report) {
            return CatalogingMarcMissingTagReportService::supports($report)
                || CatalogingMarcFieldFinderService::supports($report);
        }
        public static function build($report, array $inputs, $folioDb) {
            if (CatalogingMarcMissingTagReportService::supports($report)) {
                return CatalogingMarcMissingTagReportService::build($report, $inputs, $folioDb);
            }
            return CatalogingMarcFieldFinderService::build($report, $inputs, $folioDb);
        }
    }
    class ReportExecutionContractService
    {
        public const METADATA_KEY = 'reportExecution';
        public static $contexts = [];
        public static function fromReport($report, array $context) {
            self::$contexts[] = $context;
            if ($report->getExecutionConfig() === null) { return null; }
            return ['exportKind' => $context['exportKind'], 'reportSlug' => $report->slug];
        }
    }
}

namespace Firebase\JWT { class JWT {} }

namespace {
    if (!defined('YII_ENV')) { define('YII_ENV', 'test'); }
    class Yii { public static $app; public static function warning($message, $category = 'application') {} }
    class FakeRequest { public $body = []; public function getBodyParams() { return $this->body; } }
    class FakeResponse { public $statusCode = 200; public $format; }

    function catalogingAssertSame($expected, $actual, string $message): void {
        if ($expected !== $actual) { fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n"); exit(1); }
    }
    function catalogingAssertTrue($actual, string $message): void { catalogingAssertSame(true, (bool)$actual, $message); }
    function catalogingLastJob() { return \app\models\QueryJob::$created[count(\app\models\QueryJob::$created) - 1] ?? null; }
    function validCatalogingParams(): array { return ['locationIds' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222', 'locationBasis' => 'effective_item', 'marcTag' => '856']; }
    function validFinderParams(): array {
        return [
            'locationIds' => '11111111-1111-4111-8111-111111111111',
            'locationBasis' => 'effective_item',
            'marcTag' => '245',
            'occurrenceCondition' => 'has',
            'firstIndicator' => 'any',
            'secondIndicator' => 'any',
            'subfieldCode' => '',
            'contentRule' => 'any',
            'searchValue' => '',
            'caseExact' => 'false',
        ];
    }
    function catalogingReport(bool $identifier = true): \app\models\ReportTemplate {
        $report = new \app\models\ReportTemplate();
        $report->hasIdentifierCapability = $identifier;
        $report->parameters = [
            ['name' => 'locationIds', 'required' => true],
            ['name' => 'locationBasis', 'required' => true],
            ['name' => 'marcTag', 'required' => true],
        ];
        return $report;
    }
    function finderReport(bool $identifier = true): \app\models\ReportTemplate {
        $report = new \app\models\ReportTemplate();
        $report->slug = 'marc-field-indicator-content-finder';
        $report->name = 'MARC Field, Indicator, and Content Finder';
        $report->hasIdentifierCapability = $identifier;
        $report->parameters = [
            ['name' => 'locationIds', 'required' => true],
            ['name' => 'locationBasis', 'required' => true],
            ['name' => 'marcTag', 'required' => true],
            ['name' => 'occurrenceCondition', 'required' => true],
            ['name' => 'firstIndicator', 'required' => false],
            ['name' => 'secondIndicator', 'required' => false],
            ['name' => 'subfieldCode', 'required' => false],
            ['name' => 'contentRule', 'required' => false],
            ['name' => 'searchValue', 'required' => false],
            ['name' => 'caseExact', 'required' => false],
        ];
        return $report;
    }
    function resetCatalogingState(): void {
        \app\models\QueryJob::$created = [];
        \app\services\SqlBuilderService::$safetyCalls = [];
        \app\services\SqlBuilderService::$policyCalls = [];
        \app\services\SqlBuilderService::$safetyException = null;
        \app\services\SqlBuilderService::$policyException = null;
        \app\services\SqlPreflightService::$calls = [];
        \app\services\SqlPreflightService::$nextResult = ['rows' => 10, 'cost' => 100.0];
        \app\services\CatalogingMarcMissingTagReportService::$buildCalls = [];
        \app\services\CatalogingMarcMissingTagReportService::$buildException = null;
        \app\services\CatalogingMarcMissingTagReportService::$buildResult = [
            'sql' => 'SELECT * FROM marctab.mt856 AS marc_tag ORDER BY instance_uuid LIMIT 100001',
            'params' => [':locationIds' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222', ':marcTag' => '856'],
            'marcTag' => '856',
            'location' => ['name' => '2 Locations', 'code' => 'MULTI'],
        ];
        \app\services\CatalogingMarcFieldFinderService::$buildCalls = [];
        \app\services\CatalogingMarcFieldFinderService::$buildException = null;
        \app\services\CatalogingMarcFieldFinderService::$buildResult = [
            'sql' => 'SELECT * FROM marctab.mt245 AS marc_match JOIN marctab.mt245 AS marc_missing ON 1=1 ORDER BY instance_uuid LIMIT 100001',
            'params' => [':locationIds' => '11111111-1111-4111-8111-111111111111', ':locationBasis' => 'effective_item', ':marcTag' => '245', ':occurrenceCondition' => 'has', ':firstIndicator' => 'any', ':secondIndicator' => 'any', ':subfieldCode' => '', ':contentRule' => 'any', ':searchValue' => '', ':caseExact' => 'false'],
            'marcTag' => '245',
            'location' => ['name' => 'Main', 'code' => 'MAIN'],
        ];
        \app\services\ReportExecutionContractService::$contexts = [];
    }
    function runCatalogingReport(array $body, ?\app\models\ReportTemplate $report = null): array {
        resetCatalogingState();
        \app\models\ReportTemplate::$report = $report ?: catalogingReport();
        Yii::$app->request->body = $body;
        Yii::$app->response->statusCode = 200;
        return (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    }
    function runCatalogingReportWithEstimate($rows, $cost, array $body): array {
        resetCatalogingState();
        \app\models\ReportTemplate::$report = catalogingReport();
        \app\services\SqlPreflightService::$nextResult = ['rows' => $rows, 'cost' => $cost];
        Yii::$app->request->body = $body;
        Yii::$app->response->statusCode = 200;
        return (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    }
    function runFinderReport(array $body, ?\app\models\ReportTemplate $report = null): array {
        resetCatalogingState();
        \app\models\ReportTemplate::$report = $report ?: finderReport();
        Yii::$app->request->body = $body;
        Yii::$app->response->statusCode = 200;
        return (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    }

    require_once __DIR__ . '/../controllers/FolioQueryController.php';

    Yii::$app = (object) [
        'request' => new FakeRequest(),
        'response' => new FakeResponse(),
        'folioDb' => (object) [],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
        'params' => ['maxQueryRows' => 1000, 'queryTimeoutMs' => 1800000, 'exportRowThreshold' => 10000, 'exportCostThreshold' => 500000],
    ];

    // A missing compiler branch would call the fixed report's legacy binder and throw.
    $small = runCatalogingReport(['params' => validCatalogingParams(), 'outputMode' => 'table']);
    catalogingAssertSame('file', $small['outputMode'] ?? null, 'Governed fixed reports must always route to file output.');
    catalogingAssertSame('marctab.mt856', preg_match('/FROM\s+(marctab\.mt\d{3})/i', catalogingLastJob()->sql_text, $matches) ? strtolower($matches[1]) : null, 'The compiler-selected MARC tag table must be queued.');
    catalogingAssertSame(1, count(\app\services\SqlBuilderService::$safetyCalls), 'Compiled fixed SQL must receive safety validation.');
    catalogingAssertSame(1, count(\app\services\SqlBuilderService::$policyCalls), 'Compiled fixed SQL must receive table-policy validation.');
    catalogingAssertSame(10, catalogingLastJob()->estimated_rows, 'Fixed report estimates must be saved on the job.');
    catalogingAssertSame(100.0, catalogingLastJob()->estimated_cost, 'Fixed report cost estimates must be saved on the job.');
    catalogingAssertSame([
        'exportKind' => 'worklist',
        'marcTag' => '856',
        'locationName' => '2 Locations',
        'locationCode' => 'MULTI',
    ], \app\services\ReportExecutionContractService::$contexts[0] ?? null, 'Governed metadata must receive compiler-derived export context.');

    $large = runCatalogingReportWithEstimate(10001, 1000.0, ['params' => validCatalogingParams(), 'outputMode' => 'table']);
    catalogingAssertSame('file', $large['outputMode'] ?? null, 'Large fixed reports must route to file output.');

    $identifier = runCatalogingReport(['params' => validCatalogingParams(), 'outputMode' => 'table', 'exportKind' => 'identifier']);
    catalogingAssertSame('file', $identifier['outputMode'] ?? null, 'Identifier exports must always route to file output.');
    catalogingAssertSame('identifier', catalogingLastJob()->metadata['reportExecution']['exportKind'] ?? null, 'Identifier exports must be persisted in governed job metadata.');

    // The newer governed finder must use the same dispatcher and bypass the
    // generic ReportTemplate binder just like the legacy fixed report.
    $finder = runFinderReport(['params' => validFinderParams(), 'outputMode' => 'table']);
    catalogingAssertSame('file', $finder['outputMode'] ?? null, 'The governed MARC finder must always route to file output.');
    catalogingAssertSame(0, \app\models\ReportTemplate::$report->bindCalls, 'Governed finder reports must bypass the generic binder.');
    catalogingAssertSame(1, count(\app\services\CatalogingMarcFieldFinderService::$buildCalls), 'The dispatcher must invoke the finder compiler.');
    catalogingAssertSame(1, count(\app\services\SqlBuilderService::$safetyCalls), 'Finder SQL must receive safety validation.');
    catalogingAssertSame(1, count(\app\services\SqlBuilderService::$policyCalls), 'Finder SQL must receive table-policy validation.');
    catalogingAssertSame(1, count(\app\services\SqlPreflightService::$calls), 'Finder SQL must receive preflight validation.');
    catalogingAssertSame('marc-field-indicator-content-finder', catalogingLastJob()->metadata['reportExecution']['reportSlug'] ?? null, 'Finder execution metadata must be persisted.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = finderReport();
    \app\services\CatalogingMarcFieldFinderService::$buildException = new \app\exceptions\ReportParameterValidationException('marcTag', 'MARC tag must be exactly three ASCII digits from 001 through 999.');
    Yii::$app->request->body = ['params' => validFinderParams()];
    Yii::$app->response->statusCode = 200;
    $fieldError = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Finder parameter errors use HTTP 400.');
    catalogingAssertSame('Report parameters are invalid.', $fieldError['error'] ?? null, 'Finder parameter errors use the stable top-level error.');
    catalogingAssertSame(['marcTag' => 'MARC tag must be exactly three ASCII digits from 001 through 999.'], $fieldError['fieldErrors'] ?? null, 'Finder parameter errors identify the invalid field.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Invalid finder parameters must not create jobs.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = finderReport();
    \app\services\CatalogingMarcFieldFinderService::$buildException = new \InvalidArgumentException('A selected location no longer exists.');
    Yii::$app->request->body = ['params' => validFinderParams()];
    Yii::$app->response->statusCode = 200;
    $missingFinderLocation = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(422, Yii::$app->response->statusCode, 'Finder missing locations must retain the safe integrity status.');
    catalogingAssertSame('A selected location is unavailable. Please update the selection.', $missingFinderLocation['error'] ?? null, 'Finder missing locations must not become field validation errors.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Finder integrity failures must not create jobs.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = finderReport();
    \app\services\CatalogingMarcFieldFinderService::$buildException = new \app\exceptions\ReportParameterValidationException('locationIds', 'At least one location is required.');
    Yii::$app->request->body = ['params' => array_diff_key(validFinderParams(), ['locationIds' => true])];
    Yii::$app->response->statusCode = 200;
    $missingFinderLocationParam = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Omitted governed locations must use HTTP 400 field validation.');
    catalogingAssertSame('Report parameters are invalid.', $missingFinderLocationParam['error'] ?? null, 'Omitted governed locations must use the stable validation error.');
    catalogingAssertSame(['locationIds' => 'At least one location is required.'], $missingFinderLocationParam['fieldErrors'] ?? null, 'Omitted governed locations must identify locationIds.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = finderReport();
    \app\services\CatalogingMarcFieldFinderService::$buildException = new \app\exceptions\ReportParameterValidationException('marcTag', 'MARC tag must be exactly three ASCII digits from 001 through 999.');
    $missingFinderTagParams = validFinderParams();
    unset($missingFinderTagParams['marcTag']);
    Yii::$app->request->body = ['params' => $missingFinderTagParams];
    Yii::$app->response->statusCode = 200;
    $missingFinderTag = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Omitted governed MARC tags must use HTTP 400 field validation.');
    catalogingAssertSame(['marcTag' => 'MARC tag must be exactly three ASCII digits from 001 through 999.'], $missingFinderTag['fieldErrors'] ?? null, 'Omitted governed MARC tags must identify marcTag.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = finderReport();
    \app\services\CatalogingMarcFieldFinderService::$buildException = new \InvalidArgumentException('MARC finder report definition does not match the reviewed seed contract.');
    Yii::$app->request->body = ['params' => validFinderParams()];
    Yii::$app->response->statusCode = 200;
    $invalidFinderDefinition = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(422, Yii::$app->response->statusCode, 'Governed definition failures must be safe integrity responses.');
    catalogingAssertSame('The report definition could not be validated. Please contact an administrator.', $invalidFinderDefinition['error'] ?? null, 'Governed definition failures must not expose raw compiler details.');
    catalogingAssertTrue(strpos((string) ($invalidFinderDefinition['error'] ?? ''), 'reviewed seed contract') === false, 'Governed definition failures must not expose the raw definition detail.');

    $invalidKind = runCatalogingReport(['params' => validCatalogingParams(), 'exportKind' => 'other']);
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Unknown export kinds must be rejected.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Unknown export kinds must not create jobs.');

    $noIdentifier = runCatalogingReport(['params' => validCatalogingParams(), 'exportKind' => 'identifier'], catalogingReport(false));
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Identifier exports require report capability.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Unauthorized identifier exports must not create jobs.');

    foreach ([
        'MARC tag must be exactly three ASCII digits from 001 through 999.',
        'A supported location basis is required.',
        'Every selected location must be a valid UUID.',
    ] as $inputError) {
        resetCatalogingState();
        \app\models\ReportTemplate::$report = catalogingReport();
        $field = $inputError === 'MARC tag must be exactly three ASCII digits from 001 through 999.'
            ? 'marcTag'
            : ($inputError === 'A supported location basis is required.' ? 'locationBasis' : 'locationIds');
        \app\services\CatalogingMarcMissingTagReportService::$buildException = new \app\exceptions\ReportParameterValidationException($field, $inputError);
        Yii::$app->request->body = ['params' => validCatalogingParams()];
        Yii::$app->response->statusCode = 200;
        (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
        catalogingAssertSame(400, Yii::$app->response->statusCode, 'Invalid fixed-report inputs must return 400.');
        catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Invalid fixed-report inputs must not create jobs.');
    }

    resetCatalogingState();
    \app\models\ReportTemplate::$report = catalogingReport();
    \app\services\CatalogingMarcMissingTagReportService::$buildException = new \InvalidArgumentException('Reporting schema is missing the expected MARC tag table marctab.mt856.');
    Yii::$app->request->body = ['params' => validCatalogingParams()];
    Yii::$app->response->statusCode = 200;
    $missingTable = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(422, Yii::$app->response->statusCode, 'Missing MARC tag tables must return a safe integrity status.');
    catalogingAssertSame('The selected MARC tag data is unavailable. Please contact an administrator.', $missingTable['error'] ?? null, 'Missing MARC tag tables must not expose internal schema detail.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = catalogingReport();
    \app\services\CatalogingMarcMissingTagReportService::$buildException = new \InvalidArgumentException('A selected location no longer exists.');
    Yii::$app->request->body = ['params' => validCatalogingParams()];
    Yii::$app->response->statusCode = 200;
    $missingLocation = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(422, Yii::$app->response->statusCode, 'Missing selected locations must return a safe integrity status.');
    catalogingAssertSame('A selected location is unavailable. Please update the selection.', $missingLocation['error'] ?? null, 'Missing selected locations must not expose internal lookup details.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = catalogingReport();
    \app\services\SqlPreflightService::$nextResult = ['error' => 'SQLSTATE[42P01]: relation marctab.mt856 missing'];
    Yii::$app->request->body = ['params' => validCatalogingParams()];
    Yii::$app->response->statusCode = 200;
    $preflight = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(422, Yii::$app->response->statusCode, 'Fixed-report preflight failures must return 422.');
    catalogingAssertSame('Query validation failed before execution.', $preflight['error'] ?? null, 'Fixed-report preflight must not expose database errors.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Preflight failures must not create jobs.');

    resetCatalogingState();
    \app\models\ReportTemplate::$report = catalogingReport();
    \app\services\SqlPreflightService::$nextResult = ['rows' => 1, 'cost' => 9.9e18];
    Yii::$app->request->body = ['params' => validCatalogingParams()];
    (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(1.0e15, catalogingLastJob()->estimated_cost, 'Fixed report cost estimates must use the query-submit overflow cap.');

    resetCatalogingState();
    $ordinary = catalogingReport();
    $ordinary->slug = 'ordinary-report';
    $ordinary->executionConfig = null;
    $ordinary->parameters = [];
    \app\models\ReportTemplate::$report = $ordinary;
    Yii::$app->request->body = ['params' => []];
    $ordinaryResult = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame('table', $ordinaryResult['outputMode'] ?? null, 'Ordinary reports must retain their existing output path.');
    catalogingAssertSame(1, $ordinary->bindCalls, 'Ordinary reports must retain the legacy binder path.');
    catalogingAssertSame(null, catalogingLastJob()->metadata, 'Ordinary reports without execution config must not gain governed metadata.');

    resetCatalogingState();
    $ordinaryRequired = catalogingReport();
    $ordinaryRequired->slug = 'ordinary-required-report';
    $ordinaryRequired->parameters = [['name' => 'requiredValue', 'label' => 'Required value', 'required' => true]];
    \app\models\ReportTemplate::$report = $ordinaryRequired;
    Yii::$app->request->body = ['params' => []];
    $ordinaryMissing = (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertSame(400, Yii::$app->response->statusCode, 'Ordinary reports must retain generic required-parameter validation.');
    catalogingAssertSame('Missing required parameters: Required value', $ordinaryMissing['error'] ?? null, 'Ordinary reports must retain their existing missing-parameter message.');
    catalogingAssertSame(0, count(\app\models\QueryJob::$created), 'Ordinary missing parameters must not create jobs.');

    resetCatalogingState();
    $composite = catalogingReport();
    $composite->slug = 'ordinary-composite-report';
    $composite->data_source = 'composite';
    $composite->executionConfig = null;
    $composite->parameters = [];
    $composite->compositeConfig = ['secondary_sql' => 'SELECT 1'];
    \app\models\ReportTemplate::$report = $composite;
    Yii::$app->request->body = ['params' => []];
    (new \app\controllers\FolioQueryController('folio-query', null))->actionReportRun(38);
    catalogingAssertTrue(isset(catalogingLastJob()->metadata['composite_config']), 'Composite report metadata must be preserved when governed metadata is absent.');

    fwrite(STDOUT, "FolioQueryController cataloging report tests passed\n");
}
