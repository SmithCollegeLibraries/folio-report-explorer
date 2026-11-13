<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;
use app\controllers\traits\AcrlApiBehaviorTrait;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\controllers\traits\AcrlInstitutionRequestTrait;
use app\services\AcrlInviteConflictException;
use app\services\AcrlInviteMailer;
use app\services\AcrlInviteService;
use app\services\AcrlQuestionAssignmentRepository;
use app\services\AcrlSectionAssignmentRepository;

require_once __DIR__ . '/traits/AcrlApiBehaviorTrait.php';
require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';
require_once __DIR__ . '/traits/AcrlInstitutionRequestTrait.php';
require_once __DIR__ . '/../services/AcrlInviteService.php';
require_once __DIR__ . '/../services/AcrlInviteMailer.php';
require_once __DIR__ . '/../services/AcrlSectionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentRepository.php';

/** Admin-only API for ACRL invitations. */
class AcrlInviteController extends Controller
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

    public function actionCreate($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';
        $targets = isset($body['targets']) && is_array($body['targets']) ? $body['targets'] : [];
        try {
            $service = $this->service();
            $email = $service->validateCreateBasics($email, $targets, Yii::$app->user->identity);
            $hostInfo = Yii::$app->request->hostInfo;
            if (!is_string($hostInfo) || $hostInfo === '') {
                throw new \InvalidArgumentException('Request host is invalid.');
            }
            $origin = AcrlInviteService::linkOriginFromRequest($hostInfo, YII_ENV === 'dev');
            return $this->withAcrlInstitution($service->createInvite((int)$year, $email, $targets, Yii::$app->user->identity, $origin, $institution), $institution);
        } catch (AcrlInviteConflictException $exception) {
            Yii::$app->response->statusCode = 409;
            $response = ['error' => $exception->getMessage()];
            if ($exception->user !== null) {
                $response['user'] = $exception->user;
            }
            return $response;
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse($exception->getCode() === 403 ? 403 : ($exception->getCode() === 409 ? 409 : 404), $exception->getMessage());
        }
    }

    public function actionList($year)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        try {
            return $this->withAcrlInstitution($this->service()->listInvites((int)$year, $institution), $institution);
        } catch (\DomainException $exception) {
            return $this->errorResponse($exception->getCode() === 403 ? 403 : 404, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->errorResponse(500, 'Invites could not be loaded.');
        }
    }

    public function actionResend($id)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return $this->withAcrlInstitution($this->service()->resendInvite((int)$id, Yii::$app->user->identity), 'SC');
        } catch (AcrlInviteConflictException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->errorResponse(500, 'Invite could not be resent.');
        }
    }

    public function actionRevoke($id)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }
        try {
            return $this->withAcrlInstitution($this->service()->revokeInvite((int)$id, Yii::$app->user->identity), 'SC');
        } catch (AcrlInviteConflictException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->errorResponse(500, 'Invite could not be revoked.');
        }
    }

    private function service(): AcrlInviteService
    {
        $db = Yii::$app->db;
        return new AcrlInviteService(
            $db,
            new AcrlSectionAssignmentRepository($db),
            new AcrlQuestionAssignmentRepository($db),
            new AcrlInviteMailer()
        );
    }

    private function errorResponse(int $status, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $message];
    }
}
