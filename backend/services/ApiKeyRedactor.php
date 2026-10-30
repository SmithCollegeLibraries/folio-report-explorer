<?php

namespace app\services;

/**
 * Redacts provider credentials from strings before they reach logs or worker output.
 */
final class ApiKeyRedactor
{
    private const REDACTED = '[REDACTED]';

    private const HEADER_NAMES = '(?:authorization|x-goog-api-key|api-key|x-api-key)';

    private const SECRET_NAMES = '[a-z][a-z0-9_]*(?:_api_key|_secret|_pass[a-z0-9_]*|_token)';

    public static function redact(string $text): string
    {
        $text = self::redactSerializedObjects($text);
        $text = self::redactHeaderArrays($text);
        $text = self::redactQuotedFields($text, self::HEADER_NAMES);
        $text = self::redactQuotedFields($text, self::SECRET_NAMES);
        $text = self::redactUnterminatedQuotedFields($text, self::HEADER_NAMES);
        $text = self::redactUnterminatedQuotedFields($text, self::SECRET_NAMES);
        $text = self::redactRawHeaders($text);
        $text = self::redactRawSecretNames($text);
        $text = self::redactQueryParameters($text);
        $text = self::redactBearerTokens($text);

        return preg_replace('/\bsk-[A-Za-z0-9_-]{16,}\b/', self::REDACTED, $text);
    }

    private static function redactSerializedObjects(string $text): string
    {
        return preg_replace_callback('~unserialize\((["\'])(.*?)(?<!\\\\)\1\)~s', function ($matches) {
            $serialized = $matches[2];
            $sensitiveName = '(?:authorization|x-goog-api-key|api-key|x-api-key|' . self::SECRET_NAMES . ')';
            if (preg_match('~s:\d+:"' . $sensitiveName . '";s:\d+:"~i', $serialized)) {
                return self::REDACTED;
            }

            return $matches[0];
        }, $text);
    }

    private static function redactHeaderArrays(string $text): string
    {
        return preg_replace_callback('~(["\'])(authorization|x-goog-api-key|api-key|x-api-key)\1(\s*=>\s*)(array\s*\([^)]*\)|\[[^\]]*\])~i', function ($matches) {
            $array = preg_replace_callback('~(["\'])(.*?)\1~s', function ($value) {
                return $value[1] . self::REDACTED . $value[1];
            }, $matches[4]);

            return $matches[1] . $matches[2] . $matches[1] . $matches[3] . $array;
        }, $text);
    }

    private static function redactQuotedFields(string $text, string $namePattern): string
    {
        $pattern = '~(["\'])(' . $namePattern . ')\1(\s*:\s*|\s*=>\s*)(["\'])(.*?)\4~i';
        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . $matches[1]
                . $matches[3] . $matches[4] . self::REDACTED . $matches[4];
        }, $text);
    }

    private static function redactUnterminatedQuotedFields(string $text, string $namePattern): string
    {
        $pattern = '~(["\'])(' . $namePattern . ')\1(\s*:\s*|\s*=>\s*)(["\'])([^"\'\r\n]*)(?:\.\.\.)?$~im';
        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . $matches[1]
                . $matches[3] . $matches[4] . self::REDACTED . $matches[4];
        }, $text);
    }

    private static function redactRawHeaders(string $text): string
    {
        $pattern = '~(?<![A-Za-z0-9_-])(' . self::HEADER_NAMES . ')([ \t]*:[ \t]*)(?!["\'])([^\r\n,;}\]]+)~i';
        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . self::REDACTED;
        }, $text);
    }

    private static function redactRawSecretNames(string $text): string
    {
        $pattern = '~(?<![A-Za-z0-9_])(' . self::SECRET_NAMES . ')(\s*(?:=>|:|=)\s*)(["\'])(.*?)\3~i';
        $text = preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . $matches[3] . self::REDACTED . $matches[3];
        }, $text);

        $pattern = '~(?<![A-Za-z0-9_])(' . self::SECRET_NAMES . ')(\s*(?:=>|:|=)\s*)(?!["\'])([^\s,;}\]]+)~i';
        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . self::REDACTED;
        }, $text);
    }

    private static function redactQueryParameters(string $text): string
    {
        $pattern = '~\b(key|api_key)(=|%3d)([^&\s"\'<>;,}\]]+)~i';
        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . $matches[2] . self::REDACTED;
        }, $text);
    }

    private static function redactBearerTokens(string $text): string
    {
        return preg_replace('/\b(Bearer[ \t]+)[^\s,;"\']+/i', '$1' . self::REDACTED, $text);
    }
}
