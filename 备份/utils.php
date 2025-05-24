<?php
// utils.php

session_start();

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

// 生成10位UUID
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

// 处理后的订阅链接生成
function generateProxyLink($uuid, $subscriptionId) {
    global $config;
    return "{$config['base_domain']}/proxy.php?uuid={$uuid}&sid={$subscriptionId}";
}

// 检查用户是否登录
function checkAuth() {
    if (empty($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
}

function checkAdminAuth() {
    session_start();
    if (empty($_SESSION['is_admin'])) {
        header('Location: admin_login.php');
        exit();
    }
}