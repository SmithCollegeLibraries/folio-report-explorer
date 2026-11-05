<?php

namespace app\services;

use app\models\User;

/** Runs the invite decision before the established sign-in notification and approval checks. */
class AcrlInviteSignInOrchestrator
{
    public function run(User $user, bool $isNewUser, ?string $eppn, ?string $uid, callable $accept, callable $reload, callable $notifyAdmins, callable $issueTokens, callable $log)
    {
        $accepted = false;
        try {
            $accepted = (bool)call_user_func($accept, $user, $eppn, $uid);
        } catch (\Throwable $error) {
            call_user_func($log, 'ACRL_INVITE_ACCEPT_FAILED');
        }

        $user = call_user_func($reload, $user);
        if ($isNewUser && !$accepted && $user && !$user->is_approved) {
            call_user_func($notifyAdmins, $user);
        }

        if (!$user || !$user->is_approved) {
            return ['redirect' => '/auth/pending', 'tokens' => null];
        }

        return [
            'redirect' => '/',
            'tokens' => call_user_func($issueTokens, $user),
        ];
    }
}
