<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\LabyrinthRenderer;
use PHPUnit\Framework\TestCase;

final class LabyrinthRendererTest extends TestCase
{
    public function testPageIsNoindex(): void
    {
        $response = (new LabyrinthRenderer(new Config()))->render('/some/path');

        self::assertSame(200, $response->status());
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertStringContainsString('content="noindex, nofollow"', $response->body());
    }

    public function testContainsPaginatorAndBaitLinks(): void
    {
        $config = new Config(baitPaths: ['/backup', '/.env'], tarpitFakePageCount: 500);
        $response = (new LabyrinthRenderer($config))->render('/catalog', 3);
        $body = $response->body();

        self::assertStringContainsString('Page 3 of 500', $body);
        self::assertStringContainsString('Next', $body);
        self::assertStringContainsString('href="/catalog?page=4"', $body);
        self::assertStringContainsString('href="/backup"', $body);
        self::assertStringContainsString('href="/.env"', $body);
    }

    public function testOutputIsDeterministicPerSeed(): void
    {
        $renderer = new LabyrinthRenderer(new Config());

        self::assertSame(
            $renderer->render('/stable/path', 2)->body(),
            $renderer->render('/stable/path', 2)->body(),
        );
        // Different paths produce different mazes.
        self::assertNotSame(
            $renderer->render('/path-a', 1)->body(),
            $renderer->render('/path-b', 1)->body(),
        );
    }

    public function testFakePageCountNeverBelowCurrentPage(): void
    {
        $config = new Config(tarpitFakePageCount: 10);
        $response = (new LabyrinthRenderer($config))->render('/x', 25);

        // "Page 25 of 26" — the maze always has a next page.
        self::assertStringContainsString('Page 25 of 26', $response->body());
    }

    public function testEmitsConfiguredNumberOfInternalLinks(): void
    {
        $config = new Config(baitPaths: [], tarpitLinksPerPage: 7);
        $body = (new LabyrinthRenderer($config))->render('/x')->body();

        // 7 internal links + the paginator "Next" link = 8 anchors.
        self::assertSame(8, substr_count($body, '<a '));
    }

    public function testBodyIsPlainJunkNotAnExploitPayload(): void
    {
        // Guard the "no destructive payloads" promise: output is small HTML.
        $body = (new LabyrinthRenderer(new Config()))->render('/x')->body();

        self::assertLessThan(64 * 1024, strlen($body));
        self::assertStringStartsWith('<!DOCTYPE html>', $body);
    }
}
