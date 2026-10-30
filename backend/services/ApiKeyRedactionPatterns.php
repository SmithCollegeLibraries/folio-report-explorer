<?php

namespace app\services;

final class ApiKeyRedactionPatterns
{
    private const HEADER_NAMES = ['authorization', 'x-goog-api-key', 'api-key', 'x-api-key'];

    public static function rawHeaderAt($text, $index, $start, $limit, &$valueStart, &$valueEnd)
    {
        if ($index > $start && ApiKeyRedactionSyntax::nameByte($text[$index - 1])) {
            return false;
        }
        foreach (self::HEADER_NAMES as $header) {
            $length = strlen($header);
            if ($index + $length > $limit || strncasecmp(substr($text, $index, $length), $header, $length) !== 0) {
                continue;
            }
            $colon = $index + $length;
            while ($colon < $limit && ($text[$colon] === ' ' || $text[$colon] === "\t")) {
                $colon++;
            }
            if ($colon >= $limit || $text[$colon] !== ':') {
                continue;
            }
            $valueStart = $colon + 1;
            while ($valueStart < $limit && ($text[$valueStart] === ' ' || $text[$valueStart] === "\t")) {
                $valueStart++;
            }
            $valueEnd = self::rawHeaderEnd($text, $valueStart, $limit);
            return true;
        }
        return false;
    }

    private static function rawHeaderEnd($text, $start, $limit)
    {
        $quote = null;
        for ($index = $start; $index < $limit; $index++) {
            $character = $text[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === "\r" || $character === "\n" || $character === ';') {
                return $index;
            }
        }
        return $limit;
    }

    public static function rawSecretAssignmentAt($text, $index, $start, $limit, &$valueStart, &$valueEnd)
    {
        if (!ApiKeyRedactionSyntax::letter($text[$index])
            || ($index > $start && ApiKeyRedactionSyntax::identifierByte($text[$index - 1]))) {
            return false;
        }
        $nameEnd = $index + 1;
        while ($nameEnd < $limit && ApiKeyRedactionSyntax::identifierByte($text[$nameEnd])) {
            $nameEnd++;
        }
        if (!ApiKeyRedactionSyntax::isSecretVariableName(substr($text, $index, $nameEnd - $index))) {
            return false;
        }
        $separator = ApiKeyRedactionSyntax::skipWhitespace($text, $nameEnd, $limit);
        if ($separator >= $limit) {
            return false;
        }
        if ($text[$separator] === ':') {
            $separator++;
        } elseif ($text[$separator] === '=') {
            $separator++;
            if ($separator < $limit && $text[$separator] === '>') {
                $separator++;
            }
        } else {
            return false;
        }
        $valueStart = ApiKeyRedactionSyntax::skipWhitespace($text, $separator, $limit);
        $valueEnd = ApiKeyRedactionSyntax::fieldValueEnd($text, $valueStart, $limit);
        return true;
    }

    public static function queryValueAt($text, $index, $start, $limit, &$valueStart, &$valueEnd)
    {
        $nameStart = $index;
        if ($text[$index] === '?' || $text[$index] === '&') {
            $nameStart++;
        } elseif ($index + 2 < $limit && $text[$index] === '%'
            && (strncasecmp(substr($text, $index, 3), '%3f', 3) === 0
                || strncasecmp(substr($text, $index, 3), '%26', 3) === 0)) {
            $nameStart += 3;
        } elseif ($index === $start || !ApiKeyRedactionSyntax::identifierByte($text[$index - 1])) {
            $nameStart = $index;
        } else {
            return false;
        }

        $nameLength = 0;
        if ($nameStart + 7 <= $limit && strncasecmp(substr($text, $nameStart, 7), 'api_key', 7) === 0) {
            $nameLength = 7;
        } elseif ($nameStart + 3 <= $limit && strncasecmp(substr($text, $nameStart, 3), 'key', 3) === 0) {
            $nameLength = 3;
        }
        if ($nameLength === 0) {
            return false;
        }
        $equals = $nameStart + $nameLength;
        if ($equals < $limit && $text[$equals] === '=') {
            $valueStart = $equals + 1;
        } elseif ($equals + 2 < $limit && $text[$equals] === '%'
            && strncasecmp(substr($text, $equals, 3), '%3d', 3) === 0) {
            $valueStart = $equals + 3;
        } else {
            return false;
        }

        $valueEnd = $valueStart;
        while ($valueEnd < $limit) {
            $character = $text[$valueEnd];
            if ($character === '&' || $character === '?' || ApiKeyRedactionSyntax::valueDelimiter($character)
                || $character === '"' || $character === "'" || $character === '<' || $character === '>') {
                break;
            }
            if ($valueEnd + 2 < $limit && $character === '%'
                && (strncasecmp(substr($text, $valueEnd, 3), '%26', 3) === 0
                    || strncasecmp(substr($text, $valueEnd, 3), '%3f', 3) === 0)) {
                break;
            }
            $valueEnd++;
        }
        return true;
    }

