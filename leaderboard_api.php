<?php
// leaderboard_api.php

// 启动会话以访问 $_SESSION
session_start();

// 引入必要的工具和函数
require_once 'utils.php';
require_once 'csrf.php';

// 设置响应头
header('Content-Type: application/json');

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

// API认证检查
if (!isUserAuthenticated()) {
    // 记录未授权的访问尝试
    error_log("Unauthorized access attempt to leaderboard_api.php from IP: " . $_SERVER['REMOTE_ADDR']);
    
    // 设置HTTP状态码为401 Unauthorized
    http_response_code(401);
    
    // 返回JSON格式的错误信息
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

// CSRF 检查（如果适用）
if (function_exists('checkCsrf') && !checkCsrf()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF validation failed"]);
    exit();
}

// 获取当前用户 ID
$userId = $_SESSION['user_id'];

// 数据库和缓存连接
$pdo = getDbConnection();
$redis = getRedis();

$cacheKey = 'leaderboard_full';
$leaderboard = $redis->get($cacheKey);
if ($leaderboard === false) {
    error_log('Error fetching leaderboard data from Redis');
}

if (!$leaderboard) {
    // 1. 用户订阅次数Top10
    $stmt = $pdo->prepare(
        "SELECT u.username, SUM(us.sub_count) AS total_subs, u.id AS user_id,
                COUNT(DISTINCT ui.subscription_id) AS dinyue,
                COUNT(DISTINCT ui.ip_address) AS ips
        FROM user_subscriptions us
        JOIN users u ON us.user_id = u.id
        LEFT JOIN user_ips ui ON us.user_id = ui.user_id
        GROUP BY us.user_id
        ORDER BY total_subs DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. 被订阅次数最多的订阅链接Top10
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, u.username, s.total_subs, s.source, s.available_traffic, s.remark
        FROM subscriptions s
        JOIN users u ON s.created_by = u.id
        WHERE s.name <> s.source
        ORDER BY s.total_subs DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 可用流量最多Top10
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, u.username, s.available_traffic, s.source, s.remark
        FROM subscriptions s
        JOIN users u ON s.created_by = u.id
        WHERE s.name <> s.source
        ORDER BY s.available_traffic DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topTraffic = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. 大善人榜单：上传订阅最多的用户Top10
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, COUNT(s.id) AS uploads,
                COUNT(DISTINCT ui.ip_address) AS ips
        FROM subscriptions s
        JOIN users u ON s.created_by = u.id
        LEFT JOIN user_ips ui ON s.created_by = ui.user_id
        WHERE s.name <> s.source
        GROUP BY u.id, u.username
        ORDER BY uploads DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topUploaders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取每个大善人上传的订阅详情
    // Avoiding N+1 queries by fetching all subscriptions at once
    $uploaderIds = array_column($topUploaders, 'user_id');
    if (!empty($uploaderIds)) {
        // 使用预处理语句防止SQL注入
        $inQuery = implode(',', array_fill(0, count($uploaderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT s.id, s.name, s.source, s.available_traffic, s.remark, s.created_by
            FROM subscriptions s
            WHERE s.created_by IN ($inQuery) AND s.name <> s.source"
        );
        $stmt->execute($uploaderIds);
        $allSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topUploaders as &$uploader) {
            $uploader['subscriptions'] = array_values(array_filter($allSubscriptions, function ($sub) use ($uploader) {
                return $sub['created_by'] == $uploader['user_id'];
            }));
        }
    }

    $leaderboard = [
        'topUsers' => $topUsers,
        'topSubscriptions' => $topSubscriptions,
        'topTraffic' => $topTraffic,
        'topUploaders' => $topUploaders,
    ];

    // 更新缓存
    $redis->set($cacheKey, json_encode($leaderboard), 600);
} else {
    $leaderboard = json_decode($leaderboard, true);
}

// 支持查询单个订阅或用户详情
if (isset($_GET['subscription_id'])) {
    $subscriptionId = $_GET['subscription_id'];
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.source, s.available_traffic, s.remark
        FROM subscriptions s
        WHERE s.id = ? AND s.name <> s.source"
    );
    $stmt->execute([$subscriptionId]);
    $subscriptionDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($subscriptionDetails) {
        $subscriptionDetails['link'] = generateProxyLink($_SESSION['uuid'], $subscriptionId);
    }
    header('Content-Type: application/json');
    echo json_encode(['subscription' => $subscriptionDetails]);
    exit;
}

if (isset($_GET['user_id'])) {
    $userId = $_GET['user_id'];
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.source, s.available_traffic, s.remark
        FROM subscriptions s
        WHERE s.created_by = ? AND s.name <> s.source"
    );
    $stmt->execute([$userId]);
    $userSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($userSubscriptions as &$subscription) {
        $subscription['link'] = generateProxyLink($_SESSION['uuid'], $subscription['id']);
    }
    header('Content-Type: application/json');
    echo json_encode(['subscriptions' => $userSubscriptions]);
    exit;
}

header('Content-Type: application/json');
echo json_encode($leaderboard, JSON_PRETTY_PRINT);
?>