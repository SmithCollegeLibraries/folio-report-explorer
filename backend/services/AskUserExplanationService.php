<?php

namespace app\services;

final class AskUserExplanationService
{
    private const REASON_MESSAGES = [
        'cross_domain_analysis' => 'This report combines information from more than one reporting area.',
        'material_repair' => 'The report needed a substantial automatic correction before it could run.',
        'limited_semantic_coverage' => 'The checked requirements passed, but this is still an exploratory analysis.',
        'proxy_linkage' => 'Some records are connected through a broader matching method rather than an exact item link.',
        'known_data_limitation' => 'The available reporting data has an important limitation for this question.',
        'unresolved_domain_ambiguity' => 'I used a reasonable interpretation for wording that can have more than one meaning.',
        'documented_default' => 'I used a documented reporting assumption where the question did not specify one.',
    ];

    public static function notice(
        string $mode,
        bool $reviewRequired,
        array $reviewReasons,
        array $assumptions
    ): ?array {
        if ($mode === 'canonical') {
            return null;
        }

        $messages = [];
        foreach ($reviewReasons as $reason) {
            if (!is_string($reason) || !isset(self::REASON_MESSAGES[$reason])) {
                continue;
            }

            $message = self::REASON_MESSAGES[$reason];
            $messages[$message] = $message;
            if (count($messages) === 3) {
                break;
            }
        }

        return [
            'title' => $reviewRequired
                ? 'AI-assisted report — review flagged'
                : 'AI-assisted report',
            'message' => implode(' ', array_values($messages)),
        ];
    }
}
