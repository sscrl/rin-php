<?php
return [
    // 管理员账号。首次登录会自动创建对应用户。
    'admin_username' => 'admin',
    'admin_password' => 'admin123',

    // JWT 密钥，生产环境必须改成足够长的随机字符串。
    'jwt_secret' => 'change-this-jwt-secret',

    // 站点默认信息；后台设置里保存后优先生效。
    'site_name' => 'Rin',
    'site_description' => 'A lightweight personal blogging system',
    'site_avatar' => '',
    'page_size' => 5,

    // RSS
    'rss_title' => 'Rin',
    'rss_description' => 'Feed from Rin',

    // 评论/友链通知，可留空
    'webhook_url' => '',

    // 本地上传子目录，文件保存在 storage/uploads 下
    'upload_folder' => 'images/',
    'cache_folder' => 'cache/',
];
