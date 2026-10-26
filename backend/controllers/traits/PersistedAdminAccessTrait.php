<?php

namespace app\controllers\traits;

use Yii;
use app\models\User;

/** Shared persisted-administrator check for admin API controllers. */
trait PersistedAdminAccessTrait
{
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
