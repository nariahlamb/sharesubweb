<?php
// admin_check_subscription.php

require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subId = intval($_POST['id']);
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT link FROM subscriptions WHERE id = ?");
    $stmt->execute([$subId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        echo json_encode(['message' => '订阅不存在']);
        exit();
    }

    // 检测订阅链接是否可用
    $link = $sub['link'];
    $headers = @get_headers($link);
    $isActive = $headers && strpos($headers[0], '200') !== false;

    $message = $isActive ? '订阅可用' : '订阅不可用';
    echo json_encode(['message' => $message]);
    exit();
}