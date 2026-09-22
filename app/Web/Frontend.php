<?php
declare(strict_types=1);

namespace Rin\Web;

use Rin\Controllers\AuthController;
use Rin\Controllers\CommentController;
use Rin\Controllers\ConfigController;
use Rin\Controllers\FaviconController;
use Rin\Controllers\FeedController;
use Rin\Controllers\FriendController;
use Rin\Controllers\MomentController;
use Rin\Controllers\SearchController;
use Rin\Controllers\StorageController;
use Rin\Controllers\TagController;
use Rin\Controllers\UserController;
use Rin\Controllers\WordpressController;
use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Helpers;
use Rin\Support\Markdown;

final class Frontend
{
    private array $clientConfig;
    private bool $csrfFresh = false;
    private ?string $csrf = null;
    private bool $clearFlash = false;
    private ?array $flash = null;
    private ?array $messages = null;

    private function __construct(private Context $ctx)
    {
        $this->clientConfig = Helpers::clientConfigResponse($ctx);
        $raw = (string) ($ctx->request->cookies['rin_flash'] ?? '');
        $split = explode("\n", $raw, 2);
        if (count($split) === 2) {
            $this->flash = [$split[0] === 'err' ? 'err' : 'ok', $split[1]];
            $this->clearFlash = true;
        }
    }

    public static function handle(Context $ctx): Response
    {
        $page = new self($ctx);
        try {
            if ($ctx->request->method === 'POST') {
                $page->assertCsrf();
                return $page->post();
            }
            if (!in_array($ctx->request->method, ['GET', 'HEAD'], true)) {
                return $page->page('方法不允许', '<p class="empty">不支持的请求方法。</p>', '', false, 405);
            }
            return $page->get();
        } catch (HttpException $e) {
            return $page->page('出错了', '<p class="empty">' . $page->e($page->human($e)) . '</p>', '', false, $e->status());
        }
    }

    private function get(): Response
    {
        $parts = $this->parts();
        $count = count($parts);
        if ($count === 0) {
            return $this->feeds();
        }
        if ($count === 1) {
            return match ($parts[0]) {
                'timeline' => $this->timeline(),
                'moments' => $this->moments(),
                'friends' => $this->friends(),
                'hashtags' => $this->hashtags(),
                'search' => $this->search(),
                'login' => $this->loginPage(),
                'profile' => $this->profile(),
                'admin' => $this->redirect('/admin/writing'),
                'callback', 'user' => $this->page('登录方式已更换', '<p class="empty">这个站点只使用账号密码登录，不再使用 GitHub。</p>'),
                default => $this->article($parts[0]),
            };
        }
        if ($count === 2) {
            return match ($parts[0]) {
                'feed' => $this->article($parts[1]),
                'hashtag' => $this->hashtag($parts[1]),
                'search' => $this->search($parts[1]),
                'admin' => $this->admin($parts[1]),
                'user' => $this->page('登录方式已更换', '<p class="empty">这个站点只使用账号密码登录，不再使用 GitHub。</p>'),
                default => $this->notFound(),
            };
        }
        if ($count === 3 && $parts[0] === 'admin' && $parts[1] === 'writing') {
            return $this->writing($parts[2]);
        }
        if ($parts[0] === 'user') {
            return $this->page('登录方式已更换', '<p class="empty">这个站点只使用账号密码登录，不再使用 GitHub。</p>');
        }
        return $this->notFound();
    }

    private function post(): Response
    {
        $action = (string) ($this->ctx->request->post['_action'] ?? '');
        $parts = $this->parts();
        $head = $parts[0] ?? '';
        if ($head === 'login' && $action === 'login') {
            return $this->doLogin();
        }
        if ($head === 'logout' && $action === 'logout') {
            $result = $this->call([UserController::class, 'logout']);
            return $this->redirect('/', '已退出', 'ok', $result['response']);
        }
        if ($head === 'profile' && $action === 'profile') {
            return $this->doProfile();
        }
        if ($head === 'friends') {
            return $this->doFriends($action);
        }
        if ($head === 'moments') {
            return $this->doMoments($action);
        }
        if ($head === 'feed' && isset($parts[1])) {
            return $this->doArticle($parts[1], $action);
        }
        if ($head === 'admin') {
            return $this->doAdmin($parts, $action);
        }
        if (count($parts) === 1 && $action === 'comment') {
            return $this->doArticle($parts[0], $action);
        }
        return $this->redirect($this->nextPath('/'), '无法处理这个操作', 'err');
    }
    private function feeds(): Response
    {
        $type = (string) ($this->ctx->request->query('type') ?? 'normal');
        if (!in_array($type, ['normal', 'draft', 'unlisted'], true)) {
            $type = 'normal';
        }
        if ($type !== 'normal' && !$this->ctx->admin) {
            return $this->page('没有权限', '<p class="empty">没有权限查看这个列表。</p>', '', false, 403);
        }
        $query = $this->pagingQuery();
        $query['type'] = $type;
        $result = $this->call([FeedController::class, 'list'], query: $query);
        if (!$result['ok']) {
            return $this->page('文章', '<p class="empty">' . $this->e($result['error']) . '</p>');
        }
        $data = $result['data'];
        $title = $type === 'draft' ? '草稿箱' : ($type === 'unlisted' ? '未列出' : '文章');
        $links = '';
        if ($this->ctx->admin) {
            $links = '<div class="actions">'
                . '<a class="' . ($type === 'draft' ? 'badge' : '') . '" href="/?type=draft">草稿箱</a>'
                . '<a class="' . ($type === 'unlisted' ? 'badge' : '') . '" href="/?type=unlisted">未列出</a>'
                . '<a class="btn" href="/admin/writing">写文章</a></div>';
        }
        $html = '<h1 class="page-title">' . $title . '</h1>'
            . '<div class="sub"><span>共 ' . (int) ($data['size'] ?? 0) . ' 篇</span>' . $links . '</div>'
            . $this->feedCards($data['data'] ?? [])
            . $this->pager((int) ($data['size'] ?? 0), !empty($data['hasNext']));
        return $this->page($title, $html);
    }

    private function article(string $id): Response
    {
        $result = $this->call([FeedController::class, 'show'], ['id' => $id]);
        if (!$result['ok']) {
            return $result['status'] === 403
                ? $this->page('没有权限', '<p class="empty">没有权限查看这篇文章。</p>', '', false, 403)
                : $this->notFound();
        }
        $feed = $result['data'];
        $adjacent = $this->call([FeedController::class, 'adjacent'], ['id' => (string) $feed['id']]);
        $near = $adjacent['ok'] ? $adjacent['data'] : ['previousFeed' => null, 'nextFeed' => null];
        $html = Markdown::toHtml((string) ($feed['content'] ?? ''));
        [$html, $toc] = $this->headings($html);
        $badges = '';
        if ($this->ctx->admin) {
            if ((int) ($feed['draft'] ?? 0) === 1) {
                $badges .= '<span class="badge">草稿</span>';
            }
            if ((int) ($feed['listed'] ?? 1) === 0) {
                $badges .= '<span class="badge">未列出</span>';
            }
            if ((int) ($feed['top'] ?? 0) === 1) {
                $badges .= '<span class="badge">置顶</span>';
            }
        }
        $cover = trim((string) ($feed['cover'] ?? ''));
        $coverHtml = $cover !== '' ? '<img class="cover" alt="" src="' . $this->e($cover) . '">' : '';
        $tags = $this->tagsHtml($feed['hashtags'] ?? []);
        $stats = '';
        if ($this->on('counter.enabled', true)) {
            $stats = '<span>阅读 ' . (int) ($feed['pv'] ?? 0) . '</span> <span>访客 ' . (int) ($feed['uv'] ?? 0) . '</span>';
        }
        $admin = '';
        if ($this->ctx->admin) {
            $top = (int) ($feed['top'] ?? 0) === 1 ? 0 : 1;
            $admin = '<div class="actions">'
                . '<a class="btn ghost" href="/admin/writing/' . (int) $feed['id'] . '">编辑</a>'
                . $this->miniForm('/feed/' . (int) $feed['id'], 'top', '/feed/' . (int) $feed['id'], '<input type="hidden" name="top" value="' . $top . '"><button class="btn ghost" type="submit">' . ($top ? '置顶' : '取消置顶') . '</button>')
                . $this->miniForm('/feed/' . (int) $feed['id'], 'delete', '/', '<button class="btn danger" type="submit">删除</button>', true)
                . '</div>';
        }
        $ai = trim((string) ($feed['ai_summary'] ?? ''));
        $aiHtml = $ai !== '' ? '<div class="card"><strong>AI 摘要</strong><div class="md">' . $this->e($ai) . '</div></div>' : '';
        $nav = '<div class="adjacent">';
        $nav .= !empty($near['previousFeed']) ? '<a href="/feed/' . (int) $near['previousFeed']['id'] . '"><span class="muted">上一篇</span><br>' . $this->e($near['previousFeed']['title']) . '</a>' : '<span></span>';
        $nav .= !empty($near['nextFeed']) ? '<a href="/feed/' . (int) $near['nextFeed']['id'] . '"><span class="muted">下一篇</span><br>' . $this->e($near['nextFeed']['title']) . '</a>' : '<span></span>';
        $nav .= '</div>';
        $body = '<article class="article"><div class="article-grid"><div>'
            . $coverHtml
            . '<h1>' . $this->e($feed['title'] ?? '无标题') . '</h1>'
            . '<p class="meta">' . $badges . '<span title="' . $this->e($feed['createdAt'] ?? '') . '">' . $this->e($this->ago((string) ($feed['createdAt'] ?? ''))) . '</span> ' . $stats . '</p>'
            . $tags . $admin . $aiHtml
            . '<div class="md toc-content">' . $html . '</div>'
            . $nav
            . $this->comments((int) $feed['id'])
            . '</div><aside class="toc"><strong>目录</strong>' . $toc . '</aside></div></article>';
        return $this->page((string) ($feed['title'] ?? '文章'), $body, (string) ($feed['summary'] ?? ''));
    }

