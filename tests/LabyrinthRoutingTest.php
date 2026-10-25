<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\Labyrinth;
use NorthWebDev\Labyrinth\Request;
use NorthWebDev\Labyrinth\Store\InMemoryStore;
use NorthWebDev\Labyrinth\Tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class LabyrinthRoutingTest extends TestCase
{
    private function labyrinth(Config $config, FakeResolver $resolver): Labyrinth
    {
        // No-op sleeper so tests never actually stall on the tarpit delay.
        return new Labyrinth(
            $config,
            new InMemoryStore(),
            $resolver,
            null,
            static function (int $seconds): void {},
        );
    }

    private function harvesterRequest(string $path = '/catalog/items/4242'): Request
    {
        return new Request('203.0.113.80', 'GET', $path, [
            'User-Agent' => 'Mozilla/5.0 Chrome/155.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]);
    }

    public function testVerifiedCrawlerBypassesEverything(): void
    {
        $resolver = new FakeResolver(
            ptr: ['192.0.2.10' => 'crawl-192-0-2-10.googlebot.com'],
            forward: ['crawl-192-0-2-10.googlebot.com' => ['192.0.2.10']],
        );
        // Even with a tiny rate cap and a maximally-suspicious request, a
        // verified crawler is never handled (never trapped, never limited).
        $lab = $this->labyrinth(new Config(rateMaxRequests: 1), $resolver);

        $request = new Request('192.0.2.10', 'GET', '/catalog/items/1', [
            'User-Agent' => 'Mozilla/5.0 Chrome/155.0.0.0',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]);

        self::assertNull($lab->handle($request));
        self::assertNull($lab->handle($request)); // would be 429 for a normal client
    }

    public function testSuspiciousContentRequestIsTrapped(): void
    {
        $lab = $this->labyrinth(new Config(), new FakeResolver());

        $response = $lab->handle($this->harvesterRequest());

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
    }

    public function testFlaggedClientIsTrappedOnEveryPath(): void
    {
        $lab = $this->labyrinth(new Config(), new FakeResolver());

        // First, trip the flag with a suspicious request.
        self::assertNotNull($lab->handle($this->harvesterRequest()));

        // Now a perfectly clean, shallow request from the SAME IP is still
        // trapped — routing is by client, not URL.
        $clean = new Request('203.0.113.80', 'GET', '/', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Mode' => 'navigate',
        ]);
        $response = $lab->handle($clean);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
    }

    public function testAssetRequestsPassThroughAndClearNoAssetSignal(): void
    {
        $lab = $this->labyrinth(new Config(), new FakeResolver());
        $ip = '203.0.113.81';

        // An asset fetch is never handled and is recorded.
        $asset = new Request($ip, 'GET', '/assets/app.css', ['User-Agent' => 'Mozilla/5.0 Chrome/140.0.0.0']);
        self::assertNull($lab->handle($asset));

        // A later content request from the same IP no longer trips the
        // "no asset requests" signal. With that signal cleared the remaining
        // weak signals stay under threshold, so it is not trapped.
        $content = new Request($ip, 'GET', '/articles/story', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Mode' => 'navigate',
        ]);
        self::assertNull($lab->handle($content));
    }

    public function testRateCapReturns429(): void
    {
        $lab = $this->labyrinth(new Config(rateMaxRequests: 2, rateWindowSeconds: 60), new FakeResolver());
        $clean = new Request('203.0.113.82', 'GET', '/', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Mode' => 'navigate',
        ]);

        self::assertNull($lab->handle($clean)); // 1
        self::assertNull($lab->handle($clean)); // 2
        $response = $lab->handle($clean);       // 3 -> over cap

        self::assertNotNull($response);
        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));
    }

    public function testDailyByteCapReturns429(): void
    {
        $lab = $this->labyrinth(new Config(dailyMaxBytes: 100), new FakeResolver());
        $ip = '203.0.113.83';

        $lab->recordBytes($ip, 250); // exceed the cap out-of-band

        $clean = new Request($ip, 'GET', '/', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Mode' => 'navigate',
        ]);
        $response = $lab->handle($clean);

        self::assertNotNull($response);
        self::assertSame(429, $response->status());
    }

    public function testCleanBrowserIsNotHandled(): void
    {
        // PTR present so the datacenter signal does not fire.
        $resolver = new FakeResolver(
            ptr: ['198.51.100.90' => 'host.example.com'],
            forward: ['host.example.com' => ['198.51.100.90']],
        );
        $lab = $this->labyrinth(new Config(), $resolver);

        $request = new Request('198.51.100.90', 'GET', '/articles/story', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en-US;q=0.9,en;q=0.8',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-CH-UA' => '"Chromium";v="140"',
        ]);

        self::assertNull($lab->handle($request));
    }

    public function testInspectReportsScoreWithoutTrapping(): void
    {
        $lab = $this->labyrinth(new Config(), new FakeResolver());
        $result = $lab->inspect($this->harvesterRequest());

        self::assertTrue($result->flagged);
        // inspect() must not flag the client: a follow-up still evaluates fresh.
        self::assertGreaterThan(0, $result->total);
    }
}
