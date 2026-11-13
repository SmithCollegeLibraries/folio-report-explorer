<?php

namespace app\controllers\traits;

use Yii;
use app\services\AcrlEntryService;
use app\services\AcrlAutofillBusyException;
use app\services\AcrlAutofillService;
use app\services\AcrlImportService;
use app\services\AcrlVeaAutofillService;

/** Institution-scoped entry, answer, import, and autofill actions. */
trait AcrlEntryActionsTrait
{
    public function actionEntry($year = null)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        try {
            return $this->withAcrlInstitution(
                (new AcrlEntryService())->entry($year === null ? null : (int)$year, (string)(Yii::$app->user->id ?? ''), $institution),
                $institution
            );
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionAutofill($year, $sheet, $question, $part)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitutionCode($institution)) !== null) return $invalid;
        if ($institution !== 'SC') {
            if (!$this->isPersistedAdministrator()) {
                Yii::$app->response->statusCode = 403;
                return ['reason' => 'institution_not_enabled'];
            }
            return $this->errorResponse(409, 'autofill_not_configured');
        }
        if ((string)$part === '_') $part = '';
        try {
            $weekStart = AcrlVeaAutofillService::validateWeekStart(Yii::$app->request->bodyParams['weekStart'] ?? null);
            set_time_limit(180);
            return $this->withAcrlInstitution($this->createAcrlAutofillService()->run(
                (int)$year,
                rawurldecode((string)$sheet),
                rawurldecode((string)$question),
                rawurldecode((string)$part),
                $weekStart
            ), $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (AcrlAutofillBusyException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();
            if ($code === 'no_doors') return $this->errorResponse(409, 'Choose the gate-count doors in Settings first.');
            if ($code === 'not_configured') return $this->errorResponse(409, 'Vea is not configured.');
            if (in_array($code, ['unreachable', 'auth_failed', 'vea_error'], true)) return $this->errorResponse(502, 'Could not get a gate count from Vea.');
            throw $exception;
        }
    }

    public function actionAutofillMethods()
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        return ['methods' => $this->createAcrlAutofillService()->methods()];
    }

    protected function createAcrlAutofillService()
    {
        return new AcrlAutofillService();
    }

    public function actionSaveAnswer($year)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        try {
            $result = (new AcrlEntryService())->saveAnswer(
                (int)$year,
                (string)($body['sheet'] ?? 'survey'),
                (string)($body['question_id'] ?? ''),
                (string)($body['part'] ?? ''),
                $body['value'] ?? null,
                $body['note'] ?? null,
                isset($body['expected_updated_at']) && $body['expected_updated_at'] !== '' ? (string)$body['expected_updated_at'] : null,
                (string)(Yii::$app->user->id ?? ''),
                $this->isPersistedAdministrator(),
                $institution
            );
            if ($result['conflict']) Yii::$app->response->statusCode = 409;
            return $this->withAcrlInstitution($result, $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            $status = strpos($exception->getMessage(), 'read-only') !== false ? 409 : 404;
            return $this->errorResponse($status, $exception->getMessage());
        }
    }

    public function actionAcceptSuggestions($year, $section)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        if (!isset($body['items']) || !is_array($body['items'])) return $this->errorResponse(422, 'Select one or more suggestions from a section.');
        try {
            $result = (new AcrlEntryService())->acceptSuggestions(
                (int)$year,
                rawurldecode((string)$section),
                $body['items'],
                (string)(Yii::$app->user->id ?? ''),
                $this->isPersistedAdministrator(),
                $institution
            );
            if (!empty($result['conflict'])) Yii::$app->response->statusCode = 409;
            return $this->withAcrlInstitution($result, $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }

    public function actionSectionStatus($year, $section)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        $status = (string)($body['status'] ?? '');
        if ($status === 'open' && ($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        try {
            return $this->withAcrlInstitution((new AcrlEntryService())->setSectionStatus(
                (int)$year,
                rawurldecode((string)$section),
                $status,
                (string)(Yii::$app->user->id ?? ''),
                $this->isPersistedAdministrator(),
                $institution
            ), $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            $code = strpos($exception->getMessage(), 'Only an administrator') !== false ? 403 : 409;
            return $this->errorResponse($code, $exception->getMessage());
        }
    }

    public function actionImportPreview($year)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        try {
            return $this->withAcrlInstitution((new AcrlImportService())->preview(
                (int)$year, $body['rows'] ?? null, $body['sourceLabel'] ?? null, !empty($body['overwrite']), $institution
            ), $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }

    public function actionImportConfirm($year)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        if (($invalid = $this->requestAcrlInstitution($institution)) !== null) return $invalid;
        $body = Yii::$app->request->bodyParams;
        try {
            return $this->withAcrlInstitution((new AcrlImportService())->confirm(
                (int)$year,
                $body['rows'] ?? null,
                $body['sourceLabel'] ?? null,
                !empty($body['overwrite']),
                (string)(Yii::$app->user->id ?? ''),
                $institution
            ), $institution);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(409, $exception->getMessage());
        }
    }
}
