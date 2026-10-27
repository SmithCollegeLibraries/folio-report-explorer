<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;
use app\controllers\traits\AcrlApiBehaviorTrait;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\services\AcrlSectionAssignmentService;

require_once __DIR__ . '/traits/AcrlApiBehaviorTrait.php';
require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';

/** Admin-only API for ACRL section assignments and approved-user search. */
class AcrlAssignmentController extends Controller
{
    use AcrlApiBehaviorTrait;
    use PersistedAdminAccessTrait;

    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = $this->acrlApiBehaviors(parent::behaviors());
        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = ['class' => HttpBearerAuth::class, 'except' => ['options']];
            $behaviors['access'] = [
                'class' => AccessControl::class,
                'except' => ['options'],
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function ($rule, $action) {
                        return $this->isPersistedAdministrator();
                    },
                ]],
            ];
        }
        return $behaviors;
    }

    public function beforeAction($action)
    {
        $this->setAcrlJsonResponseFormat();
        return parent::beforeAction($action);
    }

    public function actionSearchUsers()
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        return ['users' => (new AcrlSectionAssignmentService())->searchApprovedUsers((string)Yii::$app->request->get('q', ''))];
    }

    public function actionListAssignments($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return (new AcrlSectionAssignmentService())->listForYear((int)$year);
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionSaveAssignments($year, $section)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        $body = Yii::$app->request->bodyParams;
        if (!isset($body['userIds']) || !is_array($body['userIds'])) {
            return $this->errorResponse(422, 'Select zero or more approved users.');
        }
        try {
            return (new AcrlSectionAssignmentService())->saveSection(
                (int)$year,
                rawurldecode((string)$section),
                $body['userIds'],
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionCopyFromPrevious($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return (new AcrlSectionAssignmentService())->copyFromPrevious((int)$year, (string)(Yii::$app->user->id ?? ''));
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        }
    }

    private function errorResponse(int $status, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $message];
    }
}
