# Labyrinth

A PHP 8.1+ drop-in that defends a website against content scrapers. It combines
four layers, applied in order on every request:

1. **Verified-crawler allowlist** — confirmed Googlebot / Bingbot / Yandex bypass
   every defence.
2. **Scraper heuristics** — weak, combinable signals score a request; a high
   score flags the client (it does not hard-block).
3. **Tarpit labyrinth** — flagged clients get a slow `noindex` page of endless
   fake links, burning their crawl budget. No destructive payloads.
4. **Volume caps** — per-IP request-rate and daily-download limits. This is the
   real ceiling against clients the heuristics cannot catch.

It is framework-agnostic: a plain `Labyrinth::handle(Request): ?Response` entry
point plus an optional PSR-15 middleware adapter. Counters live behind a
pluggable `StoreInterface` (file / APCu / Redis).

```
composer require north-web-dev/labyrinth
```

---

## The mechanism, in order

Each inbound request flows through `Labyrinth::handle()`:

1. **Verified crawler?** If the client IP is a confirmed search-engine crawler
   (see below), `handle()` returns `null` immediately — the request is never
   rate-limited and never trapped.
2. **Over a volume cap?** The per-IP request-rate counter is incremented. If the
   client is over the request rate *or* over the daily byte cap, `handle()`
   returns a `429`.
3. **Already flagged?** If this client has been flagged on a previous request,
   it gets the labyrinth on **every** path — routing is by client, not by URL.
4. **Asset request?** Requests for static assets (`.css`, `.js`, images, fonts…)
   pass through and are recorded. The record feeds the "fetches HTML but never
   assets" signal.
5. **Score the content request.** If the heuristic score meets the configured
   threshold, the client is flagged and served the labyrinth. Otherwise
   `handle()` returns `null` and your application serves the request normally.

### 1. Verified-crawler allowlist

> **Trapping a real crawler deindexes your site.** This layer exists to make
> absolutely sure Googlebot/Bingbot/Yandex are never slowed or trapped. It errs
> toward letting crawlers through.

Verification uses **forward-confirmed reverse DNS (FCrDNS)** — the method the
search engines themselves document:

1. PTR lookup of the client IP yields a hostname.
2. The hostname ends in an official crawler domain suffix
   (`googlebot.com`, `google.com`, `search.msn.com`, `yandex.com`,
   `yandex.net`, `yandex.ru` by default).
3. A forward (A/AAAA) lookup of that hostname resolves **back to the original
   client IP**.

A spoofer can set a PTR record ending in `googlebot.com`, but cannot make the
forward lookup resolve back to their own IP, so step 3 rejects them.

Because PTR lookups can be slow, you may also supply an **optional static CIDR
safety net** of published crawler IP ranges (`staticCrawlerRanges`). An IP in
one of those ranges is verified instantly without DNS. This list ships **empty**
— you populate it from the crawler operators' own published ranges (e.g.
Google publishes `googlebot.json`) and keep it fresh, because such ranges change
over time. rDNS remains the source of truth.

### 2. Scraper heuristics (flag, don't block)

Each signal is individually weak and individually defeatable. They are summed
and compared to a threshold, so only a *combination* trips the labyrinth:

| Signal | Default weight | What it observes |
|---|---|---|
| `impossible_ua_version` | 3 | UA advertises a browser major version beyond plausibility (e.g. `Chrome/155`). |
| `datacenter_no_ptr` | 2 | Client IP has no PTR record (typical of harvest VMs). |
| `missing_fetch_metadata` | 2 | No `Sec-Fetch-*` and no `Sec-CH-UA` headers (real browsers send these). |
| `deep_hit_no_referer` | 1 | A deep content URL fetched with no `Referer`. |
| `no_asset_requests` | 1 | IP has pulled several HTML pages but never a single CSS/JS/image. |
| `default_accept_language` | 1 | `Accept-Language` is exactly the stdlib default `en-US,en;q=0.5`. |

Weights and the threshold (default `4`) are configurable. The `no_asset_requests`
signal deliberately does **not** fire on a visitor's first pageviews — only after
an IP has fetched several content pages with zero assets — so a normal first-time
visitor is never trapped on arrival.

### 3. The labyrinth (tarpit)

A flagged, non-verified client is served a page that:

- carries `noindex, nofollow` (both an `X-Robots-Tag` header and a meta tag), so
  it can never pollute a search index;
- is produced after a configurable delay (`tarpitDelaySeconds`), to slow the
  harvester down;
- contains many fake internal links, a fake paginator (`Page X of N, Next →`)
  that never ends, and a few link-only **bait paths** (e.g. `/backup`, `/.env`)
  that lead only to more junk;
