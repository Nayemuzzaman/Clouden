<?php

namespace App\Services\Deployment;

final class ImageReference
{
    private const PATTERN = '/^(?:[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[0-9]{1,5})?\/)?[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*(?::[A-Za-z0-9_][A-Za-z0-9._-]{0,127})?(?:@sha256:[a-f0-9]{64})?$/';

    public static function isValid(string $ref): bool
    {
        return strlen($ref) <= 255 && (bool) preg_match(self::PATTERN, $ref);
    }

    /** @return array{0: string, 1: string} [name, tag-or-digest] */
    public static function split(string $ref): array
    {
        if (str_contains($ref, '@')) {
            [$name, $digest] = explode('@', $ref, 2);

            return [preg_replace('/:[^\/]+$/', '', $name) ?? $name, $digest];
        }
        $slash = strrpos($ref, '/');
        $colon = strrpos($ref, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            return [substr($ref, 0, $colon), substr($ref, $colon + 1)];
        }

        return [$ref, 'latest'];
    }
}
