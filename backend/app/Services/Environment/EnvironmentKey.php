<?php

namespace App\Services\Environment;

final class EnvironmentKey
{
    public const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,254}$/';

    private const SECRET_HINTS = '/(KEY|SECRET|PASSWORD|PASSWD|TOKEN|PRIVATE|CREDENTIAL|DSN|DATABASE_URL|_PASS$)/i';

    public static function isValid(string $key): bool
    {
        return (bool) preg_match(self::PATTERN, $key);
    }

    /** Whether a variable name looks like it holds a secret (used as the default for "is secret"). */
    public static function looksSecret(string $key): bool
    {
        return (bool) preg_match(self::SECRET_HINTS, $key);
    }
}
