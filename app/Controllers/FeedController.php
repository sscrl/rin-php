<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;
use Rin\Support\Helpers;
use Rin\Support\Images;

final class FeedController
{
    public static function list(Context $ctx): Response
    {
        $type = $ctx->request->query('type');
        if (($type === 'draft' || $type === 'unlisted') && !$ctx->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        $page = max((int) ($ctx->request->query('page') ?: 1), 1) - 1;
        $limit = (int) ($ctx->request->query('limit') ?: 20);
        $limit = $limit > 50 ? 50 : ($limit > 0 ? $limit : 20);
        $cacheKey = "feeds_{$type}_{$page}_{$limit}";
        if ($type === null || $type === 'normal' || $type === '') {
            $cached = $ctx->cache->get($cacheKey);
            if (is_array($cached)) {
                return Response::json($cached);
            }
        }
        $where = 'f.draft = 0 AND f.listed = 1';
        if ($type === 'draft') {
            $where = 'f.draft = 1';
        } elseif ($type === 'unlisted') {
            $where = 'f.draft = 0 AND f.listed = 0';
        }
        $count = (int) ($ctx->db->fetch('SELECT COUNT(*) AS c FROM feeds f WHERE ' . $where)['c'] ?? 0);
        if ($count === 0) {
            return Response::json(['size' => 0, 'data' => [], 'hasNext' => false]);
        }
        $rows = $ctx->db->fetchAll(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . "
             WHERE {$where}
             ORDER BY f.top DESC, f.created_at DESC, f.updated_at DESC
             LIMIT " . ($limit + 1) . " OFFSET " . ($page * $limit),
            []
        );
        $hasNext = count($rows) === $limit + 1;
        if ($hasNext) {
            array_pop($rows);
        }
        $data = [
            'size' => $count,
            'data' => array_map(fn ($row) => Helpers::mapFeedListItem($ctx->db, $row, $ctx->admin, Helpers::siteAvatar($ctx)), $rows),
            'hasNext' => $hasNext,
        ];
        if ($type === null || $type === 'normal' || $type === '') {
            $ctx->cache->set($cacheKey, $data);
        }
        return Response::json($data);
    }

    public static function timeline(Context $ctx): Response
    {
        $rows = $ctx->db->fetchAll(
            'SELECT id, title, created_at FROM feeds WHERE draft = 0 AND listed = 1 ORDER BY created_at DESC, updated_at DESC'
        );
        return Response::json(array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'createdAt' => Dates::iso($row['created_at']),
        ], $rows));
    }

    public static function create(Context $ctx): Response
    {
        $uid = $ctx->requireAdmin();
        $body = $ctx->request->json();
        $title = $body['title'] ?? null;
        $content = $body['content'] ?? null;
        if (!$title) {
            throw HttpException::text('Title is required', 400);
        }
        if (!$content) {
            throw HttpException::text('Content is required', 400);
        }
        $exist = $ctx->db->fetch(
            'SELECT id FROM feeds WHERE title = :title OR content = :content',
            ['title' => $title, 'content' => $content]
        );
        if ($exist) {
            throw HttpException::text('Content already exists', 400);
        }
        $now = Dates::parse($body['createdAt'] ?? null) ?? Dates::now();
        $id = $ctx->db->insert(
            'INSERT INTO feeds (alias, title, summary, ai_summary, ai_summary_status, ai_summary_error, content, cover, listed, draft, top, uid, created_at, updated_at)
             VALUES (:alias, :title, :summary, \'\', \'idle\', \'\', :content, :cover, :listed, :draft, 0, :uid, :c, :u)',
            [
                'alias' => $body['alias'] ?? null,
                'title' => $title,
                'summary' => (string) ($body['summary'] ?? ''),
                'content' => $content,
                'cover' => trim((string) ($body['cover'] ?? $body['avatar'] ?? '')),
                'listed' => !empty($body['listed']) ? 1 : 0,
                'draft' => !empty($body['draft']) ? 1 : 0,
                'uid' => $uid,
                'c' => $now,
                'u' => $now,
            ]
        );
        Helpers::bindTags($ctx->db, $id, $body['tags'] ?? []);
        Helpers::syncAiSummary($ctx, $id, !empty($body['draft']), true);
        $ctx->cache->deletePrefix('feeds_');
        return Response::json(['insertedId' => $id]);
    }

    public static function show(Context $ctx, array $params): Response
    {
        $id = $params['id'];
        $idNum = ctype_digit($id) ? (int) $id : -1;
        $row = $ctx->db->fetch(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . ' WHERE f.id = :id OR f.alias = :alias',
            ['id' => $idNum, 'alias' => $id]
        );
        if (!$row) {
            throw HttpException::text('Not found', 404);
        }
        if ((int) $row['draft'] === 1 && (int) $row['uid'] !== (int) $ctx->uid && !$ctx->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        $pv = 0;
        $uv = 0;
        if (Helpers::bool($ctx->clientConfig->getOrDefault('counter.enabled', true))) {
            $ip = $ctx->request->ip;
            $stats = $ctx->db->fetch('SELECT * FROM visit_stats WHERE feed_id = :id', ['id' => $row['id']]);
            $now = Dates::now();
            if (!$stats) {
                $ctx->db->execute(
                    'INSERT INTO visit_stats (feed_id, pv, hll_data, updated_at) VALUES (:id, 1, :hll, :u)',
                    ['id' => $row['id'], 'hll' => $ip, 'u' => $now]
                );
                $pv = 1;
                $uv = 1;
            } else {
                $ips = array_filter(explode("\n", (string) $stats['hll_data']));
                if (!in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
                $pv = (int) $stats['pv'] + 1;
                $uv = count($ips);
                $ctx->db->execute(
                    'UPDATE visit_stats SET pv = :pv, hll_data = :hll, updated_at = :u WHERE feed_id = :id',
                    ['pv' => $pv, 'hll' => implode("\n", $ips), 'u' => $now, 'id' => $row['id']]
                );
            }
            $ctx->db->execute(
                'INSERT INTO visits (feed_id, ip, created_at) VALUES (:id, :ip, :c)',
                ['id' => $row['id'], 'ip' => $ip, 'c' => Dates::now()]
            );
        }
        return Response::json(Helpers::mapFeedDetail($ctx->db, $row, $pv, $uv, Helpers::siteAvatar($ctx)));
    }

    public static function adjacent(Context $ctx, array $params): Response
    {
        $id = $params['id'];
        if (ctype_digit($id)) {
            $idNum = (int) $id;
        } else {
            $alias = $ctx->db->fetch('SELECT id FROM feeds WHERE alias = :alias', ['alias' => $id]);
            if (!$alias) {
                throw HttpException::text('Not found', 404);
            }
            $idNum = (int) $alias['id'];
        }
        $feed = $ctx->db->fetch('SELECT created_at FROM feeds WHERE id = :id', ['id' => $idNum]);
        if (!$feed) {
            throw HttpException::text('Not found', 404);
        }
        $created = (int) $feed['created_at'];
        $prev = $ctx->db->fetch(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . '
             WHERE f.draft = 0 AND f.listed = 1 AND f.created_at < :c
             ORDER BY f.created_at DESC LIMIT 1',
            ['c' => $created]
        );
        $next = $ctx->db->fetch(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . '
             WHERE f.draft = 0 AND f.listed = 1 AND f.created_at > :c
             ORDER BY f.created_at ASC LIMIT 1',
            ['c' => $created]
        );
        $map = static function (?array $row) use ($ctx) {
            if (!$row) {
                return null;
            }
            $content = (string) $row['content'];
            return [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'summary' => Helpers::summary((string) $row['summary'], $content, 50),
                'hashtags' => Helpers::feedHashtags($ctx->db, (int) $row['id']),
                'createdAt' => Dates::iso($row['created_at']),
                'updatedAt' => Dates::iso($row['updated_at']),
            ];
        };
        return Response::json([
            'previousFeed' => $map($prev),
            'nextFeed' => $map($next),
        ]);
    }

    public static function update(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Not found', 404);
        }
        if ((int) $feed['uid'] !== $uid && !$ctx->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        $body = $ctx->request->json();
        $content = $body['content'] ?? $feed['content'];
        $contentChanged = isset($body['content']) && $body['content'] !== $feed['content'];
        $isDraft = array_key_exists('draft', $body) ? !empty($body['draft']) : ((int) $feed['draft'] === 1);
        $shouldQueue = ($contentChanged && !$isDraft) || (!$isDraft && (int) $feed['draft'] === 1 && ($feed['ai_summary'] === ''));
        $now = Dates::now();
        $created = Dates::parse($body['createdAt'] ?? null);
        $ctx->db->execute(
            'UPDATE feeds SET title = :title, content = :content, cover = :cover, summary = :summary, alias = :alias, top = :top,
                listed = :listed, draft = :draft, created_at = :created, updated_at = :updated
             WHERE id = :id',
            [
                'title' => $body['title'] ?? $feed['title'],
                'content' => $content,
                'cover' => array_key_exists('cover', $body) || array_key_exists('avatar', $body)
                    ? trim((string) ($body['cover'] ?? $body['avatar'] ?? ''))
                    : (string) ($feed['cover'] ?? ''),
                'summary' => $body['summary'] ?? $feed['summary'],
                'alias' => array_key_exists('alias', $body) ? $body['alias'] : $feed['alias'],
                'top' => array_key_exists('top', $body) ? (int) $body['top'] : $feed['top'],
                'listed' => !empty($body['listed']) ? 1 : 0,
                'draft' => $isDraft ? 1 : 0,
                'created' => $created ?? $feed['created_at'],
                'updated' => $now,
                'id' => $id,
            ]
        );
        if (isset($body['tags']) && is_array($body['tags'])) {
            Helpers::bindTags($ctx->db, $id, $body['tags']);
        }
        if ($shouldQueue || $isDraft) {
            Helpers::syncAiSummary($ctx, $id, $isDraft, $shouldQueue);
        }
        Helpers::clearFeedCache($ctx->cache, $id, $feed['alias'] ?? null, $body['alias'] ?? null);
        return Response::text('Updated');
    }

    public static function setTop(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Not found', 404);
        }
        if ((int) $feed['uid'] !== $uid && !$ctx->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        $body = $ctx->request->json();
        $ctx->db->execute('UPDATE feeds SET top = :top WHERE id = :id', ['top' => (int) ($body['top'] ?? 0), 'id' => $id]);
        Helpers::clearFeedCache($ctx->cache, $id, null, null);
        return Response::text('Updated');
    }

    public static function delete(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Not found', 404);
        }
        if ((int) $feed['uid'] !== $uid && !$ctx->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        $ctx->db->execute('DELETE FROM feeds WHERE id = :id', ['id' => $id]);
        Helpers::clearFeedCache($ctx->cache, $id, $feed['alias'] ?? null, null);
        return Response::text('Deleted');
    }
}
