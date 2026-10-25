<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Minimal HTTP response value object returned by the library.
 *
 * A null return from {@see Labyrinth::handle()} means "not handled — let your
 * application serve the request normally". A non-null Response means the
 * library produced the output and your front controller should send it and
 * stop.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status,
        private string $body,
        private array $headers = [],
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Emit the response using PHP's native output functions.
     *
     * Safe no-op on headers when they have already been sent (e.g. in tests).
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }
}
