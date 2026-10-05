<?php

namespace App\Services\Source;

/** Validation helpers for user supplied git identifiers. */
final class GitRefs
{
    public const FULL_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/';

    public static function isValidFullName(string $value): bool
    {
        return (bool) preg_match(self::FULL_NAME_PATTERN, $value) && ! str_contains($value, '..');
    }

    /** Conservative subset of git-check-ref-format rules. */
    public static function isValidBranch(string $value): bool
    {
        if ($value === '' || strlen($value) > 200) {
            return false;
        }
        if (str_starts_with($value, '-') || str_starts_with($value, '/') || str_ends_with($value, '/')
            || str_ends_with($value, '.') || str_ends_with($value, '.lock')
            || str_contains($value, '..') || str_contains($value, '//') || str_contains($value, '@{')) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._\/-]+$/', $value);
    }

    public static function isValidSha(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', $value);
    }

    /**
     * Validate a manual git URL. Only https:// is accepted in production; URLs with
     * embedded credentials, unusual ports, or hosts that resolve to private/loopback
     * addresses are rejected to prevent SSRF against internal services.
     */
    public static function validateGitUrl(string $url, bool $allowInsecure): ?string
    {
        if (strlen($url) > 500 || preg_match('/\s/', $url)) {
            return 'The repository URL is not valid.';
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full URL such as https://github.com/owner/repository.git';
        }
        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = $allowInsecure ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            return 'Only https:// repository URLs are supported.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Do not put credentials in the URL. Connect GitHub in Settings for private repositories.';
        }
        if (! $allowInsecure) {
            $host = $parts['host'];
            if (isset($parts['port']) && (int) $parts['port'] !== 443) {
                return 'Custom ports are not allowed for repository URLs.';
            }
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return 'Repository URLs pointing at private or local network addresses are not allowed.';
                }
            }
        }

        return null;
    }

    /** Extract owner/repo from a github.com URL, if it is one. */
    public static function githubFullNameFromUrl(string $url): ?string
    {
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $url, $m)) {
            return $m[1].'/'.$m[2];
        }

        return null;
    }
}
