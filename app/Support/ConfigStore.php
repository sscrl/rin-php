<?php
declare(strict_types=1);

namespace Rin\Support;

final class ConfigStore
{
    private array $data = [];
    private bool $loaded = false;

    public function __construct(
        private Database $db,
        private string $type,
        private array $defaults = [],
    ) {
    }

    public function all(): array
    {
        $this->load();
        return $this->data;
    }

    public function get(string $key): mixed
    {
        $this->load();
        return $this->data[$key] ?? null;
    }

    public function getOrDefault(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return $this->defaults[$key] ?? $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $now = Dates::now();
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = $this->db->fetch(
            'SELECT id FROM cache WHERE key = :key AND type = :type',
            ['key' => $key, 'type' => $this->type]
        );
        if ($existing) {
            $this->db->execute(
                'UPDATE cache SET value = :value, updated_at = :updated WHERE key = :key AND type = :type',
                ['value' => $encoded, 'updated' => $now, 'key' => $key, 'type' => $this->type]
            );
        } else {
            $this->db->execute(
                'INSERT INTO cache (key, value, type, created_at, updated_at) VALUES (:key, :value, :type, :created, :updated)',
                ['key' => $key, 'value' => $encoded, 'type' => $this->type, 'created' => $now, 'updated' => $now]
            );
        }
    }

    public function delete(string $key): void
    {
        $this->load();
        unset($this->data[$key]);
        $this->db->execute(
            'DELETE FROM cache WHERE key = :key AND type = :type',
            ['key' => $key, 'type' => $this->type]
        );
    }

    public function deletePrefix(string $prefix): void
    {
        $this->load();
        foreach (array_keys($this->data) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->data[$key]);
            }
        }
        $this->db->execute(
            'DELETE FROM cache WHERE type = :type AND key LIKE :prefix',
            ['type' => $this->type, 'prefix' => $prefix . '%']
        );
    }

    public function clear(): void
    {
        $this->data = [];
        $this->loaded = true;
        $this->db->execute('DELETE FROM cache WHERE type = :type', ['type' => $this->type]);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $rows = $this->db->fetchAll('SELECT key, value FROM cache WHERE type = :type', ['type' => $this->type]);
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $this->data[$row['key']] = $decoded === null && $row['value'] !== 'null' ? $row['value'] : $decoded;
        }
        $this->loaded = true;
    }
}
