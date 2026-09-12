<?php
declare(strict_types=1);

namespace Rin\Support;

final class StorageService
{
    public function __construct(
        private string $root,
        private string $folder = 'images/',
    ) {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function folder(): string
    {
        return $this->folder;
    }

    public function put(string $key, string $contents): string
    {
        $storageKey = $this->join($this->folder, $key);
        return $this->putAtKey($storageKey, $contents);
    }

    public function putAtKey(string $storageKey, string $contents): string
    {
        $path = $this->path($storageKey);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
        return $storageKey;
    }

    public function get(string $storageKey): ?array
    {
        $path = $this->path($storageKey);
        if (!is_file($path)) {
            return null;
        }
        return [
            'body' => (string) file_get_contents($path),
            'mime' => mime_content_type($path) ?: 'application/octet-stream',
            'mtime' => filemtime($path) ?: time(),
            'size' => filesize($path) ?: 0,
        ];
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->path($storageKey));
    }

    public function publicUrl(string $storageKey, string $baseUrl): string
    {
        $encoded = implode('/', array_map('rawurlencode', array_filter(explode('/', $storageKey), static fn ($s) => $s !== '')));
        return rtrim($baseUrl, '/') . '/api/blob/' . $encoded;
    }

    public function path(string $storageKey): string
    {
        $safe = str_replace(['\\', "\0"], '/', $storageKey);
        $safe = preg_replace('#/+#', '/', $safe) ?? $safe;
        $safe = ltrim($safe, '/');
        if (str_contains($safe, '..')) {
            throw new \InvalidArgumentException('Invalid storage key');
        }
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
    }

    public function join(string ...$parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim(str_replace('\\', '/', $part), '/');
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode('/', $clean);
    }
}
