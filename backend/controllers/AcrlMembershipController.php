<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;
use app\controllers\traits\AcrlApiBehaviorTrait;
use app\controllers\traits\PersistedAdminAccessTrait;
use app\services\AcrlInstitutionMembershipService;

require_once __DIR__ . '/traits/AcrlApiBehaviorTrait.php';
require_once __DIR__ . '/traits/PersistedAdminAccessTrait.php';
require_once __DIR__ . '/../services/AcrlInstitutionMembershipService.php';

/** Manages institution memberships without changing app approval or role. */
class AcrlMembershipController extends Controller
{
    use AcrlApiBehaviorTrait;
    use PersistedAdminAccessTrait;

    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = $this->acrlApiBehaviors(parent::behaviors());
        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = ['class' => HttpBearerAuth::class, 'except' => ['options']];
            $behaviors['access'] = ['class' => AccessControl::class, 'except' => ['options'], 'rules' => [[
                'allow' => true,
                'roles' => ['@'],
            ]]];
        }
        return $behaviors;
    }

    public function beforeAction($action)
    {
        $this->setAcrlJsonResponseFormat();
        return parent::beforeAction($action);
    }

    public function actionInstitutions()
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        return (new AcrlInstitutionMembershipService())->institutionsForUser((int)Yii::$app->user->id);
    }

    public function actionMembers($code)
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        if (($denied = $this->requireManager($code)) !== null) return $denied;
        return ['members' => $this->service()->members($code)];
    }

    public function actionCandidates($code)
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        if (($denied = $this->requireManager($code)) !== null) return $denied;
        $query = (string)Yii::$app->request->get('q', '');
        return ['users' => strlen(trim($query)) < 2 ? [] : $this->service()->candidates((int)Yii::$app->user->id, $code, $query)];
    }

    public function actionAddMember($code)
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        if (($denied = $this->requireManager($code)) !== null) return $denied;
        $body = Yii::$app->request->bodyParams;
        if (!isset($body['userId']) || !is_numeric($body['userId']) || (int)$body['userId'] < 1
            || !isset($body['role']) || !is_string($body['role'])) return $this->error(422, 'A user ID and role are required.');
        try {
            $this->service()->add((int)Yii::$app->user->id, $code, (int)$body['userId'], $body['role']);
            return ['added' => true, 'institution' => $code];
        } catch (\InvalidArgumentException $error) {
            return $this->error(422, $error->getMessage());
        } catch (\DomainException $error) {
            return $this->error(403, $error->getMessage());
        }
    }

    public function actionUpdateMember($code, $userId)
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        if (($denied = $this->requireManager($code)) !== null) return $denied;
        $role = Yii::$app->request->bodyParams['role'] ?? null;
        if (!is_string($role)) return $this->error(422, 'A member role is required.');
        try {
            $this->service()->update((int)Yii::$app->user->id, $code, (int)$userId, $role);
            return ['updated' => true, 'institution' => $code];
        } catch (\InvalidArgumentException $error) {
            return $this->error(422, $error->getMessage());
        } catch (\DomainException $error) {
            return $this->error(404, $error->getMessage());
        }
    }

    public function actionRemoveMember($code, $userId)
    {
        if (($denied = $this->requireAcrlUser()) !== null) return $denied;
        if (($denied = $this->requireManager($code)) !== null) return $denied;
        try {
            $this->service()->remove((int)Yii::$app->user->id, $code, (int)$userId);
            return ['removed' => true, 'institution' => $code];
        } catch (\DomainException $error) {
            return $this->error(404, $error->getMessage());
        }
    }

    private function requireManager(string $code): ?array
    {
        if (!array_key_exists($code, AcrlInstitutionMembershipService::INSTITUTIONS)) {
            return $this->error(400, 'The institution code is invalid.');
        }
        if (!$this->service()->canManage((int)Yii::$app->user->id, $code)) {
            return $this->error(403, 'Forbidden');
        }
        return null;
    }

    private function service(): AcrlInstitutionMembershipService
    {
        return new AcrlInstitutionMembershipService(Yii::$app->db);
    }

    private function error(int $status, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $message];
    }
}
