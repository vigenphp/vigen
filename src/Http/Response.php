<?php

declare(strict_types=1);

namespace Vigen\Http;

use RuntimeException;

/**
 * An HTTP response. Immutable - every mutator returns a new instance - so a
 * response can be built up in a controller or a middleware and passed around
 * without any risk of one holder editing another's copy.
 */
class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new RuntimeException('Vigen could not encode the response as JSON: ' . json_last_error_msg());
        }

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, [$name => $value] + $this->headers);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Emit the response. Header emission is skipped under the plain `cli`
     * SAPI (where header() cannot work) so tests can call send() freely.
     */
    public function send(): void
    {
        if (PHP_SAPI !== 'cli' && ! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}
