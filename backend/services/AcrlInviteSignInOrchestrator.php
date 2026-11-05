<?php

namespace app\services;

use app\models\User;

/** Test-round symbol stub for the extracted authorize.php sign-in decision. */
class AcrlInviteSignInOrchestrator
{
    public function run(User $user, bool $isNewUser, ?string $eppn, ?string $uid, callable $accept, callable $reload, callable $notifyAdmins, callable $issueTokens, callable $log)
    {
    }
}
