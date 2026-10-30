<?php

namespace app\services;

final class ApiKeyRedactor
{
    const REDACTED = '[REDACTED]';

    public static function redact($text)
    {
        if (!is_string($text)) {
            return self::REDACTED;
        }

        try {
            return ApiKeyRedactionScanner::redact($text);
        } catch (\Throwable $error) {
            return self::REDACTED;
        }
    }
}
