<?php

namespace app\services;

/** Maps a stored sign-in email to its Five Colleges ACRL institution. */
class AcrlInstitutionIdentityService
{
    private const DOMAINS = [
        'smith.edu' => 'SC',
        'amherst.edu' => 'AC',
        'hampshire.edu' => 'HC',
        'mtholyoke.edu' => 'MH',
        'umass.edu' => 'UM',
    ];

    public static function collegeForEmail(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = substr($email, $at + 1);
        return self::DOMAINS[$domain] ?? null;
    }

    public static function domainForCollege(string $college): ?string
    {
        $domains = array_flip(self::DOMAINS);
        return $domains[$college] ?? null;
    }
}
