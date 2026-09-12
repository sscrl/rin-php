<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;

final class MomentController
{
    public static function list(Context $ctx): Response
    {
        $page = max((int) ($ctx->request->query('page') ?: 1), 1) - 1;
        $limit = (int) ($ctx->request->query('limit') ?: 20);
        $limit = $limit > 50 ? 50 : ($limit > 0 ? $limit : 20);
        $cacheKey = "moments_{$page}_{$limit}";
        $cached = $ctx->cache->get($cacheKey);
        if (is_array($cached)) {
            return Response::json($cached);
        }
        $count = (int) ($ctx->db->fetch('SELECT COUNT(*) AS c FROM moments')['c'] ?? 0);
        if ($count === 0) {
            return Response::json(['size' => 0, 'data' => [], 'hasNext' => false]);
        }
        $rows = $ctx->db->fetchAll(
            'SELECT m.*, u.id AS user_id, u.username, u.avatar
             FROM moments m INNER JOIN users u ON u.id = m.uid
             ORDER BY m.created_at DESC
             LIMIT :limit OFFSET :offset',
            ['limit' => $limit + 1, 'offset' => $page * $limit]
        );
        $hasNext = count($rows) === $limit + 1;
        if ($hasNext) {
            array_pop($rows);
        }
        $data = [
            'size' => $count,
            'data' => array_map(static fn ($row) => [
                'id' => (int) $row['id'],
                'content' => $row['content'],
                'createdAt' => Dates::iso($row['created_at']),
                'updatedAt' => Dates::iso($row['updated_at']),
                'user' => [
                    'id' => (int) $row['user_id'],
                    'username' => $row['username'],
                    'avatar' => $row['avatar'] ?? '',
                ],
            ], $rows),
            'hasNext' => $hasNext,
        ];
        $ctx->cache->set($cacheKey, $data);
        return Response::json($data);
    }

    public static function create(Context $ctx): Response
    {
        $uid = $ctx->requireAdmin();
        $body = $ctx->request->json();
        $content = $body['content'] ?? '';
        if ($content === '') {
            throw HttpException::text('Content is required', 400);
        }
        $now = Dates::now();
        $id = $ctx->db->insert(
            'INSERT INTO moments (content, uid, created_at, updated_at) VALUES (:c, :u, :created, :updated)',
            ['c' => $content, 'u' => $uid, 'created' => $now, 'updated' => $now]
        );
        $ctx->cache->deletePrefix('moments_');
        return Response::json(['insertedId' => $id]);
    }

    public static function update(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $id = (int) $params['id'];
        $moment = $ctx->db->fetch('SELECT * FROM moments WHERE id = :id', ['id' => $id]);
        if (!$moment) {
            throw HttpException::text('Not found', 404);
        }
        $body = $ctx->request->json();
        $content = $body['content'] ?? '';
        if ($content === '') {
            throw HttpException::text('Content is required', 400);
        }
        $ctx->db->execute(
            'UPDATE moments SET content = :c, updated_at = :u WHERE id = :id',
            ['c' => $content, 'u' => Dates::now(), 'id' => $id]
        );
        $ctx->cache->deletePrefix('moments_');
        return Response::text('Updated');
    }

    public static function delete(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $id = (int) $params['id'];
        $moment = $ctx->db->fetch('SELECT * FROM moments WHERE id = :id', ['id' => $id]);
        if (!$moment) {
            throw HttpException::text('Not found', 404);
        }
        $ctx->db->execute('DELETE FROM moments WHERE id = :id', ['id' => $id]);
        $ctx->cache->deletePrefix('moments_');
        return Response::text('Deleted');
    }
}
