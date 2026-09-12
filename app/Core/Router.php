<?php
declare(strict_types=1);

namespace Rin\Core;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,keys:list<string>,handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $normalized = rtrim($path, '/') ?: '/';
        $quoted = preg_quote($normalized, '#');
        $regex = preg_replace_callback('/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/', static function (array $m) use (&$keys) {
            $keys[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $quoted);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $path,
            'regex' => '#^' . $regex . '$#u',
            'keys' => $keys,
            'handler' => $handler,
        ];
    }

    public function match(string $method, string $path): ?array
    {
        $path = $path === '/' ? '/' : rtrim($path, '/');
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($route['keys'] as $key) {
                $params[$key] = rawurldecode((string) ($matches[$key] ?? ''));
            }
            return [$route['handler'], $params];
        }
        return null;
    }
}
