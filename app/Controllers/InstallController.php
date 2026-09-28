<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Request;
use Rin\Core\Response;
use Rin\Support\Installer;

final class InstallController
{
    public static function status(string $root): Response
    {
        return Response::json(['installed' => Installer::isInstalled($root)]);
    }

    public static function testDb(Request $request, string $root): Response
    {
        if (Installer::isInstalled($root)) {
            return self::fail('站点已经安装', 409);
        }
        try {
            $body = $request->json();
            $driver = strtolower(trim((string) ($body['db_driver'] ?? 'sqlite')));
            if ($driver === 'mysql') {
                if (trim((string) ($body['db_name'] ?? '')) === '' || trim((string) ($body['db_user'] ?? '')) === '') {
                    return self::fail('请填写 MySQL 数据库名和用户名');
                }
            }
            Installer::testConnection($root, $body);
        } catch (\Throwable $e) {
            return self::fail($e->getMessage());
        }
        return Response::json(['success' => true]);
    }

    public static function install(Request $request, string $root): Response
    {
        if (Installer::isInstalled($root)) {
            return self::fail('站点已经安装', 409);
        }
        try {
            Installer::install($root, $request->json());
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        } catch (\Throwable $e) {
            return self::fail($e->getMessage());
        }
        return Response::json([
            'success' => true,
            'redirect' => '/',
        ]);
    }

    private static function fail(string $message, int $status = 400): Response
    {
        return Response::json([
            'success' => false,
            'error' => ['code' => 'INSTALL_ERROR', 'message' => $message],
        ], $status);
    }
}