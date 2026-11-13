<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\controllers\traits\AcrlApiBehaviorTrait;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\controllers\traits\AcrlDetailsActionsTrait;
use app\controllers\traits\AcrlEntryActionsTrait;
use app\controllers\traits\AcrlInstitutionRequestTrait;
use app\services\AcrlCatalogueService;
use app\services\AcrlThresholdService;
use app\models\User;

require_once __DIR__ . '/traits/AcrlApiBehaviorTrait.php';
require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';
require_once __DIR__ . '/traits/AcrlDetailsActionsTrait.php';
require_once __DIR__ . '/traits/AcrlEntryActionsTrait.php';
require_once __DIR__ . '/traits/AcrlInstitutionRequestTrait.php';

/** Admin-only API for the ACRL question catalogue and annual review. */
class AcrlController extends Controller
{
    use AcrlApiBehaviorTrait;
    use PersistedAdminAccessTrait;
    use AcrlDetailsActionsTrait;
    use AcrlEntryActionsTrait;
    use AcrlInstitutionRequestTrait;

    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = $this->acrlApiBehaviors(parent::behaviors());

        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = [
                'class' => \yii\filters\auth\HttpBearerAuth::class,
                'except' => ['options'],
            ];
            $userActions = ['entry', 'autofill', 'autofill-methods', 'save-answer', 'section-status', 'accept-suggestions', 'question-details', 'add-question-note'];
            $actions = ['years', 'upload', 'review', 'save-links', 'publish', 'threshold', 'save-threshold', 'import-preview', 'import-confirm', 'save-instructions', 'notes-import-preview', 'notes-import-confirm', 'edit-question-note', 'delete-question-note'];
            $behaviors['access'] = [
                'class' => \yii\filters\AccessControl::class,
                'except' => ['options'],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => $userActions,
                        'roles' => ['@'],
                    ],
                    [
                        'allow' => true,
                        'actions' => $actions,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return $this->isPersistedAdministrator();
                        },
                    ],
                    [
                        'allow' => false,
                        'actions' => $actions,
                        'roles' => ['@'],
                        'denyCallback' => function ($rule, $action) {
                            Yii::$app->response->statusCode = 403;
                            Yii::$app->response->data = ['error' => 'Forbidden'];
                        },
                    ],
                ],
            ];
        }

        return $behaviors;
    }

    public function beforeAction($action)
    {
        $this->setAcrlJsonResponseFormat();
        return parent::beforeAction($action);
    }

    public function actionYears()
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        return (new AcrlCatalogueService())->listYears();
    }

    public function actionUpload()
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlCatalogueService())->importWorksheet(
                $body['surveyYear'] ?? null,
                $body['fileName'] ?? null,
                $body['sha256'] ?? null,
                $body['items'] ?? null,
                !empty($body['replacePublished']),
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }

    public function actionReview($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return (new AcrlCatalogueService())->getReview((int)$year);
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionSaveLinks($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlCatalogueService())->saveLinks(
                (int)$year,
                $body['links'] ?? null,
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }

    public function actionPublish($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return (new AcrlCatalogueService())->publishYear((int)$year, (string)(Yii::$app->user->id ?? ''));
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }

    public function actionThreshold()
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) {
            return $forbidden;
        }
        return ['thresholdPercent' => (new AcrlThresholdService())->get()];
    }

    public function actionSaveThreshold()
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) {
            return $forbidden;
        }
        $body = Yii::$app->request->bodyParams;
        try {
            return ['thresholdPercent' => (new AcrlThresholdService())->save($body['thresholdPercent'] ?? null)];
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        }
    }

    private function requireAcrlUser(): ?array
    {
        $user = Yii::$app->user;
        if ($user->isGuest) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Unauthorized'];
        }
        $identity = $user->identity;
        if (!($identity instanceof User) || !User::find()->where([
            'id' => (int)$identity->getId(),
            'is_approved' => 1,
        ])->exists()) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }
        return null;
    }

    private function requireAcrlAdministrator(): ?array
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) {
            return $forbidden;
        }
        if ($this->isPersistedAdministrator()) {
            return null;
        }
        Yii::$app->response->statusCode = 403;
        return ['error' => 'Forbidden'];
    }

    private function errorResponse(int $status, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $message];
    }
}
