<?php
// admin_batch_check.php

require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT id, name, link FROM subscriptions");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inactiveSubs = [];
    $html = '<ul>';

    // 初始化cURL多线程处理
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $results = [];

    // 设置最大并发请求数量，可以根据服务器性能进行调整
    $maxConcurrency = 10;
    $activeConnections = 0;
    $queue = [];

    // 将所有订阅添加到队列
    foreach ($subscriptions as $key => $sub) {
        $queue[] = ['key' => $key, 'sub' => $sub];
    }

    // 开始处理队列中的订阅
    while (!empty($queue) || $activeConnections > 0) {
        // 添加新的cURL请求，直到达到最大并发数
        while (!empty($queue) && $activeConnections < $maxConcurrency) {
            $item = array_shift($queue);
            $key = $item['key'];
            $sub = $item['sub'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sub['link']);
            curl_setopt($ch, CURLOPT_NOBODY, true); // 只获取响应头
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 设置执行超时时间
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 连接超时
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 跳过SSL验证
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'clash.meta'); // 设置User-Agent

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[(int)$ch] = ['handle' => $ch, 'key' => $key, 'sub' => $sub];
            $activeConnections++;
        }

        // 执行cURL批处理
        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        // 处理完成的请求
        while ($completed = curl_multi_info_read($multiHandle)) {
            $ch = $completed['handle'];
            $info = curl_getinfo($ch);
            $error = curl_error($ch);

            $handleData = $curlHandles[(int)$ch];
            $key = $handleData['key'];
            $sub = $handleData['sub'];

            $isActive = !$error && $info['http_code'] >= 200 && $info['http_code'] < 400;
            $statusText = $isActive ? '可用' : '不可用';
            $html .= '<li>' . htmlspecialchars($sub['name']) . ' - ' . $statusText . '</li>';

            if (!$isActive) {
                $inactiveSubs[] = $sub['id'];
            }

            // 移除并关闭cURL句柄
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
            unset($curlHandles[(int)$ch]);
            $activeConnections--;
        }

        // 暂停以避免CPU占用过高
        if ($running) {
            curl_multi_select($multiHandle, 0.5);
        }
    }

    // 关闭cURL多线程句柄
    curl_multi_close($multiHandle);

    $html .= '</ul>';

    // 将不活跃的订阅ID存入会话，供后续使用
    $_SESSION['inactive_subscriptions'] = $inactiveSubs;

    echo json_encode(['html' => $html]);
    exit();
}
