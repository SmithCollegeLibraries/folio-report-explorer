<?php

namespace app\services;

final class ApiKeyRedactionSyntax
{
    private const HEADER_NAMES = ['authorization', 'x-goog-api-key', 'api-key', 'x-api-key'];

    public static function quotedEnd($text, $quoteStart, $limit)
    {
        $quote = $text[$quoteStart];
        for ($index = $quoteStart + 1; $index < $limit; $index++) {
            if ($text[$index] === '\\') {
                $index++;
            } elseif ($text[$index] === $quote) {
                return $index;
            }
        }
        return $limit;
    }

    public static function quoteAt($text, $index, $limit)
    {
        if ($index < $limit && ($text[$index] === '"' || $text[$index] === "'")) {
            return $text[$index];
        }
        return null;
    }

    public static function fieldValueStart($text, $index, $limit, &$valueStart)
    {
        $index = self::skipWhitespace($text, $index, $limit);
        if ($index < $limit && $text[$index] === ':') {
            $index++;
        } elseif ($index + 1 < $limit && $text[$index] === '=' && $text[$index + 1] === '>') {
            $index += 2;
        } else {
            return false;
        }
        $valueStart = self::skipWhitespace($text, $index, $limit);
        return true;
    }

    public static function fieldValueEnd($text, $start, $limit)
    {
        if ($start >= $limit) {
            return $limit;
        }
        if (self::quoteAt($text, $start, $limit) !== null) {
            $end = self::quotedEnd($text, $start, $limit);
            return $end < $limit ? $end + 1 : $limit;
        }
        $open = $start;
        if (self::arrayExpressionAt($text, $start, $limit, $open)) {
            return self::balancedEnd($text, $open, $limit);
        }
        if ($text[$start] === '[' || $text[$start] === '{' || $text[$start] === '(') {
            return self::balancedEnd($text, $start, $limit);
        }
        $index = $start;
        while ($index < $limit && !self::valueDelimiter($text[$index])) {
            $index++;
        }
        return $index;
    }

    private static function arrayExpressionAt($text, $start, $limit, &$open)
    {
        if ($start + 5 > $limit || strncasecmp(substr($text, $start, 5), 'array', 5) !== 0) {
            return false;
        }
        $index = self::skipWhitespace($text, $start + 5, $limit);
        if ($index >= $limit || $text[$index] !== '(') {
            return false;
        }
        $open = $index;
        return true;
    }

    private static function balancedEnd($text, $start, $limit)
    {
        $stack = [];
        $pairs = ['[' => ']', '{' => '}', '(' => ')'];
        for ($index = $start; $index < $limit; $index++) {
            $character = $text[$index];
            if ($character === '"' || $character === "'") {
                $quoted = self::quotedEnd($text, $index, $limit);
                if ($quoted >= $limit) {
                    return $limit;
                }
                $index = $quoted;
            } elseif (isset($pairs[$character])) {
                $stack[] = $pairs[$character];
            } elseif (!empty($stack) && $character === end($stack)) {
                array_pop($stack);
                if (empty($stack)) {
                    return $index + 1;
                }
            }
        }
        return $limit;
    }

    public static function valueDelimiter($character)
    {
        return $character === ' ' || $character === "\t" || $character === "\r" || $character === "\n"
            || $character === ',' || $character === ';' || $character === '}' || $character === ']'
            || $character === ')';
    }

    public static function isSensitiveName($name)
    {
        $name = strtolower($name);
        return in_array($name, self::HEADER_NAMES, true) || self::isSecretVariableName($name);
    }

    public static function isSecretVariableName($name)
    {
        $name = strtolower($name);
        foreach (['_api_key', '_secret', '_token'] as $suffix) {
            $suffixLength = strlen($suffix);
            if (strlen($name) > $suffixLength && substr_compare($name, $suffix, -$suffixLength) === 0) {
                return true;
            }
        }
        $passPosition = strpos($name, '_pass');
        return $passPosition !== false && $passPosition > 0;
    }

    public static function skipWhitespace($text, $index, $limit)
    {
        while ($index < $limit) {
            $character = $text[$index];
            if ($character !== ' ' && $character !== "\t" && $character !== "\r" && $character !== "\n") {
                break;
            }
            $index++;
        }
        return $index;
    }

    public static function letter($character)
    {
        return ($character >= 'a' && $character <= 'z') || ($character >= 'A' && $character <= 'Z');
    }

    public static function identifierByte($character)
    {
        return self::letter($character) || ($character >= '0' && $character <= '9') || $character === '_';
    }

    public static function nameByte($character)
    {
        return self::identifierByte($character) || $character === '-';
    }

    public static function skByte($character)
    {
        return self::identifierByte($character) || $character === '-';
    }
}