    private function timeline(): Response
    {
        $result = $this->call([FeedController::class, 'timeline']);
        $items = $result['ok'] ? $result['data'] : [];
        $groups = [];
        foreach ($items as $item) {
            $year = substr((string) ($item['createdAt'] ?? ''), 0, 4);
            $groups[$year][] = $item;
        }
        $html = '<h1 class="page-title">时间线</h1><div class="timeline">';
        if (!$groups) {
            $html .= '<p class="empty">还没有文章。</p>';
        }
        foreach ($groups as $year => $rows) {
            $html .= '<h2 class="year">' . $this->e($year) . '</h2>';
            foreach ($rows as $row) {
                $ts = strtotime((string) $row['createdAt']) ?: time();
                $html .= '<div class="tl-item"><a href="/feed/' . (int) $row['id'] . '"><span class="date">' . date('m-d', $ts) . '</span>' . $this->e($row['title']) . '</a></div>';
            }
        }
        $html .= '</div>';
        return $this->page('时间线', $html);
    }

    private function moments(): Response
    {
        $query = $this->pagingQuery();
        $result = $this->call([MomentController::class, 'list'], query: $query);
        $data = $result['ok'] ? $result['data'] : ['data' => [], 'size' => 0, 'hasNext' => false];
        $editId = (int) ($this->ctx->request->query('edit') ?? 0);
        $html = '<h1 class="page-title">动态</h1><p class="sub"><span>共 ' . (int) ($data['size'] ?? 0) . ' 条</span></p>';
        if ($this->ctx->admin) {
            $editing = '';
            foreach ($data['data'] as $moment) {
                if ((int) $moment['id'] === $editId) {
                    $editing = (string) $moment['content'];
                }
            }
            $posted = $this->ctx->request->method === 'POST' ? $this->field('content') : $editing;
            $action = $editId > 0 ? 'update' : 'create';
            $html .= $this->formOpen('/moments', '/moments', $action)
                . ($editId > 0 ? '<input type="hidden" name="id" value="' . $editId . '">' : '')
                . '<div class="form-row"><textarea class="code" name="content" rows="6" placeholder="写一条动态，支持 Markdown">' . $this->e($posted) . '</textarea></div>'
                . '<button class="btn" type="submit">' . ($editId > 0 ? '保存' : '发布') . '</button></form>';
        }
        if (!$data['data']) {
            $html .= '<p class="empty">还没有动态。</p>';
        }
        foreach ($data['data'] as $moment) {
            $html .= '<div class="card"><div class="meta">' . $this->e($moment['user']['username'] ?? '') . ' · ' . $this->e($this->ago((string) $moment['createdAt'])) . '</div>'
                . '<div class="md">' . Markdown::toHtml((string) $moment['content']) . '</div>';
            if ($this->ctx->admin) {
                $html .= '<div class="actions"><a class="btn ghost" href="/moments?edit=' . (int) $moment['id'] . '">编辑</a>'
                    . $this->miniForm('/moments', 'delete', '/moments', '<input type="hidden" name="id" value="' . (int) $moment['id'] . '"><button class="btn danger" type="submit">删除</button>', true)
                    . '</div>';
            }
            $html .= '</div>';
        }
        $html .= $this->pager((int) ($data['size'] ?? 0), !empty($data['hasNext']));
        return $this->page('动态', $html);
    }
    private function friends(): Response
    {
        $result = $this->call([FriendController::class, 'list']);
        $data = $result['ok'] ? $result['data'] : ['friend_list' => [], 'apply_list' => null];
        $html = '<h1 class="page-title">友链</h1>';
        $canApply = $this->ctx->uid !== null && ($this->ctx->admin || $this->on('friend_apply_enable', true));
        if ($canApply && ($this->ctx->admin || empty($data['apply_list']))) {
            $html .= '<div class="card"><h2>' . ($this->ctx->admin ? '添加友链' : '申请友链') . '</h2>'
                . $this->friendFields('/friends', 'create', '/friends') . '</div>';
        } elseif ($this->ctx->uid === null && $this->on('friend_apply_enable', true)) {
            $html .= '<p class="muted"><a href="/login?redirect=/friends">登录</a> 后可以申请友链。</p>';
        }
        if (!empty($data['apply_list']) && !$this->ctx->admin) {
            $mine = $data['apply_list'];
            $state = (int) $mine['accepted'] === 1 ? '已通过' : '等待审核';
            $html .= '<div class="card"><h2>我的申请</h2><p class="muted">' . $state . '</p>'
                . $this->friendFields('/friends', 'update', '/friends', $mine) . '</div>';
        }
        $html .= '<div class="friends">';
        foreach ($data['friend_list'] as $friend) {
            $html .= '<div class="card friend"><img alt="" src="' . $this->e($this->safeUrl((string) $friend['avatar'])) . '"><div><strong>'
                . $this->e($friend['name']) . '</strong><div class="muted">' . $this->e($friend['desc']) . '</div>'
                . '<a href="' . $this->e($this->safeUrl((string) $friend['url'])) . '" target="_blank" rel="noopener">' . $this->e($friend['url']) . '</a>';
            if ($this->ctx->admin) {
                $html .= '<div class="muted">' . ((int) $friend['accepted'] === 1 ? '已通过' : '待审核') . ' ' . $this->e((string) $friend['health']) . '</div>'
                    . '<details><summary>管理</summary>' . $this->friendFields('/friends', 'update', '/friends', $friend, true)
                    . $this->miniForm('/friends', 'delete', '/friends', '<input type="hidden" name="id" value="' . (int) $friend['id'] . '"><button class="btn danger" type="submit">删除</button>', true)
                    . '</details>';
            }
            $html .= '</div></div>';
        }
        if (!$data['friend_list']) {
            $html .= '<p class="empty">还没有友链。</p>';
        }
        $html .= '</div>';
        return $this->page('友链', $html);
    }

    private function hashtags(): Response
    {
        $result = $this->call([TagController::class, 'list']);
        $tags = $result['ok'] ? $result['data'] : [];
        $html = '<h1 class="page-title">标签</h1><div class="tags">';
        foreach ($tags as $tag) {
            $html .= '<a class="tag" href="/hashtag/' . rawurlencode((string) $tag['name']) . '">#' . $this->e($tag['name']) . ' ' . (int) $tag['feeds'] . '</a>';
        }
        if (!$tags) {
            $html .= '<p class="empty">还没有标签。</p>';
        }
        $html .= '</div>';
        return $this->page('标签', $html);
    }

    private function hashtag(string $name): Response
    {
        $result = $this->call([TagController::class, 'show'], ['name' => $name]);
        if (!$result['ok']) {
            return $this->notFound();
        }
        $tag = $result['data'];
        $html = '<h1 class="page-title">#' . $this->e($tag['name']) . '</h1>' . $this->feedCards($tag['feeds'] ?? []);
        return $this->page('#' . $tag['name'], $html);
    }

    private function search(?string $keyword = null): Response
    {
        $keyword = trim($keyword !== null ? $keyword : (string) ($this->ctx->request->query('q') ?? ''));
        $html = '<h1 class="page-title">搜索</h1><form class="search" action="/search" method="get"><input name="q" value="' . $this->e($keyword) . '" placeholder="搜索文章"></form>';
        if ($keyword === '') {
            return $this->page('搜索', $html . '<p class="empty">输入关键词开始搜索。</p>');
        }
        $result = $this->call([SearchController::class, 'search'], ['keyword' => $keyword], query: $this->pagingQuery());
        $data = $result['ok'] ? $result['data'] : ['data' => [], 'size' => 0, 'hasNext' => false];
        $html .= '<p class="sub">“' . $this->e($keyword) . '” 共 ' . (int) ($data['size'] ?? 0) . ' 篇</p>'
            . $this->feedCards($data['data'] ?? [])
            . $this->pager((int) ($data['size'] ?? 0), !empty($data['hasNext']));
        return $this->page('搜索 ' . $keyword, $html);
    }

