<?php

namespace app\services;

use app\models\User;

/** Looks up approved app users without exposing other user fields. */
class AcrlApprovedUserDirectoryService
{
    public function search(string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return [];
        }
        $users = User::find()
            ->select(['id', 'first_name', 'last_name', 'username'])
            ->where(['is_approved' => 1])
            ->andWhere(['or',
                ['like', 'first_name', $query],
                ['like', 'last_name', $query],
                ['like', 'username', $query],
            ])
            ->orderBy(['last_name' => SORT_ASC, 'first_name' => SORT_ASC, 'username' => SORT_ASC])
            ->limit(25)
            ->all();
        return $this->represent($users);
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $users = User::find()
            ->select(['id', 'first_name', 'last_name', 'username'])
            ->where(['is_approved' => 1, 'id' => $ids])
            ->orderBy(['last_name' => SORT_ASC, 'first_name' => SORT_ASC, 'username' => SORT_ASC])
            ->all();
        return $this->represent($users);
    }

    private function represent(array $users): array
    {
        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => (int)$user->id,
                'displayName' => $user->getDisplayName(),
            ];
        }
        return $result;
    }
}
