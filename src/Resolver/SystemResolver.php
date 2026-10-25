<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Resolver;

/**
 * Default resolver backed by PHP's system DNS functions.
 *
 * WARNING: these calls are blocking and can be slow (hundreds of ms on a cold
 * cache). Verification results should be cached per-IP by the caller; the
 * static IP-range safety net in {@see \NorthWebDev\Labyrinth\CrawlerVerifier}
 * exists precisely so a slow PTR lookup never traps a real crawler.
 */
final class SystemResolver implements ResolverInterface
{
    public function reverse(string $ip): ?string
    {
        $host = @gethostbyaddr($ip);
        if ($host === false || $host === '' || $host === $ip) {
            return null;
        }

        return rtrim($host, '.');
    }

    public function forward(string $hostname): array
    {
        $ips = [];

        $a = @dns_get_record($hostname, DNS_A);
        if (is_array($a)) {
            foreach ($a as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
            }
        }

        $aaaa = @dns_get_record($hostname, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
