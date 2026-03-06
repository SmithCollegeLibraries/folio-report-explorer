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
    public function getId() { return 1; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
    public function isAdmin(): bool { return true; }

    /**
     * No-op stub — dev mode has no real session to revoke.
     */
    public function revokeRefreshToken(): void {}

    /**
     * Returns an empty identity payload for dev mode.
     *
     * @param array $fields
     * @param array $expand
     * @param bool  $recursive
     * @return array
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id'          => 1,
            'username'    => 'dev',
            'displayName' => 'Dev User',
            'role'        => 'admin',
            'isApproved'  => true,
        ];
    }
}
