<?php
declare(strict_types=1);

namespace Rin\Support;

use PDO;

final class Database
{
    public function __construct(
        private PDO $pdo,
        private string $driver = 'sqlite',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $this->pdo->exec('SET NAMES utf8mb4');
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function ident(string $name): string
    {
        if ($this->driver === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public static function connect(string $path, string $schemaFile): self
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $db = new self($pdo, 'sqlite');
        $db->applySchema($schemaFile);
        $db->migrate();
        return $db;
    }

    public static function fromEnv(array $env, string $root, string $schemaFile, bool $createMysqlDatabase = false): self
    {
        $driver = strtolower((string) ($env['db_driver'] ?? 'sqlite'));
        if ($driver === 'mysql') {
            $pdo = self::mysqlPdo($env, $createMysqlDatabase);
            $db = new self($pdo, 'mysql');
            $db->applySchema($schemaFile);
            $db->migrate();
            return $db;
        }
        return self::connect(Installer::sqlitePath($root, $env), $schemaFile);
    }

    public static function probeMysql(array $env): void
    {
        self::mysqlPdo($env, false);
    }

    private static function mysqlPdo(array $env, bool $createDatabase): PDO
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('当前 PHP 未启用 pdo_mysql，请改用 SQLite，或在面板里安装 MySQL PDO 扩展');
        }
        $host = (string) ($env['db_host'] ?? '127.0.0.1');
        $port = (int) ($env['db_port'] ?? 3306) ?: 3306;
        $name = (string) ($env['db_name'] ?? '');
        $user = (string) ($env['db_user'] ?? '');
        $pass = (string) ($env['db_pass'] ?? '');
        $charset = (string) ($env['db_charset'] ?? 'utf8mb4');
        if ($name === '') {
            throw new \RuntimeException('MySQL 数据库名不能为空');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            $unknown = stripos($e->getMessage(), 'Unknown database') !== false
                || (int) $e->getCode() === 1049;
            if (!$unknown) {
                throw new \RuntimeException('无法连接 MySQL：' . $e->getMessage(), 0, $e);
            }
            if (!$createDatabase) {
                throw new \RuntimeException('数据库不存在，请先在面板创建，或在安装时自动创建', 0, $e);
            }
            $rootDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
            $rootPdo = new PDO($rootDsn, $user, $pass, $options);
            $safeName = str_replace(['`', ';'], '', $name);
            $rootPdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return new PDO($dsn, $user, $pass, $options);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function applySchema(string $schemaFile): void
    {
        $sql = (string) file_get_contents($schemaFile);
        if ($this->driver === 'mysql') {
            foreach (self::mysqlStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            return;
        }
        $this->pdo->exec($sql);
    }

    public function migrate(): void
    {
        $names = $this->columnNames('feeds');
        if (!in_array('cover', $names, true)) {
            $this->pdo->exec("ALTER TABLE feeds ADD COLUMN cover TEXT NOT NULL DEFAULT ''");
        }
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function columnNames(string $table): array
    {
        if ($this->driver === 'mysql') {
            $cols = $this->fetchAll('SHOW COLUMNS FROM ' . $this->ident($table));
            return array_map(static fn ($col) => (string) $col['Field'], $cols);
        }
        $cols = $this->fetchAll('PRAGMA table_info(' . $table . ')');
        return array_map(static fn ($col) => (string) $col['name'], $cols);
    }

    private static function mysqlStatements(string $sqliteSchema): array
    {
        $parts = preg_split('/;\s*/', $sqliteSchema) ?: [];
        $statements = [];
        foreach ($parts as $part) {
            $stmt = trim($part);
            if ($stmt === '') {
                continue;
            }
            $stmt = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY', $stmt);
            $stmt = preg_replace('/\bINTEGER\b/', 'INT', $stmt) ?? $stmt;
            $stmt = str_replace('"desc"', '`desc`', $stmt);
            if (stripos($stmt, 'CREATE TABLE') === 0) {
                $stmt .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }
            $statements[] = $stmt;
        }
        return $statements;
    }
}