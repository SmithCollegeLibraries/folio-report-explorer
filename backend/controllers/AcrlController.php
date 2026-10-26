<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\services\AcrlCatalogueService;

require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';

/** Admin-only API for the ACRL question catalogue and annual review. */
class AcrlController extends Controller
{
    use PersistedAdminAccessTrait;

    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        $behaviors['contentNegotiator'] = [
            'class' => \yii\filters\ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ];

        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = [
                'class' => \yii\filters\auth\HttpBearerAuth::class,
                'except' => ['options'],
            ];
            $actions = ['years', 'upload', 'review', 'save-links', 'publish'];
            $behaviors['access'] = [
                'class' => \yii\filters\AccessControl::class,
                'except' => ['options'],
                'rules' => [
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
        Yii::$app->response->format = Response::FORMAT_JSON;
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

    private function errorResponse(int $status, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $message];
    }
}
