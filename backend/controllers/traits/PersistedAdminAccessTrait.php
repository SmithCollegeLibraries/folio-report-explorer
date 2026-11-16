<?php

namespace app\controllers\traits;

use Yii;
use app\models\User;

/** Shared persisted-administrator check for admin API controllers. */
trait PersistedAdminAccessTrait
{
    protected function requireAcrlUser(): ?array
    {
        $user = Yii::$app->user;
        if ($user->isGuest) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Unauthorized'];
        }
        $identity = $user->identity;
        if (!($identity instanceof User) || !User::find()->where([
            'id' => (int)$identity->getId(),
            'is_approved' => 1,
        ])->exists()) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }
        return null;
    }

    protected function requireAdministrator(): ?array
    {
        if ($this->isPersistedAdministrator()) {
            return null;
        }

        Yii::$app->response->statusCode = 403;
        return ['error' => 'Forbidden'];
    }

    protected function isPersistedAdministrator(): bool
    {
        $user = Yii::$app->user;
        $identity = $user->identity;
        if ($user->isGuest || !($identity instanceof User)) {
            return false;
        }

        return User::find()->where([
            'id' => (int)$identity->getId(),
            'role' => 'admin',
            'is_approved' => 1,
        ])->exists();
    }
}
