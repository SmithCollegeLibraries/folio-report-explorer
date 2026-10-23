<?php

namespace app\services;

use Yii;
use app\models\User;

final class AllocationEditorAccessService
{
    public static function rules(): array
    {
        $allocationActions = [
            'local-alloc-list', 'local-alloc-years', 'local-alloc-upsert',
            'local-alloc-delete', 'local-alloc-copy-year',
        ];

        return [
            [
                'allow' => true,
                'actions' => $allocationActions,
                'roles' => ['@'],
                'matchCallback' => function ($rule, $action) {
                    $identity = Yii::$app->user->identity;
                    return $identity instanceof User && $identity->canEditAllocationsFor();
                },
            ],
            [
                'allow' => false,
                'actions' => $allocationActions,
                'roles' => ['@'],
                'denyCallback' => function ($rule, $action) {
                    Yii::$app->response->statusCode = 403;
                    Yii::$app->response->data = ['error' => 'You are not permitted to edit allocations.'];
                },
            ],
            [
                'allow' => false,
                'actions' => ['user-allocation-colleges'],
                'roles' => ['@'],
                'denyCallback' => function ($rule, $action) {
                    Yii::$app->response->statusCode = 403;
                    Yii::$app->response->data = ['error' => 'Forbidden'];
                },
            ],
            [
                'allow' => false,
                'actions' => [
                    'local-acrl-list', 'local-acrl-years', 'local-acrl-create',
                    'local-acrl-update', 'local-acrl-delete', 'local-acrl-copy-year',
                ],
                'roles' => ['@'],
                'denyCallback' => function ($rule, $action) {
                    Yii::$app->response->statusCode = 403;
                    Yii::$app->response->data = ['error' => 'Forbidden'];
                },
            ],
        ];
    }

    public static function requireCollege($prefix): void
    {
        if (YII_ENV === 'dev') {
            return;
        }

        $identity = Yii::$app->user->identity;
        if ($identity instanceof User && $identity->canEditAllocationsFor($prefix === null ? '' : $prefix)) {
            return;
        }

        throw new \yii\web\ForbiddenHttpException('You are not permitted to edit allocations for this college.');
    }
}
