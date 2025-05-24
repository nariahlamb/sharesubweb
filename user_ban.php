<?php
// user_ban.php

require_once 'utils.php';

$pdo = getDbConnection();

try {
    // 获取满足封禁条件的用户 - 第一种情况
    $stmt = $pdo->prepare(
        "SELECT us.user_id
        FROM user_subscriptions us
        JOIN (
            SELECT user_id, COUNT(DISTINCT ip_address) AS unique_ips, COUNT(DISTINCT subscription_id) AS unique_subs
            FROM user_ips
            GROUP BY user_id
        ) ui ON us.user_id = ui.user_id
        WHERE us.sub_count / ui.unique_subs >= 1000 AND ui.unique_ips > 5"
    );
    $stmt->execute();
    $usersToBan1 = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 获取满足封禁条件的用户 - 第二种情况
    $stmt = $pdo->prepare(
        "SELECT user_id
        FROM user_ips
        GROUP BY user_id
        HAVING COUNT(DISTINCT ip_address) >= 10"
    );
    $stmt->execute();
    $usersToBan2 = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 合并所有需要封禁的用户ID
    $usersToBan = array_unique(array_merge($usersToBan1, $usersToBan2));

    if (!empty($usersToBan)) {
        // 封禁用户 - 更新 users 表中的 is_blocked 字段
        $inQuery = implode(',', array_fill(0, count($usersToBan), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($inQuery) AND is_blocked = 0");

        // 检查用户是否已经被封禁
        $stmt->execute(array_values($usersToBan)); // 使用 array_values 来确保参数顺序正确
        $bannedUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 更新尚未封禁的用户
        if (!empty($bannedUsers)) {
            $updateStmt = $pdo->prepare("UPDATE users SET is_blocked = 1 WHERE id IN ($inQuery) AND is_blocked = 0");

            // 绑定参数并执行更新
            $updateStmt->execute(array_values($usersToBan));

            // 提交封禁用户名到外部 API
            foreach ($bannedUsers as $userId) {
                // 获取用户名（假设有一个字段是 username）
                $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $username = $stmt->fetchColumn();

                // 提交封禁的用户名到外部 API
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => "https://ban.linzefeng.top/api/ban",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer 76e77db96658ff41af53d938c9f91599",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_POSTFIELDS => json_encode(["username" => $username])
                ]);

                $response = curl_exec($curl);
                if (curl_errno($curl)) {
                    echo "[" . date('Y-m-d H:i:s') . "] 提交封禁用户名时发生错误：" . curl_error($curl) . "\n";
                }
                curl_close($curl);
            }

            echo "[" . date('Y-m-d H:i:s') . "] 已成功封禁用户: " . implode(',', $bannedUsers) . "\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] 没有新的封禁用户。\n";
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] 没有符合条件的用户需要封禁。\n";
    }
} catch (Exception $e) {
    // 输出错误信息
    echo "[" . date('Y-m-d H:i:s') . "] 封禁用户时发生错误：" . $e->getMessage() . "\n";
}
?>