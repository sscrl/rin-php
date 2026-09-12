<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Ai;
use Rin\Support\Dates;
use Rin\Support\Helpers;
use Rin\Support\Images;
use Rin\Support\Webhook;

final class ConfigController
{
    public static function getAll(Context $ctx): Response
    {
        $ctx->requireAdmin();
        return Response::json([
            'clientConfig' => Helpers::clientConfigResponse($ctx),
            'serverConfig' => Helpers::serverConfigResponse($ctx),
        ]);
    }

    public static function getOne(Context $ctx, array $params): Response
    {
        $type = $params['type'] ?? '';
        if ($type !== 'client' && $type !== 'server') {
            throw HttpException::text('Invalid type', 400);
        }
        if ($type === 'server') {
            $ctx->requireAdmin();
            return Response::json(Helpers::serverConfigResponse($ctx));
        }
        return Response::json(Helpers::clientConfigResponse($ctx));
    }

    public static function updateAll(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $body = $ctx->request->json();
        [$regularClient] = Helpers::splitConfig($body['clientConfig'] ?? []);
        [$regularServer, $ai] = Helpers::splitConfig($body['serverConfig'] ?? []);
        Helpers::persist($ctx->clientConfig, $regularClient);
        if (array_key_exists('site.avatar', $regularClient)) {
            Helpers::syncAdminAvatar($ctx, $regularClient['site.avatar']);
        }
        Helpers::persist($ctx->serverConfig, $regularServer);
        if ($ai) {
            Helpers::setAiConfig($ctx->serverConfig, $ai);
        }
        return Response::json([
            'clientConfig' => Helpers::clientConfigResponse($ctx),
            'serverConfig' => Helpers::serverConfigResponse($ctx),
        ]);
    }

    public static function updateOne(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $type = $params['type'] ?? '';
        if ($type !== 'client' && $type !== 'server') {
            throw HttpException::text('Invalid type', 400);
        }
        $body = $ctx->request->json();
        [$regular, $ai] = Helpers::splitConfig($body);
        Helpers::persist($type === 'server' ? $ctx->serverConfig : $ctx->clientConfig, $regular);
        if ($type === 'client' && array_key_exists('site.avatar', $regular)) {
            Helpers::syncAdminAvatar($ctx, $regular['site.avatar']);
        }
        if ($ai) {
            Helpers::setAiConfig($ctx->serverConfig, $ai);
        }
        return Response::text('OK');
    }

    public static function clearCache(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $ctx->cache->clear();
        return Response::text('OK');
    }

