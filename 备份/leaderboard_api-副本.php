<?php
// leaderboard_api.php

require_once 'utils.php';
checkAuth();
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
        "SELECT u.username, SUM(us.sub_count) AS total_subs, u.id AS user_id
        FROM user_subscriptions us
        JOIN users u ON us.user_id = u.id
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
        ORDER BY s.available_traffic DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topTraffic = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. 大善人榜单：上传订阅最多的用户Top10
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, COUNT(s.id) AS uploads
        FROM subscriptions s
        JOIN users u ON s.created_by = u.id
        GROUP BY s.created_by
        ORDER BY uploads DESC
        LIMIT 10"
    );
    $stmt->execute();
    $topUploaders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取每个大善人上传的订阅详情
    // Avoiding N+1 queries by fetching all subscriptions at once
    $uploaderIds = array_column($topUploaders, 'user_id');
    if (!empty($uploaderIds)) {
        $inQuery = implode(',', array_fill(0, count($uploaderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT s.id, s.name, s.source, s.available_traffic, s.remark, s.created_by
            FROM subscriptions s
            WHERE s.created_by IN ($inQuery)"
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
        WHERE s.id = ?"
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
        WHERE s.created_by = ?"
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
echo json_encode($leaderboard);
