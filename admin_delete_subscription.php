<?php
// admin_delete_subscription.php

require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subId = intval($_POST['id']);
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = ?");
    $stmt->execute([$subId]);

    echo json_encode(['message' => '订阅已删除']);
    exit();
}