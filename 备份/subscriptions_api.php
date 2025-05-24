<?php
// api/subscriptions.php

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/csrf.php';

// CORS 处理
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// 权限检查
checkAuth();

// CSRF 检查，如果函数不存在则跳过
if (function_exists('checkCsrf') && !checkCsrf()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF validation failed"]);
    exit;
}

// 获取当前用户 ID
$userId = $_SESSION['user_id'];

// 数据库和缓存连接
$pdo = getDbConnection();
$redis = getRedis();

// 获取当前用户的用户名
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

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
    $subscriptions = $stmt->fetchAll();
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
        'name' => $sub['name'],
        'username' => $sub['username'],
        'weekly_subs' => intval($sub['weekly_subs']),
        'available_traffic' => floatval($sub['available_traffic']),
        'source' => $sub['source'],
        'remark' => $sub['remark'],
        'proxy_link' => generateProxyLink($_SESSION['uuid'], $sub['id']),
        'expiration_date' => $sub['expiration_date']
    ];
}

// 返回 JSON 响应
echo json_encode($response);
?>