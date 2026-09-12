<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;
use Rin\Support\Helpers;
use Rin\Support\Webhook;

final class FriendController
{
    public static function list(Context $ctx): Response
    {
        if ($ctx->admin) {
            $rows = $ctx->db->fetchAll('SELECT * FROM friends ORDER BY sort_order DESC, created_at ASC');
        } else {
            $rows = $ctx->db->fetchAll('SELECT * FROM friends WHERE accepted = 1 ORDER BY sort_order DESC, created_at ASC');
        }
        $apply = $ctx->uid ? $ctx->db->fetch('SELECT * FROM friends WHERE uid = :uid', ['uid' => $ctx->uid]) : null;
        return Response::json([
            'friend_list' => array_map([self::class, 'map'], $rows),
            'apply_list' => $apply ? self::map($apply) : null,
        ]);
    }

    public static function create(Context $ctx): Response
    {
        $uid = $ctx->requireUser();
        $enable = Helpers::bool($ctx->clientConfig->getOrDefault('friend_apply_enable', true));
        if (!$enable && !$ctx->admin) {
            throw HttpException::text('Friend Link Apply Disabled', 403);
        }
        $body = $ctx->request->json();
        $name = (string) ($body['name'] ?? '');
        $desc = (string) ($body['desc'] ?? '');
        $avatar = (string) ($body['avatar'] ?? '');
        $url = (string) ($body['url'] ?? '');
        if (mb_strlen($name) > 20 || mb_strlen($desc) > 100 || mb_strlen($avatar) > 100 || mb_strlen($url) > 100) {
            throw HttpException::text('Invalid input', 400);
        }
        if ($name === '' || $desc === '' || $avatar === '' || $url === '') {
            throw HttpException::text('Invalid input', 400);
        }
        if (!$ctx->admin) {
            $exist = $ctx->db->fetch('SELECT id FROM friends WHERE uid = :uid', ['uid' => $uid]);
            if ($exist) {
                throw HttpException::text('Already sent', 400);
            }
        }
        $now = Dates::now();
        $ctx->db->execute(
            'INSERT INTO friends (name, "desc", avatar, url, uid, accepted, health, sort_order, created_at, updated_at)
             VALUES (:name, :desc, :avatar, :url, :uid, :accepted, \'\', 0, :c, :u)',
            [
                'name' => $name,
                'desc' => $desc,
                'avatar' => $avatar,
                'url' => $url,
                'uid' => $uid,
                'accepted' => $ctx->admin ? 1 : 0,
                'c' => $now,
                'u' => $now,
            ]
        );
        if (!$ctx->admin) {
            $hook = Helpers::webhookConfig($ctx);
            $frontendUrl = $ctx->request->baseUrl();
            Webhook::notify($hook['webhookUrl'] ?? '', [
                'event' => 'friend.created',
                'message' => "{$frontendUrl}/friends\n{$ctx->username} 申请友链: {$name}\n{$desc}\n{$url}",
                'title' => $name,
                'url' => $frontendUrl . '/friends',
                'username' => (string) $ctx->username,
                'content' => $url,
                'description' => $desc,
            ], [
                'method' => $hook['webhookMethod'] ?? 'POST',
                'contentType' => $hook['webhookContentType'] ?? 'application/json',
                'headers' => $hook['webhookHeaders'] ?? '{}',
                'bodyTemplate' => $hook['webhookBodyTemplate'] ?? '{"content":"{{message}}"}',
            ]);
        }
        return Response::text('OK');
    }

    public static function update(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $exist = $ctx->db->fetch('SELECT * FROM friends WHERE id = :id', ['id' => $id]);
        if (!$exist) {
            throw HttpException::text('Not found', 404);
        }
        if (!$ctx->admin && (int) $exist['uid'] !== $uid) {
            throw HttpException::text('Permission denied', 403);
        }
        $body = $ctx->request->json();
        $name = self::wrap($body['name'] ?? null) ?? $exist['name'];
        $desc = self::wrap($body['desc'] ?? null) ?? $exist['desc'];
        $avatar = self::wrap($body['avatar'] ?? null) ?? $exist['avatar'];
        $url = self::wrap($body['url'] ?? null) ?? $exist['url'];
        $accepted = $ctx->admin ? ($body['accepted'] ?? $exist['accepted']) : 0;
        $sort = $ctx->admin ? ($body['sort_order'] ?? $exist['sort_order']) : $exist['sort_order'];
        $ctx->db->execute(
            'UPDATE friends SET name = :name, "desc" = :desc, avatar = :avatar, url = :url, accepted = :accepted, sort_order = :sort, updated_at = :u WHERE id = :id',
            [
                'name' => $name,
                'desc' => $desc,
                'avatar' => $avatar,
                'url' => $url,
                'accepted' => (int) $accepted,
                'sort' => (int) $sort,
                'u' => Dates::now(),
                'id' => $id,
            ]
        );
        if (!$ctx->admin) {
            $hook = Helpers::webhookConfig($ctx);
            $frontendUrl = $ctx->request->baseUrl();
            Webhook::notify($hook['webhookUrl'] ?? '', [
                'event' => 'friend.updated',
                'message' => "{$frontendUrl}/friends\n{$ctx->username} 更新友链: {$name}\n{$desc}\n{$url}",
                'title' => (string) $name,
                'url' => $frontendUrl . '/friends',
                'username' => (string) $ctx->username,
                'content' => (string) $url,
                'description' => (string) $desc,
            ], [
                'method' => $hook['webhookMethod'] ?? 'POST',
                'contentType' => $hook['webhookContentType'] ?? 'application/json',
                'headers' => $hook['webhookHeaders'] ?? '{}',
                'bodyTemplate' => $hook['webhookBodyTemplate'] ?? '{"content":"{{message}}"}',
            ]);
        }
        return Response::text('OK');
    }

    public static function delete(Context $ctx, array $params): Response
    {
        $uid = $ctx->requireUser();
        $id = (int) $params['id'];
        $exist = $ctx->db->fetch('SELECT * FROM friends WHERE id = :id', ['id' => $id]);
        if (!$exist) {
            throw HttpException::text('Not found', 404);
        }
        if (!$ctx->admin && (int) $exist['uid'] !== $uid) {
            throw HttpException::text('Permission denied', 403);
        }
        $ctx->db->execute('DELETE FROM friends WHERE id = :id', ['id' => $id]);
        return Response::text('OK');
    }

    public static function checkHealth(Context $ctx): void
    {
        $enable = Helpers::bool($ctx->serverConfig->getOrDefault('friend_crontab', true));
        if (!$enable) {
            return;
        }
        $ua = (string) ($ctx->serverConfig->get('friend_ua') ?: 'Rin-Check/0.1.0');
        $friends = $ctx->db->fetchAll('SELECT * FROM friends');
        foreach ($friends as $friend) {
            $health = '';
            $ch = curl_init($friend['url']);
            if ($ch === false) {
                $health = 'request failed';
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_NOBODY => false,
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                if ($status < 200 || $status >= 300) {
                    $health = $error !== '' ? $error : (string) $status;
                }
            }
            $ctx->db->execute(
                'UPDATE friends SET health = :health, updated_at = :u WHERE id = :id',
                ['health' => $health, 'u' => Dates::now(), 'id' => $friend['id']]
            );
        }
    }

    private static function map(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'desc' => $row['desc'],
            'avatar' => $row['avatar'],
            'url' => $row['url'],
            'accepted' => (int) $row['accepted'],
            'sort_order' => (int) $row['sort_order'],
            'createdAt' => Dates::iso($row['created_at']),
            'uid' => (int) $row['uid'],
            'updatedAt' => Dates::iso($row['updated_at']),
            'health' => $row['health'],
        ];
    }

    private static function wrap(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        return $s;
    }
}

