<?php

namespace app\models;

use yii\web\IdentityInterface;

/**
 * Dummy identity class for REST API (no auth).
 */
class DummyIdentity implements IdentityInterface
{
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return null; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
}
