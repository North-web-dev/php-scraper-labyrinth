<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

use NorthWebDev\Labyrinth\Resolver\ResolverInterface;

/**
 * Confirms whether a client is a genuine search-engine crawler, so it can
 * bypass every defence.
 *
 * TRAPPING A REAL CRAWLER = SEARCH DEINDEXING. This check must err on the side
 * of letting crawlers through, which is why it combines two independent paths:
 *
 *  1. Forward-confirmed reverse DNS (FCrDNS) — the authoritative method used by
 *     Google/Bing/Yandex themselves:
 *       a. PTR lookup of the client IP yields a hostname.
 *       b. That hostname ends in an official crawler domain suffix.
 *       c. A forward (A/AAAA) lookup of that hostname resolves back to the
 *          original client IP.
 *     Spoofing requires control of reverse DNS for the IP's owning netblock,
 *     which an attacker on a random IP does not have.
 *
 *  2. An OPTIONAL static CIDR safety net (you supply the ranges). PTR lookups
 *     can be slow or time out; a published crawler IP range catches the crawler
 *     instantly without DNS. Ranges go stale — keep them fresh and treat rDNS
 *     as the source of truth.
 */
final class CrawlerVerifier
{
    /**
     * Official PTR hostname suffixes for the major crawlers. A client's PTR
     * record must end in one of these (after which forward confirmation still
     * has to pass).
     *
     * @var list<string>
     */
    public const DEFAULT_SUFFIXES = [
        'googlebot.com',
        'google.com',
        'search.msn.com',
        'yandex.com',
        'yandex.net',
        'yandex.ru',
    ];

    /** @var list<string> */
    private array $suffixes;

    /** @var list<string> */
    private array $staticRanges;

    /**
     * @param list<string>|null $suffixes     Override the allowed PTR suffixes.
     * @param list<string>      $staticRanges Optional verified-crawler CIDR ranges (empty = rDNS only).
     */
    public function __construct(
        private ResolverInterface $resolver,
        ?array $suffixes = null,
        array $staticRanges = [],
    ) {
        $this->suffixes = $suffixes ?? self::DEFAULT_SUFFIXES;
        $this->staticRanges = $staticRanges;
    }

    /**
     * Is this IP a verified crawler?
     *
     * @param string $clientIp The client IP (already de-proxied by the caller).
     */
    public function verify(string $clientIp): bool
    {
        if ($clientIp === '') {
            return false;
        }

        // Fast path: static published ranges, no DNS required.
        if ($this->staticRanges !== [] && IpMatcher::inAnyRange($clientIp, $this->staticRanges)) {
            return true;
        }

        return $this->verifyByRdns($clientIp);
    }

    /**
     * Forward-confirmed reverse DNS check, exposed separately so it can be
     * unit-tested and cached independently of the static net.
     */
    public function verifyByRdns(string $clientIp): bool
    {
        $host = $this->resolver->reverse($clientIp);
        if ($host === null) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));
        if (!$this->hostMatchesSuffix($host)) {
            return false;
        }

        // Forward-confirm: the hostname must resolve back to this exact IP.
        $normalisedClient = IpMatcher::normalise($clientIp);
        if ($normalisedClient === null) {
            return false;
        }

        foreach ($this->resolver->forward($host) as $forwardIp) {
            if (IpMatcher::normalise($forwardIp) === $normalisedClient) {
                return true;
            }
        }

        return false;
    }

    private function hostMatchesSuffix(string $host): bool
    {
        foreach ($this->suffixes as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
