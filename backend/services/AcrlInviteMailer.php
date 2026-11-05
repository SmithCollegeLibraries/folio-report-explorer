<?php

namespace app\services;

/** Test-round symbol stub. The build round supplies mail validation and delivery. */
class AcrlInviteMailer
{
    public function __construct(?callable $sender = null)
    {
    }

    public function send(int $inviteId, string $recipient, string $subject, string $body, string $from, string $replyTo): bool
    {
    }
}
