<?php
// api/subscriptions.php

// 启动会话以访问 $_SESSION
session_start();

// 引入必要的工具和函数
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/csrf.php';

// ============ 1. 首先进行用户认证检查 ============
if (!isUserAuthenticated()) {
    // 记录未授权的访问
    error_log("Unauthorized access attempt from IP: " . $_SERVER['REMOTE_ADDR']);
    
    // 检查请求是否为AJAX请求
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // 检查是否为API请求（通过Accept头或其他标识）
    $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && 
                    (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    
    // 无论是什么类型的请求，都直接重定向到首页
    // 这是最简单有效的方法
    header('Location: /index.html');
    exit;
}

// 设置响应头
header('Content-Type: application/json');

// ============ 2. 然后进行 PoW 验证 ============
// 定义 PoW 参数
define('POW_DIFFICULTY', 4); // 哈希前导零数量
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
    if (abs($current_time - intval($timestamp)) > POW_TIMESTAMP_WINDOW) {
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
    // 记录失败的 PoW 验证
    error_log("PoW validation failed. Timestamp: $timestamp, Nonce: $nonce, IP: " . $_SERVER['REMOTE_ADDR']);
    http_response_code(403);
    echo json_encode(["error" => "Proof of Work validation failed"]);
    exit;
}

// ============ 3. 接着进行 CSRF 检查 ============
if (function_exists('checkCsrf') && !checkCsrf()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF validation failed"]);
    exit;
}

// ============ 4. 最后处理业务逻辑 ============
// 获取当前用户 ID
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
    'username' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), // 返回当前用户的用户名，转义特殊字符
    'subscriptions' => []    // 包含所有订阅信息
];

foreach ($subscriptions as $sub) {
    $response['subscriptions'][] = [
        'id' => intval($sub['id']),
        'name' => htmlspecialchars($sub['name'], ENT_QUOTES, 'UTF-8'),
        'username' => htmlspecialchars($sub['username'], ENT_QUOTES, 'UTF-8'),
        'weekly_subs' => intval($sub['weekly_subs']),
        'available_traffic' => floatval($sub['available_traffic']),
        'source' => htmlspecialchars($sub['source'], ENT_QUOTES, 'UTF-8'),
        'remark' => htmlspecialchars($sub['remark'], ENT_QUOTES, 'UTF-8'),
        'proxy_link' => generateProxyLink($_SESSION['uuid'], $sub['id']),
        'expiration_date' => $sub['expiration_date'] ? htmlspecialchars($sub['expiration_date'], ENT_QUOTES, 'UTF-8') : 'null'
    ];
}

// 返回 JSON 响应
echo json_encode($response);
?>