<?php

namespace app\services;

require_once __DIR__ . '/AcrlInstitutionMembershipService.php';

/** Validates the institution query used by institution-scoped ACRL routes. */
class AcrlInstitutionAccessService
{
    public function requireSupported(string $queryString, array $queryParams): string
    {
        $institution = $this->requireCode($queryString, $queryParams);
        if (!in_array($institution, AcrlInstitutionMembershipService::ENABLED_INSTITUTIONS, true)) {
            throw new \DomainException('institution_not_enabled', 403);
        }
        return $institution;
    }

    public function requireCode(string $queryString, array $queryParams): string
    {
        $occurrences = 0;
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (urldecode($parts[0]) === 'institution') {
                $occurrences += 1;
            }
        }
        if ($occurrences !== 1 || !array_key_exists('institution', $queryParams)
            || !is_string($queryParams['institution'])) {
            throw new \DomainException('institution_required', 400);
        }

        $institution = $queryParams['institution'];
        if (!array_key_exists($institution, AcrlInstitutionMembershipService::INSTITUTIONS)) {
            throw new \DomainException('institution_unknown', 400);
        }
        return $institution;
    }
}
