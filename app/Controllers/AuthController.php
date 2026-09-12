<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Auth;
use Rin\Support\Dates;
use Rin\Support\Jwt;

final class AuthController
{
    public static function status(Context $ctx): Response
    {
        return Response::json([
            'github' => false,
            'password' => ($ctx->env['admin_username'] ?? '') !== '' && ($ctx->env['admin_password'] ?? '') !== '',
        ]);
    }

    public static function login(Context $ctx): Response
    {
        $adminUsername = (string) ($ctx->env['admin_username'] ?? '');
        $adminPassword = (string) ($ctx->env['admin_password'] ?? '');
        if ($adminUsername === '' || $adminPassword === '') {
            throw HttpException::json('Admin credentials not configured', 400, [
                'success' => false,
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'Admin credentials not configured'],
            ]);
        }
        $body = $ctx->request->json();
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            throw HttpException::json('Username and password are required', 400, [
                'success' => false,
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'Username and password are required'],
            ]);
        }
        $hashed = Auth::hashPassword($password);
        $jwt = new Jwt((string) $ctx->env['jwt_secret']);
        if ($username === $adminUsername) {
            $expected = Auth::hashPassword($adminPassword);
            if (!hash_equals($expected, $hashed)) {
                throw HttpException::json('Invalid credentials', 403, [
                    'success' => false,
                    'error' => ['code' => 'FORBIDDEN', 'message' => 'Invalid credentials'],
                ]);
            }
            $user = $ctx->db->fetch('SELECT * FROM users WHERE openid = :openid', ['openid' => 'admin']);
            $now = Dates::now();
            if (!$user) {
                $id = $ctx->db->insert(
                    'INSERT INTO users (username, openid, avatar, password, permission, created_at, updated_at)
                     VALUES (:username, :openid, :avatar, :password, 1, :c, :u)',
                    [
                        'username' => $adminUsername,
                        'openid' => 'admin',
                        'avatar' => '',
                        'password' => $expected,
                        'c' => $now,
                        'u' => $now,
                    ]
                );
                $user = $ctx->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
            } elseif (($user['password'] ?? '') !== $expected) {
                $ctx->db->execute(
                    'UPDATE users SET password = :password, username = :username, updated_at = :u WHERE id = :id',
                    ['password' => $expected, 'username' => $adminUsername, 'u' => $now, 'id' => $user['id']]
                );
                $user['password'] = $expected;
                $user['username'] = $adminUsername;
            }
            return self::success($ctx, $jwt, $user);
        }

        $user = $ctx->db->fetch('SELECT * FROM users WHERE username = :username', ['username' => $username]);
        if (!$user || empty($user['password']) || !hash_equals((string) $user['password'], $hashed)) {
            throw HttpException::json('Invalid credentials', 403, [
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Invalid credentials'],
            ]);
        }
        return self::success($ctx, $jwt, $user);
    }

    private static function success(Context $ctx, Jwt $jwt, array $user): Response
    {
        $token = $jwt->sign(['id' => (int) $user['id']]);
        $secure = $ctx->request->scheme === 'https';
        return Response::json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'avatar' => $user['avatar'],
                'permission' => (int) $user['permission'] === 1,
            ],
        ])->addCookie('token', $token, Auth::cookieOptions($secure));
    }
}
