<?php

namespace App\Services\Domains;

use App\Services\Server\ServerIdentity;

class DnsChecker
{
    public function __construct(private readonly ServerIdentity $identity) {}

    /**
     * @return array{status: string, records: array{a: list<string>, aaaa: list<string>}, expected: list<string>}
     *                                                                                                            status: ok | mismatch | missing | unknown
     */
    public function check(string $hostname): array
    {
        $a = $this->lookup($hostname, DNS_A, 'ip');
        $aaaa = $this->lookup($hostname, DNS_AAAA, 'ipv6');
        $expected = $this->identity->publicIps();
        $records = ['a' => $a, 'aaaa' => $aaaa];

        if ($a === [] && $aaaa === []) {
            return ['status' => 'missing', 'records' => $records, 'expected' => $expected];
        }
        if ($expected === []) {
            return ['status' => 'unknown', 'records' => $records, 'expected' => $expected];
        }

        $all = array_merge($a, $aaaa);
        $pointsHere = array_intersect($all, $expected) !== [];
        // Every A record must point here; stray records cause intermittent certificate failures.
        $strayIpv4 = array_diff($a, $expected);

        return ['status' => $pointsHere && $strayIpv4 === [] ? 'ok' : 'mismatch', 'records' => $records, 'expected' => $expected];
    }

    /** @return list<string> */
    protected function lookup(string $hostname, int $type, string $field): array
    {
        $records = @dns_get_record($hostname, $type);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r[$field] ?? null, $records))));
    }
}
