<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Store;

use Redis;

/**
 * Redis-backed store (phpredis). The right choice for multi-server
 * deployments: counters are shared, so a scraper cannot multiply its quota by
 * hitting different app servers.
 *
 * Accepts a configured \Redis connection. INCRBY is atomic; a TTL is set only
 * when the key is first created (NX) so a fixed window expires on schedule.
 */
final class RedisStore implements StoreInterface
{
    public function __construct(
        private Redis $redis,
        private string $prefix = 'labyrinth:',
    ) {
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $k = $this->prefix . $key;
        $value = (int) $this->redis->incrBy($k, $by);

        // Set the expiry only on creation (value just became $by).
        if ($value === $by && $ttlSeconds > 0) {
            $this->redis->expire($k, $ttlSeconds);
        }

        return $value;
    }

    public function get(string $key): int
    {
        $value = $this->redis->get($this->prefix . $key);

        return $value === false ? 0 : (int) $value;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $k = $this->prefix . $key;
        if ($ttlSeconds > 0) {
            $this->redis->setex($k, $ttlSeconds, (string) $value);
        } else {
            $this->redis->set($k, (string) $value);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
