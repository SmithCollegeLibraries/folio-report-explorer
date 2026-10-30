<?php

namespace app\services;

final class ApiKeyRedactionScanner
{
    public static function redact($text)
    {
        return self::scan($text, 0, strlen($text), false);
    }

    private static function scan($text, $start, $limit, $opaque)
    {
        $output = '';
        $index = $start;
        while ($index < $limit) {
            $expressionEnd = 0;
            if (ApiKeyRedactionPatterns::serializedExpressionAt($text, $index, $limit, $expressionEnd)) {
                if (ApiKeyRedactionPatterns::serializedValueHasSensitiveField($text, $index, $expressionEnd)) {
                    $output .= ApiKeyRedactor::REDACTED;
                    $index = $expressionEnd;
                    continue;
                }
            }

            $character = $text[$index];
            if (!$opaque && ($character === '"' || $character === "'")) {
                $quotedEnd = ApiKeyRedactionSyntax::quotedEnd($text, $index, $limit);
                $contentEnd = $quotedEnd < $limit ? $quotedEnd : $limit;
                $afterQuote = $quotedEnd < $limit ? $quotedEnd + 1 : $limit;
                $name = substr($text, $index + 1, $contentEnd - $index - 1);
                $valueStart = 0;
                if (ApiKeyRedactionSyntax::isSensitiveName($name)
                    && ApiKeyRedactionSyntax::fieldValueStart($text, $afterQuote, $limit, $valueStart)) {
                    $valueEnd = ApiKeyRedactionSyntax::fieldValueEnd($text, $valueStart, $limit);
                    $output .= substr($text, $index, $valueStart - $index);
                    $output .= $character . ApiKeyRedactor::REDACTED . $character;
                    $index = $valueEnd;
                    continue;
                }

                $output .= $character;
                if ($contentEnd > $index + 1) {
                    $output .= self::scan($text, $index + 1, $contentEnd, true);
                }
                if ($quotedEnd < $limit) {
                    $output .= $character;
                }
                $index = $afterQuote;
                continue;
            }

            $valueStart = 0;
            $valueEnd = 0;
            if (ApiKeyRedactionPatterns::rawHeaderAt($text, $index, $start, $limit, $valueStart, $valueEnd)) {
                $output .= substr($text, $index, $valueStart - $index) . ApiKeyRedactor::REDACTED;
                $index = $valueEnd;
                continue;
            }
            if (!$opaque && ApiKeyRedactionPatterns::rawSecretAssignmentAt($text, $index, $start, $limit, $valueStart, $valueEnd)) {
                $output .= substr($text, $index, $valueStart - $index);
                $quote = ApiKeyRedactionSyntax::quoteAt($text, $valueStart, $limit);
                $output .= $quote === null
                    ? ApiKeyRedactor::REDACTED
                    : $quote . ApiKeyRedactor::REDACTED . $quote;
                $index = $valueEnd;
                continue;
            }
            if (ApiKeyRedactionPatterns::queryValueAt($text, $index, $start, $limit, $valueStart, $valueEnd)) {
                $output .= substr($text, $index, $valueStart - $index) . ApiKeyRedactor::REDACTED;
                $index = $valueEnd;
                continue;
            }
            if (ApiKeyRedactionPatterns::bearerValueAt($text, $index, $start, $limit, $valueStart, $valueEnd)) {
                $output .= substr($text, $index, $valueStart - $index) . ApiKeyRedactor::REDACTED;
                $index = $valueEnd;
                continue;
            }
            if (ApiKeyRedactionPatterns::skValueAt($text, $index, $start, $limit, $valueEnd)) {
                $output .= ApiKeyRedactor::REDACTED;
                $index = $valueEnd;
                continue;
            }

            $output .= $character;
            $index++;
        }
        return $output;
    }
}
