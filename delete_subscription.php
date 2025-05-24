<?php
// delete_subscription.php

require_once 'utils.php';
checkAuth();
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscriptionId = intval($_POST['id'] ?? 0);
    if ($subscriptionId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的请求']);
        exit();
    }

    // 获取当前用户ID
    $userId = $_SESSION['user_id'];

    // 删除用户的订阅
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = ? AND created_by = ?");
    $stmt->execute([$subscriptionId, $userId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '订阅已删除']);
    } else {
        echo json_encode(['success' => false, 'message' => '订阅不存在或没有权限删除']);
    }
    exit();
}