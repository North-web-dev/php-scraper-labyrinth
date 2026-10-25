<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use NorthWebDev\Labyrinth\Config;
use NorthWebDev\Labyrinth\Labyrinth;
use NorthWebDev\Labyrinth\Psr15Middleware;
use NorthWebDev\Labyrinth\Store\InMemoryStore;
use NorthWebDev\Labyrinth\Tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Psr15MiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function middleware(): Psr15Middleware
    {
        $labyrinth = new Labyrinth(
            new Config(),
            new InMemoryStore(),
            new FakeResolver(),
            null,
            static function (int $seconds): void {},
        );

        return new Psr15Middleware($labyrinth, $this->factory, $this->factory);
    }

    private function handler(): RequestHandlerInterface
    {
        $factory = $this->factory;

        return new class ($factory) implements RequestHandlerInterface {
            public bool $called = false;

            public function __construct(private Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return $this->factory->createResponse(204);
            }
        };
    }

    public function testCleanRequestIsDelegatedToNextHandler(): void
    {
        $request = $this->factory
            ->createServerRequest('GET', 'http://example.com/', ['REMOTE_ADDR' => '198.51.100.90'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36')
            ->withHeader('Referer', 'https://example.com/')
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->withHeader('Sec-Fetch-Mode', 'navigate');

        $handler = $this->handler();
        $response = $this->middleware()->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testSuspiciousRequestIsTrappedAndHandlerNotCalled(): void
    {
        $request = $this->factory
            ->createServerRequest('GET', 'http://example.com/catalog/items/9', ['REMOTE_ADDR' => '203.0.113.80'])
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/155.0.0.0 Safari/537.36')
            ->withHeader('Accept-Language', 'en-US,en;q=0.5');

        $handler = $this->handler();
        $response = $this->middleware()->process($request, $handler);

        self::assertFalse($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('Page 1 of', (string) $response->getBody());
    }

    public function testClientIpAttributeIsPreferredOverRemoteAddr(): void
    {
        // Behind a proxy: the stack sets the real IP as the "ip" attribute.
        $request = $this->factory
            ->createServerRequest('GET', 'http://example.com/catalog/items/9', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withAttribute('ip', '203.0.113.80')
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/155.0.0.0 Safari/537.36')
            ->withHeader('Accept-Language', 'en-US,en;q=0.5');

        $handler = $this->handler();
        $response = $this->middleware()->process($request, $handler);

        self::assertFalse($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }
}
