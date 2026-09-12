<?php
declare(strict_types=1);

namespace Rin\Core;

final class Response
{
    public function __construct(
        private int $status = 200,
        private mixed $body = '',
        private array $headers = [],
        private bool $json = false,
    ) {
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        $headers += ['Content-Type' => 'text/plain; charset=utf-8'];
        return new self($status, $body, $headers, false);
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        $headers += ['Content-Type' => 'text/html; charset=utf-8'];
        return new self($status, $body, $headers, false);
    }

    public static function json(mixed $body, int $status = 200, array $headers = []): self
    {
        $headers += ['Content-Type' => 'application/json; charset=utf-8'];
        return new self($status, $body, $headers, true);
    }

    public static function bytes(string $body, string $contentType, int $status = 200, array $headers = []): self
    {
        $headers += ['Content-Type' => $contentType];
        return new self($status, $body, $headers, false);
    }

    public static function empty(int $status = 204, array $headers = []): self
    {
        return new self($status, '', $headers, false);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url], false);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->headers['Set-Cookie'] = self::buildCookie($name, $value, $options);
        $clone->headers['X-Set-Cookie-' . $name] = self::buildCookie($name, $value, $options);
        return $clone;
    }

    public function addCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->headers['X-Set-Cookie-' . $name] = self::buildCookie($name, $value, $options);
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            if (str_starts_with($name, 'X-Set-Cookie-')) {
                header('Set-Cookie: ' . $value, false);
                continue;
            }
            if ($name === 'Set-Cookie') {
                header('Set-Cookie: ' . $value, false);
                continue;
            }
            header($name . ': ' . $value);
        }

        if ($this->status === 204) {
            return;
        }

        if ($this->json) {
            echo json_encode(
                $this->body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }

        echo is_string($this->body) ? $this->body : (string) $this->body;
    }

    private static function buildCookie(string $name, string $value, array $options): string
    {
        $parts = [$name . '=' . rawurlencode($value)];
        if (!empty($options['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', (int) $options['expires']);
        }
        $parts[] = 'Path=' . ($options['path'] ?? '/');
        if (!empty($options['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }
        if (!empty($options['secure'])) {
            $parts[] = 'Secure';
        }
        $parts[] = 'SameSite=' . ($options['sameSite'] ?? 'Lax');
        return implode('; ', $parts);
    }
}
