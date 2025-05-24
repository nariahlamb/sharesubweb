<?php
// admin_delete_inactive.php

require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inactiveSubs = $_SESSION['inactive_subscriptions'] ?? [];
    if (empty($inactiveSubs)) {
        echo json_encode(['message' => '没有要删除的订阅']);
        exit();
    }

    $pdo = getDbConnection();
    $inClause = implode(',', array_fill(0, count($inactiveSubs), '?'));
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id IN ($inClause)");
    $stmt->execute($inactiveSubs);

    // 清除会话中的数据
    unset($_SESSION['inactive_subscriptions']);

    echo json_encode(['message' => '不活跃的订阅已删除']);
    exit();
}