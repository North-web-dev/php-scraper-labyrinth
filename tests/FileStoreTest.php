<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\Store\FileStore;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/labyrinth-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    public function testIncrementCreatesAndAccumulates(): void
    {
        $store = new FileStore($this->dir);

        self::assertSame(1, $store->increment('k', 1, 60));
        self::assertSame(3, $store->increment('k', 2, 60));
        self::assertSame(3, $store->get('k'));
    }

    public function testGetMissingKeyIsZero(): void
    {
        $store = new FileStore($this->dir);
        self::assertSame(0, $store->get('nope'));
    }

    public function testSetAndDelete(): void
    {
        $store = new FileStore($this->dir);

        $store->set('k', 42, 60);
        self::assertSame(42, $store->get('k'));

        $store->delete('k');
        self::assertSame(0, $store->get('k'));
    }

    public function testTtlExpiryReadsAsAbsent(): void
    {
        $store = new FileStore($this->dir);

        // 1-second TTL; after the window elapses the key reads as absent and a
        // fresh increment starts over. Uses a real (short) sleep since the
        // FileStore clock is the system clock.
        $store->increment('k', 5, 1);
        self::assertSame(5, $store->get('k'));

        sleep(2);

        self::assertSame(0, $store->get('k'));
        self::assertSame(9, $store->increment('k', 9, 60));
    }

    public function testCreatesDirectoryIfMissing(): void
    {
        $nested = $this->dir . '/a/b/c';
        $store = new FileStore($nested);
        $store->set('k', 1, 60);

        self::assertDirectoryExists($nested);
        self::assertSame(1, $store->get('k'));

        // cleanup nested
        foreach (glob($nested . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($nested);
        @rmdir($this->dir . '/a/b');
        @rmdir($this->dir . '/a');
    }

    public function testCreatedDirectoryIsPrivate(): void
    {
        new FileStore($this->dir);

        // Created mode must have no group/world permission bits.
        clearstatcache(true, $this->dir);
        self::assertSame(0, fileperms($this->dir) & 0o077);
    }

    public function testRejectsEmptyDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        new FileStore('');
    }

    public function testRejectsGroupWorldAccessibleDirectory(): void
    {
        mkdir($this->dir, 0o777, true);
        chmod($this->dir, 0o777); // defeat umask for the test

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/group\/world accessible/');
        new FileStore($this->dir);
    }

    public function testRejectsSymlinkedDirectory(): void
    {
        $real = $this->dir . '-real';
        mkdir($real, 0o700, true);
        symlink($real, $this->dir);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/symlink/');
            new FileStore($this->dir);
        } finally {
            @unlink($this->dir);
            @rmdir($real);
        }
    }

    public function testRefusesSymlinkedStateFile(): void
    {
        $store = new FileStore($this->dir);

        // Plant a symlink where key "k" would be stored, pointing elsewhere.
        $statePath = $this->dir . '/lab_' . hash('sha256', 'k') . '.json';
        $target = $this->dir . '/decoy-target';
        file_put_contents($target, 'x');
        symlink($target, $statePath);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/symlink/');
            $store->increment('k', 1, 60);
        } finally {
            @unlink($statePath);
            @unlink($target);
        }
    }
}
