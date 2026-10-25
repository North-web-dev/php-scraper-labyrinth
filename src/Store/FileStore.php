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
 * SECURITY: you MUST pass an explicit, app-owned, private directory — there is
 * no temp-directory fallback, because a world-writable location (e.g. /tmp)
 * would let any local user forge or tamper with the volume-cap counters.
 *
 *  - The path is canonicalised once at construction with realpath(); that
 *    resolved path is used for all state files, so any symlink in the
 *    configured path is collapsed up front and every file resolves under it.
 *  - The directory is created mode 0700 if missing; it must end up owned by the
 *    current user with no group/world permission bits, or construction throws.
 *  - The whole parent chain up to the filesystem root is checked: no component
 *    may be group- or world-writable unless it is a root-owned sticky directory
 *    (e.g. /tmp). This closes the "swap a symlink under a world-writable parent"
 *    vector.
 *  - Keys are sha256-hashed into flat filenames, so a key can never traverse
 *    out of the directory.
 *  - A state file that is a symlink is refused before opening.
 *
 * Residual limitation, stated plainly: pure PHP cannot open a file with
 * O_NOFOLLOW, so a symlink swapped in between the is_link() check and fopen()
 * (a TOCTOU race) cannot be fully excluded. That is exactly why the directory
 * must be private 0700 and its parents non-group/world-writable: with no
 * untrusted writer able to place or swap a symlink in the path, the race is not
 * reachable in practice.
 *
 * Suitable for a single server. For a multi-server deployment use
 * {@see RedisStore} so counters are shared; a per-host FileStore would let a
 * scraper get N times the quota across N app servers.
 */
final class FileStore implements StoreInterface
{
    public function __construct(private string $directory)
    {
        if ($this->directory === '') {
            throw new RuntimeException('FileStore requires an explicit, app-owned private directory.');
        }

        if (!file_exists($this->directory)) {
            if (!@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
                throw new RuntimeException("FileStore directory could not be created: {$this->directory}");
            }
        }

        // Canonicalise once: resolve any symlink in the configured path to a
        // real path, and use only that for every state file from here on.
        $real = realpath($this->directory);
        if ($real === false) {
            throw new RuntimeException("FileStore directory could not be resolved: {$this->directory}");
        }
        $this->directory = $real;

        $this->assertSecureDirectory($this->directory);
    }

    /**
     * Reject an unsafe store directory: a non-directory, one with any
     * group/world permission bit, one not owned by the current user, or one
     * reachable only through a group/world-writable parent.
     */
    private function assertSecureDirectory(string $dir): void
    {
        clearstatcache(true, $dir);

        if (!is_dir($dir)) {
            throw new RuntimeException("FileStore path is not a directory: {$dir}");
        }

        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            throw new RuntimeException(sprintf(
                'FileStore directory %s must not be group/world accessible (want mode 0700, got 0%o).',
                $dir,
                $perms & 0o777,
            ));
        }

        if (function_exists('posix_getuid')) {
            $owner = fileowner($dir);
            if ($owner !== false && $owner !== posix_getuid()) {
                throw new RuntimeException("FileStore directory must be owned by the current user: {$dir}");
            }
        }

        $this->assertSafeParentChain($dir);
    }

    /**
     * Walk from the store directory up to the filesystem root. Reject any
     * component that is group- or world-writable, unless it is a root-owned
     * sticky directory (the legitimate /tmp pattern). A writable parent would
     * let an attacker swap the store directory for a symlink.
     */
    private function assertSafeParentChain(string $dir): void
    {
        $path = dirname($dir);

        while (true) {
            clearstatcache(true, $path);
            $perms = fileperms($path);
            if ($perms === false) {
                throw new RuntimeException("FileStore cannot stat a parent directory: {$path}");
            }

            if (($perms & 0o022) !== 0) {
                $rootOwnedSticky = fileowner($path) === 0 && ($perms & 0o1000) !== 0;
                if (!$rootOwnedSticky) {
                    throw new RuntimeException(sprintf(
                        'FileStore parent directory %s is group/world-writable (0%o) and not a root-owned sticky dir.',
                        $path,
                        $perms & 0o7777,
                    ));
                }
            }

            $parent = dirname($path);
            if ($parent === $path) {
                break; // reached the filesystem root
            }
            $path = $parent;
        }
    }

    /** Refuse to operate on a state file that is a symlink (tampering). */
    private function assertNotSymlink(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException("Refusing to use symlinked store file: {$path}");
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
        $this->assertNotSymlink($path);
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
        $this->assertNotSymlink($path);
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
