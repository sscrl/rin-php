<?php
declare(strict_types=1);

namespace Rin\Support;

final class Installer
{
    public static function isInstalled(string $root): bool
    {
        $config = $root . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($config)) {
            return false;
        }
        $lock = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed.lock';
        if (is_file($lock)) {
            return true;
        }
        $env = self::readConfig($config);
        $driver = strtolower((string) ($env['db_driver'] ?? 'sqlite'));
        if ($driver === 'mysql') {
            return trim((string) ($env['db_name'] ?? '')) !== '';
        }
        $sqlite = self::sqlitePath($root, $env);
        return is_file($sqlite);
    }

    public static function readConfig(string $file): array
    {
        $env = include $file;
        return is_array($env) ? $env : [];
    }

    public static function sqlitePath(string $root, array $env = []): string
    {
        $path = (string) ($env['db_path'] ?? 'database.sqlite');
        if (self::isAbsolutePath($path)) {
            return $path;
        }
        return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $path;
    }

    public static function schemaPath(string $root): string
    {
        return $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schema.sql';
    }

    public static function testConnection(string $root, array $input): void
    {
        $driver = self::driver($input);
        self::ensureStorage($root);
        if ($driver === 'sqlite') {
            $dir = $root . DIRECTORY_SEPARATOR . 'storage';
            if (!is_writable($dir)) {
                throw new \RuntimeException('storage 目录不可写，请检查权限');
            }
            return;
        }
        Database::probeMysql($input);
    }

    public static function install(string $root, array $input): void
    {
        if (self::isInstalled($root)) {
            throw new \RuntimeException('站点已经安装');
        }
        $data = self::normalize($input);
        self::ensureStorage($root);
        self::testConnection($root, $data);

        $config = [
            'admin_username' => $data['admin_username'],
            'admin_password' => $data['admin_password'],
            'jwt_secret' => bin2hex(random_bytes(32)),
            'site_name' => $data['site_name'],
            'site_description' => $data['site_description'],
            'site_avatar' => $data['site_avatar'],
            'page_size' => 5,
            'rss_title' => $data['site_name'],
            'rss_description' => $data['site_description'] !== '' ? $data['site_description'] : $data['site_name'],
            'webhook_url' => '',
            'upload_folder' => 'images/',
            'cache_folder' => 'cache/',
            'db_driver' => $data['db_driver'],
            'db_path' => 'database.sqlite',
            'db_host' => $data['db_host'],
            'db_port' => $data['db_port'],
            'db_name' => $data['db_name'],
            'db_user' => $data['db_user'],
            'db_pass' => $data['db_pass'],
            'db_charset' => 'utf8mb4',
        ];

        $db = Database::fromEnv($config, $root, self::schemaPath($root), true);
        self::seed($db, $config);
        self::writeConfig($root, $config);
        $lock = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed.lock';
        file_put_contents($lock, date('c') . PHP_EOL);
    }

    public static function writeConfig(string $root, array $config): void
    {
        $file = $root . DIRECTORY_SEPARATOR . 'config.php';
        $tmp = $file . '.tmp';
        $code = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($tmp, $code) === false) {
            throw new \RuntimeException('无法写入 config.php，请检查目录权限');
        }
        if (!@rename($tmp, $file) && !(@copy($tmp, $file) && @unlink($tmp))) {
            @unlink($tmp);
            throw new \RuntimeException('无法写入 config.php，请检查目录权限');
        }
        @unlink($tmp);
    }

    public static function ensureStorage(string $root): void
    {
        foreach (['storage', 'storage/uploads', 'storage/cache', 'storage/logs'] as $rel) {
            $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('无法创建目录 ' . $rel);
            }
        }
    }

    private static function seed(Database $db, array $config): void
    {
        $now = Dates::now();
        $adminHash = Auth::hashPassword((string) $config['admin_password']);
        $admin = $db->fetch('SELECT id FROM users WHERE openid = :openid', ['openid' => 'admin']);
        if ($admin) {
            $db->execute(
                'UPDATE users SET username = :username, password = :password, permission = 1, updated_at = :u WHERE id = :id',
                [
                    'username' => $config['admin_username'],
                    'password' => $adminHash,
                    'u' => $now,
                    'id' => $admin['id'],
                ]
            );
        } else {
            $db->insert(
                'INSERT INTO users (username, openid, avatar, password, permission, created_at, updated_at)
                 VALUES (:username, :openid, :avatar, :password, 1, :c, :u)',
                [
                    'username' => $config['admin_username'],
                    'openid' => 'admin',
                    'avatar' => '',
                    'password' => $adminHash,
                    'c' => $now,
                    'u' => $now,
                ]
            );
        }

        $guest = $db->fetch('SELECT id FROM users WHERE openid = :openid', ['openid' => 'guest']);
        if (!$guest) {
            $db->insert(
                'INSERT INTO users (username, openid, avatar, password, permission, created_at, updated_at)
                 VALUES (:username, :openid, :avatar, :password, 0, :c, :u)',
                [
                    'username' => 'guest',
                    'openid' => 'guest',
                    'avatar' => '',
                    'password' => '',
                    'c' => $now,
                    'u' => $now,
                ]
            );
        }

        $client = new ConfigStore($db, 'client.config', Helpers::CLIENT_DEFAULTS);
        $client->set('site.name', $config['site_name']);
        $client->set('site.description', $config['site_description']);
        $client->set('site.avatar', $config['site_avatar']);
        $client->set('friend_apply_enable', true);
    }

    public static function normalize(array $input): array
    {
        $driver = self::driver($input);
        $siteName = trim((string) ($input['site_name'] ?? ''));
        $siteDescription = trim((string) ($input['site_description'] ?? ''));
        $siteAvatar = trim((string) ($input['site_avatar'] ?? ''));
        $username = trim((string) ($input['admin_username'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        $confirm = (string) ($input['admin_password_confirm'] ?? $input['admin_password'] ?? '');

        if ($siteName === '') {
            throw new \InvalidArgumentException('请填写站点名称');
        }
        if (mb_strlen($siteName) > 50) {
            throw new \InvalidArgumentException('站点名称最多 50 个字符');
        }
        if (mb_strlen($siteDescription) > 200) {
            throw new \InvalidArgumentException('站点简介最多 200 个字符');
        }
        if ($siteAvatar !== '' && !self::isHttpUrl($siteAvatar)) {
            throw new \InvalidArgumentException('站点头像需要是 http(s) 链接');
        }
        if ($username === '' || mb_strlen($username) < 2 || mb_strlen($username) > 32) {
            throw new \InvalidArgumentException('管理员用户名长度为 2-32 个字符');
        }
        if (preg_match('/\s/', $username) === 1) {
            throw new \InvalidArgumentException('管理员用户名不能包含空格');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('密码至少 8 位');
        }
        if (strlen($password) > 128) {
            throw new \InvalidArgumentException('密码过长');
        }
        if ($password !== $confirm) {
            throw new \InvalidArgumentException('两次输入的密码不一致');
        }

        $data = [
            'db_driver' => $driver,
            'db_host' => trim((string) ($input['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
            'db_port' => (int) ($input['db_port'] ?? 3306) ?: 3306,
            'db_name' => trim((string) ($input['db_name'] ?? '')),
            'db_user' => trim((string) ($input['db_user'] ?? '')),
            'db_pass' => (string) ($input['db_pass'] ?? ''),
            'site_name' => $siteName,
            'site_description' => $siteDescription,
            'site_avatar' => $siteAvatar,
            'admin_username' => $username,
            'admin_password' => $password,
        ];
        if ($driver === 'mysql') {
            if ($data['db_name'] === '' || $data['db_user'] === '') {
                throw new \InvalidArgumentException('请填写 MySQL 数据库名和用户名');
            }
        }
        return $data;
    }

    private static function driver(array $input): string
    {
        $driver = strtolower(trim((string) ($input['db_driver'] ?? 'sqlite')));
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \InvalidArgumentException('不支持的数据库类型');
        }
        return $driver;
    }

    private static function isHttpUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}