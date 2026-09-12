<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;
use Rin\Support\Helpers;

final class TagController
{
    public static function list(Context $ctx): Response
    {
        $tags = $ctx->db->fetchAll('SELECT * FROM hashtags ORDER BY name');
        $out = [];
        foreach ($tags as $tag) {
            $count = (int) ($ctx->db->fetch(
                'SELECT COUNT(*) AS c FROM feed_hashtags WHERE hashtag_id = :id',
                ['id' => $tag['id']]
            )['c'] ?? 0);
            $out[] = [
                'id' => (int) $tag['id'],
                'name' => $tag['name'],
                'feeds' => $count,
                'createdAt' => Dates::iso($tag['created_at']),
                'updatedAt' => Dates::iso($tag['updated_at']),
            ];
        }
        return Response::json($out);
    }

    public static function show(Context $ctx, array $params): Response
    {
        $name = rawurldecode($params['name'] ?? '');
        $tag = $ctx->db->fetch('SELECT * FROM hashtags WHERE name = :name', ['name' => $name]);
        if (!$tag) {
            throw HttpException::text('Not found', 404);
        }
        $where = $ctx->admin ? '1=1' : 'f.draft = 0 AND f.listed = 1';
        $rows = $ctx->db->fetchAll(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . '
             INNER JOIN feed_hashtags fh ON fh.feed_id = f.id
             WHERE fh.hashtag_id = :id AND ' . $where . '
             ORDER BY f.created_at DESC',
            ['id' => $tag['id']]
        );
        $feeds = [];
        foreach ($rows as $row) {
            $item = Helpers::mapFeedListItem($ctx->db, $row, false, Helpers::siteAvatar($ctx));
            $item['content'] = $row['content'];
            $feeds[] = $item;
        }
        return Response::json([
            'id' => (int) $tag['id'],
            'name' => $tag['name'],
            'createdAt' => Dates::iso($tag['created_at']),
            'updatedAt' => Dates::iso($tag['updated_at']),
            'feeds' => $feeds,
        ]);
    }
}