- fills its body with meaningless prose.

Output is deterministic per URL (seeded) so the same link always yields the same
maze page.

> **No destructive techniques.** The labyrinth serves only slow, junk HTML. There
> are **no** zip/decompression bombs, **no** malware, **no** exploit payloads, and
> the bait links serve junk — they never expose real files or secrets. Wasting
> the scraper's time is the only weapon.

**Non-blocking note:** `tarpitDelaySeconds` uses `sleep()` by default, which ties
up a PHP-FPM worker for the duration. Keep the delay small (1–3s), or inject your
own non-blocking sleeper via the `Labyrinth` constructor, or terminate the delay
at the web-server/proxy layer. Do not set a long blocking delay on a busy FPM
pool — you would tarpit yourself.

### 4. Volume caps (the real ceiling)

Two per-IP caps, both backed by the store and both exempting verified crawlers:

- **Request rate** — `rateMaxRequests` per `rateWindowSeconds` (fixed window).
  Over the cap ⇒ `429` with `Retry-After`.
- **Daily download** — `dailyMaxBytes` per `dailyWindowSeconds` (rolling). Over
  the cap ⇒ `429`. For accurate byte accounting, report what your app serves via
  `Labyrinth::recordBytes($ip, $bytes)` (the labyrinth counts its own output
  automatically).

---

## Honest threat model

This library raises the cost of scraping. It does not make it impossible. Be
clear-eyed about what each layer catches:

- **Lazy / datacenter scrapers and library-default harvesters** (curl, requests,
  Scrapy with defaults, cheap datacenter IPs) trip several heuristics at once and
  land in the labyrinth. These are the bulk of casual scraping and this is where
  the heuristics earn their keep.
- **A careful headless browser on residential/mobile proxies defeats the
  heuristics.** It sends real `Sec-Fetch-*`/`Sec-CH-UA` headers, a plausible UA, a
  real `Referer`, loads assets, and originates from an IP with normal rDNS. No
  request-shape heuristic can reliably distinguish it from a human. **For this
  adversary the volume caps — not the heuristics — are the real limit:** a hard
  per-IP request and byte ceiling bounds how much any single IP can take.
- **A farm rotating across many residential IPs** raises its effective total by
  using many IPs. Per-IP caps still bound each IP; bounding the aggregate needs
  controls outside this library's scope (ASN reputation, proof-of-work/CAPTCHA
  challenges, account-level limits, commercial bot management).
- **Heuristic maintenance is a real cost.** `impossible_ua_version` bounds and
  the static crawler ranges go stale as browsers and crawler fleets change. An
  out-of-date UA bound causes false positives (never deindexing — verified
  crawlers bypass heuristics entirely); keep them current.

If you need a hard guarantee rather than a cost increase, this is not that tool.

---

## Quickstart

### Plain front controller

Put this at the very top of `public/index.php`, before any routing or output:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\Labyrinth;
use NorthWebDev\Labyrinth\Request;
use NorthWebDev\Labyrinth\Store\FileStore;

$labyrinth = new Labyrinth(
    new Config(
        heuristicThreshold: 4,
        tarpitDelaySeconds: 2,
        rateMaxRequests: 120,          // per 60s window
        dailyMaxBytes: 512 * 1024 * 1024,
        staticCrawlerRanges: [],       // optionally your maintained crawler ranges
    ),
    new FileStore(sys_get_temp_dir() . '/labyrinth'),
);

$request = Request::fromGlobals();      // see the proxy note below

$response = $labyrinth->handle($request);
if ($response !== null) {
    $response->send();
    exit;
}

// ... not handled: continue to your real application ...
```

A ready-to-adapt copy lives in [`examples/front-controller.php`](examples/front-controller.php).

> **Behind a proxy/CDN?** `Request::fromGlobals()` trusts `REMOTE_ADDR`. If you
> sit behind a proxy, resolve the real client IP yourself from a *verified*
> forwarded header and pass it: `Request::fromGlobals($trustedClientIp)`. Never
> trust a raw `X-Forwarded-For`.

### PSR-15 middleware

```php
use NorthWebDev\Labyrinth\Psr15Middleware;