    private function loginPage(string $error = ''): Response
    {
        if ($this->ctx->uid !== null && $error === '') {
            return $this->redirect($this->nextPath('/'));
        }
        $status = $this->call([AuthController::class, 'status']);
        $password = !is_array($status['data']) || !array_key_exists('password', $status['data']) || !empty($status['data']['password']);
        $html = '<div class="card" style="max-width:420px;margin:32px auto"><h1 class="page-title">登录</h1>';
        if ($error !== '') {
            $html .= '<p class="flash err">' . $this->e($error) . '</p>';
        }
        if ($password) {
            $html .= $this->formOpen('/login', $this->nextPath('/'), 'login')
                . '<div class="form-row"><input class="field" name="username" placeholder="用户名" autocomplete="username" autofocus></div>'
                . '<div class="form-row"><input class="field" type="password" name="password" placeholder="密码" autocomplete="current-password"></div>'
                . '<button class="btn" type="submit">登录</button></form>';
        } else {
            $html .= '<p class="empty">当前没有可用的登录方式。</p>';
        }
        $html .= '</div>';
        return $this->page('登录', $html);
    }

    private function profile(): Response
    {
        if ($this->ctx->uid === null) {
            return $this->redirect('/login?redirect=/profile');
        }
        $result = $this->call([UserController::class, 'profile']);
        if (!$result['ok']) {
            return $this->page('个人资料', '<p class="empty">' . $this->e($result['error']) . '</p>');
        }
        $user = $result['data'];
        $html = '<h1 class="page-title">个人资料</h1><div class="card">'
            . $this->formOpen('/profile', '/profile', 'profile', true)
            . '<div class="form-row"><span>用户名</span><input class="field" name="username" value="' . $this->e($user['username']) . '"></div>'
            . '<div class="form-row"><span>头像地址</span><input class="field" name="avatar" value="' . $this->e((string) ($user['avatar'] ?? '')) . '"></div>'
            . '<div class="form-row"><span>或上传头像</span><input class="field" type="file" name="avatar_file" accept="image/*"></div>'
            . '<button class="btn" type="submit">保存</button></form></div>';
        return $this->page('个人资料', $html);
    }
    private function admin(string $section): Response
    {
        return match ($section) {
            'writing' => $this->writing(),
            'settings' => $this->settings(),
            'health' => $this->health(),
            'queue', 'queue-status' => $this->queue(),
            'compat', 'compat-tasks' => $this->compat(),
            default => $this->notFound(),
        };
    }

