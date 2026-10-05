<?php

namespace App\Services\Domains;

final class DomainValidator
{
    private const LABEL = '(?!-)[a-z0-9-]{1,63}(?<!-)';

    /**
     * Normalize user input to a lowercase ASCII (punycode) hostname, or return
     * null if it is not a valid public hostname.
     */
    public static function normalize(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^https?://#', '', $host) ?? '';
        $host = rtrim($host, '/.');

        if ($host === '' || str_contains($host, '/') || str_contains($host, ':') || str_contains($host, '*')) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $host)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = strtolower($ascii);
        }

        return self::isSafeHostname($host) ? $host : null;
    }

    /** Strict check used before a hostname is written into Caddy configuration. */
    public static function isSafeHostname(string $host): bool
    {
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! preg_match('/^(?:'.self::LABEL.'\.)+[a-z][a-z0-9-]{0,62}(?<!-)$/', $host)
            && ! preg_match('/^(?:'.self::LABEL.'\.)+xn--[a-z0-9-]{1,59}$/', $host)) {
            return false;
        }

        return true;
    }

    /** Explain why a syntactically valid hostname cannot be used, or null if fine. */
    public static function policyViolation(string $host): ?string
    {
        $dashboard = strtolower((string) config('privatecloud.dashboard_domain'));
        if ($dashboard !== '' && $host === $dashboard) {
            return 'This hostname is used by the PrivateCloud dashboard itself.';
        }
        if (config('privatecloud.caddy.auto_https') !== 'off' && (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'))) {
            return 'Local hostnames cannot receive public HTTPS certificates.';
        }

        return null;
    }
}
