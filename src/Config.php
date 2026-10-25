<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Immutable configuration for {@see Labyrinth}.
 *
 * Every value has a documented default; override any subset via the
 * constructor's named arguments. No secrets are baked in — anything sensitive
 * (e.g. the optional static crawler IP ranges) is supplied by you.
 */
final class Config
{
    /**
     * @param int                             $heuristicThreshold Minimum heuristic score to treat a client as a
     *                                                            suspected scraper and route it into the labyrinth.
     * @param array<string, int>              $heuristicWeights   Per-signal point weights. Keys are the signal
     *                                                            names from {@see HeuristicScorer}. Override to
     *                                                            tune sensitivity.
     * @param int                             $contentPathMinDepth Path segment count at/above which a request counts
     *                                                            as a "deep content hit" for the no-referer signal.
     * @param int                             $noAssetMinContentHits How many content pages an IP must fetch (with zero
     *                                                            static-asset requests) before the "no asset requests"
     *                                                            signal fires. Prevents trapping a visitor's first
     *                                                            pageview, which legitimately has no prior assets.
     * @param array<string, int>              $maxPlausibleUaVersions Product => highest believable major version.
     *                                                            A UA claiming a higher version is flagged as
     *                                                            impossible/rotating. MUST be bumped over time as
     *                                                            real browsers advance (see README — honest limit).
     * @param list<string>                    $assetExtensions    File extensions treated as static assets. Asset
     *                                                            requests are recorded (to detect "fetches HTML but
     *                                                            never assets") and always passed through. MUST NOT
     *                                                            include data formats (json/xml/csv/txt); allowing
     *                                                            them lets a scraper append the extension to a content
     *                                                            path and bypass scoring. Conservative by default.
     * @param list<string>                    $baitPaths          Link-only bait paths placed in the labyrinth. They
     *                                                            are served junk, never real files. Use obviously
     *                                                            tempting but harmless names.
     * @param int                             $tarpitDelaySeconds Seconds to stall a flagged client before responding.
     *                                                            0 disables the delay. See README for non-blocking
     *                                                            guidance (do not stall a blocking FPM worker long).
     * @param int                             $tarpitLinksPerPage Fake internal links to emit per labyrinth page.
     * @param int                             $tarpitFakePageCount "Page X of N" upper bound shown by the fake paginator.
     * @param int                             $flaggedClientTtlSeconds How long a flagged client keeps being routed into
     *                                                            the labyrinth on every path (route-by-client).
     * @param int                             $rateWindowSeconds  Fixed window length for the per-IP request-rate cap.
     * @param int                             $rateMaxRequests    Max requests per IP per window before 429. 0 disables.
     * @param int                             $dailyMaxBytes      Per-IP rolling daily download cap in bytes before 429.
     *                                                            0 disables. Requires your app to report served bytes
     *                                                            via Labyrinth::recordBytes() for full accounting.
     * @param int                             $dailyWindowSeconds Window length for the daily byte cap (default 86400).
     * @param list<string>                    $staticCrawlerRanges Optional CIDR safety net of verified-crawler IP
     *                                                            ranges (e.g. Google's published Googlebot ranges).
     *                                                            Empty by default — YOU supply current ranges; they
     *                                                            go stale, so prefer rDNS and refresh these.
     * @param int                             $verifyCacheSeconds TTL for caching a per-IP crawler-verification and
     *                                                            PTR result in the store, to avoid repeating slow
     *                                                            DNS lookups on every request. 0 disables caching.
     */
    public function __construct(
        public int $heuristicThreshold = 4,
        public array $heuristicWeights = self::DEFAULT_WEIGHTS,
        public int $contentPathMinDepth = 2,
        public int $noAssetMinContentHits = 3,
        public array $maxPlausibleUaVersions = self::DEFAULT_MAX_UA_VERSIONS,
        public array $assetExtensions = self::DEFAULT_ASSET_EXTENSIONS,
        public array $baitPaths = self::DEFAULT_BAIT_PATHS,
        public int $tarpitDelaySeconds = 2,
        public int $tarpitLinksPerPage = 15,
        public int $tarpitFakePageCount = 9999,
        public int $flaggedClientTtlSeconds = 3600,
        public int $rateWindowSeconds = 60,
        public int $rateMaxRequests = 120,
        public int $dailyMaxBytes = 536_870_912, // 512 MiB
        public int $dailyWindowSeconds = 86_400,
        public array $staticCrawlerRanges = [],
        public int $verifyCacheSeconds = 3_600,
    ) {
    }

    public const DEFAULT_WEIGHTS = [
        'deep_hit_no_referer' => 1,
        'no_asset_requests' => 1,
        'impossible_ua_version' => 3,
        'datacenter_no_ptr' => 2,
        'default_accept_language' => 1,
        'missing_fetch_metadata' => 2,
    ];

    /**
     * Conservative upper bounds as of this release. These WILL need raising as
     * browsers ship new majors; an out-of-date bound produces false positives,
     * never deindexing (verified crawlers bypass heuristics entirely).
     */
    public const DEFAULT_MAX_UA_VERSIONS = [
        'Chrome' => 145,
        'CriOS' => 145,
        'Edg' => 145,
        'Firefox' => 140,
        'FxiOS' => 140,
        'OPR' => 125,
        'Version' => 20, // Safari marketing version
    ];

    /**
     * TRUE static assets only. Deliberately conservative: data formats
     * (json, xml, csv, txt, …) are EXCLUDED, because treating them as assets
     * would let a scraper append e.g. ".json" to a content path and skip
     * scoring entirely. Extension matching is a cheap default; for stronger
     * guarantees detect assets by a configured path prefix (e.g. "/assets/")
     * or by your app's response content-type instead of extension.
     */
    public const DEFAULT_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'map',
    ];

    /** Link-only honeypot paths. Harmless names; served junk, never real files. */
    public const DEFAULT_BAIT_PATHS = [
        '/backup',
        '/.env',
        '/admin/export',
        '/wp-admin/',
        '/config.bak',
    ];
}
