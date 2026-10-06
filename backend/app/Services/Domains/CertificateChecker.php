<?php

namespace App\Services\Domains;

use Carbon\CarbonImmutable;

/**
 * Determines certificate state by performing a real TLS handshake against the
 * local Caddy instance with the domain as SNI, verifying the chain against the
 * system trust store. Verification is never disabled.
 */
class CertificateChecker
{
    /** @return array{valid: bool, expires_at: ?CarbonImmutable, issuer: ?string, error: ?string} */
    public function check(string $hostname): array
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $hostname,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
        ]]);

        $target = 'ssl://'.config('privatecloud.caddy.tls_host').':443';
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

        if ($client === false) {
            $error = error_get_last()['message'] ?? $errstr;

            return ['valid' => false, 'expires_at' => null, 'issuer' => null, 'error' => $this->describe((string) $error)];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $cert ? openssl_x509_parse($cert) : false;

        return [
            'valid' => true,
            'expires_at' => $parsed ? CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']) : null,
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
            'error' => null,
        ];
    }

    private function describe(string $error): string
    {
        return match (true) {
            str_contains($error, 'certificate verify failed') => 'The server does not have a trusted certificate for this domain yet.',
            str_contains($error, 'internal error'), str_contains($error, 'alert') => 'No certificate has been issued for this domain yet.',
            str_contains($error, 'Connection refused') => 'The web server is not accepting HTTPS connections.',
            default => mb_substr($error, 0, 300),
        };
    }
}
