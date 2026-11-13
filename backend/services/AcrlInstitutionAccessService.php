<?php

namespace app\services;

/** Validates the institution query used by institution-scoped ACRL routes. */
class AcrlInstitutionAccessService
{
    private const INSTITUTIONS = ['AC', 'HC', 'MH', 'SC', 'UM'];

    public function requireSupported(string $queryString, array $queryParams): string
    {
        $institution = $this->requireCode($queryString, $queryParams);
        if ($institution !== 'SC') {
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
        if (!in_array($institution, self::INSTITUTIONS, true)) {
            throw new \DomainException('institution_unknown', 400);
        }
        return $institution;
    }
}
