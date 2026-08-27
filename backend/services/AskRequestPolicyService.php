<?php

namespace app\services;

final class AskRequestPolicyService
{
    public const READ_ONLY = 'read_only';
    public const REQUEST_BLOCKED = 'request_blocked';

    /**
     * Classify only high-confidence imperative database writes as blocked.
     * Ambiguous language deliberately continues through read-only generation.
     *
     * @return array{state:string,reason:string}
     */
    public static function classify(string $question): array
    {
        $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $question)));
        if ($normalized === '' || preg_match(
            '/^(?:show|list|find|count|summarize|compare|report|include|what|which|how|update me on)\b/',
            $normalized
        ) === 1) {
            return ['state' => self::READ_ONLY, 'reason' => 'reporting_or_uncertain'];
        }

        $prefix = '^(?:(?:please|kindly)\s+|can you\s+|could you\s+|would you\s+)?';
        $imperative = '/' . $prefix . '(?:'
            . 'insert\b.{0,80}\binto\b'
            . '|update\b.{0,80}\b(?:set|rows?|records?|tables?|database)\b'
            . '|delete\b.{0,40}\b(?:from|every|all|these|rows?|records?)\b'
            . '|(?:drop|alter|truncate|create)\b.{0,80}\b(?:tables?|schemas?|indexes?|views?|database)\b'
            . '|(?:grant|revoke)\b.{0,80}\b(?:on|to|from)\b'
            . '|copy\b.{0,80}\b(?:from|to)\b'
            . '|call\b.{0,80}\b(?:functions?|procedures?)\b'
            . ')/i';

        return preg_match($imperative, $normalized) === 1
            ? ['state' => self::REQUEST_BLOCKED, 'reason' => 'explicit_write_intent']
            : ['state' => self::READ_ONLY, 'reason' => 'reporting_or_uncertain'];
    }
}
