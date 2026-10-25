<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 adapter. Drop it into any PSR-15 middleware stack (Slim, Mezzio, Laminas,
 * etc.). When the labyrinth handles a request it short-circuits the stack with
 * its own response; otherwise the next handler runs unchanged.
 *
 * The client IP is read from the "ip" request attribute if your stack sets one
 * (recommended behind a proxy), falling back to the REMOTE_ADDR server param.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function __construct(
        private Labyrinth $labyrinth,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->labyrinth->handle($this->toRequest($request));

        if ($result === null) {
            return $handler->handle($request);
        }

        return $this->toPsrResponse($result);
    }

    private function toRequest(ServerRequestInterface $request): Request
    {
        $serverParams = $request->getServerParams();
        $ipAttribute = $request->getAttribute('ip');
        $ip = is_string($ipAttribute) && $ipAttribute !== ''
            ? $ipAttribute
            : (string) ($serverParams['REMOTE_ADDR'] ?? '');

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $query = $request->getQueryParams();

        return new Request(
            $ip,
            $request->getMethod(),
            $request->getUri()->getPath(),
            $headers,
            $query,
        );
    }

    private function toPsrResponse(Response $response): ResponseInterface
    {
        $psr = $this->responseFactory->createResponse($response->status());
        foreach ($response->headers() as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        return $psr->withBody($this->streamFactory->createStream($response->body()));
    }
}
