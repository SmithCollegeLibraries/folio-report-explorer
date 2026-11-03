<?php

namespace app\services;

use app\models\DummyIdentity;

/** Provides the synthetic identity used by unauthenticated dev-mode API requests. */
final class DevIdentityProvider
{
    public static function identity(): DummyIdentity
    {
        return new DummyIdentity();
    }

    public static function id(): int
    {
        return (int)self::identity()->getId();
    }
}
