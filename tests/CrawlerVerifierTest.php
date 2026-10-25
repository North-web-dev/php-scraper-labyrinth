<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\CrawlerVerifier;
use NorthWebDev\Labyrinth\Tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class CrawlerVerifierTest extends TestCase
{
    public function testForwardConfirmedGooglebotIsVerified(): void
    {
        // 192.0.2.10 is an RFC 5737 documentation address (never a real host).
        $resolver = new FakeResolver(
            ptr: ['192.0.2.10' => 'crawl-192-0-2-10.googlebot.com'],
            forward: ['crawl-192-0-2-10.googlebot.com' => ['192.0.2.10']],
        );
        $verifier = new CrawlerVerifier($resolver);

        self::assertTrue($verifier->verify('192.0.2.10'));
    }

    public function testBingAndYandexSuffixesAreAccepted(): void
    {
        $resolver = new FakeResolver(
            ptr: [
                '192.0.2.20' => 'msnbot-192-0-2-20.search.msn.com',
                '192.0.2.30' => 'spider-192-0-2-30.yandex.com',
            ],
            forward: [
                'msnbot-192-0-2-20.search.msn.com' => ['192.0.2.20'],
                'spider-192-0-2-30.yandex.com' => ['192.0.2.30'],
            ],
        );
        $verifier = new CrawlerVerifier($resolver);

        self::assertTrue($verifier->verify('192.0.2.20'));
        self::assertTrue($verifier->verify('192.0.2.30'));
    }

    public function testSpoofedPtrWithoutForwardConfirmationIsRejected(): void
    {
        // Attacker sets a PTR ending in googlebot.com, but the forward lookup
        // of that hostname does NOT resolve back to their IP.
        $resolver = new FakeResolver(
            ptr: ['198.51.100.5' => 'totally.googlebot.com'],
            forward: ['totally.googlebot.com' => ['203.0.113.9']],
        );
        $verifier = new CrawlerVerifier($resolver);

        self::assertFalse($verifier->verify('198.51.100.5'));
    }

    public function testNonCrawlerSuffixIsRejected(): void
    {
        $resolver = new FakeResolver(
            ptr: ['198.51.100.6' => 'host.example.com'],
            forward: ['host.example.com' => ['198.51.100.6']],
        );
        $verifier = new CrawlerVerifier($resolver);

        self::assertFalse($verifier->verify('198.51.100.6'));
    }

    public function testNoPtrRecordIsRejected(): void
    {
        $verifier = new CrawlerVerifier(new FakeResolver());

        self::assertFalse($verifier->verify('198.51.100.7'));
    }

    public function testStaticRangeSafetyNetBypassesDns(): void
    {
        // No PTR at all, but IP falls in a configured static crawler range.
        $verifier = new CrawlerVerifier(new FakeResolver(), null, ['192.0.2.0/24']);

        self::assertTrue($verifier->verify('192.0.2.200'));
        self::assertFalse($verifier->verify('198.51.100.200'));
    }

    public function testEmptyIpIsNotVerified(): void
    {
        $verifier = new CrawlerVerifier(new FakeResolver());

        self::assertFalse($verifier->verify(''));
    }
}
