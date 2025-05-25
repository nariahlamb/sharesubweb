<?php
// utils.php - 单用户模式版本

// 不需要会话
// session_start(); - 已移除，单用户模式不需要会话

$config = require 'config.php';

// 数据库连接
function getDbConnection() {
    global $config;
    static $pdo;
    if ($pdo === null) {
        $dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset={$config['database']['charset']}";
        try {
            $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Redis连接
function getRedis() {
    global $config;
    static $redis;
    if ($redis === null) {
        $redis = new Redis();
        $redis->connect($config['redis']['redis_host'], $config['redis']['redis_port']);
        if (!empty($config['redis']['redis_password'])) {
            $redis->auth($config['redis']['redis_password']);
        }
        $redis->select($config['redis']['redis_db']);
    }
    return $redis;
}

// 生成10位UUID - 保留，用于生成唯一标识
function generateUUID() {
    return substr(bin2hex(random_bytes(5)), 0, 10);
}

// CSRF令牌生成和验证
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// OAuth2获取用户信息
function getOAuthUser($accessToken) {
    global $config;
    $options = [
        'http' => [
            'header' => "Authorization: Bearer {$accessToken}\r\n",
            'method' => 'GET',
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents($config['oauth2']['resourceEndpoint'], false, $context);
    return json_decode($response, true);
}

// 处理后的订阅链接生成 - 修改为无需uuid参数
function generateProxyLink($subscriptionId) {
    global $config;
    return "{$config['base_domain']}/proxy.php?sid={$subscriptionId}";
}

// 单用户模式下，以下认证功能不再需要
// 但为了兼容性，保留函数但简化实现

function checkAuth() {
    // 单用户模式不需要认证，函数为空
    return true;
}

function isUserAuthenticated() {
    // 单用户模式总是认证通过
    return true;
}

function checkAdminAuth() {
    // 单用户模式不需要管理员认证
    return true;
}
?>