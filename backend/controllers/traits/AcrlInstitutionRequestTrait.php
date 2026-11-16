<?php

namespace app\controllers\traits;

use Yii;
use app\services\AcrlInstitutionAccessService;
use app\services\AcrlInstitutionMembershipService;

require_once __DIR__ . '/../../services/AcrlInstitutionAccessService.php';

/** Validates institution query parameters and marks scoped ACRL responses. */
trait AcrlInstitutionRequestTrait
{
    protected function requestAcrlInstitution(&$institution): ?array
    {
        if (($invalid = $this->requestAcrlInstitutionCode($institution)) !== null) {
            return $invalid;
        }
        if (!in_array($institution, AcrlInstitutionMembershipService::ENABLED_INSTITUTIONS, true)) {
            Yii::$app->response->statusCode = 403;
            return ['reason' => 'institution_not_enabled'];
        }
        $userId = (int)(Yii::$app->user->id ?? 0);
        if (!$userId || !(new AcrlInstitutionMembershipService())->isMember($userId, $institution)) {
            Yii::$app->response->statusCode = 403;
            return ['reason' => 'not_a_member'];
        }
        return null;
    }

    protected function requireInstitutionAdministrator(string $institution): ?array
    {
        if (!(new AcrlInstitutionMembershipService())->canManage((int)(Yii::$app->user->id ?? 0), $institution)) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }
        return null;
    }

    protected function requestAcrlInstitutionCode(&$institution): ?array
    {
        try {
            $request = Yii::$app->request;
            $institution = (new AcrlInstitutionAccessService())->requireCode(
                $request->getQueryString(),
                $request->queryParams
            );
            return null;
        } catch (\DomainException $exception) {
            if ((int)$exception->getCode() === 403) {
                Yii::$app->response->statusCode = 403;
                return ['reason' => 'institution_not_enabled'];
            }
            Yii::$app->response->statusCode = 400;
            return ['error' => 'A single supported institution query parameter is required.'];
        }
    }

    protected function withAcrlInstitution(array $response, string $institution): array
    {
        return array_merge(['institution' => $institution], $response);
    }
}
