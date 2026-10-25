<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Store;

/**
 * Process-local, non-persistent store.
 *
 * State lives only for the current PHP process and is never shared. Intended
 * for unit tests and for single-request CLI/experiment use — NOT for enforcing
 * volume caps in production (each request is usually its own process, so
 * counters would always reset). A clock callable is injectable so expiry can
 * be tested deterministically.
 */
final class InMemoryStore implements StoreInterface
{
    /** @var array<string, array{value: int, exp: int|null}> */
    private array $data = [];

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Returns current unix time; defaults to time().
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $this->expireIfNeeded($key);

        if (!isset($this->data[$key])) {
            $this->data[$key] = [
                'value' => $by,
                'exp' => $ttlSeconds > 0 ? ($this->clock)() + $ttlSeconds : null,
            ];

            return $by;
        }

        $this->data[$key]['value'] += $by;

        return $this->data[$key]['value'];
    }

    public function get(string $key): int
    {
        $this->expireIfNeeded($key);

        return $this->data[$key]['value'] ?? 0;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->data[$key] = [
            'value' => $value,
            'exp' => $ttlSeconds > 0 ? ($this->clock)() + $ttlSeconds : null,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    private function expireIfNeeded(string $key): void
    {
        if (!isset($this->data[$key])) {
            return;
        }
        $exp = $this->data[$key]['exp'];
        if ($exp !== null && $exp <= ($this->clock)()) {
            unset($this->data[$key]);
        }
    }
}
