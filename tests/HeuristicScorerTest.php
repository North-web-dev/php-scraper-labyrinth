<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\HeuristicContext;
use NorthWebDev\Labyrinth\HeuristicScorer;
use NorthWebDev\Labyrinth\Request;
use PHPUnit\Framework\TestCase;

final class HeuristicScorerTest extends TestCase
{
    private function scorer(?Config $config = null): HeuristicScorer
    {
        return new HeuristicScorer($config ?? new Config());
    }

    /** A realistic browser request with a Referer and full metadata scores 0. */
    public function testCleanBrowserRequestScoresZero(): void
    {
        $request = new Request('198.51.100.1', 'GET', '/articles/the-news', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'Referer' => 'https://example.com/',
            'Accept-Language' => 'en-GB,en-US;q=0.9,en;q=0.8',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-CH-UA' => '"Chromium";v="140"',
        ]);

        $result = $this->scorer()->score($request, new HeuristicContext(hasPtr: true, hasPriorAssetRequests: true));

        self::assertSame(0, $result->total);
        self::assertFalse($result->flagged);
    }

    public function testLazyHarvesterTripsThreshold(): void
    {
        // Deep URL, no referer, no assets fetched, impossible UA, no PTR,
        // default accept-language, no fetch metadata => well over threshold.
        $request = new Request('203.0.113.50', 'GET', '/catalog/items/9001', [
            'User-Agent' => 'Mozilla/5.0 Chrome/155.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]);

        $result = $this->scorer()->score($request, new HeuristicContext(hasPtr: false, hasPriorAssetRequests: false));

        self::assertTrue($result->flagged);
        self::assertContains('deep_hit_no_referer', $result->firedSignals());
        self::assertContains('no_asset_requests', $result->firedSignals());
        self::assertContains('impossible_ua_version', $result->firedSignals());
        self::assertContains('datacenter_no_ptr', $result->firedSignals());
        self::assertContains('default_accept_language', $result->firedSignals());
        self::assertContains('missing_fetch_metadata', $result->firedSignals());
    }

    public function testImpossibleUaVersionDetection(): void
    {
        $scorer = $this->scorer();

        self::assertTrue($scorer->hasImpossibleUaVersion('Mozilla/5.0 Chrome/155.0.0.0'));
        self::assertTrue($scorer->hasImpossibleUaVersion('Mozilla/5.0 Firefox/151.0'));
        self::assertTrue($scorer->hasImpossibleUaVersion('Mozilla/5.0 CriOS/151.0'));

        // Plausible current versions must NOT fire.
        self::assertFalse($scorer->hasImpossibleUaVersion('Mozilla/5.0 Chrome/140.0.0.0'));
        self::assertFalse($scorer->hasImpossibleUaVersion('Mozilla/5.0 Firefox/139.0'));
        self::assertFalse($scorer->hasImpossibleUaVersion(''));
    }

    public function testDefaultAcceptLanguageExactMatchOnly(): void
    {
        $scorer = $this->scorer();

        self::assertTrue($scorer->hasDefaultAcceptLanguage('en-US,en;q=0.5'));
        // A real locale mix must not fire.
        self::assertFalse($scorer->hasDefaultAcceptLanguage('en-US,en;q=0.9,fr;q=0.8'));
        self::assertFalse($scorer->hasDefaultAcceptLanguage(null));
    }

    public function testMissingFetchMetadataDetection(): void
    {
        $scorer = $this->scorer();

        $bare = new Request('203.0.113.1', 'GET', '/', ['User-Agent' => 'x']);
        self::assertTrue($scorer->isMissingFetchMetadata($bare));

        $withSecFetch = new Request('203.0.113.1', 'GET', '/', ['Sec-Fetch-Site' => 'none']);
        self::assertFalse($scorer->isMissingFetchMetadata($withSecFetch));

        $withClientHint = new Request('203.0.113.1', 'GET', '/', ['Sec-CH-UA' => '"Chromium";v="140"']);
        self::assertFalse($scorer->isMissingFetchMetadata($withClientHint));
    }

    public function testDeepHitSignalRequiresDepthAndNoReferer(): void
    {
        $config = new Config(contentPathMinDepth: 2);
        $scorer = $this->scorer($config);

        // Shallow path => signal does not fire even without referer.
        $shallow = new Request('203.0.113.1', 'GET', '/about', ['Sec-Fetch-Mode' => 'navigate']);
        self::assertNotContains(
            'deep_hit_no_referer',
            $scorer->score($shallow, new HeuristicContext(hasPtr: true, hasPriorAssetRequests: true))->firedSignals(),
        );

        // Deep path with a referer => does not fire.
        $withReferer = new Request('203.0.113.1', 'GET', '/a/b/c', [
            'Referer' => 'https://example.com/',
            'Sec-Fetch-Mode' => 'navigate',
        ]);
        self::assertNotContains(
            'deep_hit_no_referer',
            $scorer->score($withReferer, new HeuristicContext(hasPtr: true, hasPriorAssetRequests: true))->firedSignals(),
        );
    }

    public function testUnknownPtrDoesNotFireDatacenterSignal(): void
    {
        $request = new Request('203.0.113.1', 'GET', '/a/b', ['Sec-Fetch-Mode' => 'navigate']);
        // hasPtr: null => unknown, must not contribute points.
        $result = $this->scorer()->score($request, new HeuristicContext(hasPtr: null, hasPriorAssetRequests: true));

        self::assertNotContains('datacenter_no_ptr', $result->firedSignals());
    }

    public function testThresholdIsConfigurable(): void
    {
        $request = new Request('203.0.113.1', 'GET', '/a/b', [
            'User-Agent' => 'curl/8.0',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]);
        $context = new HeuristicContext(hasPtr: true, hasPriorAssetRequests: true);

        // Default weights for this request: deep_hit(1) + default_lang(1) + missing_meta(2) = 4.
        $strict = new HeuristicScorer(new Config(heuristicThreshold: 10));
        self::assertFalse($strict->score($request, $context)->flagged);

        $loose = new HeuristicScorer(new Config(heuristicThreshold: 2));
        self::assertTrue($loose->score($request, $context)->flagged);
    }
}