    private function writing(?string $id = null, string $error = ''): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $feed = null;
        if ($id !== null && $id !== '') {
            if (!ctype_digit($id)) {
                return $this->notFound();
            }
            $feed = $this->loadFeed((int) $id);
            if (!$feed) {
                return $this->notFound();
            }
        }
        $posted = $this->ctx->request->method === 'POST';
        $title = $posted ? $this->field('title') : (string) ($feed['title'] ?? '');
        $alias = $posted ? $this->field('alias') : (string) ($feed['alias'] ?? '');
        $summary = $posted ? $this->field('summary') : (string) ($feed['summary'] ?? '');
        $content = $posted ? $this->field('content') : (string) ($feed['content'] ?? '');
        $cover = $posted ? $this->field('cover') : (string) ($feed['cover'] ?? '');
        $tags = $posted ? $this->field('tags') : implode(' ', array_map(static fn ($tag) => $tag['name'], $feed['hashtags'] ?? []));
        $created = $posted ? $this->field('created_at') : $this->localTime((string) ($feed['createdAt'] ?? gmdate('c')));
        $draft = $posted ? $this->flag('draft') === 1 : ((int) ($feed['draft'] ?? 0) === 1);
        $listed = $posted ? $this->flag('listed') === 1 : ((int) ($feed['listed'] ?? 1) === 1);
        $top = $posted ? $this->flag('top') === 1 : ((int) ($feed['top'] ?? 0) === 1);
        $preview = $posted && (string) ($this->ctx->request->post['_action'] ?? '') === 'preview'
            ? '<div class="card md">' . Markdown::toHtml($content) . '</div>' : '';
        $action = '/admin/writing' . ($feed ? '/' . (int) $feed['id'] : '');
        $html = '<h1 class="page-title">' . ($feed ? '编辑文章' : '写文章') . '</h1>'
            . ($error !== '' ? '<p class="flash err">' . $this->e($error) . '</p>' : '')
            . $preview
            . $this->formOpen($action, $feed ? '/feed/' . (int) $feed['id'] : '/', 'save', true)
            . '<div class="form-row"><span>标题</span><input class="field" name="title" value="' . $this->e($title) . '" required></div>'
            . '<div class="split"><div class="form-row"><span>别名</span><input class="field" name="alias" value="' . $this->e($alias) . '" placeholder="about"></div>'
            . '<div class="form-row"><span>发布时间</span><input class="field" type="datetime-local" name="created_at" value="' . $this->e($created) . '"></div></div>'
            . '<div class="form-row"><span>摘要</span><textarea name="summary" rows="3">' . $this->e($summary) . '</textarea></div>'
            . '<div class="form-row"><span>标签</span><input class="field" name="tags" value="' . $this->e($tags) . '" placeholder="用空格或 # 分隔"></div>'
            . '<div class="split"><div class="form-row"><span>封面地址</span><input class="field" name="cover" value="' . $this->e($cover) . '"></div>'
            . '<div class="form-row"><span>上传封面</span><input class="field" type="file" name="cover_file" accept="image/*"></div></div>'
            . '<div class="form-row"><span>正文 Markdown</span><textarea class="code" name="content" required>' . $this->e($content) . '</textarea></div>'
            . '<div class="checks"><label><input type="hidden" name="draft" value="0"><input type="checkbox" name="draft" value="1"' . ($draft ? ' checked' : '') . '> 草稿</label>'
            . '<label><input type="hidden" name="listed" value="0"><input type="checkbox" name="listed" value="1"' . ($listed ? ' checked' : '') . '> 列出</label>'
            . '<label><input type="hidden" name="top" value="0"><input type="checkbox" name="top" value="1"' . ($top ? ' checked' : '') . '> 置顶</label></div>'
            . '<div class="actions"><button class="btn" name="_action" value="save" type="submit">保存</button>'
            . '<button class="btn ghost" name="_action" value="preview" type="submit">预览</button></div></form>'
            . ($feed ? $this->miniForm($action, 'delete', '/', '<button class="btn danger" type="submit">删除</button>', true) : '');
        return $this->adminPage($feed ? '编辑文章' : '写文章', $html);
    }

    private function settings(string $error = '', string $notice = ''): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $all = $this->call([ConfigController::class, 'getAll']);
        $client = $all['ok'] ? $all['data']['clientConfig'] : $this->clientConfig;
        $server = $all['ok'] ? $all['data']['serverConfig'] : [];
        $html = '<h1 class="page-title">设置</h1>'
            . ($error !== '' ? '<p class="flash err">' . $this->e($error) . '</p>' : '')
            . ($notice !== '' ? '<p class="flash ok">' . $this->e($notice) . '</p>' : '')
            . $this->formOpen('/admin/settings', '/admin/settings', 'save', true);
        $html .= $this->input('站点名称', 'site_name', (string) ($client['site.name'] ?? ''));
        $html .= $this->input('站点描述', 'site_description', (string) ($client['site.description'] ?? ''));
        $html .= $this->input('头像地址', 'site_avatar', (string) ($client['site.avatar'] ?? ''));
        $html .= $this->input('Logo 地址', 'site_logo', (string) ($client['site.logo'] ?? ''));
        $html .= $this->input('每页文章数', 'page_size', (string) ($client['site.page_size'] ?? 5));
        $html .= $this->input('主题色', 'theme_color', (string) ($client['theme.color'] ?? '#fc466b'), 'color');
        $html .= $this->select('导航布局', 'header_layout', (string) ($client['header.layout'] ?? 'classic'), ['classic' => '经典', 'compact' => '紧凑']);
        $html .= $this->select('导航行为', 'header_behavior', (string) ($client['header.behavior'] ?? 'fixed'), ['fixed' => '固定', 'static' => '静态', 'reveal' => '下滑隐藏']);
        $html .= $this->select('文章布局', 'feed_layout', (string) ($client['feed.layout'] ?? 'list'), ['list' => '列表', 'masonry' => '瀑布流']);
        $html .= $this->select('卡片样式', 'card_variant', (string) ($client['feed.card_variant'] ?? 'default'), ['default' => '默认', 'editorial' => '杂志']);
        $html .= '<div class="checks">';
        foreach ([
            'login_enabled' => '允许登录',
            'comment_enabled' => '允许评论',
            'counter_enabled' => '阅读计数',
            'friend_apply_enable' => '允许申请友链',
            'rss' => '显示 RSS',
            'cache_enabled' => '页面缓存',
        ] as $name => $label) {
            $key = [
                'login_enabled' => 'login.enabled',
                'comment_enabled' => 'comment.enabled',
                'counter_enabled' => 'counter.enabled',
                'friend_apply_enable' => 'friend_apply_enable',
                'rss' => 'rss',
                'cache_enabled' => 'cache.enabled',
            ][$name];
            $html .= $this->check($name, $label, Helpers::bool($client[$key] ?? false));
        }
        $html .= '</div>';
        $html .= $this->area('页脚 HTML', 'footer', (string) ($client['footer'] ?? ''));
        $html .= '<div class="form-row"><span>站点图标</span><input class="field" type="file" name="favicon" accept="image/*"></div>';
        $html .= '<h2>友链检查</h2>';
        $html .= '<div class="checks">' . $this->check('friend_auto', '自动通过申请', Helpers::bool($server['friend_apply_auto_accept'] ?? false))
            . $this->check('friend_crontab', '启用健康检查', Helpers::bool($server['friend_crontab'] ?? true)) . '</div>';
        $html .= $this->input('检查 UA', 'friend_ua', (string) ($server['friend_ua'] ?? 'Rin-Check/0.1.0'));
        $html .= '<h2>Webhook</h2>';
        $html .= $this->input('地址', 'webhook_url', (string) ($server['webhook_url'] ?? ''));
        $html .= $this->input('方法', 'webhook_method', (string) ($server['webhook.method'] ?? 'POST'));
        $html .= $this->input('Content-Type', 'webhook_content_type', (string) ($server['webhook.content_type'] ?? 'application/json'));
        $html .= $this->area('请求头 JSON', 'webhook_headers', (string) ($server['webhook.headers'] ?? '{}'));
        $html .= $this->area('请求体模板', 'webhook_body', (string) ($server['webhook.body_template'] ?? '{"content":"{{message}}"}'));
        $html .= $this->input('测试消息', 'test_message', 'This is a test webhook message from Rin settings.');
        $html .= '<h2>AI 摘要</h2>';
        $html .= '<div class="checks">' . $this->check('ai_enabled', '启用', Helpers::bool($server['ai_summary.enabled'] ?? false)) . '</div>';
        $html .= $this->input('Provider', 'ai_provider', (string) ($server['ai_summary.provider'] ?? 'openai'));
        $html .= $this->input('Model', 'ai_model', (string) ($server['ai_summary.model'] ?? 'gpt-4o-mini'));
        $html .= $this->input('API 地址', 'ai_url', (string) ($server['ai_summary.api_url'] ?? ''));
        $html .= $this->input('API Key', 'ai_key', '', 'password');
        $html .= '<p class="muted">留空则保留原来的 API Key。当前' . (($server['ai_summary.api_key'] ?? '') !== '' ? '已填写' : '未填写') . '。</p>';
        $html .= '<div class="actions"><button class="btn" name="_action" value="save" type="submit">保存</button>'
            . '<button class="btn ghost" name="_action" value="test_webhook" type="submit">测试 Webhook</button>'
            . '<button class="btn ghost" name="_action" value="test_ai" type="submit">测试 AI</button>'
            . '<button class="btn ghost" name="_action" value="clear_cache" type="submit">清空缓存</button></form>';
        $html .= '<h2>WordPress 导入</h2>' . $this->formOpen('/admin/settings', '/admin/settings', 'import', true)
            . '<div class="form-row"><input class="field" type="file" name="data" accept=".xml,text/xml"></div>'
            . '<button class="btn" type="submit">导入 WXR</button></form>';
        return $this->adminPage('设置', $html);
    }
    private function health(): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $result = $this->call([ConfigController::class, 'health']);
        if (!$result['ok']) {
            return $this->adminPage('健康检查', '<p class="empty">' . $this->e($result['error']) . '</p>');
        }
        $data = $result['data'];
        $html = '<h1 class="page-title">健康检查</h1><p class="muted">正常 ' . (int) ($data['summary']['success'] ?? 0)
            . ' · 注意 ' . (int) ($data['summary']['warning'] ?? 0)
            . ' · 异常 ' . (int) ($data['summary']['danger'] ?? 0) . '</p>';
        foreach ($data['items'] as $item) {
            $html .= '<div class="health"><span class="status ' . $this->e($item['status']) . '">' . $this->e($item['status']) . '</span> '
                . '<strong>' . $this->e($this->tr($item['title'])) . '</strong>'
                . '<p>' . $this->e($this->tr($item['summary'])) . '</p>'
                . '<p class="muted">' . $this->e($this->tr($item['impact'])) . '</p>';
            if (!empty($item['suggestion'])) {
                $html .= '<p class="muted">' . $this->e($this->tr($item['suggestion'])) . '</p>';
            }
            $html .= '</div>';
        }
        return $this->adminPage('健康检查', $html);
    }

    private function queue(): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $result = $this->call([ConfigController::class, 'queueStatus']);
        $data = $result['ok'] ? $result['data'] : ['summary' => [], 'items' => []];
        $html = '<h1 class="page-title">AI 队列</h1><p class="muted">';
        foreach (['idle', 'pending', 'processing', 'completed', 'failed'] as $key) {
            $html .= $key . ' ' . (int) ($data['summary'][$key] ?? 0) . ' · ';
        }
        $html .= '</p><table><tr><th>文章</th><th>状态</th><th></th></tr>';
        foreach ($data['items'] as $item) {
            $html .= '<tr><td><a href="/feed/' . (int) $item['id'] . '">' . $this->e($item['title'] ?: ('#' . $item['id'])) . '</a><div class="muted">' . $this->e((string) $item['aiSummaryError']) . '</div></td><td>'
                . $this->e($item['aiSummaryStatus']) . '</td><td class="actions">';
            if ($item['aiSummaryStatus'] === 'failed') {
                $html .= $this->miniForm('/admin/queue', 'retry', '/admin/queue', '<input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="btn ghost" type="submit">重试</button>');
            }
            if (in_array($item['aiSummaryStatus'], ['failed', 'completed'], true)) {
                $html .= $this->miniForm('/admin/queue', 'clear', '/admin/queue', '<input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="btn ghost" type="submit">清除</button>');
            }
            $html .= '</td></tr>';
        }
        $html .= '</table>';
        return $this->adminPage('AI 队列', $html);
    }

    private function compat(): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $result = $this->call([ConfigController::class, 'compatTasks']);
        $data = $result['ok'] ? $result['data'] : ['aiSummary' => [], 'blurhash' => []];
        $blur = $this->call([ConfigController::class, 'compatBlurhash']);
        $items = $blur['ok'] ? ($blur['data']['items'] ?? []) : [];
        $html = '<h1 class="page-title">兼容任务</h1><div class="card"><h2>AI 摘要</h2><p class="muted">待生成 '
            . (int) ($data['aiSummary']['eligible'] ?? 0) . '，可强制 ' . (int) ($data['aiSummary']['forceEligible'] ?? 0) . '</p>'
            . $this->formOpen('/admin/compat', '/admin/compat', 'ai')
            . '<label><input type="checkbox" name="force" value="1"> 强制重新生成</label> '
            . '<button class="btn" type="submit">开始</button></form></div>'
            . '<div class="card"><h2>图片尺寸信息</h2><p class="muted">缺少宽高或 blurhash 的文章：' . count($items) . '。纯 PHP 页面直接显示图片，不生成 blurhash。</p><ul>';
        foreach ($items as $item) {
            $html .= '<li><a href="/admin/writing/' . (int) $item['id'] . '">' . $this->e($item['title'] ?: ('#' . $item['id'])) . '</a></li>';
        }
        $html .= '</ul></div>';
        return $this->adminPage('兼容任务', $html);
    }

    private function doLogin(): Response
    {
        $result = $this->call([AuthController::class, 'login'], json: [
            'username' => $this->field('username'),
            'password' => $this->field('password'),
        ]);
        if (!$result['ok']) {
            return $this->loginPage($result['error'] !== '' ? $result['error'] : '登录失败');
        }
        return $this->redirect($this->nextPath('/'), '已登录', 'ok', $result['response']);
    }

    private function doProfile(): Response
    {
        if ($this->ctx->uid === null) {
            return $this->redirect('/login?redirect=/profile');
        }
        $avatar = $this->field('avatar');
        $uploaded = $this->uploadedUrl('avatar_file');
        if ($uploaded !== null) {
            $avatar = $uploaded;
        }
        $payload = ['username' => $this->field('username')];
        if ($avatar !== '') {
            $payload['avatar'] = $avatar;
        }
        $result = $this->call([UserController::class, 'updateProfile'], json: $payload, method: 'PUT');
        if (!$result['ok']) {
            return $this->redirect('/profile', $result['error'], 'err');
        }
        return $this->redirect('/profile', '已保存');
    }

    private function doFriends(string $action): Response
    {
        if ($action === 'create') {
            $result = $this->call([FriendController::class, 'create'], json: $this->friendPayload());
        } elseif ($action === 'update') {
            $result = $this->call([FriendController::class, 'update'], ['id' => (string) (int) $this->field('id')], $this->friendPayload(), null, 'PUT');
        } elseif ($action === 'delete') {
            $result = $this->call([FriendController::class, 'delete'], ['id' => (string) (int) $this->field('id')], method: 'DELETE');
        } else {
            return $this->redirect('/friends', '无法处理这个操作', 'err');
        }
        return $this->redirect('/friends', $result['ok'] ? '已保存' : $result['error'], $result['ok'] ? 'ok' : 'err');
    }

    private function doMoments(string $action): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        if ($action === 'create') {
            $result = $this->call([MomentController::class, 'create'], json: ['content' => $this->field('content')]);
        } elseif ($action === 'update') {
            $result = $this->call([MomentController::class, 'update'], ['id' => (string) (int) $this->field('id')], ['content' => $this->field('content')]);
        } elseif ($action === 'delete') {
            $result = $this->call([MomentController::class, 'delete'], ['id' => (string) (int) $this->field('id')], method: 'DELETE');
        } else {
            return $this->redirect('/moments', '无法处理这个操作', 'err');
        }
        if (!$result['ok'] && $action !== 'delete') {
            return $this->moments();
        }
        return $this->redirect('/moments', $result['ok'] ? '已保存' : $result['error'], $result['ok'] ? 'ok' : 'err');
    }

    private function doArticle(string $id, string $action): Response
    {
        $back = '/feed/' . rawurlencode($id);
        if ($action === 'comment') {
            if (!$this->on('comment.enabled', true)) {
                return $this->redirect($back, '评论已关闭', 'err');
            }
            $result = $this->call([CommentController::class, 'create'], ['feed' => (string) (int) $this->field('feed_id', $id)], ['content' => $this->field('content')]);
            return $this->redirect('/feed/' . (int) $this->field('feed_id', $id), $result['ok'] ? '已评论' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        if ($action === 'comment_delete') {
            $result = $this->call([CommentController::class, 'delete'], ['id' => (string) (int) $this->field('id')], method: 'DELETE');
            return $this->redirect($back, $result['ok'] ? '已删除' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        if ($action === 'top') {
            $result = $this->call([FeedController::class, 'setTop'], ['id' => (string) (int) $id], ['top' => (int) $this->field('top')]);
            return $this->redirect($back, $result['ok'] ? '已更新' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        if ($action === 'delete') {
            $result = $this->call([FeedController::class, 'delete'], ['id' => (string) (int) $id], method: 'DELETE');
            return $this->redirect('/', $result['ok'] ? '已删除' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        return $this->redirect($back, '无法处理这个操作', 'err');
    }
    private function doAdmin(array $parts, string $action): Response
    {
        $section = $parts[1] ?? '';
        if ($section === 'writing') {
            $id = $parts[2] ?? null;
            if ($action === 'preview') {
                return $this->writing($id);
            }
            if ($action === 'delete' && $id !== null) {
                $result = $this->call([FeedController::class, 'delete'], ['id' => (string) (int) $id], method: 'DELETE');
                return $this->redirect('/', $result['ok'] ? '已删除' : $result['error'], $result['ok'] ? 'ok' : 'err');
            }
            if ($action === 'save') {
                return $this->saveFeed($id);
            }
        }
        if ($section === 'settings') {
            return $this->saveSettings($action);
        }
        if (($section === 'queue' || $section === 'queue-status') && in_array($action, ['retry', 'clear'], true)) {
            $id = (string) (int) $this->field('id');
            $handler = $action === 'retry' ? [ConfigController::class, 'retryQueue'] : [ConfigController::class, 'deleteQueue'];
            $method = $action === 'retry' ? 'POST' : 'DELETE';
            $result = $this->call($handler, ['id' => $id], method: $method);
            return $this->redirect('/admin/queue', $result['ok'] ? '已更新' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        if (($section === 'compat' || $section === 'compat-tasks') && $action === 'ai') {
            $result = $this->call([ConfigController::class, 'compatAiSummary'], json: ['force' => $this->flag('force') === 1]);
            $text = $result['ok']
                ? '已处理 ' . (int) ($result['data']['queued'] ?? 0) . ' 篇，跳过 ' . (int) ($result['data']['skipped'] ?? 0) . ' 篇'
                : $result['error'];
            return $this->redirect('/admin/compat', $text, $result['ok'] ? 'ok' : 'err');
        }
        return $this->redirect('/admin/settings', '无法处理这个操作', 'err');
    }

    private function saveFeed(?string $id): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        $cover = $this->field('cover');
        $uploaded = $this->uploadedUrl('cover_file');
        if ($uploaded !== null) {
            $cover = $uploaded;
        }
        $alias = trim($this->field('alias'));
        $payload = [
            'title' => trim($this->field('title')),
            'alias' => $alias !== '' ? $alias : null,
            'summary' => $this->field('summary'),
            'content' => $this->field('content'),
            'cover' => $cover,
            'tags' => $this->tagList($this->field('tags')),
            'draft' => $this->flag('draft'),
            'listed' => $this->flag('listed'),
            'top' => $this->flag('top'),
            'createdAt' => $this->field('created_at') !== '' ? date('c', strtotime($this->field('created_at')) ?: time()) : null,
        ];
        if ($id === null || $id === '') {
            $result = $this->call([FeedController::class, 'create'], json: $payload);
            if (!$result['ok']) {
                return $this->writing(null, $result['error']);
            }
            $newId = (int) ($result['data']['insertedId'] ?? 0);
            if ($payload['top'] === 1 && $newId > 0) {
                $this->call([FeedController::class, 'setTop'], ['id' => (string) $newId], ['top' => 1]);
            }
            return $this->redirect('/feed/' . $newId, '已发布');
        }
        $result = $this->call([FeedController::class, 'update'], ['id' => (string) (int) $id], $payload);
        if (!$result['ok']) {
            return $this->writing($id, $result['error']);
        }
        return $this->redirect('/feed/' . (int) $id, '已保存');
    }

    private function saveSettings(string $action): Response
    {
        $denied = $this->guardAdmin();
        if ($denied) {
            return $denied;
        }
        if ($action === 'clear_cache') {
            $result = $this->call([ConfigController::class, 'clearCache'], method: 'DELETE');
            return $this->redirect('/admin/settings', $result['ok'] ? '缓存已清空' : $result['error'], $result['ok'] ? 'ok' : 'err');
        }
        if ($action === 'import') {
            if (empty($_FILES['data']['tmp_name'])) {
                return $this->settings('请选择 WordPress 导出文件');
            }
            $result = $this->call([WordpressController::class, 'import']);
            if (!$result['ok']) {
                return $this->settings($result['error']);
            }
            $data = $result['data'];
            return $this->redirect('/admin/settings', '导入 ' . (int) ($data['success'] ?? 0) . ' 篇，跳过 ' . (int) ($data['skipped'] ?? 0) . ' 篇');
        }
        if (!empty($_FILES['favicon']['tmp_name'])) {
            $_FILES['file'] = $_FILES['favicon'];
            $icon = $this->call([FaviconController::class, 'upload']);
            if (!$icon['ok']) {
                return $this->settings($icon['error']);
            }
        }
        $color = $this->field('theme_color', '#fc466b');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#fc466b';
        }
        $pageSize = max(1, min(50, (int) $this->field('page_size', '5')));
        $client = [
            'site.name' => $this->field('site_name'),
            'site.description' => $this->field('site_description'),
            'site.avatar' => $this->field('site_avatar'),
            'site.logo' => $this->field('site_logo'),
            'site.page_size' => $pageSize,
            'theme.color' => $color,
            'header.layout' => $this->choice('header_layout', ['classic', 'compact'], 'classic'),
            'header.behavior' => $this->choice('header_behavior', ['fixed', 'static', 'reveal'], 'fixed'),
            'feed.layout' => $this->choice('feed_layout', ['list', 'masonry'], 'list'),
            'feed.card_variant' => $this->choice('card_variant', ['default', 'editorial'], 'default'),
            'login.enabled' => $this->flag('login_enabled') === 1,
            'comment.enabled' => $this->flag('comment_enabled') === 1,
            'counter.enabled' => $this->flag('counter_enabled') === 1,
            'friend_apply_enable' => $this->flag('friend_apply_enable') === 1,
            'rss' => $this->flag('rss') === 1,
            'cache.enabled' => $this->flag('cache_enabled') === 1,
            'footer' => $this->field('footer'),
        ];
        $server = [
            'friend_apply_auto_accept' => $this->flag('friend_auto') === 1,
            'friend_crontab' => $this->flag('friend_crontab') === 1,
            'friend_ua' => $this->field('friend_ua'),
            'webhook_url' => $this->field('webhook_url'),
            'webhook.method' => $this->field('webhook_method', 'POST'),
            'webhook.content_type' => $this->field('webhook_content_type', 'application/json'),
            'webhook.headers' => $this->field('webhook_headers', '{}'),
            'webhook.body_template' => $this->field('webhook_body'),
            'ai_summary.enabled' => $this->flag('ai_enabled') === 1,
            'ai_summary.provider' => $this->field('ai_provider', 'openai'),
            'ai_summary.model' => $this->field('ai_model', 'gpt-4o-mini'),
            'ai_summary.api_url' => $this->field('ai_url'),
        ];
        $key = $this->field('ai_key');
        if ($key !== '' && !str_contains($key, '•')) {
            $server['ai_summary.api_key'] = $key;
        }
        if ($action === 'test_webhook') {
            $result = $this->call([ConfigController::class, 'testWebhook'], json: [
                'webhook_url' => $server['webhook_url'],
                'webhook.method' => $server['webhook.method'],
                'webhook.content_type' => $server['webhook.content_type'],
                'webhook.headers' => $server['webhook.headers'],
                'webhook.body_template' => $server['webhook.body_template'],
                'test_message' => $this->field('test_message'),
            ]);
            return $this->settings($result['ok'] ? '' : $result['error'], $result['ok'] ? 'Webhook 测试已发送' : '');
        }
        if ($action === 'test_ai') {
            $payload = [
                'provider' => $server['ai_summary.provider'],
                'model' => $server['ai_summary.model'],
                'api_url' => $server['ai_summary.api_url'],
            ];
            if (isset($server['ai_summary.api_key'])) {
                $payload['api_key'] = $server['ai_summary.api_key'];
            }
            $result = $this->call([ConfigController::class, 'testAi'], json: $payload);
            $text = $result['ok'] ? (string) ($result['data']['response'] ?? 'AI 可用') : $result['error'];
            return $this->settings($result['ok'] ? '' : $text, $result['ok'] ? $text : '');
        }
        $result = $this->call([ConfigController::class, 'updateAll'], json: [
            'clientConfig' => $client,
            'serverConfig' => $server,
        ]);
        if (!$result['ok']) {
            return $this->settings($result['error']);
        }
        return $this->redirect('/admin/settings', '设置已保存');
    }
    private function comments(int $feedId): string
    {
        if (!$this->on('comment.enabled', true)) {
            return '';
        }
        $result = $this->call([CommentController::class, 'list'], ['feed' => (string) $feedId]);
        $rows = $result['ok'] ? $result['data'] : [];
        $html = '<h2>评论</h2>';
        if ($this->ctx->uid === null) {
            $html .= '<p class="muted"><a href="/login?redirect=' . rawurlencode('/feed/' . $feedId) . '">登录</a> 后可以评论。</p>';
        } else {
            $html .= $this->formOpen('/feed/' . $feedId, '/feed/' . $feedId, 'comment')
                . '<input type="hidden" name="feed_id" value="' . $feedId . '">'
                . '<div class="form-row"><textarea name="content" rows="4" placeholder="写下评论" required></textarea></div>'
                . '<button class="btn" type="submit">发送</button></form>';
        }
        if (!$rows) {
            $html .= '<p class="empty">还没有评论。</p>';
        }
        foreach ($rows as $row) {
            $html .= '<div class="comment"><img alt="" src="' . $this->e((string) ($row['user']['avatar'] ?? '')) . '"><div><strong>'
                . $this->e($row['user']['username'] ?? '') . '</strong> <span class="muted">' . $this->e($this->ago((string) $row['createdAt'])) . '</span>'
                . '<div class="md">' . Markdown::toHtml((string) $row['content']) . '</div>';
            if ($this->ctx->admin || (int) ($row['user']['id'] ?? 0) === (int) $this->ctx->uid) {
                $html .= $this->miniForm('/feed/' . $feedId, 'comment_delete', '/feed/' . $feedId, '<input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn ghost" type="submit">删除</button>', true);
            }
            $html .= '</div></div>';
        }
        return $html;
    }

    private function feedCards(array $rows): string
    {
        if (!$rows) {
            return '<p class="empty">这里还没有内容。</p>';
        }
        $variant = (($this->clientConfig['feed.card_variant'] ?? '') === 'editorial') ? ' editorial' : '';
        $layout = (($this->clientConfig['feed.layout'] ?? '') === 'masonry') ? ' masonry' : '';
        $html = '<div class="feed-list' . $layout . '">';
        foreach ($rows as $row) {
            $cover = trim((string) ($row['avatar'] ?? $row['cover'] ?? ''));
            $html .= '<a class="card' . $variant . '" href="/feed/' . (int) $row['id'] . '">';
            if ($cover !== '') {
                $html .= '<img class="cover" alt="" src="' . $this->e($cover) . '">';
            }
            $html .= '<h2>' . $this->e($row['title'] ?: '无标题') . '</h2><p class="meta">';
            $html .= '<span title="' . $this->e((string) ($row['createdAt'] ?? '')) . '">' . $this->e($this->ago((string) ($row['createdAt'] ?? ''))) . '</span>';
            if ($this->ctx->admin && (int) ($row['draft'] ?? 0) === 1) {
                $html .= ' <span class="badge">草稿</span>';
            }
            if ($this->ctx->admin && array_key_exists('listed', $row) && (int) $row['listed'] === 0) {
                $html .= ' <span class="badge">未列出</span>';
            }
            if ((int) ($row['top'] ?? 0) === 1) {
                $html .= ' <span class="badge">置顶</span>';
            }
            $html .= '</p><p class="summary">' . $this->e((string) ($row['summary'] ?? '')) . '</p>' . $this->tagsHtml($row['hashtags'] ?? []) . '</a>';
        }
        return $html . '</div>';
    }

    private function tagsHtml(array $tags): string
    {
        if (!$tags) {
            return '';
        }
        $html = '<div class="tags">';
        foreach ($tags as $tag) {
            $name = (string) ($tag['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $html .= '<span class="tag">#' . $this->e($name) . '</span>';
        }
        return $html . '</div>';
    }

    private function pager(int $size, bool $hasNext): string
    {
        $page = max(1, (int) ($this->ctx->request->query('page') ?? 1));
        if ($page === 1 && !$hasNext) {
            return '';
        }
        $html = '<div class="pager">';
        if ($page > 1) {
            $html .= '<a href="' . $this->e($this->pageUrl($page - 1)) . '">上一页</a>';
        }
        $html .= '<span class="muted">第 ' . $page . ' 页 / 共 ' . $size . ' 条</span>';
        if ($hasNext) {
            $html .= '<a href="' . $this->e($this->pageUrl($page + 1)) . '">下一页</a>';
        }
        return $html . '</div>';
    }

    private function pageUrl(int $page): string
    {
        $query = $this->ctx->request->query;
        $query['page'] = (string) $page;
        $path = $this->ctx->request->path;
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    private function adminPage(string $title, string $body): Response
    {
        $items = [
            '/admin/writing' => '写作',
            '/admin/settings' => '设置',
            '/admin/health' => '健康检查',
            '/admin/queue' => 'AI 队列',
            '/admin/compat' => '兼容任务',
        ];
        $path = $this->ctx->request->path;
        $nav = '<aside class="side"><nav>';
        foreach ($items as $href => $label) {
            $active = str_starts_with($path, $href) ? ' class="active"' : '';
            $nav .= '<a' . $active . ' href="' . $href . '">' . $label . '</a>';
        }
        $nav .= '</nav></aside>';
        return $this->page($title, '<div class="admin">' . $nav . '<div>' . $body . '</div></div>', '', true);
    }

    private function page(string $title, string $body, string $description = '', bool $wide = false, int $status = 200): Response
    {
        $name = $this->siteName();
        $description = $description !== '' ? $description : (string) ($this->clientConfig['site.description'] ?? '');
        $theme = (string) ($this->clientConfig['theme.color'] ?? '#fc466b');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme)) {
            $theme = '#fc466b';
        }
        $rgb = $this->rgb($theme);
        $avatar = trim((string) ($this->clientConfig['site.avatar'] ?? ''));
        if ($avatar === '') {
            $avatar = '/favicon';
        }
        $behavior = $this->choiceValue((string) ($this->clientConfig['header.behavior'] ?? 'fixed'), ['fixed', 'static', 'reveal'], 'fixed');
        $layout = $this->choiceValue((string) ($this->clientConfig['header.layout'] ?? 'classic'), ['classic', 'compact'], 'classic');
        $path = $this->ctx->request->path;
        $nav = [
            '/' => '文章',
            '/timeline' => '时间线',
            '/moments' => '动态',
            '/hashtags' => '标签',
            '/friends' => '友链',
            '/about' => '关于',
        ];
        $links = '';
        foreach ($nav as $href => $label) {
            $active = ($href === '/' ? ($path === '/' || str_starts_with($path, '/feed/')) : $path === $href) ? ' class="active"' : '';
            $links .= '<a' . $active . ' href="' . $href . '">' . $label . '</a>';
        }
        $tools = '<form class="search" action="/search" method="get"><input name="q" value="' . $this->e((string) ($this->ctx->request->query('q') ?? '')) . '" placeholder="搜索" aria-label="搜索"></form>';
        if ($this->ctx->admin) {
            $tools .= '<a href="/admin/writing">后台</a>';
        }
        if ($this->ctx->uid !== null) {
            $tools .= '<a href="/profile">资料</a>' . $this->miniForm('/logout', 'logout', '/', '退出', false, true);
        } elseif ($this->on('login.enabled', true)) {
            $tools .= '<a href="/login?redirect=' . rawurlencode($path) . '">登录</a>';
        }
        $flash = '';
        if ($this->flash) {
            $flash = '<div class="flash ' . $this->flash[0] . '">' . $this->e($this->flash[1]) . '</div>';
        }
        $footerHtml = (string) ($this->clientConfig['footer'] ?? '');
        $rss = $this->on('rss') ? ' · <a href="/rss.xml">RSS</a>' : '';
        $hiddenLogin = $this->on('login.enabled', true) ? '' : '<script>(function(){var n=0;var el=document.getElementById("copy");if(!el)return;el.addEventListener("dblclick",function(){n+=1;if(n>=3)location.href="/login?redirect="+encodeURIComponent(location.pathname);});})();</script>';
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($title) . ' - ' . $this->e($name) . '</title>'
            . '<meta name="description" content="' . $this->e($description) . '">'
            . '<link rel="icon" href="/favicon"><link rel="stylesheet" href="/site.css">'
            . '<link rel="alternate" type="application/rss+xml" title="' . $this->e($name) . '" href="/rss.xml">'
            . '<style>:root{--theme:' . $rgb . '}</style>'
            . '<script>(function(){var m=localStorage.getItem("theme")||"system";var d=m==="dark"||(m!=="light"&&matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.setAttribute("data-color-mode",d?"dark":"light");document.documentElement.setAttribute("data-theme-mode",m);})();</script>'
            . '</head><body class="' . ($layout === 'compact' ? 'layout-compact' : 'layout-classic') . '" data-header="' . $behavior . '">'
            . '<header class="site-header"><div class="header-inner"><a class="brand" href="/"><img alt="" src="' . $this->e($avatar) . '"><span>' . $this->e($name) . '</span></a>'
            . '<div class="pill"><nav>' . $links . '</nav></div><div class="tools">' . $tools . '</div></div></header><div class="header-spacer"></div>'
            . '<main class="' . ($wide ? 'wrap-wide' : 'wrap') . '">' . $flash . $body . '</main>'
            . '<footer class="site-footer"><div class="theme-switch"><button type="button" data-theme="light">浅色</button><button type="button" data-theme="dark">深色</button><button type="button" data-theme="system">跟随系统</button></div>'
            . ($footerHtml !== '' ? '<div class="footer-html">' . $footerHtml . '</div>' : '')
            . '<p id="copy">© ' . date('Y') . ' Powered by <a href="https://github.com/openRin/Rin" target="_blank" rel="noopener">Rin</a>' . $rss . '</p></footer>'
            . '<script>(function(){var h=document.querySelector(".site-header");var b=document.body.getAttribute("data-header");if(b==="reveal"&&h){var y=0;addEventListener("scroll",function(){var n=scrollY;h.classList.toggle("is-hidden",n>24&&n>y);y=n;},{passive:true});}var mode=document.documentElement.getAttribute("data-theme-mode")||"system";document.querySelectorAll("[data-theme]").forEach(function(btn){if(btn.getAttribute("data-theme")===mode)btn.classList.add("active");btn.addEventListener("click",function(){var m=btn.getAttribute("data-theme");localStorage.setItem("theme",m);var d=m==="dark"||(m!=="light"&&matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.setAttribute("data-color-mode",d?"dark":"light");document.querySelectorAll("[data-theme]").forEach(function(el){el.classList.toggle("active",el===btn);});});});})();</script>'
            . $hiddenLogin . '</body></html>';
        $response = Response::html($html, $status)->withHeader('Cache-Control', 'private, no-cache');
        if ($this->clearFlash) {
            $response = $response->addCookie('rin_flash', '', $this->cookie(time() - 3600));
        }
        return $this->ensureCsrf($response);
    }
    private function call(callable $handler, array $params = [], ?array $json = null, ?array $query = null, ?string $method = null): array
    {
        $request = $this->ctx->request;
        if ($query !== null) {
            $request = $request->withQuery($query);
        }
        if ($json !== null || $method !== null) {
            $request = $request->withJsonBody($json ?? [], $method);
        }
        $current = $this->ctx;
        $next = new Context($request, $current->db, $current->env, $current->clientConfig, $current->serverConfig, $current->cache, $current->uid, $current->username, $current->admin);
        try {
            $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
            $response = $ref->getNumberOfParameters() >= 2 ? $handler($next, $params) : $handler($next);
        } catch (HttpException $e) {
            return ['ok' => false, 'status' => $e->status(), 'data' => $e->payload(), 'error' => $this->human($e), 'response' => null];
        }
        $data = $response->body();
        $error = '';
        if ($response->status() >= 400) {
            $error = $this->translate($this->stringify($data, '请求失败'));
        } elseif (is_array($data) && array_key_exists('success', $data) && $data['success'] === false) {
            $error = $this->translate($this->stringify($data['error'] ?? $data, '操作失败'));
        }
        return [
            'ok' => $error === '',
            'status' => $response->status(),
            'data' => $data,
            'error' => $error,
            'response' => $response,
        ];
    }

    private function guardAdmin(): ?Response
    {
        if ($this->ctx->admin) {
            return null;
        }
        if ($this->ctx->uid === null) {
            return $this->redirect('/login?redirect=' . rawurlencode($this->ctx->request->path));
        }
        return $this->page('没有权限', '<p class="empty">没有权限访问这个页面。</p>', '', false, 403);
    }

    private function notFound(): Response
    {
        return $this->page('没有找到', '<p class="empty">这个页面不存在。</p>', '', false, 404);
    }

    private function formOpen(string $action, string $next, string $act, bool $upload = false): string
    {
        return '<form method="post" action="' . $this->e($action) . '"' . ($upload ? ' enctype="multipart/form-data"' : '') . '>'
            . '<input type="hidden" name="_csrf" value="' . $this->e($this->csrf()) . '">'
            . '<input type="hidden" name="_action" value="' . $this->e($act) . '">'
            . '<input type="hidden" name="_next" value="' . $this->e($this->safeNext($next)) . '">';
    }

    private function miniForm(string $action, string $act, string $next, string $inner, bool $confirm = false, bool $link = false): string
    {
        if (!str_contains($inner, '<')) {
            $class = $link ? 'as-link' : 'btn';
            $inner = '<button class="' . $class . '" type="submit">' . $this->e($inner) . '</button>';
        }
        $form = $this->formOpen($action, $next, $act);
        if ($confirm) {
            $form = preg_replace('/<form\b/', '<form onsubmit="return confirm(\'确定继续？\')"', $form, 1) ?? $form;
        }
        return $form . $inner . '</form>';
    }

    private function friendFields(string $action, string $act, string $next, ?array $friend = null, bool $manage = false): string
    {
        $html = $this->formOpen($action, $next, $act);
        if ($friend) {
            $html .= '<input type="hidden" name="id" value="' . (int) $friend['id'] . '">';
        }
        $html .= $this->input('名称', 'name', (string) ($friend['name'] ?? ''));
        $html .= $this->input('描述', 'desc', (string) ($friend['desc'] ?? ''));
        $html .= $this->input('头像地址', 'avatar', (string) ($friend['avatar'] ?? ''));
        $html .= $this->input('链接', 'url', (string) ($friend['url'] ?? ''));
        if ($manage) {
            $html .= '<div class="checks">' . $this->check('accepted', '通过', (int) ($friend['accepted'] ?? 1) === 1) . '</div>';
            $html .= $this->input('排序', 'sort_order', (string) ($friend['sort_order'] ?? 0));
        }
        return $html . '<button class="btn" type="submit">保存</button></form>';
    }

    private function friendPayload(): array
    {
        $payload = [
            'name' => $this->field('name'),
            'desc' => $this->field('desc'),
            'avatar' => $this->field('avatar'),
            'url' => $this->field('url'),
        ];
        if ($this->ctx->admin && array_key_exists('accepted', $this->ctx->request->post)) {
            $payload['accepted'] = $this->flag('accepted');
            $payload['sort_order'] = (int) $this->field('sort_order', '0');
        }
        return $payload;
    }

    private function input(string $label, string $name, string $value, string $type = 'text'): string
    {
        return '<div class="form-row"><span>' . $this->e($label) . '</span><input class="field" type="' . $this->e($type) . '" name="' . $this->e($name) . '" value="' . $this->e($value) . '"></div>';
    }

    private function area(string $label, string $name, string $value): string
    {
        return '<div class="form-row"><span>' . $this->e($label) . '</span><textarea name="' . $this->e($name) . '" rows="4">' . $this->e($value) . '</textarea></div>';
    }

    private function select(string $label, string $name, string $value, array $options): string
    {
        $html = '<div class="form-row"><span>' . $this->e($label) . '</span><select name="' . $this->e($name) . '">';
        foreach ($options as $key => $text) {
            $html .= '<option value="' . $this->e((string) $key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . $this->e($text) . '</option>';
        }
        return $html . '</select></div>';
    }

    private function check(string $name, string $label, bool $on): string
    {
        return '<label><input type="hidden" name="' . $this->e($name) . '" value="0"><input type="checkbox" name="' . $this->e($name) . '" value="1"' . ($on ? ' checked' : '') . '> ' . $this->e($label) . '</label>';
    }

    private function uploadedUrl(string $field): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $_FILES['file'] = $file;
        $result = $this->call([StorageController::class, 'upload']);
        if (!$result['ok']) {
            throw HttpException::text($result['error'] !== '' ? $result['error'] : '上传失败', 400);
        }
        return (string) ($result['data']['url'] ?? '');
    }

    private function loadFeed(int $id): ?array
    {
        $row = $this->ctx->db->fetch(Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . ' WHERE f.id = :id', ['id' => $id]);
        if (!$row) {
            return null;
        }
        return Helpers::mapFeedDetail($this->ctx->db, $row, 0, 0, Helpers::siteAvatar($this->ctx));
    }

    private function headings(string $html): array
    {
        $toc = '';
        $i = 0;
        $html = preg_replace_callback('/<h([1-3])>(.*?)<\/h\1>/s', function (array $match) use (&$toc, &$i): string {
            $i++;
            $id = 'h' . $i;
            $toc .= '<a class="l' . $match[1] . '" href="#' . $id . '">' . strip_tags($match[2]) . '</a>';
            return '<h' . $match[1] . ' id="' . $id . '">' . $match[2] . '</h' . $match[1] . '>';
        }, $html) ?? $html;
        return [$html, $toc !== '' ? $toc : '<p class="muted">这篇没有目录</p>'];
    }

    private function parts(): array
    {
        $path = $this->ctx->request->path;
        return $path === '/' || $path === '' ? [] : explode('/', trim($path, '/'));
    }

    private function pagingQuery(): array
    {
        $query = $this->ctx->request->query;
        $limit = (int) ($query['limit'] ?? 0);
        if ($limit < 1) {
            $limit = (int) ($this->clientConfig['site.page_size'] ?? 5);
        }
        $query['page'] = (string) max(1, (int) ($query['page'] ?? 1));
        $query['limit'] = (string) max(1, min(50, $limit));
        return $query;
    }

    private function field(string $name, string $default = ''): string
    {
        $value = $this->ctx->request->post[$name] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function flag(string $name): int
    {
        return (string) ($this->ctx->request->post[$name] ?? '0') === '1' ? 1 : 0;
    }

    private function choice(string $field, array $allowed, string $default): string
    {
        return $this->choiceValue($this->field($field, $default), $allowed, $default);
    }

    private function choiceValue(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function tagList(string $raw): array
    {
        $parts = preg_split('/[\s,#，]+/u', trim($raw)) ?: [];
        $tags = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $tags[$part] = $part;
            }
        }
        return array_values($tags);
    }

    private function nextPath(string $fallback): string
    {
        $next = (string) ($this->ctx->request->post['_next'] ?? $this->ctx->request->query('redirect') ?? $fallback);
        return $this->safeNext($next, $fallback);
    }

    private function safeNext(string $next, string $fallback = '/'): string
    {
        if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, "\\") || str_contains($next, "\n")) {
            return $fallback;
        }
        return $next;
    }

    private function on(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->clientConfig) || $this->clientConfig[$key] === '' || $this->clientConfig[$key] === null) {
            return $default;
        }
        return Helpers::bool($this->clientConfig[$key]);
    }

    private function siteName(): string
    {
        $name = trim((string) ($this->clientConfig['site.name'] ?? ''));
        return $name !== '' ? $name : (string) ($this->ctx->env['site_name'] ?? 'Rin');
    }

    private function ago(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' 小时前';
        }
        if ($diff < 86400 * 30) {
            return intdiv($diff, 86400) . ' 天前';
        }
        return date('Y-m-d', $ts);
    }

    private function localTime(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
    }

    private function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || preg_match('/[\r\n]/', $url)) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return preg_match('#^https?://#i', $url) ? $url : '';
        }
        return $url;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function csrf(): string
    {
        if ($this->csrf !== null) {
            return $this->csrf;
        }
        $cookie = (string) ($this->ctx->request->cookies['rin_csrf'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $this->csrf = $cookie;
            return $cookie;
        }
        $this->csrf = bin2hex(random_bytes(16));
        $this->csrfFresh = true;
        return $this->csrf;
    }

    private function assertCsrf(): void
    {
        $sent = (string) ($this->ctx->request->post['_csrf'] ?? '');
        $cookie = (string) ($this->ctx->request->cookies['rin_csrf'] ?? '');
        if ($sent === '' || $cookie === '' || !hash_equals($cookie, $sent)) {
            throw HttpException::text('页面已过期，请刷新后再试', 400);
        }
    }

    private function ensureCsrf(Response $response): Response
    {
        if (!$this->csrfFresh) {
            return $response;
        }
        return $response->addCookie('rin_csrf', $this->csrf(), $this->cookie(time() + 60 * 60 * 24 * 14));
    }

    private function cookie(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'httpOnly' => true,
            'secure' => $this->ctx->request->scheme === 'https',
            'sameSite' => 'Lax',
        ];
    }

    private function redirect(string $to, string $message = '', string $type = 'ok', ?Response $carry = null): Response
    {
        $response = Response::redirect($to);
        if ($carry) {
            foreach ($carry->headers() as $name => $value) {
                if ($name === 'Set-Cookie' || str_starts_with($name, 'X-Set-Cookie-')) {
                    $response = $response->withHeader($name, $value);
                }
            }
        }
        if ($message !== '') {
            $message = str_replace(["\r", "\n"], ' ', $message);
            $response = $response->addCookie('rin_flash', ($type === 'err' ? 'err' : 'ok') . "\n" . $message, $this->cookie(time() + 120));
        }
        return $this->ensureCsrf($response);
    }

    private function human(HttpException $e): string
    {
        $payload = $e->payload();
        $message = $e->getMessage();
        if (is_array($payload)) {
            $message = (string) ($payload['error']['message'] ?? $payload['message'] ?? $message);
        }
        return $this->translate($message);
    }

    private function stringify(mixed $data, string $fallback): string
    {
        if (is_string($data) && $data !== '') {
            return $data;
        }
        if (is_array($data)) {
            $message = $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? '';
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        return $fallback;
    }

    private function translate(string $message): string
    {
        $map = [
            'Invalid credentials' => '用户名或密码错误',
            'Username and password are required' => '请填写用户名和密码',
            'Title is required' => '标题不能为空',
            'Content is required' => '内容不能为空',
            'Content already exists' => '相同内容已经存在',
            'Permission denied' => '没有权限',
            'Unauthorized' => '请先登录',
            'Not found' => '没有找到',
            'Friend Link Apply Disabled' => '友链申请已关闭',
            'Already sent' => '已经申请过了',
            'Invalid input' => '输入超出长度或内容不完整',
            'File is required' => '请选择文件',
            'No file uploaded' => '请选择文件',
            'Disallowed file type' => '不支持这个文件类型',
            'File size exceeds limit (10MB)' => '文件不能超过 10MB',
            'AI summary is not enabled' => '还没有启用 AI 摘要',
            'Webhook URL is required' => '请先填写 Webhook 地址',
            'Data is required' => '请选择要导入的文件',
            'No items found' => '没有找到可导入的文章',
        ];
        return $map[$message] ?? $message;
    }

    private function tr(mixed $node): string
    {
        if (!is_array($node)) {
            return (string) $node;
        }
        $key = (string) ($node['key'] ?? '');
        $text = $this->message($key);
        foreach (($node['values'] ?? []) as $name => $value) {
            if (is_scalar($value)) {
                $text = str_replace(['{{' . $name . '}}', '{' . $name . '}'], (string) $value, $text);
            }
        }
        return $text;
    }

    private function message(string $key): string
    {
        if ($this->messages === null) {
            $this->messages = [];
            foreach ([
                dirname(__DIR__, 2) . '/public/locales/zh-CN/translation.json',
                dirname(__DIR__, 2) . '/frontend/client/public/locales/zh-CN/translation.json',
            ] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $this->messages = $decoded;
                    break;
                }
            }
        }
        $cursor = $this->messages;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $key;
            }
            $cursor = $cursor[$part];
        }
        return is_string($cursor) ? $cursor : $key;
    }
}