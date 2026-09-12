<?php
declare(strict_types=1);

namespace Rin\Support;

use Rin\Core\Context;

final class Helpers
{
    public const CLIENT_DEFAULTS = [
        'cache.enabled' => false,
        'counter.enabled' => true,
        'friend_apply_enable' => true,
        'header.behavior' => 'fixed',
        'header.layout' => 'classic',
        'feed.layout' => 'list',
        'feed.card_variant' => 'default',
        'theme.color' => '#fc466b',
        'comment.enabled' => true,
        'login.enabled' => true,
        'site.name' => 'Rin',
        'site.description' => 'A lightweight personal blogging system',
        'site.avatar' => '',
        'site.logo' => '',
        'site.page_size' => 5,
    ];

    public const SERVER_DEFAULTS = [
        'friend_apply_auto_accept' => false,
        'friend_crontab' => true,
        'friend_ua' => 'Rin-Check/0.1.0',
        'webhook.method' => 'POST',
        'webhook.content_type' => 'application/json',
        'webhook.headers' => '{}',
        'webhook.body_template' => '{"content":"{{message}}"}',
    ];

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true') {
                return true;
            }
            if ($normalized === 'false' || $normalized === '') {
                return false;
            }
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        return (bool) $value;
    }

    public static function summary(string $summary, string $content, int $len = 100): string
    {
        if ($summary !== '') {
            return $summary;
        }
        return mb_strlen($content) > $len ? mb_substr($content, 0, $len) : $content;
    }

    public static function siteAvatar(Context $ctx): string
    {
        return trim((string) ($ctx->clientConfig->getOrDefault('site.avatar', $ctx->env['site_avatar'] ?? '') ?: ''));
    }

    public static function resolveUserAvatar(mixed $avatar, string $siteAvatar = ''): ?string
    {
        $value = is_string($avatar) ? trim($avatar) : '';
        if ($value !== '') {
            return $value;
        }
        $siteAvatar = trim($siteAvatar);
        return $siteAvatar !== '' ? $siteAvatar : null;
    }

    public static function resolveFeedCover(array $row): ?string
    {
        $cover = trim((string) ($row['cover'] ?? ''));
        if ($cover !== '') {
            return $cover;
        }
        return Images::extractImageWithMetadata((string) ($row['content'] ?? ''));
    }

    public static function syncAdminAvatar(Context $ctx, mixed $avatar): void
    {
        if (!is_string($avatar)) {
            return;
        }
        $ctx->db->execute(
            'UPDATE users SET avatar = :avatar, updated_at = :u WHERE openid = :openid OR permission = 1',
            ['avatar' => trim($avatar), 'u' => Dates::now(), 'openid' => 'admin']
        );
    }

    public static function mapUser(array $row, string $siteAvatar = ''): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'avatar' => self::resolveUserAvatar($row['avatar'] ?? '', $siteAvatar),
        ];
    }

    public static function feedHashtags(Database $db, int $feedId): array
    {
        $rows = $db->fetchAll(
            'SELECT h.id, h.name FROM hashtags h
             INNER JOIN feed_hashtags fh ON fh.hashtag_id = h.id
             WHERE fh.feed_id = :id',
            ['id' => $feedId]
        );
        return array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ], $rows);
    }

    public static function mapFeedListItem(Database $db, array $row, bool $admin, string $siteAvatar = ''): array
    {
        $item = [
            'id' => (int) $row['id'],
            'alias' => $row['alias'],
            'title' => $row['title'],
            'summary' => self::summary((string) $row['summary'], (string) $row['content']),
            'hashtags' => self::feedHashtags($db, (int) $row['id']),
            'user' => [
                'id' => (int) $row['user_id'],
                'username' => $row['user_name'],
                'avatar' => self::resolveUserAvatar($row['user_avatar'] ?? '', $siteAvatar),
            ],
            'avatar' => self::resolveFeedCover($row),
            'cover' => trim((string) ($row['cover'] ?? '')) ?: null,
            'createdAt' => Dates::iso($row['created_at']),
            'updatedAt' => Dates::iso($row['updated_at']),
            'uid' => (int) $row['uid'],
            'top' => (int) $row['top'],
            'ai_summary' => $row['ai_summary'],
            'ai_summary_status' => $row['ai_summary_status'],
            'ai_summary_error' => $row['ai_summary_error'],
        ];
        if ($admin) {
            $item['draft'] = (int) $row['draft'];
            $item['listed'] = (int) $row['listed'];
        }
        return $item;
    }

    public static function mapFeedDetail(Database $db, array $row, int $pv = 0, int $uv = 0, string $siteAvatar = ''): array
    {
        return [
            'id' => (int) $row['id'],
            'alias' => $row['alias'],
            'title' => $row['title'],
            'summary' => $row['summary'],
            'content' => $row['content'],
            'cover' => trim((string) ($row['cover'] ?? '')) ?: null,
            'listed' => (int) $row['listed'],
            'draft' => (int) $row['draft'],
            'top' => (int) $row['top'],
            'uid' => (int) $row['uid'],
            'createdAt' => Dates::iso($row['created_at']),
            'updatedAt' => Dates::iso($row['updated_at']),
            'ai_summary' => $row['ai_summary'],
            'ai_summary_status' => $row['ai_summary_status'],
            'ai_summary_error' => $row['ai_summary_error'],
            'hashtags' => self::feedHashtags($db, (int) $row['id']),
            'user' => [
                'id' => (int) $row['user_id'],
                'username' => $row['user_name'],
                'avatar' => self::resolveUserAvatar($row['user_avatar'] ?? '', $siteAvatar),
            ],
            'pv' => $pv,
            'uv' => $uv,
        ];
    }

    public static function bindTags(Database $db, int $feedId, array $tags): void
    {
        $now = Dates::now();
        $db->execute('DELETE FROM feed_hashtags WHERE feed_id = :id', ['id' => $feedId]);
        foreach ($tags as $tag) {
            $name = trim((string) $tag);
            if ($name === '') {
                continue;
            }
            $existing = $db->fetch('SELECT id FROM hashtags WHERE name = :name', ['name' => $name]);
            if ($existing) {
                $tagId = (int) $existing['id'];
            } else {
                $tagId = $db->insert(
                    'INSERT INTO hashtags (name, created_at, updated_at) VALUES (:name, :c, :u)',
                    ['name' => $name, 'c' => $now, 'u' => $now]
                );
            }
            $db->execute(
                'INSERT OR IGNORE INTO feed_hashtags (feed_id, hashtag_id, created_at, updated_at) VALUES (:f, :h, :c, :u)',
                ['f' => $feedId, 'h' => $tagId, 'c' => $now, 'u' => $now]
            );
        }
    }

    public static function clearFeedCache(ConfigStore $cache, int $id, ?string $alias = null, ?string $newAlias = null): void
    {
        $cache->deletePrefix('feeds_');
        $cache->deletePrefix('search_');
        $cache->delete('feed_' . $id);
        $cache->deletePrefix($id . '_previous_feed');
        $cache->deletePrefix($id . '_next_feed');
        if ($alias !== null && $alias !== '') {
            $cache->delete('feed_' . $alias);
        }
        if ($newAlias !== null && $newAlias !== '' && $newAlias !== $alias) {
            $cache->delete('feed_' . $newAlias);
        }
    }

    public static function aiConfig(ConfigStore $server): array
    {
        $enabled = $server->get('ai_summary.enabled');
        return [
            'enabled' => $enabled === null ? false : self::bool($enabled),
            'provider' => (string) ($server->get('ai_summary.provider') ?: 'openai'),
            'model' => (string) ($server->get('ai_summary.model') ?: 'gpt-4o-mini'),
            'api_key' => (string) ($server->get('ai_summary.api_key') ?: ''),
            'api_url' => (string) ($server->get('ai_summary.api_url') ?: 'https://api.openai.com/v1'),
        ];
    }

    public static function setAiConfig(ConfigStore $server, array $updates): void
    {
        foreach (['enabled', 'provider', 'model', 'api_key', 'api_url'] as $field) {
            if (!array_key_exists($field, $updates)) {
                continue;
            }
            $value = $updates[$field];
            if ($field === 'api_key' && is_string($value) && trim($value) === '') {
                continue;
            }
            $server->set('ai_summary.' . $field, $value);
        }
    }

    public static function syncAiSummary(Context $ctx, int $feedId, bool $draft, bool $resetSummary = false): void
    {
        $config = self::aiConfig($ctx->serverConfig);
        $should = $config['enabled'] && !$draft;
        if (!$should) {
            $sql = $resetSummary
                ? 'UPDATE feeds SET ai_summary = \'\', ai_summary_status = \'idle\', ai_summary_error = \'\' WHERE id = :id'
                : 'UPDATE feeds SET ai_summary_status = \'idle\', ai_summary_error = \'\' WHERE id = :id';
            $ctx->db->execute($sql, ['id' => $feedId]);
            return;
        }
        $ctx->db->execute(
            $resetSummary
                ? 'UPDATE feeds SET ai_summary = \'\', ai_summary_status = \'processing\', ai_summary_error = \'\' WHERE id = :id'
                : 'UPDATE feeds SET ai_summary_status = \'processing\', ai_summary_error = \'\' WHERE id = :id',
            ['id' => $feedId]
        );
        $feed = $ctx->db->fetch('SELECT content, alias FROM feeds WHERE id = :id', ['id' => $feedId]);
        try {
            $summary = Ai::summarize($config, (string) ($feed['content'] ?? ''));
            $ctx->db->execute(
                'UPDATE feeds SET ai_summary = :s, ai_summary_status = \'completed\', ai_summary_error = \'\' WHERE id = :id',
                ['s' => $summary, 'id' => $feedId]
            );
        } catch (\Throwable $e) {
            $ctx->db->execute(
                'UPDATE feeds SET ai_summary_status = \'failed\', ai_summary_error = :e WHERE id = :id',
                ['e' => $e->getMessage(), 'id' => $feedId]
            );
        }
        self::clearFeedCache($ctx->cache, $feedId, $feed['alias'] ?? null, $feed['alias'] ?? null);
    }

    public static function clientConfigResponse(Context $ctx): array
    {
        $result = self::CLIENT_DEFAULTS;
        foreach ($ctx->clientConfig->all() as $key => $value) {
            $result[$key] = $value;
        }
        if (($result['site.name'] ?? '') === '') {
            $result['site.name'] = $ctx->env['site_name'] ?? 'Rin';
        }
        if (($result['site.description'] ?? '') === '') {
            $result['site.description'] = $ctx->env['site_description'] ?? $result['site.description'];
        }
        if (($result['site.avatar'] ?? '') === '') {
            $result['site.avatar'] = $ctx->env['site_avatar'] ?? '';
        }
        if (($result['site.page_size'] ?? '') === '' || $result['site.page_size'] === null) {
            $result['site.page_size'] = $ctx->env['page_size'] ?? 5;
        }
        $result['ai_summary.enabled'] = self::aiConfig($ctx->serverConfig)['enabled'];
        return $result;
    }

    public static function serverConfigResponse(Context $ctx): array
    {
        $result = self::SERVER_DEFAULTS;
        foreach ($ctx->serverConfig->all() as $key => $value) {
            $result[$key] = $value;
        }
        $ai = self::aiConfig($ctx->serverConfig);
        $result['ai_summary.enabled'] = $ai['enabled'] ? 'true' : 'false';
        $result['ai_summary.provider'] = $ai['provider'];
        $result['ai_summary.model'] = $ai['model'];
        $result['ai_summary.api_url'] = $ai['api_url'];
        $result['ai_summary.api_key'] = $ai['api_key'] !== '' ? '••••••••' : '';
        $webhook = $result['webhook_url'] ?? $result['WEBHOOK_URL'] ?? ($ctx->env['webhook_url'] ?? '');
        if ($webhook) {
            $result['webhook_url'] = $webhook;
        }
        if (is_array($result['webhook.headers'] ?? null)) {
            $result['webhook.headers'] = json_encode($result['webhook.headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($result['webhook.body_template'] ?? null)) {
            $result['webhook.body_template'] = json_encode($result['webhook.body_template'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $result;
    }

    public static function webhookConfig(Context $ctx, array $overrides = []): array
    {
        $server = $ctx->serverConfig;
        $url = $overrides['webhook_url']
            ?? $server->get('webhook_url')
            ?? $server->get('WEBHOOK_URL')
            ?? ($ctx->env['webhook_url'] ?? '');
        return [
            'webhookUrl' => is_string($url) ? trim($url) : '',
            'webhookMethod' => $overrides['webhook.method'] ?? $server->get('webhook.method'),
            'webhookContentType' => $overrides['webhook.content_type'] ?? $server->get('webhook.content_type'),
            'webhookHeaders' => $overrides['webhook.headers'] ?? $server->get('webhook.headers'),
            'webhookBodyTemplate' => $overrides['webhook.body_template'] ?? $server->get('webhook.body_template'),
        ];
    }

    public static function splitConfig(array $body): array
    {
        $regular = [];
        $ai = [];
        foreach ($body as $key => $value) {
            if (str_starts_with((string) $key, 'ai_summary.')) {
                $ai[substr((string) $key, strlen('ai_summary.'))] = $value;
            } else {
                $regular[$key] = $value;
            }
        }
        return [$regular, $ai];
    }

    public static function persist(ConfigStore $store, array $updates): void
    {
        foreach ($updates as $key => $value) {
            $store->set((string) $key, $value);
        }
    }

    public static function feedJoinSql(): string
    {
        return 'FROM feeds f LEFT JOIN users u ON u.id = f.uid';
    }

    public static function feedSelectSql(): string
    {
        return 'SELECT f.*, u.id AS user_id, u.username AS user_name, u.avatar AS user_avatar';
    }
}
