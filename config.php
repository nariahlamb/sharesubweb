<?php
// config.php

return [
    'database' => [
        'host' => 'localhost', // 数据库主机
        'dbname' => 'share_lzf_email',
        'username' => 'share_lzf_email',
        'password' => '1111',
        'charset' => 'utf8mb4',
    ],
    'oauth2' => [
        'clientId' => '111',
        'clientSecret' => '11111',
        'redirectUri' => 'https://share.lzf.email/callback.php',
        'authorizationEndpoint' => 'https://connect.linux.do/oauth2/authorize',
        'tokenEndpoint' => 'https://connect.linux.do/oauth2/token',
        'resourceEndpoint' => 'https://connect.linux.do/api/user',
    ],
    'admin' => [
        'username' => 'admin',
        'password' => 'admin', // 建议使用哈希存储
    ],
    'pow' => [
        'apiBaseUrl' => 'https://share.lzf.email', // POW API 的基 URL
        'challengeInterval' => 10000, // 挑战获取的时间间隔（毫秒）
    ],
    'redis' => [
        'redis_host' => '127.0.0.1',         // Redis主机
        'redis_port' => 6379,                // Redis端口
        'redis_password' => '',              // Redis密码（如果有）
        'redis_db' => 10,                     // Redis数据库编号
    ],
    'base_domain' => 'https://share.lzf.email', // 基础域名
];