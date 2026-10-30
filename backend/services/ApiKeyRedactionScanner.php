<?php

namespace app\services;

final class ApiKeyRedactionScanner
{
    public static function redact($text)
    {
        return self::scan($text, 0, strlen($text), 0);
    }

    private static function scan($text, $start, $limit, $depth)
    {
        if ($depth >= 64) {
            return ApiKeyRedactor::REDACTED;
        }

        $output = '';
        $index = $start;
        while ($index < $limit) {
            $expressionEnd = 0;
            $serializedValue = '';
            if (ApiKeyRedactionSerialized::expressionAt($text, $index, $limit, $expressionEnd, $serializedValue)) {
                if (ApiKeyRedactionSerialized::hasSensitiveField($serializedValue)) {
                    $output .= ApiKeyRedactor::REDACTED;
                    $index = $expressionEnd;
                    continue;
                }
            }

            $character = $text[$index];
            if ($character === '"' || $character === "'") {
                $quotedEnd = ApiKeyRedactionSyntax::quotedEnd($text, $index, $limit);
                $contentEnd = $quotedEnd < $limit ? $quotedEnd : $limit;
                $afterQuote = $quotedEnd < $limit ? $quotedEnd + 1 : $limit;
                $name = substr($text, $index + 1, $contentEnd - $index - 1);
                $valueStart = 0;
                $hasFieldValue = ApiKeyRedactionSyntax::fieldValueStart($text, $afterQuote, $limit, $valueStart);
                if (ApiKeyRedactionSyntax::isSensitiveName($name) && $hasFieldValue) {
                    $valueEnd = ApiKeyRedactionSyntax::fieldValueEnd($text, $valueStart, $limit);
                    $output .= substr($text, $index, $valueStart - $index);
                    $output .= $character . ApiKeyRedactor::REDACTED . $character;
                    $index = $valueEnd;
                    continue;
                }

                $output .= $character;
                if ($contentEnd > $index + 1) {
                    if ($hasFieldValue) {
                        $output .= substr($text, $index + 1, $contentEnd - $index - 1);
                    } else {
                        $output .= self::scan($text, $index + 1, $contentEnd, $depth + 1);
                    }
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
            if (ApiKeyRedactionPatterns::rawSecretAssignmentAt($text, $index, $start, $limit, $valueStart, $valueEnd)) {
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
