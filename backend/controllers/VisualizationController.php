<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\auth\HttpBearerAuth;
use yii\web\Controller;
use yii\web\Response;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\models\User;
use app\services\DevIdentityProvider;
use app\services\VisualizationDataService;
use app\services\VisualizationHistoryService;

require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';

/** Authenticated unit-scoped visualization data and administrator history API. */
final class VisualizationController extends Controller
{
    use PersistedAdminAccessTrait;

    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = ['class' => HttpBearerAuth::class];
            $behaviors['access'] = [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () { return $this->isPersistedApprovedUser(); },
                ], [
                    'allow' => false,
                    'denyCallback' => function () {
                        Yii::$app->response->statusCode = Yii::$app->user->isGuest ? 401 : 403;
                    },
                ]],
            ];
        }
        return $behaviors;
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionOptions(): array
    {
        if (($denied = $this->requireApprovedUser()) !== null) return $denied;
        return $this->dataService()->options();
    }

    public function actionIndex(): array
    {
        if (($denied = $this->requireApprovedUser()) !== null) return $denied;
        $rawUnitId = Yii::$app->request->get('acqUnitId', '');
        if (!is_string($rawUnitId) || preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $rawUnitId) !== 1) {
            return $this->error(400, 'Choose a valid acquisitions unit id.');
        }
        $unitId = $rawUnitId;
        $rawYears = Yii::$app->request->get('years', 10);
        $materialYear = Yii::$app->request->get('materialFiscalYearEnd');
        if (!(is_int($rawYears) || (is_string($rawYears) && preg_match('/^\d+$/', $rawYears)))) {
            return $this->error(400, 'Choose 5, 10, 15 or 20 years.');
        }
        try {
            return $this->dataService()->get($unitId, (int)$rawYears, $materialYear);
        } catch (\InvalidArgumentException $error) {
            return $this->error(400, $error->getMessage());
        }
    }

    public function actionHistory(): array
    {
        if (($denied = $this->requireVisualizationAdministrator()) !== null) return $denied;
        $code = trim((string)Yii::$app->request->get('acqUnitCode', ''));
        foreach ($this->dataService()->options()['units'] as $unit) {
            if ($unit['code'] === $code) return $this->historyService([$code])->list($code);
        }
        return $this->error(400, 'Choose an existing acquisitions unit.');
    }

    public function actionHistoryUpsert(): array
    {
        if (($denied = $this->requireVisualizationAdministrator()) !== null) return $denied;
        $options = $this->dataService()->options();
        $codes = array_column($options['units'], 'code');
        $result = $this->historyService($codes)->upsert(Yii::$app->request->bodyParams, $this->currentActorId());
        if (isset($result['errors'])) return $this->error(422, $result);
        Yii::$app->response->statusCode = 200;
        return $result;
    }

    public function actionHistoryDelete($id): array
    {
        if (($denied = $this->requireVisualizationAdministrator()) !== null) return $denied;
        $result = $this->historyService([])->delete((int)$id, $this->currentActorId());
        if (isset($result['errors'])) return $this->error(422, $result);
        return $result;
    }

    private function dataService(): VisualizationDataService
    {
        return new VisualizationDataService(Yii::$app->folioDb, Yii::$app->db, Yii::$app->cache);
    }

    private function historyService(array $unitCodes): VisualizationHistoryService
    {
        return new VisualizationHistoryService(Yii::$app->db, Yii::$app->cache, $unitCodes);
    }

    private function isPersistedApprovedUser(): bool
    {
        $identity = Yii::$app->user->identity;
        return $identity instanceof User && User::find()->where([
            'id' => (int)$identity->getId(), 'is_approved' => 1,
        ])->exists();
    }

    private function requireApprovedUser(): ?array
    {
        if (YII_ENV === 'dev') return null;
        if ($this->isPersistedApprovedUser()) return null;
        Yii::$app->response->statusCode = Yii::$app->user->isGuest ? 401 : 403;
        return ['error' => Yii::$app->user->isGuest ? 'Unauthorized' : 'Forbidden'];
    }

    private function requireVisualizationAdministrator(): ?array
    {
        if (YII_ENV === 'dev') return null;
        return $this->requireAdministrator();
    }

    private function currentActorId(): int
    {
        if (YII_ENV === 'dev') return DevIdentityProvider::id();
        return (int)Yii::$app->user->id;
    }

    private function error(int $status, $body): array
    {
        Yii::$app->response->statusCode = $status;
        return is_array($body) ? $body : ['error' => $body];
    }
}
