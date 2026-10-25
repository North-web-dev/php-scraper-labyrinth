<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Store;

use RuntimeException;

/**
 * Filesystem-backed store. The default: zero dependencies, works everywhere.
 *
 * Each key is a single JSON file ({"v": int, "exp": int|null}) under a
 * directory, accessed under an exclusive flock so concurrent PHP workers on
 * one host increment safely. Expired entries are treated as absent and
 * rewritten lazily.
 *
 * Suitable for a single server. For a multi-server deployment use
 * {@see RedisStore} so counters are shared; a per-host FileStore would let a
 * scraper get N times the quota across N app servers.
 */
final class FileStore implements StoreInterface
{
    public function __construct(private string $directory)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            throw new RuntimeException("FileStore directory is not writable: {$this->directory}");
        }
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        return $this->withLock($key, function ($handle) use ($by, $ttlSeconds): int {
            [$value, $exp] = $this->readLocked($handle);

            if ($value === null) {
                // Fresh key: establish value and expiry.
                $value = $by;
                $exp = $ttlSeconds > 0 ? time() + $ttlSeconds : null;
            } else {
                // Existing, unexpired key: bump value, keep original expiry.
                $value += $by;
            }

            $this->writeLocked($handle, $value, $exp);

            return $value;
        });
    }

    public function get(string $key): int
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return 0;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return 0;
            }
            [$value] = $this->readLocked($handle);
            flock($handle, LOCK_UN);

            return $value ?? 0;
        } finally {
            fclose($handle);
        }
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->withLock($key, function ($handle) use ($value, $ttlSeconds): int {
            $this->writeLocked($handle, $value, $ttlSeconds > 0 ? time() + $ttlSeconds : null);

            return $value;
        });
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'lab_' . hash('sha256', $key) . '.json';
    }

    /**
     * @param callable(resource):int $mutator
     */
    private function withLock(string $key, callable $mutator): int
    {
        $path = $this->path($key);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Cannot open store file: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Cannot lock store file: {$path}");
            }

            return $mutator($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array{0: int|null, 1: int|null} [value, expiry] — value null when absent/expired.
     */
    private function readLocked($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || $raw === '') {
            return [null, null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['v'])) {
            return [null, null];
        }

        $exp = isset($data['exp']) ? (int) $data['exp'] : null;
        if ($exp !== null && $exp <= time()) {
            return [null, null];
        }

        return [(int) $data['v'], $exp];
    }

    /**
     * @param resource $handle
     */
    private function writeLocked($handle, int $value, ?int $exp): void
    {
        $payload = json_encode(['v' => $value, 'exp' => $exp], JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $payload);
        fflush($handle);
    }
}
