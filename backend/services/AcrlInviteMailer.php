<?php

namespace app\services;

use Yii;

/** Sends invite mail without putting personal data in application logs. */
class AcrlInviteMailer
{
    private $sender;

    public function __construct(?callable $sender = null)
    {
        $this->sender = $sender;
    }

    public function send(int $inviteId, string $recipient, string $subject, string $body, string $from, string $replyTo): bool
    {
        if (!$this->validAddress($recipient) || !$this->validHeader($subject) || !$this->validAddress($from)
            || !$this->validAddress($replyTo)) {
            $this->logOutcome($inviteId, 'invalid');
            return false;
        }

        try {
            if ($this->sender !== null) {
                $sent = call_user_func($this->sender, $recipient, $subject, $body, $from, $replyTo);
            } else {
                $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $replyTo;
                $sent = mail($recipient, $subject, $body, $headers);
            }
            $sent = (bool)$sent;
            $this->logOutcome($inviteId, $sent ? 'sent' : 'failed');
            return $sent;
        } catch (\Throwable $error) {
            $this->logOutcome($inviteId, 'failed');
            return false;
        }
    }

    private function validAddress(string $address): bool
    {
        return $this->validHeader($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validHeader(string $value): bool
    {
        return strpos($value, "\r") === false && strpos($value, "\n") === false;
    }

    private function logOutcome(int $inviteId, string $outcome): void
    {
        Yii::info(json_encode(['inviteId' => $inviteId, 'outcome' => $outcome]), 'acrl.invite.mail');
    }
}
