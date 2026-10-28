<?php

namespace app\services;

/** Pure policy for deciding whether a year has enough reviewed links to publish. */
class AcrlPublicationPolicyService
{
    public function evaluate(array $items): array
    {
        $unresolved = 0;
        foreach ($items as $item) {
            if (!empty($item['topical']) || in_array($item['status'] ?? null, ['new', 'removed'], true)) {
                continue;
            }
            if (!in_array($item['decision'] ?? null, ['approved', 'rejected'], true)) {
                $unresolved += 1;
            }
        }

        return [
            'can_publish' => $unresolved === 0,
            'unresolved_count' => $unresolved,
        ];
    }
}
