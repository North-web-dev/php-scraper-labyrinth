<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

use NorthWebDev\Labyrinth\Store\StoreInterface;

/**
 * Per-IP volume caps: a fixed-window request-rate limit and a rolling daily
 * download (bytes) cap, both backed by a {@see StoreInterface}.
 *
 * This is the layer the README calls the real ceiling. Heuristics can be
 * satisfied by a careful headless+residential client; a hard cap on requests
 * and bytes per IP cannot, so it bounds even a well-disguised harvester. (A
 * farm rotating across many residential IPs still raises its effective total;
 * the cap bounds each IP, which is what you can enforce server-side.)
 *
 * Fixed-window counters are used deliberately: they are atomic in every
 * backend and cheap. The trade-off is burstiness at window edges, acceptable
 * for abuse-bounding (vs. precise fairness).
 */
final class RateLimiter
{
    public function __construct(
        private StoreInterface $store,
        private Config $config,
    ) {
    }

    /**
     * Record one request from $ip and report whether it is still within the
     * rate cap. Returns true (allowed) when the request cap is disabled.
     */
    public function registerRequest(string $ip): bool
    {
        if ($this->config->rateMaxRequests <= 0) {
            return true;
        }

        $count = $this->store->increment(
            $this->rateKey($ip),
            1,
            $this->config->rateWindowSeconds,
        );

        return $count <= $this->config->rateMaxRequests;
    }

    /** True if $ip has already exceeded the request cap in the current window. */
    public function isRateExceeded(string $ip): bool
    {
        if ($this->config->rateMaxRequests <= 0) {
            return false;
        }

        return $this->store->get($this->rateKey($ip)) > $this->config->rateMaxRequests;
    }

    /** Add $bytes to $ip's daily download total and return the new total. */
    public function addBytes(string $ip, int $bytes): int
    {
        if ($this->config->dailyMaxBytes <= 0 || $bytes <= 0) {
            return $this->store->get($this->bytesKey($ip));
        }

        return $this->store->increment($this->bytesKey($ip), $bytes, $this->config->dailyWindowSeconds);
    }

    /** True if $ip has exceeded the daily byte cap. */
    public function isDailyCapExceeded(string $ip): bool
    {
        if ($this->config->dailyMaxBytes <= 0) {
            return false;
        }

        return $this->store->get($this->bytesKey($ip)) > $this->config->dailyMaxBytes;
    }

    /** Suggested Retry-After value (seconds) for a 429 response. */
    public function retryAfterSeconds(): int
    {
        return max(1, $this->config->rateWindowSeconds);
    }

    private function rateKey(string $ip): string
    {
        return 'rate:' . $this->keyIp($ip);
    }

    private function bytesKey(string $ip): string
    {
        return 'bytes:' . $this->keyIp($ip);
    }

    private function keyIp(string $ip): string
    {
        return IpMatcher::normalise($ip) ?? $ip;
    }
}
