# Rin PHP

基于 [openRin/Rin](https://github.com/openRin/Rin) v0.3.0 的 PHP 服务端版本。

页面由 PHP 直接输出，不需要 Node.js，也不需要构建前端。外观和功能按原版 Rin 保留：

- 数据库：本地 SQLite（不再使用 Cloudflare D1）
- 文件：本地目录 `storage/uploads`（不再使用 R2 / S3）
- 登录：仅账号密码（不再使用 GitHub OAuth）

## 环境要求

- PHP 8.1+（需启用 pdo_sqlite、sqlite3、curl、gd、mbstring、openssl、fileinfo）

## 快速开始

1. 复制配置：

```powershell
Copy-Item config.example.php config.php
```

2. 修改 `config.php`：把 `jwt_secret` 和默认管理员密码改掉。

默认管理员：

- 用户名：`admin`
- 密码：`admin123`

3. 启动：

```powershell
# 若已把 PHP 加入 PATH
php -S 127.0.0.1:8080 -t public public/router.php

# 或使用本仓库自带的 Windows PHP
.\tools\php\php.exe -S 127.0.0.1:8080 -t public public/router.php
```

也可以直接运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\serve.ps1
```

浏览器打开 http://127.0.0.1:8080

## 配置

`config.php` 主要项：

| 项 | 说明 |
| --- | --- |
| admin_username / admin_password | 管理员登录 |
| jwt_secret | JWT 密钥，务必修改 |
| site_name / site_description / site_avatar | 站点默认信息（也可在后台设置里改） |
| page_size | 首页每页文章数 |
| rss_title / rss_description | RSS 标题与描述 |
| webhook_url | 评论/友链通知 Webhook，可留空 |
| upload_folder | 上传子目录，默认 images/ |

站点名称、主题色、评论开关、友链申请、AI 摘要等其余选项与原版一样，在后台「设置」中修改。

## 部署

网站根目录指向 `public/`。

### 宝塔面板

运行目录选 `/public` 后，如果勾了「防跨站攻击」，`open_basedir` 会只剩 `public/` 和 `/tmp/`。`public/index.php` 就读不到上一级的 `app/`、`config.php` 和 `storage/`。

网站 → 设置 → 网站目录：

- 运行目录保持 `/public`
- 取消勾选「防跨站攻击(open_basedir)」，或者把 `public/.user.ini` 改成项目根目录：

```ini
open_basedir=/www/wwwroot/blog/:/tmp/
```

路径换成实际目录。改完重启 PHP。`.user.ini` 默认缓存约 5 分钟。

### 伪静态

宝塔的伪静态是单独文件，默认是空的，访问 `/login` 这类路径会直接 404。网站 → 设置 → 伪静态，写入：

```nginx
fastcgi_intercept_errors off;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location = /avatar.png {
    try_files $uri /index.php?$query_string;
}

location = /api/config/client/bootstrap.js {
    try_files $uri /index.php?$query_string;
}

location ^~ /api/blob/ {
    try_files $uri /index.php?$query_string;
}
```

宝塔全局打开了 `fastcgi_intercept_errors`。这里不关掉的话，PHP 自己返回的 404 会被换成 nginx 的 `404.html`。`/avatar.png`、`/api/blob/` 和 `bootstrap.js` 也要单独交给 PHP，否则会被当成静态文件。

改完执行 `nginx -t` 并重载 nginx。

### Apache

`public/.htaccess` 已包含重写规则，需开启 mod_rewrite。

### IIS

`public/web.config` 已包含 URL Rewrite。

### nginx

```nginx
server {
    listen 80;
    server_name example.com;
    root /path/to/blog/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

`storage/` 需要 PHP 可写。不要把 `storage/`、`config.php` 放到可公开下载的目录。

## 定时任务

友链健康检查对应原版每 20 分钟一次的 cron。本机示例：

```powershell
php .\scripts\cron.php
```

Linux crontab 示例：

```cron
*/20 * * * * php /path/to/blog/scripts/cron.php
```

## 目录结构

```text
app/                 PHP 后端
public/              Web 根目录（PHP 入口和样式）
storage/uploads/     本地上传文件
storage/database.sqlite  首次运行自动创建
frontend/            原版 React 源码，运行站点时不需要
scripts/cron.php     友链健康检查
config.example.php   配置模板
```

## 说明

- 图片上传、Favicon、文章封面都走本地文件，访问地址为 `/api/blob/...`
- 登录页只显示账号密码，不再显示 GitHub 按钮
- AI 摘要改为请求时同步生成（不再依赖 Cloudflare Queue / Workers AI）
- RSS 在访问 `/rss.xml`、`/atom.xml`、`/rss.json` 时即时生成
