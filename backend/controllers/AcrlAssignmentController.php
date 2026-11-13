<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;
use app\controllers\traits\AcrlApiBehaviorTrait;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\controllers\traits\AcrlInstitutionRequestTrait;
use app\services\AcrlSectionAssignmentService;
use app\services\AcrlQuestionAssignmentService;

require_once __DIR__ . '/traits/AcrlApiBehaviorTrait.php';
require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';
require_once __DIR__ . '/traits/AcrlInstitutionRequestTrait.php';

/** Admin-only API for ACRL section assignments and approved-user search. */
class AcrlAssignmentController extends Controller
{
    use AcrlApiBehaviorTrait;
    use PersistedAdminAccessTrait;
    use AcrlInstitutionRequestTrait;

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
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        try {
            $assignments = (new AcrlSectionAssignmentService())->listForYear((int)$year, $institution);
            $questions = (new AcrlQuestionAssignmentService())->listForYear((int)$year, $institution);
            $assignments['questions'] = $questions['questions'];
            return $this->withAcrlInstitution($assignments, $institution);
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionSaveAssignments($year, $section)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        if (!isset($body['userIds']) || !is_array($body['userIds'])) {
            return $this->errorResponse(422, 'Select zero or more approved users.');
        }
        try {
            return $this->withAcrlInstitution((new AcrlSectionAssignmentService())->saveSection(
                (int)$year,
                rawurldecode((string)$section),
                $body['userIds'],
                (string)(Yii::$app->user->id ?? ''),
                $institution
            ), $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionSaveQuestionAssignments($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        foreach (['sheet', 'questionId', 'part'] as $field) {
            if (!array_key_exists($field, $body) || !is_string($body[$field])) {
                return $this->errorResponse(422, 'Question sheet, question ID, and part must be strings.');
            }
        }
        if ($body['sheet'] !== 'survey' && $body['sheet'] !== 'trends') {
            return $this->errorResponse(422, 'Sheet must be survey or trends.');
        }
        if ($body['part'] === '_') {
            return $this->errorResponse(422, 'Question part must be an empty string or a question part.');
        }
        if (!array_key_exists('userIds', $body) || !is_array($body['userIds'])) {
            return $this->errorResponse(422, 'Select zero or more approved users.');
        }
        try {
            return $this->withAcrlInstitution((new AcrlQuestionAssignmentService())->saveQuestion(
                (int)$year,
                $body['sheet'],
                $body['questionId'],
                $body['part'],
                $body['userIds'],
                (string)(Yii::$app->user->id ?? ''),
                $institution
            ), $institution);
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
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        try {
            return $this->withAcrlInstitution((new AcrlSectionAssignmentService())->copyFromPrevious((int)$year, (string)(Yii::$app->user->id ?? ''), $institution), $institution);
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
