<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    public function testIpv4InsideAndOutsideRange(): void
    {
        self::assertTrue(IpMatcher::inRange('192.0.2.17', '192.0.2.0/24'));
        self::assertFalse(IpMatcher::inRange('198.51.100.17', '192.0.2.0/24'));
    }

    public function testIpv4NonByteAlignedPrefix(): void
    {
        // 192.0.2.0/25 covers .0-.127 only.
        self::assertTrue(IpMatcher::inRange('192.0.2.100', '192.0.2.0/25'));
        self::assertFalse(IpMatcher::inRange('192.0.2.200', '192.0.2.0/25'));
    }

    public function testSlashZeroMatchesEverything(): void
    {
        self::assertTrue(IpMatcher::inRange('203.0.113.1', '0.0.0.0/0'));
    }

    public function testIpv6Range(): void
    {
        self::assertTrue(IpMatcher::inRange('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(IpMatcher::inRange('2001:dead::1', '2001:db8::/32'));
    }

    public function testMixedFamilyNeverMatches(): void
    {
        self::assertFalse(IpMatcher::inRange('192.0.2.1', '2001:db8::/32'));
        self::assertFalse(IpMatcher::inRange('2001:db8::1', '192.0.2.0/24'));
    }

    public function testMalformedInputReturnsFalse(): void
    {
        self::assertFalse(IpMatcher::inRange('not-an-ip', '192.0.2.0/24'));
        self::assertFalse(IpMatcher::inRange('192.0.2.1', '192.0.2.0/notanint'));
        self::assertFalse(IpMatcher::inRange('192.0.2.1', '192.0.2.0/99'));
    }

    public function testInAnyRange(): void
    {
        $ranges = ['198.51.100.0/24', '192.0.2.0/24'];
        self::assertTrue(IpMatcher::inAnyRange('192.0.2.5', $ranges));
        self::assertFalse(IpMatcher::inAnyRange('203.0.113.5', $ranges));
    }

    public function testNormalise(): void
    {
        self::assertSame('192.0.2.1', IpMatcher::normalise('192.0.2.1'));
        self::assertSame('2001:db8::1', IpMatcher::normalise('2001:db8:0:0:0:0:0:1'));
        self::assertNull(IpMatcher::normalise('garbage'));
    }
}