    public static function bearerValueAt($text, $index, $start, $limit, &$valueStart, &$valueEnd)
    {
        if ($index > $start && ApiKeyRedactionSyntax::identifierByte($text[$index - 1])) {
            return false;
        }
        if ($index + 6 > $limit || strncasecmp(substr($text, $index, 6), 'Bearer', 6) !== 0) {
            return false;
        }
        $valueStart = $index + 6;
        while ($valueStart < $limit && ($text[$valueStart] === ' ' || $text[$valueStart] === "\t")) {
            $valueStart++;
        }
        if ($valueStart === $index + 6 || $valueStart >= $limit) {
            return false;
        }
        $valueEnd = $valueStart;
        while ($valueEnd < $limit && !ApiKeyRedactionSyntax::valueDelimiter($text[$valueEnd])
            && $text[$valueEnd] !== '"' && $text[$valueEnd] !== "'"
            && $text[$valueEnd] !== '<' && $text[$valueEnd] !== '>') {
            $valueEnd++;
        }
        return true;
    }

    public static function skValueAt($text, $index, $start, $limit, &$valueEnd)
    {
        if ($index > $start && ApiKeyRedactionSyntax::identifierByte($text[$index - 1])) {
            return false;
        }
        if ($index + 3 > $limit || substr_compare($text, 'sk-', $index, 3, true) !== 0) {
            return false;
        }
        $valueEnd = $index + 3;
        while ($valueEnd < $limit && ApiKeyRedactionSyntax::skByte($text[$valueEnd])) {
            $valueEnd++;
        }
        if ($valueEnd - $index < 19
            || ($valueEnd < $limit && ApiKeyRedactionSyntax::identifierByte($text[$valueEnd]))) {
            return false;
        }
        return true;
    }

    public static function serializedExpressionAt($text, $index, $limit, &$expressionEnd)
    {
        if ($index + 11 > $limit || strncasecmp(substr($text, $index, 11), 'unserialize', 11) !== 0) {
            return false;
        }
        $open = ApiKeyRedactionSyntax::skipWhitespace($text, $index + 11, $limit);
        if ($open >= $limit || $text[$open] !== '(') {
            return false;
        }
        $quoteStart = ApiKeyRedactionSyntax::skipWhitespace($text, $open + 1, $limit);
        if (ApiKeyRedactionSyntax::quoteAt($text, $quoteStart, $limit) === null) {
            return false;
        }
        $quoteEnd = ApiKeyRedactionSyntax::quotedEnd($text, $quoteStart, $limit);
        if ($quoteEnd >= $limit) {
            return false;
        }
        $close = ApiKeyRedactionSyntax::skipWhitespace($text, $quoteEnd + 1, $limit);
        if ($close >= $limit || $text[$close] !== ')') {
            return false;
        }
        $expressionEnd = $close + 1;
        return true;
    }

    public static function serializedValueHasSensitiveField($text, $start, $expressionEnd)
    {
        $open = strpos($text, '(', $start);
        if ($open === false || $open >= $expressionEnd) {
            return false;
        }
        $quoteStart = ApiKeyRedactionSyntax::skipWhitespace($text, $open + 1, $expressionEnd);
        $contentStart = $quoteStart + 1;
        $contentEnd = ApiKeyRedactionSyntax::quotedEnd($text, $quoteStart, $expressionEnd);
        for ($index = $contentStart; $index + 3 < $contentEnd; $index++) {
            if ($text[$index] !== 's' || $text[$index + 1] !== ':') {
                continue;
            }
            $lengthStart = $index + 2;
            $lengthEnd = $lengthStart;
            $fieldLength = 0;
            while ($lengthEnd < $contentEnd && $text[$lengthEnd] >= '0' && $text[$lengthEnd] <= '9') {
                if ($fieldLength <= 128) {
                    $fieldLength = $fieldLength * 10 + (ord($text[$lengthEnd]) - 48);
                }
                $lengthEnd++;
            }
            if ($lengthEnd === $lengthStart || $fieldLength > 128
                || $lengthEnd + 2 >= $contentEnd || $text[$lengthEnd] !== ':' || $text[$lengthEnd + 1] !== '"') {
                continue;
            }
            $fieldStart = $lengthEnd + 2;
            $fieldEnd = $fieldStart + $fieldLength;
            if ($fieldEnd + 1 < $contentEnd && $text[$fieldEnd] === '"' && $text[$fieldEnd + 1] === ';'
                && ApiKeyRedactionSyntax::isSensitiveName(substr($text, $fieldStart, $fieldLength))) {
                return true;
            }
        }
        return false;
    }
}
