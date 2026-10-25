<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Framework-agnostic value object describing the inbound HTTP request.
 *
 * Only the fields the library actually inspects are modelled. Build it from
 * PHP superglobals with {@see Request::fromGlobals()} or construct it directly
 * from your framework's request object.
 *
 * Header names are normalised to lower-case for case-insensitive lookup.
 */
final class Request
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers;

    /**
     * @param string                $clientIp The *trusted* client IP. If you sit behind a proxy,
     *                                         resolve the real client IP yourself (e.g. from a
     *                                         verified X-Forwarded-For) before constructing this.
     * @param array<string, string> $headers  Raw header map (any casing).
     * @param array<string, mixed>  $query    Parsed query string parameters.
     */
    public function __construct(
        private string $clientIp,
        private string $method,
        private string $path,
        array $headers = [],
        private array $query = [],
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }
        $this->headers = $normalised;
    }

    /**
     * Build a Request from PHP superglobals.
     *
     * NOTE: $_SERVER['REMOTE_ADDR'] is used as the client IP. If you terminate
     * behind a reverse proxy / CDN you MUST pass the verified forwarded IP
     * explicitly instead — spoofable forwarded headers must never be trusted.
     */
    public static function fromGlobals(?string $trustedClientIp = null): self
    {
        $server = $_SERVER;
        $ip = $trustedClientIp ?? (string) ($server['REMOTE_ADDR'] ?? '');
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }
        // Content-Type / Content-Length are not HTTP_ prefixed.
        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $server['CONTENT_TYPE'];
        }

        return new self($ip, $method, $path, $headers, $query);
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Single query-string parameter, or null if absent. */
    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** Case-insensitive header lookup. Returns null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, string> lower-cased header map */
    public function headers(): array
    {
        return $this->headers;
    }
}
