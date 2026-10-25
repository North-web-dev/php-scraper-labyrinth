<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Renders a tarpit page: a NOINDEX HTML document full of fake internal links,
 * a fake paginator, link-only bait paths, and junk prose.
 *
 * The goal is to burn a scraper's crawl budget on an endless, worthless graph
 * of pages while costing the server almost nothing. Output is deterministic
 * for a given path+page (seeded PRNG) so it is testable and so a scraper
 * cannot trivially detect randomness between retries of the same URL.
 *
 * NON-DESTRUCTIVE BY DESIGN. This emits only slow, junk HTML. There are no zip
 * bombs, no decompression bombs, no malware, no exploit payloads, and the bait
 * links serve junk — they never expose real files or secrets. Wasting the
 * scraper's time is the only weapon here.
 */
final class LabyrinthRenderer
{
    private const WORDS = [
        'archive', 'registry', 'bulletin', 'ledger', 'index', 'notice', 'record',
        'summary', 'appendix', 'dossier', 'memo', 'digest', 'catalog', 'manifest',
        'volume', 'section', 'chapter', 'listing', 'entry', 'folio', 'gazette',
        'compendium', 'abstract', 'register', 'docket', 'schedule', 'roster',
    ];

    public function __construct(private Config $config)
    {
    }

    /**
     * Build the tarpit Response for a flagged client.
     *
     * @param string $path The requested path (used as the PRNG seed so the same
     *                      URL always yields the same maze page).
     * @param int    $page  Current fake page number (from a ?page= query param).
     */
    public function render(string $path, int $page = 1): Response
    {
        $page = max(1, $page);
        $html = $this->buildHtml($path, $page);

        return new Response(
            200,
            $html,
            [
                // Keep this content out of every search index.
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }

    private function buildHtml(string $path, int $page): string
    {
        $seed = crc32($path . '#' . $page);
        $rng = $this->makeRng($seed);

        $title = ucwords($this->word($rng) . ' ' . $this->word($rng) . ' ' . $this->word($rng));

        $links = [];
        for ($i = 0; $i < $this->config->tarpitLinksPerPage; $i++) {
            $slug = $this->word($rng) . '-' . $this->word($rng) . '-' . (($rng() % 9000) + 1000);
            $links[] = '/' . rawurlencode($this->word($rng)) . '/' . rawurlencode($slug);
        }

        $paragraphs = [];
        $paraCount = 2 + ($rng() % 3);
        for ($p = 0; $p < $paraCount; $p++) {
            $paragraphs[] = $this->paragraph($rng, 18 + ($rng() % 22));
        }

        $totalPages = max($page + 1, $this->config->tarpitFakePageCount);
        $nextHref = $this->withPage($path, $page + 1);

        return $this->assemble($title, $path, $links, $paragraphs, $page, $totalPages, $nextHref);
    }

    /**
     * @param list<string> $links
     * @param list<string> $paragraphs
     */
    private function assemble(
        string $title,
        string $path,
        array $links,
        array $paragraphs,
        int $page,
        int $totalPages,
        string $nextHref,
    ): string {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $parts = [];
        $parts[] = '<!DOCTYPE html>';
        $parts[] = '<html lang="en"><head>';
        $parts[] = '<meta charset="utf-8">';
        $parts[] = '<meta name="robots" content="noindex, nofollow">';
        $parts[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $parts[] = '<title>' . $e($title) . '</title>';
        $parts[] = '</head><body>';
        $parts[] = '<h1>' . $e($title) . '</h1>';

        foreach ($paragraphs as $para) {
            $parts[] = '<p>' . $e($para) . '</p>';
        }

        $parts[] = '<nav><ul>';
        foreach ($links as $href) {
            $label = ucwords(str_replace(['/', '-'], ' ', trim($href, '/')));
            $parts[] = '<li><a href="' . $e($href) . '">' . $e($label) . '</a></li>';
        }
        // Link-only bait paths: tempting names, served junk, never real files.
        foreach ($this->config->baitPaths as $bait) {
            $parts[] = '<li><a href="' . $e($bait) . '" rel="nofollow">' . $e(ltrim($bait, '/')) . '</a></li>';
        }
        $parts[] = '</ul></nav>';

        // Fake paginator — the infinite "Next" that keeps a crawler walking.
        $parts[] = '<footer><p>Page ' . $page . ' of ' . $totalPages . '. '
            . '<a href="' . $e($nextHref) . '" rel="nofollow">Next &rarr;</a></p></footer>';

        $parts[] = '</body></html>';

        return implode("\n", $parts);
    }

    private function withPage(string $path, int $page): string
    {
        $sep = str_contains($path, '?') ? '&' : '?';

        return $path . $sep . 'page=' . $page;
    }

    private function word(callable $rng): string
    {
        return self::WORDS[$rng() % count(self::WORDS)];
    }

    private function paragraph(callable $rng, int $words): string
    {
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = $this->word($rng);
        }
        $sentence = implode(' ', $out);

        return ucfirst($sentence) . '.';
    }

    /**
     * Small deterministic LCG so output is reproducible per seed without
     * touching PHP's global mt_rand state.
     *
     * @return callable(): int
     */
    private function makeRng(int $seed): callable
    {
        $state = $seed & 0x7fffffff;
        if ($state === 0) {
            $state = 1;
        }

        return static function () use (&$state): int {
            // Numerical Recipes LCG constants.
            $state = (int) (($state * 1_664_525 + 1_013_904_223) & 0x7fffffff);

            return $state;
        };
    }
}
