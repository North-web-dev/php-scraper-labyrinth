<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Resolver;

/**
 * DNS lookups needed for forward-confirmed reverse DNS (FCrDNS) verification.
 *
 * Abstracted so tests can inject a deterministic resolver and so hosts can
 * swap in a caching / timeout-controlled implementation. The default
 * {@see SystemResolver} uses PHP's blocking system resolver.
 */
interface ResolverInterface
{
    /**
     * Reverse lookup: PTR record for an IP address.
     *
     * @return string|null The hostname, or null if there is no PTR record.
     */
    public function reverse(string $ip): ?string;

    /**
     * Forward lookup: all A and AAAA addresses for a hostname.
     *
     * @return list<string> IP addresses (may be empty).
     */
    public function forward(string $hostname): array;
}
