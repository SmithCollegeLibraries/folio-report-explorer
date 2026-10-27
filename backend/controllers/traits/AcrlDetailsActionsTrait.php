<?php

namespace app\controllers\traits;

use Yii;
use app\services\AcrlInstructionsService;
use app\services\AcrlQuestionNotesService;

/** ACRL instruction and question-note actions shared by the catalogue controller. */
trait AcrlDetailsActionsTrait
{
    public function actionSaveInstructions($year)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlInstructionsService())->replace(
                (int)$year,
                $body['blocks'] ?? null,
                $body['links'] ?? null,
                (string)($body['fileName'] ?? ''),
                (string)($body['sha256'] ?? ''),
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionQuestionDetails($year)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        $params = Yii::$app->request->queryParams;
        $sheet = (string)($params['sheet'] ?? 'survey');
        $questionId = (string)($params['question_id'] ?? '');
        $part = (string)($params['part'] ?? '');
        try {
            $instructions = (new AcrlInstructionsService())->forQuestion((int)$year, $sheet, $questionId, $part);
            $notes = (new AcrlQuestionNotesService())->notesForQuestion((int)$year, $sheet, $questionId, $part);
            return [
                'instructions' => $instructions,
                'notes' => $notes,
                'canAddNote' => true,
                'canManageNotes' => $this->isPersistedAdministrator(),
            ];
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionAddQuestionNote($year)
    {
        if (($forbidden = $this->requireAcrlUser()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlQuestionNotesService())->addNote(
                (int)$year,
                (string)($body['sheet'] ?? 'survey'),
                (string)($body['question_id'] ?? ''),
                (string)($body['part'] ?? ''),
                $body['note'] ?? null,
                $body['source'] ?? 'How I collected this',
                $body['origin_year'] ?? (int)$year,
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionEditQuestionNote($id)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlQuestionNotesService())->editNote(
                (int)$id,
                $body['note'] ?? null,
                $body['source'] ?? null,
                $body['origin_year'] ?? null,
                (string)(Yii::$app->user->id ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionDeleteQuestionNote($id)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        if (!(new AcrlQuestionNotesService())->deleteNote((int)$id)) {
            return $this->errorResponse(404, 'The collection note no longer exists.');
        }
        return ['deleted' => true];
    }

    public function actionNotesImportPreview($year)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlQuestionNotesService())->previewImport((int)$year, $body['rows'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }

    public function actionNotesImportConfirm($year)
    {
        if (($forbidden = $this->requireAcrlAdministrator()) !== null) return $forbidden;
        $body = Yii::$app->request->bodyParams;
        try {
            return (new AcrlQuestionNotesService())->confirmImport((int)$year, $body['rows'] ?? null, (string)(Yii::$app->user->id ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(422, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->errorResponse(404, $exception->getMessage());
        }
    }
}
