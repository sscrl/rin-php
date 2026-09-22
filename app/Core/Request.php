<?php
declare(strict_types=1);

namespace Rin\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $rawBody,
        public readonly array $post,
        public readonly array $files,
        public readonly string $origin,
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $ip,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443')
            || (($headers['x-forwarded-proto'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $headers['x-forwarded-host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $origin = $headers['origin'] ?? ($scheme . '://' . $host);
        $ip = $headers['cf-connecting-ip']
            ?? $headers['x-real-ip']
            ?? $headers['x-forwarded-for']
            ?? ($_SERVER['REMOTE_ADDR'] ?? 'UNK');
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip, 2)[0]);
        }

        return new self(
            method: $method,
            path: $path === '' ? '/' : $path,
            query: $_GET,
            headers: $headers,
            cookies: $_COOKIE,
            rawBody: file_get_contents('php://input') ?: '',
            post: $_POST,
            files: $_FILES,
            origin: $origin,
            scheme: $scheme,
            host: $host,
            ip: $ip !== '' ? $ip : 'UNK',
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? $default;
        return $value === null ? null : (string) $value;
    }

    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        return is_array($data) ? $data : [];
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '') ?? ''), 'application/json');
    }

    public function baseUrl(): string
    {
        return $this->scheme . '://' . $this->host;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        $cookie = $this->cookies['token'] ?? null;
        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    public function withQuery(array $query): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            query: $query,
            headers: $this->headers,
            cookies: $this->cookies,
            rawBody: $this->rawBody,
            post: $this->post,
            files: $this->files,
            origin: $this->origin,
            scheme: $this->scheme,
            host: $this->host,
            ip: $this->ip,
        );
    }

    public function withJsonBody(array $data, ?string $method = null): self
    {
        $headers = $this->headers;
        $headers['content-type'] = 'application/json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            method: $method ?? $this->method,
            path: $this->path,
            query: $this->query,
            headers: $headers,
            cookies: $this->cookies,
            rawBody: $json === false ? '{}' : $json,
            post: $this->post,
            files: $this->files,
            origin: $this->origin,
            scheme: $this->scheme,
            host: $this->host,
            ip: $this->ip,
        );
    }
}
