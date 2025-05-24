<?php
// api/subscriptions.php

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/csrf.php';

// 启动会话以访问 $_SESSION
session_start();

// CORS 处理 - 仅允许信任的来源
$allowed_origins = ['*l']; // 请替换为您的实际域名
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    // 如果来源不在允许列表中，可以选择拒绝请求
    http_response_code(403);
    echo json_encode(["error" => "CORS policy does not allow access from the specified Origin."]);
    exit;
}

header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// 定义 PoW 参数
define('POW_DIFFICULTY', 4); // 需要的哈希前导零数量
define('POW_TIMESTAMP_WINDOW', 300); // 时间窗口（秒），例如 5 分钟

/**
 * 验证 Proof-of-Work
 *
 * @param string $timestamp 时间戳
 * @param string $nonce 随机数
 * @return bool 是否通过验证
 */
function validate_pow($timestamp, $nonce) {
    // 检查时间戳是否为数字
    if (!is_numeric($timestamp)) {
        return false;
    }

    // 检查时间戳是否在允许的时间窗口内
    $current_time = time();
    if (abs($current_time - $timestamp) > POW_TIMESTAMP_WINDOW) {
        return false;
    }

    // 检查 nonce 是否提供
    if (empty($nonce)) {
        return false;
    }

    // 计算哈希值
    $hash_input = $timestamp . $nonce;
    $hash = hash('sha256', $hash_input);

    // 检查哈希是否满足难度要求
    $required_prefix = str_repeat('0', POW_DIFFICULTY);
    if (substr($hash, 0, POW_DIFFICULTY) !== $required_prefix) {
        return false;
    }

    return true;
}

// PoW 验证
if (!isset($_GET['timestamp']) || !isset($_GET['nonce'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing PoW parameters"]);
    exit;
}

$timestamp = $_GET['timestamp'];
$nonce = $_GET['nonce'];

if (!validate_pow($timestamp, $nonce)) {
    http_response_code(403);
    echo json_encode(["error" => "Proof of Work validation failed"]);
    exit;
}

// 权限检查
if (!function_exists('checkAuth') || !checkAuth()) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// CSRF 检查（如果适用）
if (function_exists('checkCsrf') && !checkCsrf()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF validation failed"]);
    exit;
}

// 获取当前用户 ID
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$userId = $_SESSION['user_id'];

// 数据库和缓存连接
$pdo = getDbConnection();
$redis = getRedis();

// 获取当前用户的用户名
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    http_response_code(404);
    echo json_encode(["error" => "User not found"]);
    exit;
}

$username = $currentUser['username'];

// 获取当前周的开始时间
$startOfWeek = date('Y-m-d H:i:s', strtotime('monday this week'));
$cacheKey = 'subscriptions_week_' . date('o_W');

// 从缓存获取订阅信息
$subscriptions = $redis->get($cacheKey);
if (!$subscriptions) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.username,
            (SELECT COUNT(*) FROM user_subscriptions us WHERE us.subscription_id = s.id AND us.last_used >= ?) AS weekly_subs,
            s.expiration_date
        FROM subscriptions s
        JOIN users u ON s.created_by = u.id
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$startOfWeek]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $redis->set($cacheKey, json_encode($subscriptions), 300); // 缓存 5 分钟
} else {
    $subscriptions = json_decode($subscriptions, true);
}

// 格式化返回结果
$response = [
    'username' => $username, // 返回当前用户的用户名
    'subscriptions' => []    // 包含所有订阅信息
];

foreach ($subscriptions as $sub) {
    $response['subscriptions'][] = [
        'id' => $sub['id'],
        'name' => htmlspecialchars($sub['name'], ENT_QUOTES, 'UTF-8'),
        'username' => htmlspecialchars($sub['username'], ENT_QUOTES, 'UTF-8'),
        'weekly_subs' => intval($sub['weekly_subs']),
        'available_traffic' => floatval($sub['available_traffic']),
        'source' => htmlspecialchars($sub['source'], ENT_QUOTES, 'UTF-8'),
        'remark' => htmlspecialchars($sub['remark'], ENT_QUOTES, 'UTF-8'),
        'proxy_link' => generateProxyLink($_SESSION['uuid'], $sub['id']),
        'expiration_date' => $sub['expiration_date']
    ];
}

// 返回 JSON 响应
echo json_encode($response);
?>