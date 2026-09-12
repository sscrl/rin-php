<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;

final class UserController
{
    public static function profile(Context $ctx): Response
    {
        if ($ctx->uid === null) {
            throw HttpException::json('Authentication required', 403, [
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Authentication required'],
            ]);
        }
        $user = $ctx->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $ctx->uid]);
        if (!$user) {
            throw HttpException::json('User not found', 404, [
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'User not found'],
            ]);
        }
        return Response::json([
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'avatar' => $user['avatar'],
            'permission' => (int) $user['permission'] === 1,
            'createdAt' => Dates::iso($user['created_at']),
            'updatedAt' => Dates::iso($user['updated_at']),
        ]);
    }

    public static function updateProfile(Context $ctx): Response
    {
        $uid = $ctx->uid;
        if ($uid === null) {
            throw HttpException::json('Authentication required', 403, [
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Authentication required'],
            ]);
        }
        $body = $ctx->request->json();
        $username = $body['username'] ?? null;
        $avatar = $body['avatar'] ?? null;
        if (!$username && !$avatar) {
            throw HttpException::json('At least one field (username or avatar) is required', 400, [
                'success' => false,
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'At least one field (username or avatar) is required'],
            ]);
        }
        $fields = [];
        $params = ['id' => $uid, 'u' => Dates::now()];
        if ($username) {
            $fields[] = 'username = :username';
            $params['username'] = $username;
        }
        if ($avatar) {
            $fields[] = 'avatar = :avatar';
            $params['avatar'] = $avatar;
        }
        $fields[] = 'updated_at = :u';
        $ctx->db->execute('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
        return Response::json(['success' => true]);
    }

    public static function logout(Context $ctx): Response
    {
        $secure = $ctx->request->scheme === 'https';
        $opts = ['expires' => time() - 3600, 'path' => '/', 'httpOnly' => true, 'secure' => $secure, 'sameSite' => 'Lax'];
        return Response::json(['success' => true])
            ->addCookie('token', '', $opts)
            ->addCookie('auth_token', '', ['expires' => time() - 3600, 'path' => '/', 'sameSite' => 'Lax']);
    }

    public static function github(): Response
    {
        throw HttpException::json('GitHub OAuth is not configured', 400, [
            'success' => false,
            'error' => ['code' => 'BAD_REQUEST', 'message' => 'GitHub OAuth is not configured'],
        ]);
    }
}
