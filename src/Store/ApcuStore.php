<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Store;

use RuntimeException;

/**
 * APCu-backed store: fast, in-memory, per-server.
 *
 * Counters live in the PHP worker pool's shared memory, so they are not shared
 * between servers (same caveat as FileStore) and are lost on an opcache/pool
 * restart. Ideal for a single busy server where filesystem I/O is the bottleneck.
 */
final class ApcuStore implements StoreInterface
{
    public function __construct(private string $prefix = 'labyrinth:')
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not available/enabled.');
        }
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $k = $this->prefix . $key;
        $success = false;
        // apcu_inc creates the key (with the given TTL) when absent and is atomic.
        $value = apcu_inc($k, $by, $success, $ttlSeconds);

        return $success && is_int($value) ? $value : $by;
    }

    public function get(string $key): int
    {
        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success && is_int($value) ? $value : 0;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        apcu_store($this->prefix . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        apcu_delete($this->prefix . $key);
    }
}
