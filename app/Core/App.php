<?php
declare(strict_types=1);

namespace Rin\Core;

use Rin\Controllers\AuthController;
use Rin\Controllers\CommentController;
use Rin\Controllers\ConfigController;
use Rin\Controllers\FaviconController;
use Rin\Controllers\FeedController;
use Rin\Controllers\FriendController;
use Rin\Controllers\MomentController;
use Rin\Controllers\RssController;
use Rin\Controllers\SearchController;
use Rin\Controllers\StorageController;
use Rin\Controllers\TagController;
use Rin\Controllers\UserController;
use Rin\Controllers\WordpressController;
use Rin\Support\ConfigStore;
use Rin\Support\Database;
use Rin\Support\Helpers;
use Rin\Support\Jwt;
use Rin\Support\StorageService;
use Rin\Web\Frontend;

final class App
{
    private Router $router;
    private array $env;
    private Database $db;
    private StorageService $storage;

    public function __construct(private string $root)
    {
        $this->env = $this->loadEnv();
        $this->db = Database::connect(
            $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database.sqlite',
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schema.sql'
        );
        $this->storage = new StorageService(
            $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads',
            (string) ($this->env['upload_folder'] ?? 'images/')
        );
        $GLOBALS['rin_storage'] = $this->storage;
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $origin = $request->header('origin') ?: $request->origin;
        $cors = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type, authorization, x-csrf-token',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
        if ($request->method === 'OPTIONS') {
            $response = Response::empty(204, $cors);
            $response->send();
            return;
        }

        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $e->asText()
                ? Response::text($e->getMessage(), $e->status())
                : Response::json($e->payload() ?? [
                    'success' => false,
                    'error' => ['code' => 'ERROR', 'message' => $e->getMessage()],
                ], $e->status());
        } catch (\Throwable $e) {
            $response = Response::json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()],
            ], 500);
        }

        foreach ($cors as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->send();
    }

    public function cron(): void
    {
        $ctx = $this->context(Request::fromGlobals());
        \Rin\Controllers\FriendController::checkHealth($ctx);
    }

    private function dispatch(Request $request): Response
    {
        $path = $request->path;
        if (str_starts_with($path, '/api/blob/')) {
            $ctx = $this->context($request);
            return StorageController::blob($ctx, ['key' => rawurldecode(substr($path, strlen('/api/blob/')))]);
        }

        $matched = $this->router->match($request->method, $path);
        if ($matched) {
            [$handler, $params] = $matched;
            $ctx = $this->context($request);
            return $handler($ctx, $params);
        }

        if ($this->isApiPath($path)) {
            throw HttpException::json("Route {$request->method} {$path} not found", 404, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Route {$request->method} {$path} not found",
                ],
            ]);
        }

        return Frontend::handle($this->context($request));
    }

    private function context(Request $request): Context
    {
        $client = new ConfigStore($this->db, 'client.config', Helpers::CLIENT_DEFAULTS);
        $server = new ConfigStore($this->db, 'server.config', Helpers::SERVER_DEFAULTS);
        $cache = new ConfigStore($this->db, 'cache');
        $ctx = new Context($request, $this->db, $this->env, $client, $server, $cache);
        $token = $request->bearerToken();
        if ($token) {
            $payload = (new Jwt((string) $this->env['jwt_secret']))->verify($token);
            if ($payload && isset($payload['id'])) {
                $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $payload['id']]);
                if ($user) {
                    $ctx->uid = (int) $user['id'];
                    $ctx->username = $user['username'];
                    $ctx->admin = (int) $user['permission'] === 1;
                }
            }
        }
        return $ctx;
    }

    private function registerRoutes(): void
    {
        $r = $this->router;
        $r->get('/rss.xml', [RssController::class, 'rss']);
        $r->get('/atom.xml', [RssController::class, 'atom']);
        $r->get('/rss.json', [RssController::class, 'json']);
        $r->get('/feed.json', [RssController::class, 'feedJson']);
        $r->get('/feed.xml', [RssController::class, 'legacy']);

        $r->get('/avatar.png', [FaviconController::class, 'siteAvatar']);
        $r->get('/favicon', [FaviconController::class, 'show']);
        $r->get('/favicon.ico', [FaviconController::class, 'show']);
        $r->get('/api/favicon', [FaviconController::class, 'show']);
        $r->get('/api/favicon/original', [FaviconController::class, 'original']);
        $r->post('/api/favicon', [FaviconController::class, 'upload']);

        $r->get('/api/feed/timeline', [FeedController::class, 'timeline']);
        $r->get('/api/feed/adjacent/{id}', [FeedController::class, 'adjacent']);
        $r->post('/api/feed/top/{id}', [FeedController::class, 'setTop']);
        $r->get('/api/feed', [FeedController::class, 'list']);
        $r->post('/api/feed', [FeedController::class, 'create']);
        $r->get('/api/feed/{id}', [FeedController::class, 'show']);
        $r->post('/api/feed/{id}', [FeedController::class, 'update']);
        $r->delete('/api/feed/{id}', [FeedController::class, 'delete']);

        $r->get('/api/search/{keyword}', [SearchController::class, 'search']);
        $r->get('/api/tag', [TagController::class, 'list']);
        $r->get('/api/tag/{name}', [TagController::class, 'show']);

        $r->get('/api/comment/{feed}', [CommentController::class, 'list']);
        $r->post('/api/comment/{feed}', [CommentController::class, 'create']);
        $r->delete('/api/comment/{id}', [CommentController::class, 'delete']);

        $r->get('/api/friend', [FriendController::class, 'list']);
        $r->post('/api/friend', [FriendController::class, 'create']);
        $r->put('/api/friend/{id}', [FriendController::class, 'update']);
        $r->delete('/api/friend/{id}', [FriendController::class, 'delete']);

        $r->get('/api/moments', [MomentController::class, 'list']);
        $r->post('/api/moments', [MomentController::class, 'create']);
        $r->post('/api/moments/{id}', [MomentController::class, 'update']);
        $r->delete('/api/moments/{id}', [MomentController::class, 'delete']);

        $r->get('/api/user/github', [UserController::class, 'github']);
        $r->get('/api/user/github/callback', [UserController::class, 'github']);
        $r->get('/api/user/profile', [UserController::class, 'profile']);
        $r->put('/api/user/profile', [UserController::class, 'updateProfile']);
        $r->post('/api/user/logout', [UserController::class, 'logout']);

        $r->get('/api/auth/status', [AuthController::class, 'status']);
        $r->post('/api/auth/login', [AuthController::class, 'login']);

        $r->post('/api/storage', [StorageController::class, 'upload']);
        $r->post('/api/wp', [WordpressController::class, 'import']);

        $r->post('/api/config/test-ai', [ConfigController::class, 'testAi']);
        $r->post('/api/config/test-webhook', [ConfigController::class, 'testWebhook']);
        $r->get('/api/config/health', [ConfigController::class, 'health']);
        $r->get('/api/config/queue-status', [ConfigController::class, 'queueStatus']);
        $r->post('/api/config/queue-status/{id}/retry', [ConfigController::class, 'retryQueue']);
        $r->delete('/api/config/queue-status/{id}', [ConfigController::class, 'deleteQueue']);
        $r->get('/api/config/compat-tasks', [ConfigController::class, 'compatTasks']);
        $r->post('/api/config/compat-tasks/ai-summary', [ConfigController::class, 'compatAiSummary']);
        $r->get('/api/config/compat-tasks/blurhash', [ConfigController::class, 'compatBlurhash']);
        $r->post('/api/config/compat-tasks/blurhash/{id}', [ConfigController::class, 'applyBlurhash']);
        $r->get('/api/config/client/bootstrap.js', [ConfigController::class, 'bootstrap']);
        $r->delete('/api/config/cache', [ConfigController::class, 'clearCache']);
        $r->get('/api/config', [ConfigController::class, 'getAll']);
        $r->post('/api/config', [ConfigController::class, 'updateAll']);
        $r->get('/api/config/{type}', [ConfigController::class, 'getOne']);
        $r->post('/api/config/{type}', [ConfigController::class, 'updateOne']);

        $r->get('/api/ai-config', function (Context $ctx) {
            $ctx->requireAdmin();
            return Response::json(Helpers::aiConfig($ctx->serverConfig));
        });
        $r->post('/api/ai-config', function (Context $ctx) {
            $ctx->requireAdmin();
            Helpers::setAiConfig($ctx->serverConfig, $ctx->request->json());
            return Response::text('OK');
        });
    }

    private function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/')
            || preg_match('#^/(rss\.xml|atom\.xml|rss\.json|feed\.json|feed\.xml|favicon|favicon\.ico|avatar\.png)$#', $path) === 1;
    }

    private function loadEnv(): array
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'config.php';
        $example = $this->root . DIRECTORY_SEPARATOR . 'config.example.php';
        if (!is_file($file)) {
            copy($example, $file);
        }
        $env = require $file;
        return is_array($env) ? $env : [];
    }
}

