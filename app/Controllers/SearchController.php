<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\Response;
use Rin\Support\Helpers;

final class SearchController
{
    public static function search(Context $ctx, array $params): Response
    {
        $keyword = rawurldecode($params['keyword'] ?? '');
        if (trim($keyword) === '') {
            return Response::json(['size' => 0, 'data' => [], 'hasNext' => false]);
        }
        $page = max((int) ($ctx->request->query('page') ?: 1), 1) - 1;
        $limit = (int) ($ctx->request->query('limit') ?: 20);
        $limit = $limit > 50 ? 50 : ($limit > 0 ? $limit : 20);
        $like = '%' . $keyword . '%';
        $where = '(f.title LIKE :q OR f.content LIKE :q OR f.summary LIKE :q OR f.alias LIKE :q)';
        if (!$ctx->admin) {
            $where .= ' AND f.draft = 0';
        }
        $rows = $ctx->db->fetchAll(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . "
             WHERE {$where}
             ORDER BY f.created_at DESC, f.updated_at DESC",
            ['q' => $like]
        );
        $mapped = array_map(fn ($row) => Helpers::mapFeedListItem($ctx->db, $row, $ctx->admin, Helpers::siteAvatar($ctx)), $rows);
        $size = count($mapped);
        $slice = array_slice($mapped, $page * $limit, $limit);
        return Response::json([
            'size' => $size,
            'data' => $slice,
            'hasNext' => $size > ($page * $limit + $limit),
        ]);
    }
}
