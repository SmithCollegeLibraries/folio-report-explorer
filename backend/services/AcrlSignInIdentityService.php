<?php

namespace app\services;

use app\models\User;

/** Finds or creates the identity selected by Shibboleth sign-in attributes. */
class AcrlSignInIdentityService
{
    public function findOrCreate(?string $fcIdNumber, ?string $uid, ?string $eppn, ?string $firstName, ?string $lastName, ?string $affiliation): ?array
    {
        $fcIdNumber = trim((string)$fcIdNumber);
        $uid = trim((string)$uid);
        $eppn = trim((string)$eppn);
        $smithId = $fcIdNumber;
        $user = null;

        if ($smithId !== '') {
            $user = User::findOne(['smith_id' => $smithId]);
        } else {
            if ($uid === '' || $eppn === '') {
                return null;
            }
            $smithId = $uid;
            $user = User::findOne(['smith_id' => $smithId]);
            if ($user && !$this->legacyIdentityMatches($user, $eppn)) {
                return null;
            }
            if (!$user) {
                return null;
            }
        }

        $isNewUser = !$user;
        if ($isNewUser) {
            $user = new User();
            $user->smith_id = $smithId;
            $user->username = $uid ?: $smithId;
            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->affiliation = $affiliation;
            $user->email = $eppn;
            if (User::find()->count() == 0) {
                $user->role = 'admin';
                $user->is_approved = 1;
            }
            if (!$user->save()) {
                return ['user' => $user, 'isNewUser' => true, 'saved' => false];
            }
        } else {
            $changed = false;
            foreach ([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'affiliation' => $affiliation,
                'email' => $eppn,
            ] as $attribute => $value) {
                if ($value && $user->$attribute !== $value) {
                    $user->$attribute = $value;
                    $changed = true;
                }
            }
            if ($changed && !$user->save(false)) {
                return ['user' => $user, 'isNewUser' => false, 'saved' => false];
            }
        }

        return ['user' => $user, 'isNewUser' => $isNewUser, 'saved' => true];
    }

    private function legacyIdentityMatches(User $user, string $eppn): bool
    {
        if ($user->email !== null) {
            return strtolower(trim((string)$user->email)) === strtolower(trim($eppn));
        }
        return strtolower(substr(strrchr(strtolower(trim($eppn)), '@') ?: '', 1)) === 'smith.edu';
    }
}
