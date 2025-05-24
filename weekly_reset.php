<?php
// weekly_reset.php

require_once 'utils.php';

$pdo = getDbConnection();

try {
    // 开始事务
    $pdo->beginTransaction();

    // 重置 user_subscriptions 表中的 sub_count 和 traffic_used 字段
    $stmt = $pdo->prepare("UPDATE user_subscriptions SET sub_count = 0, traffic_used = 0, last_used = NULL");
    $stmt->execute();

    // 重置 subscriptions 表中的 total_subs 和 total_traffic 字段
    $stmt = $pdo->prepare("UPDATE subscriptions SET total_subs = 0, total_traffic = 0");
    $stmt->execute();

    // 删除已过期的订阅链接
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE expiration_date IS NOT NULL AND expiration_date < NOW()");
    $stmt->execute();

    // 清理 user_ips 表中的记录
    $stmt = $pdo->prepare("DELETE FROM user_ips");
    $stmt->execute();

    // 提交事务
    $pdo->commit();

    // 清除 Redis 缓存
    $redis = getRedis();
    $redis->del('leaderboard_data');
    $redis->del('subscriptions_all');
    // 清除每周缓存（如果有）
    $currentWeekCacheKey = 'subscriptions_week_' . date('o_W');
    $redis->del($currentWeekCacheKey);
    $redis->del('leaderboard_week_' . date('o_W'));

    // 输出成功信息
    echo "[" . date('Y-m-d H:i:s') . "] 每周订阅次数已成功重置，并清理了 IP 记录和过期的订阅链接。\n";
} catch (Exception $e) {
    // 回滚事务
    $pdo->rollBack();
    // 输出错误信息
    echo "[" . date('Y-m-d H:i:s') . "] 重置订阅次数时发生错误：" . $e->getMessage() . "\n";
}
