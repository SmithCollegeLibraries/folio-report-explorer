<?php
namespace app\services;

/** Shared instructions and visible interpretations for holding-less cataloging work. */
final class CatalogingQueryGuidanceService
{
    public static function matches(string $prompt): bool
    {
        return preg_match('/\bholdings?[- ]?less\b|\b(?:without|no|missing|lacking)\s+(?:(?:any|smith|amherst|mount holyoke|hampshire|umass)(?:\s+college)?\s+)?holdings?\b|\b(?:do(?:es)? not|don[\x{2019}\x{27}]t|doesn[\x{2019}\x{27}]t) have\s+(?:any\s+)?holdings?\b/iu', $prompt) === 1;
    }

    public static function assumptions(string $prompt): array
    {
        if (!self::matches($prompt)) {
            return [];
        }
        $institutionOnly = preg_match('/\b(?:no|without|missing)\s+(?:smith|amherst|mount holyoke|hampshire|umass)(?: college)?\s+holdings?\b|\b(?:no|without)\s+holdings?\s+(?:at|for|in)\s+(?:smith|amherst|mount holyoke|hampshire|umass|massachusetts)\b/i', $prompt) === 1;
        $mode = self::suppressionMode($prompt);
        $explanations = [
            'all' => 'Include discovery- and staff-suppressed instances; no suppression exclusion was requested.',
            'as_requested' => 'Preserve the explicitly requested suppression conditions. Do not replace a positive or mixed suppression request with an unsuppressed-only filter.',
            'exclude_discovery' => 'Exclude discovery-suppressed instances; staff suppression does not filter this result.',
            'exclude_staff' => 'Exclude staff-suppressed instances; discovery suppression does not filter this result.',
            'exclude_both' => 'Interpret not suppressed as excluding both discovery and staff suppression. Unset flags count as not suppressed.',
        ];
        return [
            [
                'key' => 'holdings_absence', 'label' => 'Holdings scope',
                'value' => $institutionOnly ? 'institution' : 'consortium',
                'explanation' => $institutionOnly
                    ? 'Require no holdings at the named institution; holdings elsewhere may exist.'
                    : 'Require zero holdings anywhere in the consortium, including suppressed holdings and holdings without items.',
                'correctionExample' => 'Find instances with no Smith holdings, even if other colleges have holdings.',
                'source' => 'explicit',
            ],
            [
                'key' => 'instance_suppression', 'label' => 'Instance suppression', 'value' => $mode,
                'explanation' => $explanations[$mode],
                'correctionExample' => 'Exclude discovery suppression only, or include all suppressed instances.',
                'source' => $mode === 'all' ? 'default' : 'explicit',
            ],
        ];
    }

    private static function suppressionMode(string $prompt): string
    {
        if (preg_match('/\b(?:including|include)\s+(?:discovery|staff)[ -]suppres/i', $prompt)) {
            return 'as_requested';
        }
        if (preg_match('/\b(?:including|include)\s+(?:all\s+)?suppressed|\bdo not exclude\s+(?:any\s+)?suppressed/i', $prompt)) {
            return 'all';
        }
        $exclusion = preg_match('/\bunsuppressed\b|\bnot\s+(?:(?:discovery|staff)[ -])?suppressed\b|\b(?:exclude|excluding|without)\b[^.;\n]*\bsuppres/i', $prompt);
        if (!$exclusion) {
            return preg_match('/\bsuppressed\s+(?:instances|records)\b/i', $prompt) ? 'as_requested' : 'all';
        }
        $discovery = preg_match('/\bdiscovery\b/i', $prompt);
        $staff = preg_match('/\bstaff[ -]+(?:suppress|and\s+discovery)/i', $prompt);
        if ($discovery && !$staff) {
            return 'exclude_discovery';
        }
        if ($staff && !$discovery) {
            return 'exclude_staff';
        }
        return 'exclude_both';
    }

    public static function buildPromptGuidance(string $prompt): string
    {
        if (!self::matches($prompt)) {
            return '';
        }
        return <<<'GUIDANCE'
--- Instances Without Holdings: Cataloging Rules ---
These rules override general campus/location join advice for instances without holdings.
Start from inventory.instance__t. For zero holdings anywhere use NOT EXISTS (SELECT 1 FROM inventory.holdings_record__t h WHERE h.instance_id = inst.id). Do not require a positive holdings, item, or location join: that removes the records being sought. Suppressed holdings and holdings without items still count as holdings.
Distinguish no holdings anywhere from no holdings at a named institution. For the latter, apply the location/library/campus restriction inside the holdings NOT EXISTS only; other colleges may have holdings.
Bibliographic ownership is independent of holdings. Match the requested institution using an exact normalized 998$a marker in marctab.mt998: UPPER(BTRIM(m.content)) = the code and m.sf = 'a'. Verified codes: Smith 'SM', Amherst 'AM', Mount Holyoke 'MH', Massachusetts 'UM', Hampshire 'HA', Yiddish Book Center 'YB'. These are NOT location campus or acquisition-unit codes: Smith is not SC and Amherst is not AC here. Do not assign institutions to unverified ownership codes.
All colleges means no ownership predicate and must include absent or unrecognized 998$a values. Otherwise use the institution explicitly requested, or the selected home campus. State the ownership basis in the explanation.
Do not infer ownership from excluded 035 prefixes. Smith prefixes (SMHASLKR) and (SMISLKR) alone do not scope a request to Smith. Do not add marker exclusions unless requested.
Exclude a marker at instance level with NOT EXISTS over marctab.mt035; a different nonmatching 035 row must not let the instance back in. For 035$a prefixes use sf = 'a' and a case-insensitive literal starts-with comparison after trimming leading spaces. If the user asks for text anywhere, use contains instead of silently narrowing to prefixes or a subfield. Prefer instance_id = inst.id for MARC joins; preserve records with null HRIDs. Never concatenate field rows and then apply a row-level NOT ILIKE as a substitute for absence.
Honor the documented suppression interpretation. No suppression request means include all: never silently add suppression filters. Exclude discovery suppression with inst.discovery_suppress IS NOT TRUE; exclude staff suppression with inst.staff_suppress IS NOT TRUE; excluding both requires both predicates. Plain 'not suppressed' is interpreted as both and MUST be explained with the discovery-only alternative. Show both flags when useful for review. A request for suppressed records must preserve its requested positive suppression condition.
'LKRS or holding-less' is an OR, not just holding-less. Do not infer LKRS membership or missing child links from the absence of the excluded markers. An independent LKRS/child relationship definition is required; identify that unresolved scope explicitly instead of claiming the holding-less result is complete.
For recurring zero-holdings work, the Cataloging fixed report 'Instances Without Holdings — Cataloging Review' supports ownership, suppression, optional 035$a prefixes, worklist CSV and FOLIO UUID export.
GUIDANCE;
    }
}
