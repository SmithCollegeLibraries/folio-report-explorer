<?php

namespace app\services;

use app\models\User;

/** Test-round symbol stub. The build round supplies invite persistence and behavior. */
class AcrlInviteService
{
    public static function linkOriginFromRequest(string $hostInfo, bool $isDev): string
    {
    }

    public static function inviteFromAddress(string $linkOrigin): string
    {
    }

    public function __construct($db, AcrlSectionAssignmentRepository $sectionAssignments, AcrlQuestionAssignmentRepository $questionAssignments, AcrlInviteMailer $mailer)
    {
    }

    public function createInvite(int $year, string $email, array $targets, User $admin, string $linkOrigin)
    {
    }

    public function listInvites(int $year)
    {
    }

    public function resendInvite(int $inviteId, User $admin)
    {
    }

    public function revokeInvite(int $inviteId, User $admin)
    {
    }

    public function acceptOnSignIn(User $user, ?string $eppn, ?string $uid): bool
    {
    }
}
