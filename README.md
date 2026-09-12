# Rin PHP

基于 [openRin/Rin](https://github.com/openRin/Rin) v0.3.0 的 PHP 服务端版本。

前端外观与功能与原版一致，后端改为普通 PHP 服务器：

- 数据库：本地 SQLite（不再使用 Cloudflare D1）
- 文件：本地目录 `storage/uploads`（不再使用 R2 / S3）
- 登录：仅账号密码（不再使用 GitHub OAuth）

## 环境要求

- PHP 8.1+（需启用 pdo_sqlite、sqlite3、curl、gd、mbstring、openssl、fileinfo）
- Node.js 18+（仅构建前端时需要）

## 快速开始

1. 复制配置：

```powershell
Copy-Item config.example.php config.php
```

2. 修改 `config.php`：把 `jwt_secret` 和默认管理员密码改掉。

默认管理员：

- 用户名：`admin`
- 密码：`admin123`

3. 构建前端（首次或前端代码变更后）：

```powershell
cd frontend
npm install
npm run build
```

构建产物会输出到 `public/`，不会清空已有的 `index.php`。

4. 启动：

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
    index index.php index.html;

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
public/              Web 根目录（前端构建产物 + index.php）
storage/uploads/     本地上传文件
storage/database.sqlite  首次运行自动创建
frontend/            原版 React 前端
scripts/cron.php     友链健康检查
config.example.php   配置模板
```

## 说明

- 图片上传、Favicon、文章封面都走本地文件，访问地址为 `/api/blob/...`
- 登录页只显示账号密码，不再显示 GitHub 按钮
- AI 摘要改为请求时同步生成（不再依赖 Cloudflare Queue / Workers AI）
- RSS 在访问 `/rss.xml`、`/atom.xml`、`/rss.json` 时即时生成