    public static function bootstrap(Context $ctx): Response
    {
        $config = Helpers::clientConfigResponse($ctx);
        $serialized = str_replace(
            ['<', '>', '&', "\u{2028}", "\u{2029}"],
            ['\\u003C', '\\u003E', '\\u0026', '\\u2028', '\\u2029'],
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        return Response::bytes(
            'globalThis.__RIN_CLIENT_CONFIG__=' . $serialized . ';',
            'application/javascript; charset=utf-8',
            200,
            ['Cache-Control' => 'public, max-age=0, must-revalidate']
        );
    }

    public static function testAi(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $config = Helpers::aiConfig($ctx->serverConfig);
        $body = $ctx->request->json();
        $testConfig = [
            'provider' => $body['provider'] ?? $config['provider'],
            'model' => $body['model'] ?? $config['model'],
            'api_url' => array_key_exists('api_url', $body) ? $body['api_url'] : $config['api_url'],
            'api_key' => array_key_exists('api_key', $body) ? $body['api_key'] : $config['api_key'],
        ];
        $prompt = $body['testPrompt'] ?? 'Hello! This is a test message. Please respond with a simple greeting.';
        try {
            $text = Ai::complete($testConfig, [
                ['role' => 'user', 'content' => $prompt],
            ]);
            return Response::json([
                'success' => true,
                'response' => $text,
                'provider' => $testConfig['provider'],
                'model' => $testConfig['model'],
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $testConfig['provider'],
                'model' => $testConfig['model'],
            ]);
        }
    }

    public static function testWebhook(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $body = $ctx->request->json();
        $hook = Helpers::webhookConfig($ctx, $body);
        $url = $hook['webhookUrl'] ?? '';
        if (trim($url) === '') {
            return Response::json(['success' => false, 'error' => 'Webhook URL is required'], 400);
        }
        $message = trim((string) ($body['test_message'] ?? '')) ?: 'This is a test webhook message from Rin settings.';
        try {
            $result = Webhook::notify($url, [
                'event' => 'webhook.test',
                'message' => $message,
                'title' => 'Webhook Test',
                'url' => $ctx->request->baseUrl() . '/admin/settings',
                'username' => 'admin',
                'content' => $message,
                'description' => 'Manual webhook test triggered from settings.',
            ], [
                'method' => $hook['webhookMethod'] ?? 'POST',
                'contentType' => $hook['webhookContentType'] ?? 'application/json',
                'headers' => $hook['webhookHeaders'] ?? '{}',
                'bodyTemplate' => $hook['webhookBodyTemplate'] ?? '{"content":"{{message}}"}',
            ]);
            if (!$result || empty($result['ok'])) {
                return Response::json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Webhook request was not sent',
                    'details' => $result['body'] ?? '',
                ], 400);
            }
            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public static function health(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $items = [];
        $push = static function (array $item) use (&$items) {
            $items[] = $item;
        };
        $text = static fn (string $key, array $values = []) => $values ? ['key' => $key, 'values' => $values] : ['key' => $key];
        $jwtReady = ($ctx->env['jwt_secret'] ?? '') !== '';
        $push($jwtReady ? [
            'id' => 'auth-runtime',
            'title' => $text('health.items.auth_runtime.title'),
            'status' => 'success',
            'configured' => true,
            'impact' => $text('health.items.auth_runtime.success.impact'),
            'summary' => $text('health.items.auth_runtime.success.summary'),
            'suggestion' => $text('health.items.auth_runtime.success.suggestion'),
        ] : [
            'id' => 'auth-runtime',
            'title' => $text('health.items.auth_runtime.title'),
            'status' => 'danger',
            'configured' => false,
            'impact' => $text('health.items.auth_runtime.danger.impact'),
            'summary' => $text('health.items.auth_runtime.danger.summary'),
            'suggestion' => $text('health.items.auth_runtime.danger.suggestion'),
        ]);

        $loginEnabled = Helpers::bool($ctx->clientConfig->getOrDefault('login.enabled', true));
        $passwordReady = ($ctx->env['admin_username'] ?? '') !== '' && ($ctx->env['admin_password'] ?? '') !== '';
        $defaultPassword = ($ctx->env['admin_username'] ?? '') === 'admin' && ($ctx->env['admin_password'] ?? '') === 'admin123';
        if (!$loginEnabled) {
            $push([
                'id' => 'login-methods',
                'title' => $text('health.items.login_methods.title'),
                'status' => 'warning',
                'configured' => false,
                'impact' => $text('health.items.login_methods.disabled.impact'),
                'summary' => $text('health.items.login_methods.disabled.summary'),
                'suggestion' => $text('health.items.login_methods.disabled.suggestion'),
            ]);
        } elseif ($passwordReady && $defaultPassword) {
            $push([
                'id' => 'login-methods',
                'title' => $text('health.items.login_methods.title'),
                'status' => 'danger',
                'configured' => false,
                'impact' => $text('health.items.login_methods.default_password.impact'),
                'summary' => $text('health.items.login_methods.default_password.summary'),
                'suggestion' => $text('health.items.login_methods.default_password.suggestion'),
                'details' => [
                    $text('health.items.login_methods.details.github_missing'),
                    $text('health.items.login_methods.details.password_default'),
                ],
            ]);
        } elseif ($passwordReady) {
            $push([
                'id' => 'login-methods',
                'title' => $text('health.items.login_methods.title'),
                'status' => 'success',
                'configured' => true,
                'impact' => $text('health.items.login_methods.ready.impact'),
                'summary' => $text('health.items.login_methods.ready.summary'),
                'suggestion' => $text('health.items.login_methods.ready.suggestion'),
                'details' => [
                    $text('health.items.login_methods.details.github_missing'),
                    $text('health.items.login_methods.details.password_configured'),
                ],
            ]);
        } else {
            $push([
                'id' => 'login-methods',
                'title' => $text('health.items.login_methods.title'),
                'status' => 'danger',
                'configured' => false,
                'impact' => $text('health.items.login_methods.missing.impact'),
                'summary' => $text('health.items.login_methods.missing.summary'),
                'suggestion' => $text('health.items.login_methods.missing.suggestion'),
            ]);
        }

        $push([
            'id' => 'storage',
            'title' => $text('health.items.storage.title'),
            'status' => 'success',
            'configured' => true,
            'impact' => $text('health.items.storage.ready.impact'),
            'summary' => $text('health.items.storage.ready.summary'),
            'suggestion' => $text('health.items.common.no_action'),
        ]);

        $ai = Helpers::aiConfig($ctx->serverConfig);
        if (!$ai['enabled']) {
            $push([
                'id' => 'ai-summary',
                'title' => $text('health.items.ai_summary.title'),
                'status' => 'warning',
                'configured' => false,
                'impact' => $text('health.items.ai_summary.disabled.impact'),
                'summary' => $text('health.items.ai_summary.disabled.summary'),
                'suggestion' => $text('health.items.ai_summary.disabled.suggestion'),
            ]);
        } elseif ($ai['api_key'] !== '' && $ai['api_url'] !== '') {
            $push([
                'id' => 'ai-summary',
                'title' => $text('health.items.ai_summary.title'),
                'status' => 'success',
                'configured' => true,
                'impact' => $text('health.items.ai_summary.external.ready.impact'),
                'summary' => $text('health.items.ai_summary.external.ready.summary', ['provider' => $ai['provider']]),
                'suggestion' => $text('health.items.ai_summary.external.ready.suggestion'),
                'details' => [
                    $text('health.items.ai_summary.external.details.provider', ['provider' => $ai['provider']]),
                    $text('health.items.ai_summary.external.details.model', ['model' => $ai['model']]),
                ],
            ]);
        } else {
            $push([
                'id' => 'ai-summary',
                'title' => $text('health.items.ai_summary.title'),
                'status' => 'danger',
                'configured' => false,
                'impact' => $text('health.items.ai_summary.external.missing.impact'),
                'summary' => $text('health.items.ai_summary.external.missing.summary_key'),
                'suggestion' => $text('health.items.ai_summary.external.missing.suggestion'),
            ]);
        }

        $hook = Helpers::webhookConfig($ctx);
        $push($hook['webhookUrl'] ? [
            'id' => 'webhook',
            'title' => $text('health.items.webhook.title'),
            'status' => 'success',
            'configured' => true,
            'impact' => $text('health.items.webhook.ready.impact'),
            'summary' => $text('health.items.webhook.ready.summary'),
            'suggestion' => $text('health.items.common.no_action'),
        ] : [
            'id' => 'webhook',
            'title' => $text('health.items.webhook.title'),
            'status' => 'warning',
            'configured' => false,
            'impact' => $text('health.items.webhook.missing.impact'),
            'summary' => $text('health.items.webhook.missing.summary'),
            'suggestion' => $text('health.items.webhook.missing.suggestion'),
        ]);

        $rssEnabled = Helpers::bool($ctx->clientConfig->getOrDefault('rss', false));
        $push($rssEnabled ? [
            'id' => 'rss',
            'title' => $text('health.items.rss.title'),
            'status' => 'success',
            'configured' => true,
            'impact' => $text('health.items.rss.enabled.impact'),
            'summary' => $text('health.items.rss.enabled.summary_on_demand'),
            'suggestion' => $text('health.items.rss.enabled.suggestion'),
        ] : [
            'id' => 'rss',
            'title' => $text('health.items.rss.title'),
            'status' => 'warning',
            'configured' => false,
            'impact' => $text('health.items.rss.disabled.impact'),
            'summary' => $text('health.items.rss.disabled.summary'),
            'suggestion' => $text('health.items.rss.disabled.suggestion'),
        ]);

        $siteName = trim((string) ($ctx->clientConfig->get('site.name') ?: ($ctx->env['site_name'] ?? '')));
        $siteAvatar = trim((string) ($ctx->clientConfig->get('site.avatar') ?: ($ctx->env['site_avatar'] ?? '')));
        $push($siteName ? [
            'id' => 'site-identity',
            'title' => $text('health.items.site_identity.title'),
            'status' => $siteAvatar ? 'success' : 'warning',
            'configured' => true,
            'impact' => $text('health.items.site_identity.ready.impact'),
            'summary' => $siteAvatar
                ? $text('health.items.site_identity.ready.summary', ['name' => $siteName])
                : $text('health.items.site_identity.ready.summary_missing_avatar', ['name' => $siteName]),
            'suggestion' => $siteAvatar ? $text('health.items.common.no_action') : $text('health.items.site_identity.ready.suggestion_missing_avatar'),
        ] : [
            'id' => 'site-identity',
            'title' => $text('health.items.site_identity.title'),
            'status' => 'warning',
            'configured' => false,
            'impact' => $text('health.items.site_identity.missing.impact'),
            'summary' => $text('health.items.site_identity.missing.summary'),
            'suggestion' => $text('health.items.site_identity.missing.suggestion'),
        ]);

        $summary = ['success' => 0, 'warning' => 0, 'danger' => 0];
        foreach ($items as $item) {
            $summary[$item['status']]++;
        }
        return Response::json([
            'generatedAt' => Dates::iso(time()),
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    public static function queueStatus(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $summary = ['idle' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($ctx->db->fetchAll('SELECT ai_summary_status FROM feeds') as $row) {
            $status = $row['ai_summary_status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        $items = $ctx->db->fetchAll(
            'SELECT id, title, ai_summary_status, ai_summary_error, updated_at, created_at
             FROM feeds WHERE ai_summary_status != \'idle\'
             ORDER BY updated_at DESC LIMIT 50'
        );
        return Response::json([
            'queueConfigured' => true,
            'generatedAt' => Dates::iso(time()),
            'summary' => $summary,
            'items' => array_map(static fn ($item) => [
                'id' => (int) $item['id'],
                'title' => $item['title'],
                'aiSummaryStatus' => $item['ai_summary_status'],
                'aiSummaryError' => $item['ai_summary_error'],
                'updatedAt' => Dates::iso($item['updated_at']),
                'createdAt' => Dates::iso($item['created_at']),
            ], $items),
        ]);
    }

    public static function retryQueue(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $id = (int) $params['id'];
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Feed not found', 404);
        }
        if ($feed['ai_summary_status'] !== 'failed') {
            throw HttpException::text('Only failed AI summary tasks can be retried', 400);
        }
        Helpers::syncAiSummary($ctx, $id, (int) $feed['draft'] === 1, false);
        return Response::json(['success' => true]);
    }

    public static function deleteQueue(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $id = (int) $params['id'];
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Feed not found', 404);
        }
        if (!in_array($feed['ai_summary_status'], ['failed', 'completed'], true)) {
            throw HttpException::text('Only failed or completed AI summary task records can be deleted', 400);
        }
        $ctx->db->execute(
            'UPDATE feeds SET ai_summary_status = \'idle\', ai_summary_error = \'\' WHERE id = :id',
            ['id' => $id]
        );
        Helpers::clearFeedCache($ctx->cache, $id, $feed['alias'] ?? null, $feed['alias'] ?? null);
        return Response::json(['success' => true]);
    }

    public static function compatTasks(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $ai = Helpers::aiConfig($ctx->serverConfig);
        $items = $ctx->db->fetchAll('SELECT id, content, ai_summary, ai_summary_status, draft FROM feeds');
        $eligible = 0;
        $force = 0;
        $blur = 0;
        foreach ($items as $item) {
            if ((int) $item['draft'] !== 1 && $item['ai_summary_status'] !== 'pending' && $item['ai_summary_status'] !== 'processing') {
                $force++;
                if (trim((string) $item['ai_summary']) === '') {
                    $eligible++;
                }
            }
            if (Images::contentHasImagesMissingMetadata((string) $item['content'])) {
                $blur++;
            }
        }
        return Response::json([
            'generatedAt' => Dates::iso(time()),
            'aiSummary' => [
                'enabled' => $ai['enabled'],
                'queueConfigured' => true,
                'eligible' => $eligible,
                'forceEligible' => $force,
            ],
            'blurhash' => ['eligible' => $blur],
        ]);
    }

    public static function compatAiSummary(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $ai = Helpers::aiConfig($ctx->serverConfig);
        if (!$ai['enabled']) {
            throw HttpException::text('AI summary is not enabled', 400);
        }
        $force = (bool) ($ctx->request->json()['force'] ?? false);
        $items = $ctx->db->fetchAll('SELECT id, alias, ai_summary, ai_summary_status, draft FROM feeds ORDER BY updated_at DESC');
        $queued = 0;
        $skipped = 0;
        foreach ($items as $item) {
            if ((int) $item['draft'] === 1 || in_array($item['ai_summary_status'], ['pending', 'processing'], true)) {
                $skipped++;
                continue;
            }
            if (!$force && trim((string) $item['ai_summary']) !== '') {
                $skipped++;
                continue;
            }
            Helpers::syncAiSummary($ctx, (int) $item['id'], false, true);
            $queued++;
        }
        return Response::json(['queued' => $queued, 'skipped' => $skipped, 'forced' => $force]);
    }

    public static function compatBlurhash(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $items = $ctx->db->fetchAll('SELECT id, title, content FROM feeds ORDER BY updated_at DESC');
        $out = [];
        foreach ($items as $item) {
            if (Images::contentHasImagesMissingMetadata((string) $item['content'])) {
                $out[] = [
                    'id' => (int) $item['id'],
                    'title' => $item['title'],
                    'content' => $item['content'],
                ];
            }
        }
        return Response::json(['generatedAt' => Dates::iso(time()), 'items' => $out]);
    }

    public static function applyBlurhash(Context $ctx, array $params): Response
    {
        $ctx->requireAdmin();
        $id = (int) $params['id'];
        $body = $ctx->request->json();
        if (empty($body['content'])) {
            throw HttpException::text('Content is required', 400);
        }
        $feed = $ctx->db->fetch('SELECT * FROM feeds WHERE id = :id', ['id' => $id]);
        if (!$feed) {
            throw HttpException::text('Feed not found', 404);
        }
        if ($feed['content'] === $body['content']) {
            return Response::json(['updated' => false]);
        }
        $ctx->db->execute('UPDATE feeds SET content = :c, updated_at = :u WHERE id = :id', [
            'c' => $body['content'],
            'u' => Dates::now(),
            'id' => $id,
        ]);
        Helpers::clearFeedCache($ctx->cache, $id, $feed['alias'] ?? null, $feed['alias'] ?? null);
        return Response::json(['updated' => true]);
    }
}
