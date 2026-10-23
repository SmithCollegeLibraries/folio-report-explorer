<?php

namespace app\services;

use app\models\User;

require_once __DIR__ . '/ExpenseClassScopeService.php';

final class UserAllocationCollegesService
{
    public static function update($userId, array $body): array
    {
        if (!isset($body['allocationColleges']) || !is_array($body['allocationColleges'])) {
            return self::result(['error' => 'allocationColleges must be an array.'], 400);
        }

        try {
            $colleges = ExpenseClassScopeService::normalizeAllocationColleges($body['allocationColleges']);
        } catch (\InvalidArgumentException $error) {
            return self::result(['error' => 'Choose allocation colleges from the listed colleges.'], 422);
        }

        $user = User::findOne($userId);
        if (!$user) {
            return self::result(['error' => 'User not found'], 404);
        }

        $user->allocation_colleges = empty($colleges) ? null : implode(',', $colleges);
        if (!$user->save()) {
            return self::result(['error' => 'Allocation colleges could not be saved.', 'details' => $user->errors], 422);
        }

        return self::result($user->toArray());
    }

    private static function result(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
