<?php
// delete_subscription.php - 单用户模式

require_once 'utils.php';
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscriptionId = intval($_POST['id'] ?? 0);
    if ($subscriptionId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的请求']);
        exit();
    }

    // 直接删除指定ID的订阅，不检查用户ID
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = ?");
    $stmt->execute([$subscriptionId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '订阅已删除']);
    } else {
        echo json_encode(['success' => false, 'message' => '订阅不存在']);
    }
    exit();
}