<?php

namespace app\services;

final class WorkerOutput
{
    public static function write($message, $stream)
    {
        return fwrite($stream, ApiKeyRedactor::redact((string)$message));
    }

    public static function stdout($message)
    {
        return self::write($message, STDOUT);
    }

    public static function stderr($message)
    {
        return self::write($message, STDERR);
    }
}
