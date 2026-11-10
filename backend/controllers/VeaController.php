<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\auth\HttpBearerAuth;
use yii\web\Controller;
use yii\web\Response;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\models\User;
use app\services\VeaGateZoneService;

require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';

/** Admin settings and door-selection API for SenSource Vea. */
class VeaController extends Controller
{
    use PersistedAdminAccessTrait;

    public $enableCsrfValidation = false;

    private $gateZoneService;

    public function __construct($id, $module, $config = [], ?VeaGateZoneService $gateZoneService = null)
    {
        parent::__construct($id, $module, $config);
        $this->gateZoneService = $gateZoneService;
    }

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

    public function actionStatus(): array
    {
        if (($denied = $this->requireVeaAdministrator()) !== null) return $denied;
        return $this->gateZoneService()->status();
    }

    public function actionTest(): array
    {
        if (($denied = $this->requireVeaAdministrator()) !== null) return $denied;
        try {
            return $this->gateZoneService()->test();
        } catch (\Throwable $error) {
            Yii::$app->response->statusCode = 200;
            return ['ok' => false, 'error' => $this->safeError($error)];
        }
    }

    public function actionZones(): array
    {
        if (($denied = $this->requireVeaAdministrator()) !== null) return $denied;
        try {
            return $this->gateZoneService()->zones();
        } catch (\Throwable $error) {
            $code = $this->safeError($error);
            Yii::$app->response->statusCode = $code === 'not_configured' ? 409 : 502;
            return ['error' => $code];
        }
    }

    public function actionGateZones(): array
    {
        if (($denied = $this->requireVeaAdministrator()) !== null) return $denied;
        $payload = json_decode(Yii::$app->request->getRawBody());
        try {
            $result = $this->gateZoneService()->save($payload);
        } catch (\Throwable $error) {
            $result = ['error' => $this->safeError($error)];
        }

        if (isset($result['error'])) {
            $code = $this->safeErrorCode($result['error']);
            Yii::$app->response->statusCode = $this->saveErrorStatus($code);
            return ['error' => $code];
        }
        if (!isset($result['selectedZoneIds']) || !is_array($result['selectedZoneIds'])) {
            Yii::$app->response->statusCode = 502;
            return ['error' => 'vea_error'];
        }
        return ['selectedZoneIds' => $result['selectedZoneIds']];
    }

    private function gateZoneService(): VeaGateZoneService
    {
        if ($this->gateZoneService === null) {
            $this->gateZoneService = new VeaGateZoneService();
        }
        return $this->gateZoneService;
    }

    private function isPersistedApprovedUser(): bool
    {
        $identity = Yii::$app->user->identity;
        return $identity instanceof User && User::find()->where([
            'id' => (int)$identity->getId(), 'is_approved' => 1,
        ])->exists();
    }

    private function requireVeaAdministrator(): ?array
    {
        if (YII_ENV === 'dev') return null;
        return $this->requireAdministrator();
    }

    private function safeError(\Throwable $error): string
    {
        return $this->safeErrorCode($error->getMessage());
    }

    private function safeErrorCode($code): string
    {
        $codes = ['not_configured', 'unreachable', 'auth_failed', 'vea_error', 'invalid_input', 'unknown_zone', 'not_selectable', 'save_failed'];
        return is_string($code) && in_array($code, $codes, true) ? $code : 'vea_error';
    }

    private function saveErrorStatus(string $code): int
    {
        if (in_array($code, ['invalid_input', 'unknown_zone', 'not_selectable'], true)) return 422;
        if ($code === 'save_failed') return 500;
        return 502;
    }
}
