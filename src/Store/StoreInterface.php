<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Store;

/**
 * Pluggable counter/flag store used for volume caps and flagged-client
 * tracking.
 *
 * The data model is intentionally tiny: integer counters with a TTL. Rate
 * windows, daily byte totals, asset-request counts and the "this client is
 * flagged" marker are all expressed as counters. This keeps every backend
 * (file, APCu, Redis) trivial to implement correctly.
 */
interface StoreInterface
{
    /**
     * Atomically add $by to the counter at $key and return the new value.
     *
     * When the key does not yet exist it is created with value $by and the
     * given TTL. The TTL of an existing key SHOULD NOT be extended on
     * increment (so a fixed-window counter expires on schedule).
     *
     * @param int $ttlSeconds Lifetime for a freshly-created key, in seconds.
     */
    public function increment(string $key, int $by, int $ttlSeconds): int;

    /** Current value of $key, or 0 if missing/expired. */
    public function get(string $key): int;

    /** Set $key to $value with the given TTL (overwrites any existing value). */
    public function set(string $key, int $value, int $ttlSeconds): void;

    /** Remove $key. No-op if absent. */
    public function delete(string $key): void;
}
