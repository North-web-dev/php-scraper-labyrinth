<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Scores a request against signals commonly seen in bulk-harvester traffic.
 *
 * This is a HEURISTIC layer: it flags, it does not hard-block. Each signal is
 * individually weak and defeatable by a careful client; weights are summed and
 * compared to a threshold so that only a combination trips the labyrinth. See
 * the README threat model — a full headless browser on residential proxies can
 * satisfy every signal here, which is why the volume cap, not this scorer, is
 * the real ceiling.
 *
 * Signals (default weights in {@see Config::DEFAULT_WEIGHTS}):
 *   deep_hit_no_referer      Deep content URL fetched with no Referer.
 *   no_asset_requests        IP fetches HTML but has never requested a CSS/JS/image.
 *   impossible_ua_version    UA claims a browser major version beyond plausibility.
 *   datacenter_no_ptr        Client IP has no PTR record (typical of harvest VMs).
 *   default_accept_language  Accept-Language is exactly the stdlib default, no locale mix.
 *   missing_fetch_metadata   No Sec-Fetch-* and no Sec-CH-UA (real browsers send these).
 */
final class HeuristicScorer
{
    public function __construct(private Config $config)
    {
    }

    public function score(Request $request, HeuristicContext $context): ScoreResult
    {
        $weights = $this->config->heuristicWeights;
        $fired = [];

        if ($this->isDeepHitWithoutReferer($request)) {
            $fired['deep_hit_no_referer'] = $weights['deep_hit_no_referer'] ?? 0;
        }

        if ($context->hasPriorAssetRequests === false) {
            $fired['no_asset_requests'] = $weights['no_asset_requests'] ?? 0;
        }

        if ($this->hasImpossibleUaVersion($request->header('user-agent') ?? '')) {
            $fired['impossible_ua_version'] = $weights['impossible_ua_version'] ?? 0;
        }

        if ($context->hasPtr === false) {
            $fired['datacenter_no_ptr'] = $weights['datacenter_no_ptr'] ?? 0;
        }

        if ($this->hasDefaultAcceptLanguage($request->header('accept-language'))) {
            $fired['default_accept_language'] = $weights['default_accept_language'] ?? 0;
        }

        if ($this->isMissingFetchMetadata($request)) {
            $fired['missing_fetch_metadata'] = $weights['missing_fetch_metadata'] ?? 0;
        }

        // Drop zero-weight signals so the breakdown reflects real contributions.
        $fired = array_filter($fired, static fn (int $points): bool => $points !== 0);

        $total = array_sum($fired);

        return new ScoreResult($total, $fired, $total >= $this->config->heuristicThreshold);
    }

    private function isDeepHitWithoutReferer(Request $request): bool
    {
        if ($request->hasHeader('referer')) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $request->path()), static fn (string $s): bool => $s !== ''));

        return count($segments) >= $this->config->contentPathMinDepth;
    }

    /**
     * Flags a UA that advertises a browser major version higher than any that
     * could plausibly exist. Catches naive version-rotating harvesters (e.g.
     * "Chrome/155"). Must be kept current via Config::maxPlausibleUaVersions.
     */
    public function hasImpossibleUaVersion(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        foreach ($this->config->maxPlausibleUaVersions as $product => $maxMajor) {
            // Match "Product/NN" at a token boundary; capture the major version.
            if (preg_match('#(?<![\w.])' . preg_quote((string) $product, '#') . '/(\d+)#', $userAgent, $m) === 1) {
                if ((int) $m[1] > (int) $maxMajor) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True only for the exact stdlib default string ("en-US,en;q=0.5") that
     * many HTTP client libraries send verbatim. A real browser sends the
     * user's locale mix, so the exact-match default is a weak tell.
     */
    public function hasDefaultAcceptLanguage(?string $acceptLanguage): bool
    {
        return $acceptLanguage !== null && trim($acceptLanguage) === 'en-US,en;q=0.5';
    }

    /**
     * Real browsers attach Fetch Metadata (Sec-Fetch-*) and/or Client Hints
     * (Sec-CH-UA) on navigations. A request with none of them is unlikely to
     * be a mainstream browser. (Older browsers may omit Client Hints, hence
     * this is a weak, combinable signal — not a block.)
     */
    public function isMissingFetchMetadata(Request $request): bool
    {
        foreach ($request->headers() as $name => $_value) {
            if (str_starts_with($name, 'sec-fetch-') || $name === 'sec-ch-ua') {
                return false;
            }
        }

        return true;
    }
}
