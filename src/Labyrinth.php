<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

use NorthWebDev\Labyrinth\Resolver\ResolverInterface;
use NorthWebDev\Labyrinth\Resolver\SystemResolver;
use NorthWebDev\Labyrinth\Store\StoreInterface;

/**
 * The drop-in entry point. Wire it once and call {@see Labyrinth::handle()} at
 * the very top of your front controller.
 *
 * Request handling, in order:
 *   1. Verified-crawler allowlist. A confirmed Googlebot/Bingbot/Yandex
 *      bypasses EVERYTHING (never rate-limited, never trapped). Trapping a real
 *      crawler deindexes you — see README.
 *   2. Volume caps (applied to non-verified clients). Over the per-IP request
 *      rate or daily byte cap => 429. This is the real ceiling.
 *   3. Route-by-client: an already-flagged client gets the labyrinth on every
 *      path, regardless of URL.
 *   4. Assets pass through and are recorded (feeds the "fetches HTML but never
 *      assets" signal).
 *   5. Heuristic scoring of content requests. At/above threshold => flag the
 *      client and serve the labyrinth. Otherwise the request is not handled
 *      (null) and your app serves it normally.
 */
final class Labyrinth
{
    private CrawlerVerifier $verifier;
    private ResolverInterface $resolver;
    private HeuristicScorer $scorer;
    private LabyrinthRenderer $renderer;
    private RateLimiter $rateLimiter;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper Stall function for the tarpit delay. Defaults to sleep();
     *                                            injectable so tests don't actually block.
     */
    public function __construct(
        private Config $config,
        private StoreInterface $store,
        ?ResolverInterface $resolver = null,
        ?CrawlerVerifier $verifier = null,
        ?callable $sleeper = null,
    ) {
        $this->resolver = $resolver ?? new SystemResolver();
        $this->verifier = $verifier ?? new CrawlerVerifier(
            $this->resolver,
            null,
            $this->config->staticCrawlerRanges,
        );
        $this->scorer = new HeuristicScorer($this->config);
        $this->renderer = new LabyrinthRenderer($this->config);
        $this->rateLimiter = new RateLimiter($this->store, $this->config);
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * Inspect the request and decide what to do.
     *
     * @return Response|null Non-null: the library produced the output — send it
     *                       and stop. Null: not handled — let your app serve it.
     */
    public function handle(Request $request): ?Response
    {
        $ip = $request->clientIp();

        // 1. Verified crawlers bypass everything.
        if ($this->isVerifiedCrawler($ip)) {
            return null;
        }

        // 2. Volume caps (the real ceiling).
        $withinRate = $this->rateLimiter->registerRequest($ip);
        if (!$withinRate || $this->rateLimiter->isDailyCapExceeded($ip)) {
            return $this->tooManyRequests();
        }

        // 3. Route-by-client: a flagged client is trapped on every path.
        if ($this->isFlagged($ip)) {
            return $this->trap($request, $ip);
        }

        // 4. Assets pass through, and the fetch is recorded.
        if ($this->isAssetPath($request->path())) {
            $this->recordAssetRequest($ip);

            return null;
        }

        // 5. Score content requests. Record this content hit first so the
        //    "fetches HTML but never assets" signal reflects accumulated
        //    behaviour (and never fires on a visitor's very first pageview).
        $this->store->increment('content:' . $this->ipKey($ip), 1, $this->config->dailyWindowSeconds);

        $result = $this->scorer->score($request, $this->buildContext($ip));
        if ($result->flagged) {
            $this->flagClient($ip);

            return $this->trap($request, $ip);
        }

        return null;
    }

    /** Score a request without side effects (useful for logging/monitoring). */
    public function inspect(Request $request): ScoreResult
    {
        return $this->scorer->score($request, $this->buildContext($request->clientIp()));
    }

    /**
     * Report bytes your application served to a client, so they count toward
     * the per-IP daily download cap. Call after sending a normal (non-library)
     * response. Verified crawlers should be skipped by the caller.
     */
    public function recordBytes(string $ip, int $bytes): void
    {
        $this->rateLimiter->addBytes($ip, $bytes);
    }

    public function isVerifiedCrawler(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if ($this->config->verifyCacheSeconds <= 0) {
            return $this->verifier->verify($ip);
        }

        $key = 'verify:' . (IpMatcher::normalise($ip) ?? $ip);
        $cached = $this->store->get($key); // 0 = miss, 1 = verified, 2 = not verified
        if ($cached === 1) {
            return true;
        }
        if ($cached === 2) {
            return false;
        }

        $verified = $this->verifier->verify($ip);
        $this->store->set($key, $verified ? 1 : 2, $this->config->verifyCacheSeconds);

        return $verified;
    }

    private function buildContext(string $ip): HeuristicContext
    {
        $hasPtr = null;
        // Only pay for a PTR lookup if the signal can actually score.
        if (($this->config->heuristicWeights['datacenter_no_ptr'] ?? 0) > 0) {
            $hasPtr = $this->hasPtr($ip);
        }

        $key = $this->ipKey($ip);
        $hasAssets = $this->store->get('asset:' . $key) > 0;
        $contentHits = $this->store->get('content:' . $key);

        // Report "has assets" (benefit of the doubt) until the IP has pulled
        // several content pages with none — only then is the absence telling.
        $hasPriorAssetRequests = $hasAssets || $contentHits < $this->config->noAssetMinContentHits;

        return new HeuristicContext(
            hasPtr: $hasPtr,
            hasPriorAssetRequests: $hasPriorAssetRequests,
        );
    }

    private function hasPtr(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if ($this->config->verifyCacheSeconds <= 0) {
            return $this->resolver->reverse($ip) !== null;
        }

        $key = 'ptr:' . $this->ipKey($ip);
        $cached = $this->store->get($key); // 0 = miss, 1 = has PTR, 2 = none
        if ($cached === 1) {
            return true;
        }
        if ($cached === 2) {
            return false;
        }

        $has = $this->resolver->reverse($ip) !== null;
        $this->store->set($key, $has ? 1 : 2, $this->config->verifyCacheSeconds);

        return $has;
    }

    private function isAssetPath(string $path): bool
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->config->assetExtensions, true);
    }

    private function recordAssetRequest(string $ip): void
    {
        // Remembered for the rolling daily window so the "no assets" signal
        // reflects recent behaviour.
        $this->store->increment('asset:' . $this->ipKey($ip), 1, $this->config->dailyWindowSeconds);
    }

    private function isFlagged(string $ip): bool
    {
        return $this->store->get('flag:' . $this->ipKey($ip)) > 0;
    }

    private function flagClient(string $ip): void
    {
        $this->store->set('flag:' . $this->ipKey($ip), 1, $this->config->flaggedClientTtlSeconds);
    }

    /** Apply the tarpit delay, render the maze, and count its bytes. */
    private function trap(Request $request, string $ip): Response
    {
        ($this->sleeper)($this->config->tarpitDelaySeconds);

        $page = $this->currentPage($request);
        $response = $this->renderer->render($request->path(), $page);

        $this->rateLimiter->addBytes($ip, strlen($response->body()));

        return $response;
    }

    private function currentPage(Request $request): int
    {
        // Our own paginator uses ?page=N.
        $page = (int) ($request->query('page') ?? 1);

        return max(1, $page);
    }

    private function tooManyRequests(): Response
    {
        return new Response(
            429,
            "<!DOCTYPE html><html><head><meta name=\"robots\" content=\"noindex\"><title>Too Many Requests</title></head>"
                . "<body><h1>429 Too Many Requests</h1><p>Rate limit exceeded. Please slow down.</p></body></html>",
            [
                'Retry-After' => (string) $this->rateLimiter->retryAfterSeconds(),
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }

    private function ipKey(string $ip): string
    {
        return IpMatcher::normalise($ip) ?? $ip;
    }
}
