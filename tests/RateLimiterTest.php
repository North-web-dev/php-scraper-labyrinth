<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\RateLimiter;
use NorthWebDev\Labyrinth\Store\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testRequestRateCapAllowsUpToLimitThenBlocks(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(rateMaxRequests: 3, rateWindowSeconds: 60));
        $ip = '203.0.113.1';

        self::assertTrue($limiter->registerRequest($ip));   // 1
        self::assertTrue($limiter->registerRequest($ip));   // 2
        self::assertTrue($limiter->registerRequest($ip));   // 3
        self::assertFalse($limiter->registerRequest($ip));  // 4 -> over
        self::assertTrue($limiter->isRateExceeded($ip));
    }

    public function testRateWindowResetsAfterExpiry(): void
    {
        $now = 1_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $store = new InMemoryStore($clock);
        $limiter = new RateLimiter($store, new Config(rateMaxRequests: 1, rateWindowSeconds: 60));
        $ip = '203.0.113.2';

        self::assertTrue($limiter->registerRequest($ip));
        self::assertFalse($limiter->registerRequest($ip));

        $now += 61; // window elapsed
        self::assertTrue($limiter->registerRequest($ip));
    }

    public function testRateCapDisabledWhenZero(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(rateMaxRequests: 0));
        $ip = '203.0.113.3';

        for ($i = 0; $i < 50; $i++) {
            self::assertTrue($limiter->registerRequest($ip));
        }
        self::assertFalse($limiter->isRateExceeded($ip));
    }

    public function testDailyByteCap(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(dailyMaxBytes: 1_000));
        $ip = '203.0.113.4';

        $limiter->addBytes($ip, 600);
        self::assertFalse($limiter->isDailyCapExceeded($ip));

        $limiter->addBytes($ip, 600); // total 1200 > 1000
        self::assertTrue($limiter->isDailyCapExceeded($ip));
    }

    public function testDailyByteCapDisabledWhenZero(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(dailyMaxBytes: 0));
        $ip = '203.0.113.5';

        $limiter->addBytes($ip, 10_000_000);
        self::assertFalse($limiter->isDailyCapExceeded($ip));
    }

    public function testCountersArePerIp(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(rateMaxRequests: 1));

        self::assertTrue($limiter->registerRequest('203.0.113.6'));
        self::assertFalse($limiter->registerRequest('203.0.113.6'));
        // A different IP has its own budget.
        self::assertTrue($limiter->registerRequest('203.0.113.7'));
    }

    public function testRetryAfterIsPositive(): void
    {
        $limiter = new RateLimiter(new InMemoryStore(), new Config(rateWindowSeconds: 30));
        self::assertSame(30, $limiter->retryAfterSeconds());
    }
}
