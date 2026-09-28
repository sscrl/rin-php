<?php
declare(strict_types=1);

namespace Rin\Web;

use Rin\Core\Response;

final class InstallPages
{
    public static function home(): Response
    {
        return Response::html(self::document(
            '站点尚未安装 · Rin',
            self::homeBody()
        ), 200, ['Cache-Control' => 'no-store']);
    }

    public static function wizard(): Response
    {
        return Response::html(self::document(
            '安装 Rin',
            self::wizardBody(),
            self::wizardScript()
        ), 200, ['Cache-Control' => 'no-store']);
    }

    private static function document(string $title, string $body, string $script = ''): string
    {
        $css = self::css();
        $scriptTag = $script === '' ? '' : "<script>\n" . $script . "\n</script>";
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title}</title>
  <link rel="icon" href="/favicon.png" />
  <style>
{$css}
  </style>
</head>
<body>
{$body}
{$scriptTag}
</body>
</html>
HTML;
    }

    private static function homeBody(): string
    {
        $footer = self::footer();
        $icon = self::installIcon();
        return <<<HTML
<main class="home-main">
  <div class="empty-card">
    <div class="mark">{$icon}</div>
    <h1>站点尚未安装</h1>
    <p class="sub">源码已就绪，还差最后的初始化。<br />完成后即可登录后台、发布文章。</p>
    <a class="install-link" href="/install">点击此处安装 <span aria-hidden="true">→</span></a>
  </div>
</main>
{$footer}
HTML;
    }

    private static function wizardBody(): string
    {
        $footer = self::footer();
        $logo = self::logoIcon();
        $check = self::checkIcon();
        return <<<HTML
<main class="install-shell">
  <div class="wizard" id="wizard" data-step="1" data-db="sqlite">
    <div class="wizard-head">
      <div class="logo">{$logo}</div>
      <h1>安装 Rin</h1>
      <p class="sub">三步完成站点初始化</p>
    </div>
    <div class="steps">
      <div class="step st1"><div class="num">1</div>数据库</div>
      <div class="line"></div>
      <div class="step st2"><div class="num">2</div>站点信息</div>
      <div class="line"></div>
      <div class="step st3"><div class="num">3</div>管理员</div>
    </div>
    <div class="card">
      <div id="alert" class="alert" hidden></div>
      <div class="panel p1">
        <h2>绑定数据库</h2>
        <p class="hint">当前程序默认使用 SQLite，上传后不用在面板里建库。如果已经准备了 MySQL，也可以在这里填写。</p>
        <div class="seg">
          <button type="button" class="seg-btn" data-db="sqlite">SQLite 推荐</button>
          <button type="button" class="seg-btn" data-db="mysql">MySQL</button>
        </div>
        <div class="note sqlite">将自动创建 <b>storage/database.sqlite</b>，并写入数据表。适合个人博客，零配置。</div>
        <div class="mysql">
          <div class="fields">
            <div class="row-2">
              <label class="field">数据库主机<input id="db_host" value="127.0.0.1" autocomplete="off" /></label>
              <label class="field">端口<input id="db_port" value="3306" inputmode="numeric" /></label>
            </div>
            <label class="field">数据库名<input id="db_name" placeholder="rin" autocomplete="off" /></label>
            <label class="field">用户名<input id="db_user" placeholder="rin" autocomplete="off" /></label>
            <label class="field">密码<input id="db_pass" type="password" placeholder="数据库密码" /></label>
          </div>
        </div>
        <div class="actions"><button type="button" class="btn btn-primary" data-next="2">下一步</button></div>
      </div>
      <div class="panel p2">
        <h2>站点基本信息</h2>
        <p class="hint">这些内容会出现在首页标题、页脚和分享卡片里，之后仍可在后台修改。</p>
        <div class="fields">
          <label class="field">站点名称<input id="site_name" placeholder="例如：疏影笔记" maxlength="50" /></label>
          <label class="field">站点简介<textarea id="site_description" placeholder="一句话介绍这个博客" maxlength="200"></textarea></label>
          <label class="field">站点头像 URL<input id="site_avatar" placeholder="https://" /></label>
        </div>
        <div class="actions">
          <button type="button" class="btn btn-ghost" data-next="1">上一步</button>
          <button type="button" class="btn btn-primary" data-next="3">下一步</button>
        </div>
      </div>
      <div class="panel p3">
        <h2>管理员登录信息</h2>
        <p class="hint">这是后台唯一账号。没有访客注册，请自行保管用户名和密码。</p>
        <div class="fields">
          <label class="field">管理员用户名<input id="admin_username" placeholder="admin" maxlength="32" autocomplete="username" /></label>
          <label class="field">登录密码<input id="admin_password" type="password" placeholder="至少 8 位" autocomplete="new-password" /></label>
          <label class="field">确认密码<input id="admin_password_confirm" type="password" placeholder="再输入一次" autocomplete="new-password" /></label>
        </div>
        <div class="actions">
          <button type="button" class="btn btn-ghost" data-next="2">上一步</button>
          <button type="button" class="btn btn-primary" id="install-btn">安装</button>
        </div>
      </div>
      <div class="panel p4 done">
        {$check}
        <h2>安装完成</h2>
        <p class="hint">配置已写入，数据库已初始化。接下来可以进入首页登录后台。</p>
        <div class="actions center"><a class="btn btn-primary" href="/">进入首页</a></div>
      </div>
    </div>
  </div>
</main>
{$footer}
HTML;
    }

    private static function footer(): string
    {
        $year = date('Y');
        return '<footer class="site">© ' . $year . ' 由 <a href="https://github.com/sscrl/rin-php" target="_blank" rel="noreferrer">Rin</a></footer>';
    }

    private static function installIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="30" height="30" fill="currentColor" aria-hidden="true"><path d="M12 2a1 1 0 0 1 1 1v9.586l2.293-2.293a1 1 0 1 1 1.414 1.414l-4 4a1 1 0 0 1-1.414 0l-4-4A1 1 0 0 1 8.707 10.293L11 12.586V3a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v2h12v-2a1 1 0 1 1 2 0v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z"/></svg>';
    }

    private static function logoIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M3 21.5 6.2 20 18.7 7.5a2.6 2.6 0 0 0-3.7-3.7L2.5 18.8 3 21.5Zm14.2-13.3 2.3-2.3a2.6 2.6 0 0 0-3.7-3.7l-2.3 2.3 3.7 3.7Z"/></svg>';
    }

    private static function checkIcon(): string
    {
        return '<svg class="done-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="42" height="42" fill="currentColor" aria-hidden="true"><path d="M12 22a10 10 0 1 1 0-20 10 10 0 0 1 0 20Zm-1.2-6.2 6.3-6.3-1.4-1.4-4.9 4.9-2.1-2.1-1.4 1.4 3.5 3.5Z"/></svg>';
    }

    private static function css(): string
    {
        return <<<'CSS'
    :root {
      --theme: 252 70 107;
      --bg: #f5f5f5;
      --text: #111;
      --muted: #737373;
      --line: rgba(0,0,0,.10);
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: var(--bg);
      color: var(--text);
      font-family: Cantarell, "Noto Serif SC", "Source Han Serif SC", serif;
      line-height: 1.55;
    }
    button, input, textarea, label { font-family: inherit; }
    a { color: inherit; }
    .home-main, .install-shell {
      flex: 1;
      display: grid;
      place-items: center;
      padding: 36px 16px 24px;
    }
    .install-shell { place-items: start center; padding-top: 28px; }
    .empty-card, .card {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: 0 18px 50px rgb(0 0 0 / .04);
    }
    .empty-card { width: min(520px, 100%); padding: 40px 28px 32px; text-align: center; }
    .mark {
      width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 20px;
      background: rgb(var(--theme) / .10); color: rgb(var(--theme));
      display: grid; place-items: center;
    }
    h1 { margin: 0; font-size: 28px; letter-spacing: -.03em; }
    .sub { margin: 10px 0 0; color: var(--muted); }
    .install-link {
      display: inline-flex; margin-top: 22px; color: rgb(var(--theme)); font-weight: 700;
      text-decoration: none; border-bottom: 1px solid rgb(var(--theme) / .35); padding-bottom: 2px; gap: 6px;
    }
    footer.site { padding: 8px 0 36px; text-align: center; color: #737373; font-size: 14px; }
    footer.site a { text-decoration: none; }
    footer.site a:hover { text-decoration: underline; }
    .wizard { width: min(560px, 100%); }
    .wizard-head { text-align: center; margin-bottom: 20px; }
    .logo {
      width: 52px; height: 52px; margin: 0 auto 12px; border-radius: 18px;
      background: rgb(var(--theme)); color: #fff; display: grid; place-items: center;
      box-shadow: 0 10px 24px rgb(var(--theme) / .28);
    }
    .steps { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; align-items: center; gap: 8px; margin: 0 6px 16px; }
    .step { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--muted); font-size: 12px; }
    .num { width: 28px; height: 28px; border-radius: 999px; display: grid; place-items: center; border: 1px solid var(--line); background: #fff; font-weight: 700; }
    .line { height: 1px; background: var(--line); margin-bottom: 18px; }
    .wizard[data-step="1"] .st1,
    .wizard[data-step="2"] .st2,
    .wizard[data-step="3"] .st3 { color: rgb(var(--theme)); }
    .wizard[data-step="1"] .st1 .num,
    .wizard[data-step="2"] .st2 .num,
    .wizard[data-step="3"] .st3 .num { background: rgb(var(--theme)); color: #fff; border-color: transparent; }
    .wizard[data-step="2"] .st1,
    .wizard[data-step="3"] .st1,
    .wizard[data-step="3"] .st2,
    .wizard[data-step="4"] .st1,
    .wizard[data-step="4"] .st2,
    .wizard[data-step="4"] .st3 { color: #111; }
    .wizard[data-step="2"] .st1 .num,
    .wizard[data-step="3"] .st1 .num,
    .wizard[data-step="3"] .st2 .num,
    .wizard[data-step="4"] .st1 .num,
    .wizard[data-step="4"] .st2 .num,
    .wizard[data-step="4"] .st3 .num { background: #111; color: #fff; border-color: transparent; }
    .card { padding: 28px; }
    .card h2 { margin: 0; font-size: 18px; }
    .hint { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
    .panel { display: none; }
    .wizard[data-step="1"] .p1,
    .wizard[data-step="2"] .p2,
    .wizard[data-step="3"] .p3,
    .wizard[data-step="4"] .p4 { display: block; }
    .seg { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin: 18px 0 8px; padding: 4px; background: #f5f5f5; border: 1px solid var(--line); border-radius: 14px; }
    .seg-btn { border: 0; background: transparent; text-align: center; padding: 10px 8px; border-radius: 11px; color: var(--muted); font-weight: 600; cursor: pointer; }
    .wizard[data-db="sqlite"] .seg-btn[data-db="sqlite"],
    .wizard[data-db="mysql"] .seg-btn[data-db="mysql"] { background: #fff; color: #111; box-shadow: 0 4px 12px rgb(0 0 0 / .06); }
    .note { margin-top: 16px; padding: 14px 16px; border-radius: 16px; background: rgb(var(--theme) / .07); font-size: 13px; line-height: 1.65; }
    .mysql { display: none; }
    .wizard[data-db="mysql"] .mysql { display: block; }
    .wizard[data-db="mysql"] .sqlite { display: none; }
    .fields { display: grid; gap: 14px; margin-top: 18px; }
    .field { display: grid; gap: 6px; font-size: 13px; font-weight: 600; }
    input, textarea {
      width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 11px 14px;
      font-size: 15px; font-weight: 400; outline: none; background: #fff;
    }
    input:focus, textarea:focus { border-color: rgb(var(--theme) / .45); }
    textarea { min-height: 88px; resize: vertical; }
    .row-2 { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
    .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
    .btn {
      display: inline-flex; align-items: center; justify-content: center;
      border: 0; border-radius: 999px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
    }
    .btn:disabled { opacity: .6; cursor: wait; }
    .btn-primary { background: rgb(var(--theme)); color: #fff; }
    .btn-ghost { background: #f5f5f5; color: #111; border: 1px solid var(--line); }
    .done { text-align: center; padding: 28px 8px 8px; }
    .done-icon { color: rgb(var(--theme)); }
    .center { justify-content: center; }
    .alert {
      margin-bottom: 16px; padding: 10px 12px; border-radius: 12px;
      background: #fff1f2; color: #be123c; font-size: 13px;
    }
    @media (max-width: 720px) {
      .row-2 { grid-template-columns: 1fr; }
      .card { padding: 22px 18px; }
    }
CSS;
    }

    private static function wizardScript(): string
    {
        return <<<'JS'
    const wizard = document.getElementById('wizard');
    const alertBox = document.getElementById('alert');
    const installBtn = document.getElementById('install-btn');

    function val(id) {
      return (document.getElementById(id)?.value || '').trim();
    }
    function showAlert(message) {
      if (!message) {
        alertBox.hidden = true;
        alertBox.textContent = '';
        return;
      }
      alertBox.hidden = false;
      alertBox.textContent = message;
    }
    function setStep(step) {
      wizard.dataset.step = String(step);
      showAlert('');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function payload() {
      return {
        db_driver: wizard.dataset.db || 'sqlite',
        db_host: val('db_host') || '127.0.0.1',
        db_port: Number(val('db_port') || '3306'),
        db_name: val('db_name'),
        db_user: val('db_user'),
        db_pass: document.getElementById('db_pass')?.value || '',
        site_name: val('site_name'),
        site_description: document.getElementById('site_description')?.value.trim() || '',
        site_avatar: val('site_avatar'),
        admin_username: val('admin_username'),
        admin_password: document.getElementById('admin_password')?.value || '',
        admin_password_confirm: document.getElementById('admin_password_confirm')?.value || ''
      };
    }
    async function post(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body)
      });
      let data = {};
      try { data = await res.json(); } catch (e) { data = {}; }
      if (!res.ok || data.success === false) {
        throw new Error(data?.error?.message || data?.message || ('请求失败 (' + res.status + ')'));
      }
      return data;
    }

    wizard.querySelectorAll('.seg-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        wizard.dataset.db = btn.dataset.db;
        showAlert('');
      });
    });
    wizard.querySelectorAll('[data-next]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const next = Number(btn.dataset.next);
        const current = Number(wizard.dataset.step);
        if (next > current) {
          try {
            if (current === 1 && wizard.dataset.db === 'mysql') {
              btn.disabled = true;
              await post('/api/install/test-db', payload());
            }
            if (current === 2 && !val('site_name')) {
              showAlert('请填写站点名称');
              return;
            }
            setStep(next);
          } catch (err) {
            showAlert(err.message || '数据库连接失败');
          } finally {
            btn.disabled = false;
          }
          return;
        }
        setStep(next);
      });
    });
    installBtn.addEventListener('click', async () => {
      const data = payload();
      if (!data.admin_username) {
        showAlert('请填写管理员用户名');
        return;
      }
      if (data.admin_password.length < 8) {
        showAlert('密码至少 8 位');
        return;
      }
      if (data.admin_password !== data.admin_password_confirm) {
        showAlert('两次输入的密码不一致');
        return;
      }
      installBtn.disabled = true;
      try {
        await post('/api/install', data);
        setStep(4);
      } catch (err) {
        showAlert(err.message || '安装失败');
      } finally {
        installBtn.disabled = false;
      }
    });
JS;
    }
}