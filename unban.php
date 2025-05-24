<?php
// unban_all_users.php

require_once 'utils.php';

$pdo = getDbConnection();

try {
    // 解封所有用户 - 更新 users 表中的状态为 'active'
    $stmt = $pdo->prepare("UPDATE users SET is_blocked = 0 WHERE is_blocked = 1");
    $stmt->execute();

    echo "[" . date('Y-m-d H:i:s') . "] 已成功解封所有被封禁的用户。\n";
} catch (Exception $e) {
    // 输出错误信息
    echo "[" . date('Y-m-d H:i:s') . "] 解封用户时发生错误：" . $e->getMessage() . "\n";
}