$middleware = new Psr15Middleware($labyrinth, $responseFactory, $streamFactory);
// add $middleware to your Slim / Mezzio / Laminas stack.
```

The adapter reads the client IP from the request's `ip` attribute if your stack
sets one (recommended behind a proxy), falling back to `REMOTE_ADDR`.

---

## Configuration reference

All options are constructor arguments of `NorthWebDev\Labyrinth\Config`:

| Option | Default | Meaning |
|---|---|---|
| `heuristicThreshold` | `4` | Minimum score to flag a client. |
| `heuristicWeights` | see table above | Per-signal point weights. |
| `contentPathMinDepth` | `2` | Path depth that counts as a "deep content hit". |
| `noAssetMinContentHits` | `3` | Content pages (with zero assets) before `no_asset_requests` fires. |
| `maxPlausibleUaVersions` | Chrome 145, Firefox 140, … | Highest believable major version per product. **Bump over time.** |
| `assetExtensions` | css, js, images, fonts (true static only) | Extensions treated as static assets. Excludes data formats (json/xml/csv/txt) so a scraper cannot append one to bypass scoring. For stronger detection, match a path prefix or response content-type instead. |
| `baitPaths` | `/backup`, `/.env`, … | Link-only honeypot paths (served junk). |
| `tarpitDelaySeconds` | `2` | Stall before responding to a flagged client. See non-blocking note. |
| `tarpitLinksPerPage` | `15` | Fake internal links per labyrinth page. |
| `tarpitFakePageCount` | `9999` | Upper bound for the fake paginator. |
| `flaggedClientTtlSeconds` | `3600` | How long a flagged client keeps getting the labyrinth. |
| `rateWindowSeconds` | `60` | Request-rate window length. |
| `rateMaxRequests` | `120` | Max requests per IP per window (`0` disables). |
| `dailyMaxBytes` | `536870912` (512 MiB) | Per-IP daily download cap (`0` disables). |
| `dailyWindowSeconds` | `86400` | Daily-cap window length. |
| `staticCrawlerRanges` | `[]` | Optional verified-crawler CIDR safety net you maintain. |
| `verifyCacheSeconds` | `3600` | TTL for cached per-IP verification / PTR results (`0` disables caching). |

---

## Store backends

Counters and flags are stored behind `NorthWebDev\Labyrinth\Store\StoreInterface`.

| Store | Scope | Use when |
|---|---|---|
| `FileStore` | Single server | Default. Zero dependencies, concurrency-safe via `flock`. |

> **FileStore requires an explicit, app-owned, private directory** — there is no
> temp-directory fallback. On construction it:
> - requires the configured path to be absolute and canonical — it is compared
>   against its `realpath()` and construction throws if they differ, which
>   rejects a symlinked directory (or any symlinked/`..` component) outright
>   rather than silently following it;
> - creates the directory mode `0700` if missing, and refuses it if it ends up
>   group/world-accessible or not owned by the current user;
> - walks the whole parent chain to the filesystem root and refuses any
>   group/world-writable component unless it is a root-owned sticky directory
>   (the legitimate `/tmp` pattern), closing the "swap a symlink under a
>   writable parent" vector;
> - hashes keys (sha256) into flat filenames, so a key can never traverse out;
> - refuses a state file that is a symlink.
>
> Keep the directory outside your web root. **Residual limitation (stated
> honestly):** pure PHP has no `O_NOFOLLOW`, so a symlink swapped in the instant
> between the `is_link()` check and `fopen()` (a TOCTOU race) cannot be fully
> excluded — which is exactly why the directory must be private `0700` with
> non-writable parents, leaving no untrusted writer able to plant or swap a
> symlink in the path.

| `ApcuStore` | Single server | You want in-memory speed and have APCu. Lost on pool restart. |
| `RedisStore` | **Multiple servers** | Counters must be shared. A per-host store would let a scraper get N× the quota across N app servers. |
| `InMemoryStore` | Current process only | Tests and single-request CLI use. **Not** for production caps. |

Implement `StoreInterface` yourself (four small methods) to back it with anything
else.

---

## Running the tests

```
composer install
composer test
```

CI runs the suite on PHP 8.1, 8.2 and 8.3.

---

## Support

If this saved you some time, a star helps others find it. ⭐

| Coin | Address |
|---|---|
| **BTC** | `bc1q0sfghv7yfl594gjurhyhlhdgm0dtwh4dkjha36` |
| **ETH / USDT-ERC20 / BNB-BEP20** | `0xB827b5a8B6c5111946c5D872933b2C23484E87dE` |
| **SOL** | `3ch5aHrPp3CierhiNXmqmtFH2dMHh6Gfv7a97KtLMMCh` |
| **USDT-TRC20 / TRX** | `TUAyz7TjgJdFVD2oFHWUUTNrzzAnBRgySG` |
| **TON** | `UQAJmYVuR3hh02etjaPKJF-h6jcpdSnq9ZIFvI64NEskS6tx` |

## License

MIT — see [LICENSE](LICENSE).
