<?php

namespace app\services;

final class ApiKeyRedactionSerialized
{
    public static function expressionAt($text, $index, $limit, &$expressionEnd, &$serializedValue)
    {
        if ($index + 11 > $limit || strncasecmp(substr($text, $index, 11), 'unserialize', 11) !== 0) {
            return false;
        }
        $open = ApiKeyRedactionSyntax::skipWhitespace($text, $index + 11, $limit);
        if ($open >= $limit || $text[$open] !== '(') {
            return false;
        }
        $cursor = ApiKeyRedactionSyntax::skipWhitespace($text, $open + 1, $limit);
        $serializedValue = '';
        $fragment = '';
        if (!self::phpStringLiteralAt($text, $cursor, $limit, $fragment)) {
            return false;
        }
        $serializedValue .= $fragment;

        while ($cursor < $limit) {
            $cursor = ApiKeyRedactionSyntax::skipWhitespace($text, $cursor, $limit);
            if ($cursor < $limit && $text[$cursor] === ')') {
                $expressionEnd = $cursor + 1;
                return true;
            }
            if ($cursor >= $limit || $text[$cursor] !== '.') {
                return false;
            }
            $cursor = ApiKeyRedactionSyntax::skipWhitespace($text, $cursor + 1, $limit);
            if (!self::phpStringLiteralAt($text, $cursor, $limit, $fragment)) {
                return false;
            }
            $serializedValue .= $fragment;
        }
        return false;
    }

    private static function phpStringLiteralAt($text, &$cursor, $limit, &$value)
    {
        if ($cursor >= $limit || ($text[$cursor] !== "'" && $text[$cursor] !== '"')) {
            return false;
        }
        $quote = $text[$cursor++];
        $value = '';
        while ($cursor < $limit) {
            $character = $text[$cursor];
            if ($character === $quote) {
                $cursor++;
                return true;
            }
            if ($character !== '\\') {
                $value .= $character;
                $cursor++;
                continue;
            }
            if ($cursor + 1 >= $limit) {
                return false;
            }

            $escaped = $text[$cursor + 1];
            if ($quote === "'") {
                $value .= ($escaped === "'" || $escaped === '\\') ? $escaped : '\\' . $escaped;
                $cursor += 2;
                continue;
            }
            if ($escaped === 'x') {
                $hex = '';
                $digit = $cursor + 2;
                while ($digit < $limit && strlen($hex) < 2
                    && strspn($text[$digit], '0123456789abcdefABCDEF') === 1) {
                    $hex .= $text[$digit++];
                }
                if ($hex === '') {
                    $value .= '\\x';
                    $cursor += 2;
                } else {
                    $value .= chr(hexdec($hex));
                    $cursor = $digit;
                }
                continue;
            }
            if ($escaped >= '0' && $escaped <= '7') {
                $octal = $escaped;
                $digit = $cursor + 2;
                while ($digit < $limit && strlen($octal) < 3 && $text[$digit] >= '0' && $text[$digit] <= '7') {
                    $octal .= $text[$digit++];
                }
                $value .= chr(octdec($octal));
                $cursor = $digit;
                continue;
            }

            $escapes = [
                '0' => "\0", '"' => '"', '\\' => '\\', '$' => '$',
                'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f",
            ];
            $value .= isset($escapes[$escaped]) ? $escapes[$escaped] : '\\' . $escaped;
            $cursor += 2;
        }
        return false;
    }

    public static function hasSensitiveField($serializedValue)
    {
        $length = strlen($serializedValue);
        for ($index = 0; $index + 3 < $length; $index++) {
            if ($serializedValue[$index] !== 's' || $serializedValue[$index + 1] !== ':') {
                continue;
            }
            $lengthStart = $index + 2;
            $lengthEnd = $lengthStart;
            $fieldLength = 0;
            while ($lengthEnd < $length && $serializedValue[$lengthEnd] >= '0' && $serializedValue[$lengthEnd] <= '9') {
                if ($fieldLength <= 128) {
                    $fieldLength = $fieldLength * 10 + (ord($serializedValue[$lengthEnd]) - 48);
                }
                $lengthEnd++;
            }
            if ($lengthEnd === $lengthStart || $fieldLength > 128
                || $lengthEnd + 2 >= $length || $serializedValue[$lengthEnd] !== ':' || $serializedValue[$lengthEnd + 1] !== '"') {
                continue;
            }
            $fieldStart = $lengthEnd + 2;
            $fieldEnd = $fieldStart + $fieldLength;
            if ($fieldEnd + 1 < $length && $serializedValue[$fieldEnd] === '"' && $serializedValue[$fieldEnd + 1] === ';'
                && ApiKeyRedactionSyntax::isSensitiveName(substr($serializedValue, $fieldStart, $fieldLength))) {
                return true;
            }
        }
        return false;
    }
}
