<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;
use Rin\Support\Helpers;
use Rin\Support\Webhook;

final class CommentController
{
    public static function list(Context $ctx, array $params): Response
    {
        $feedId = (int) $params['feed'];
        $rows = $ctx->db->fetchAll(
            'SELECT c.*, u.id AS uid, u.username, u.avatar, u.permission
             FROM comments c INNER JOIN users u ON u.id = c.user_id
             WHERE c.feed_id = :id
             ORDER BY c.created_at DESC',
            ['id' => $feedId]
        );
        return Response::json(array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'content' => $row['content'],
            'createdAt' => Dates::iso($row['created_at']),
            'updatedAt' => Dates::iso($row['updated_at']),
            'user' => [
                'id' => (int) $row['uid'],
                'username' => $row['username'],
                'avatar' => Helpers::resolveUserAvatar($row['avatar'] ?? '', Helpers::siteAvatar($ctx)),
                'permission' => $row['permission'] === null ? null : (int) $row['permission'],
            ],
        ], $rows));
    }

    public static function create(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $feedId = (int) $params['feed'];
        $body = $ctx->request->json();
        $content = $body['content'] ?? '';
        if ($content === '') {
            throw HttpException::text('Content is required', 400);
        }
        $user = $ctx->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $uid]);
        if (!$user) {
            throw HttpException::text('User not found', 400);
        }
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $feedId]);
        if (!$feed) {
            throw HttpException::text('Feed not found', 400);
        }
        $now = Dates::now();
        $ctx->db->execute(
            'INSERT INTO comments (feed_id, user_id, content, created_at, updated_at) VALUES (:f, :u, :c, :created, :updated)',
            ['f' => $feedId, 'u' => $uid, 'c' => $content, 'created' => $now, 'updated' => $now]
        );
        $hook = Helpers::webhookConfig($ctx);
        $frontendUrl = $ctx->request->baseUrl();
        try {
            Webhook::notify($hook['webhookUrl'] ?? '', [
                'event' => 'comment.created',
                'message' => "{$frontendUrl}/feed/{$feedId}\n{$user['username']} 评论了: {$feed['title']}\n{$content}",
                'title' => (string) ($feed['title'] ?? ''),
                'url' => "{$frontendUrl}/feed/{$feedId}",
                'username' => $user['username'],
                'content' => $content,
            ], [
                'method' => $hook['webhookMethod'] ?? 'POST',
                'contentType' => $hook['webhookContentType'] ?? 'application/json',
                'headers' => $hook['webhookHeaders'] ?? '{}',
                'bodyTemplate' => $hook['webhookBodyTemplate'] ?? '{"content":"{{message}}"}',
            ]);
        } catch (\Throwable) {
        }
        return Response::text('OK');
    }

    public static function delete(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $comment = $ctx->db->fetch('SELECT * FROM comments WHERE id = :id', ['id' => $id]);
        if (!$comment) {
            throw HttpException::text('Not found', 404);
        }
        if (!$ctx->admin && (int) $comment['user_id'] !== $uid) {
            throw HttpException::text('Permission denied', 403);
        }
        $ctx->db->execute('DELETE FROM comments WHERE id = :id', ['id' => $id]);
        return Response::text('OK');
    }
}
