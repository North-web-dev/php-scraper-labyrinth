<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests\Support;

use NorthWebDev\Labyrinth\Resolver\ResolverInterface;

/**
 * Deterministic in-memory resolver for tests. Maps IP => PTR hostname and
 * hostname => forward IPs, so FCrDNS logic can be exercised without real DNS.
 */
final class FakeResolver implements ResolverInterface
{
    /**
     * @param array<string, string>        $ptr     IP => PTR hostname
     * @param array<string, list<string>>  $forward hostname => IPs
     */
    public function __construct(
        private array $ptr = [],
        private array $forward = [],
    ) {
    }

    public function reverse(string $ip): ?string
    {
        return $this->ptr[$ip] ?? null;
    }

    public function forward(string $hostname): array
    {
        return $this->forward[$hostname] ?? [];
    }
}
