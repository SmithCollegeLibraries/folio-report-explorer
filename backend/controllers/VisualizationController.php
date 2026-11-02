<?php

namespace app\controllers;

/** Action signatures only; no authorization or application behavior in the tests round. */
class VisualizationController extends \yii\web\Controller
{
    public function actionOptions()
    {
        throw new \RuntimeException('not implemented');
    }

    public function actionIndex()
    {
        throw new \RuntimeException('not implemented');
    }

    public function actionHistory()
    {
        throw new \RuntimeException('not implemented');
    }

    public function actionHistoryUpsert()
    {
        throw new \RuntimeException('not implemented');
    }

    public function actionHistoryDelete($id)
    {
        throw new \RuntimeException('not implemented');
    }
}
