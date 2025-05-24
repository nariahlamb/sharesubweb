<?php
// admin_unblock_user.php

require_once 'utils.php';
checkAdminAuth();
$pdo = getDbConnection();

$userId = $_POST['id'] ?? null;

if ($userId) {
    $stmt = $pdo->prepare("UPDATE users SET is_blocked = 0 WHERE id = ?");
    if ($stmt->execute([$userId])) {
        echo json_encode(['message' => '用户已被解除拉黑']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => '操作失败']);
    }
} else {
    http_response_code(400);
    echo json_encode(['message' => '缺少用户ID']);
}