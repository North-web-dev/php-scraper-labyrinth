<?php

/**
 * Plain front-controller integration (no framework).
 *
 * Put this at the very top of your public/index.php, before any routing or
 * output. If handle() returns a Response, send it and stop — the request was
 * either trapped (labyrinth) or capped (429). Otherwise continue to your app.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\Labyrinth;
use NorthWebDev\Labyrinth\Request;
use NorthWebDev\Labyrinth\Store\FileStore;

$config = new Config(
    // Tune to taste. See README for the full reference.
    heuristicThreshold: 3,
    tarpitDelaySeconds: 2,
    rateMaxRequests: 120,      // per 60s window
    dailyMaxBytes: 512 * 1024 * 1024,

    // OPTIONAL static safety net of verified-crawler IP ranges you maintain.
    // Leave empty to rely on forward-confirmed reverse DNS only.
    // Example (documentation range, NOT a real crawler): ['192.0.2.0/24'].
    staticCrawlerRanges: [],
);

// FileStore works out of the box on a single server. Use RedisStore for a
// multi-server deployment so counters are shared.
$store = new FileStore(sys_get_temp_dir() . '/labyrinth');

$labyrinth = new Labyrinth($config, $store);

// If you sit behind a proxy/CDN, pass the VERIFIED client IP here instead of
// relying on REMOTE_ADDR. Never trust a raw X-Forwarded-For.
$request = Request::fromGlobals();

$response = $labyrinth->handle($request);
if ($response !== null) {
    $response->send();
    exit;
}

// --- Not handled: carry on to your real application. ---
//
// To make the daily byte cap accurate, report what you serve:
//
//   $labyrinth->recordBytes($request->clientIp(), strlen($bodyYouSent));
//
// (Skip verified crawlers; they are exempt from the caps.)
