<?php
// admin_batch_check.php

require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT id, name, link FROM subscriptions");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll();

    $inactiveSubs = [];
    $html = '<ul>';

    foreach ($subscriptions as $sub) {
        $headers = @get_headers($sub['link']);
        $isActive = $headers && strpos($headers[0], '200') !== false;
        $status = $isActive ? '可用' : '不可用';
        $html .= '<li>' . htmlspecialchars($sub['name']) . ' - ' . $status . '</li>';

        if (!$isActive) {
            $inactiveSubs[] = $sub['id'];
        }
    }

    $html .= '</ul>';

    // 将不活跃的订阅ID存入会话，供删除时使用
    $_SESSION['inactive_subscriptions'] = $inactiveSubs;

    echo json_encode(['html' => $html]);
    exit();
}