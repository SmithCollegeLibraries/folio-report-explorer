<?php

namespace app\controllers\traits;

use Yii;
use yii\web\Response;
use yii\filters\Cors;
use yii\filters\ContentNegotiator;

/** Shared JSON and cross-origin behavior for ACRL API controllers. */
trait AcrlApiBehaviorTrait
{
    protected function acrlApiBehaviors(array $behaviors): array
    {
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ];
        return $behaviors;
    }

    protected function setAcrlJsonResponseFormat(): void
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
    }
}
