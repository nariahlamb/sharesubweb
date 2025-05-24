<?php
// proxy.php - 生产环境下的鉴权和短链系统，只获取并原样输出内容

// 禁用详细错误报告（生产环境中避免显示错误信息）
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// 设置允许任意跨站请求的 CORS 头
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 仅允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    header('Content-Type: text/plain; charset=UTF-8');
    echo '仅支持 GET 请求。';
    exit;
}

require_once 'utils.php';

// 获取并验证输入参数
$uuid = $_GET['uuid'] ?? '';
$sid = $_GET['sid'] ?? '';
$target = $_GET['target'] ?? '';

if (empty($uuid)) {
    http_response_code(400); // Bad Request
    header('Content-Type: text/plain; charset=UTF-8');
    echo '请求无效。';
    exit;
}

if ($sid !== 'all') {
    if (!is_numeric($sid) || intval($sid) <= 0) {
        http_response_code(400); // Bad Request
        header('Content-Type: text/plain; charset=UTF-8');
        echo '请求无效。';
        exit;
    } else {
        $sid = intval($sid);
    }
}

// 获取用户真实 IP，优先从 Cloudflare 的 CF-Connecting-IP 获取
$ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP']
             ?? $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['REMOTE_ADDR'];

try {
    $pdo = getDbConnection();

    // 获取用户信息
    $stmt = $pdo->prepare("SELECT id, is_blocked FROM users WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404); // Not Found
        header('Content-Type: text/plain; charset=UTF-8');
        echo '用户未找到。';
        exit;
    }

    // 检查用户是否被封禁
    if ($user['is_blocked']) {
        http_response_code(403); // Forbidden
        header('Content-Type: text/plain; charset=UTF-8');
        echo '您的账户已被禁用。';
        exit;
    }

    $user_id = $user['id'];

    // 获取客户端的 User-Agent，如果不存在则使用模拟 Clash 请求的 User-Agent
    $clientUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $userAgent = !empty($clientUserAgent) ? $clientUserAgent : "Clash/1.0";

    if ($sid === 'all') {
        // 处理 sid=all 的情况

        // 如果未设置 target 参数，默认为 'clash'
        if (empty($target)) {
            $target = 'clash';
        }

        // 实现缓存机制
        $cacheKey = "cache_sid_all_target_" . md5($target);
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;

        // 检查缓存是否存在且未过期（1小时）
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
            // 从缓存中读取
            $cacheData = unserialize(file_get_contents($cacheFile));
            $content = $cacheData['content'];
            $contentType = $cacheData['contentType'];
        } else {
            // 获取所有订阅链接
            $stmt = $pdo->prepare("SELECT id, link FROM subscriptions");
            $stmt->execute();
            $subscriptions = $stmt->fetchAll();

            if (!$subscriptions) {
                http_response_code(404); // Not Found
                header('Content-Type: text/plain; charset=UTF-8');
                echo '没有可用的订阅。';
                exit;
            }

            // 构建拼接后的链接
            $encodedUrls = [];
            foreach ($subscriptions as $sub) {
                $encodedUrls[] = urlencode($sub['link']);
            }
            $concatenatedUrls = implode('%7C', $encodedUrls); // %7C is URL encoded '|'

            // 后端服务列表（添加负载均衡）
            $backendServers = [
                'https://zuanhuan.linzefeng.top/sub'
            ];

            // 选择后端服务（简单的轮询或随机选择）
            $backendUrl = $backendServers[array_rand($backendServers)];

            // 构建后端请求 URL
            $backendUrl .= "?target=" . urlencode($target) . "&url=" . $concatenatedUrls;

            // 初始化 cURL 会话
            $ch = curl_init();

            // 设置 cURL 选项
            curl_setopt($ch, CURLOPT_URL, $backendUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 设置超时时间（秒）
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 允许重定向

            // 启用 SSL 证书验证（生产环境中确保安全）
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // 执行 cURL 请求
            $content = curl_exec($ch);

            // 检查 cURL 请求是否成功
            if ($content === false) {
                $error = curl_error($ch);
                $errorNo = curl_errno($ch);
                error_log("cURL 错误 ({$errorNo}): {$error}");
                curl_close($ch);

                // 尝试另一个后端服务器
                $otherBackendUrl = $backendServers[1 - array_search($backendUrl, $backendServers)];
                $otherBackendUrl .= "?target=" . urlencode($target) . "&url=" . $concatenatedUrls;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $otherBackendUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

                $content = curl_exec($ch);

                if ($content === false) {
                    $error = curl_error($ch);
                    $errorNo = curl_errno($ch);
                    error_log("备用后端 cURL 错误 ({$errorNo}): {$error}");
                    curl_close($ch);
                    http_response_code(502); // Bad Gateway
                    header('Content-Type: text/plain; charset=UTF-8');
                    echo '';
                    exit;
                }

                // 获取 Content-Type
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

                curl_close($ch);

                // 将结果保存到缓存
                $cacheData = ['content' => $content, 'contentType' => $contentType];
                file_put_contents($cacheFile, serialize($cacheData));
            } else {
                // 获取 Content-Type
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

                curl_close($ch);

                // 将结果保存到缓存
                $cacheData = ['content' => $content, 'contentType' => $contentType];
                file_put_contents($cacheFile, serialize($cacheData));
            }
        }

        // 设置 Content-Type
        if ($contentType) {
            header("Content-Type: {$contentType}");
        } else {
            header('Content-Type: application/octet-stream');
        }

        // 防止缓存
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        // 输出内容
        echo $content;

        // 记录订阅次数
        $pdo->beginTransaction();

        try {
            foreach ($subscriptions as $sub) {
                $subId = $sub['id'];

                // 更新 subscriptions 表中的总订阅次数
                $stmt = $pdo->prepare("UPDATE subscriptions SET total_subs = total_subs + 1 WHERE id = ?");
                $stmt->execute([$subId]);

                // 记录用户订阅使用
                $stmt = $pdo->prepare("SELECT id FROM user_subscriptions WHERE user_id = ? AND subscription_id = ?");
                $stmt->execute([$user_id, $subId]);
                $userSub = $stmt->fetch();

                if ($userSub) {
                    $stmt = $pdo->prepare("UPDATE user_subscriptions SET sub_count = sub_count + 1, last_used = NOW() WHERE id = ?");
                    $stmt->execute([$userSub['id']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO user_subscriptions (user_id, subscription_id, sub_count, last_used) VALUES (?, ?, 1, NOW())");
                    $stmt->execute([$user_id, $subId]);
                }

                // 记录 IP 地址
                $stmt = $pdo->prepare("INSERT IGNORE INTO user_ips (user_id, subscription_id, ip_address) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $subId, $ipAddress]);

                // 统计不同的 IP 数量
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip_address) AS unique_ip_count FROM user_ips WHERE user_id = ? AND subscription_id = ?");
                $stmt->execute([$user_id, $subId]);
                $uniqueIpCount = $stmt->fetchColumn();

                // 更新 unique_ip_count 字段
                $stmt = $pdo->prepare("UPDATE user_subscriptions SET unique_ip_count = ? WHERE user_id = ? AND subscription_id = ?");
                $stmt->execute([$uniqueIpCount, $user_id, $subId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("记录订阅信息时发生错误: " . $e->getMessage());
            http_response_code(500); // Internal Server Error
            header('Content-Type: text/plain; charset=UTF-8');
            echo "";
            exit;
        }

    } else {
        // 处理单个订阅

        // 获取订阅信息
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$sid]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            http_response_code(404); // Not Found
            header('Content-Type: text/plain; charset=UTF-8');
            echo '订阅未找到。';
            exit;
        }

        $result = fetchContent($subscription['link'], $userAgent);
        $content = $result['content'];
        $contentType = $result['contentType'];

        // 设置 Content-Type，如果未获取到，则使用默认值
        if ($contentType) {
            header("Content-Type: {$contentType}");
        } else {
            header('Content-Type: application/octet-stream');
        }

        // 防止缓存
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        // 直接输出内容，不做任何处理
        echo $content;

        // 记录订阅次数
        $pdo->beginTransaction();

        try {
            // 更新 subscriptions 表中的总订阅次数
            $stmt = $pdo->prepare("UPDATE subscriptions SET total_subs = total_subs + 1 WHERE id = ?");
            $stmt->execute([$sid]);

            // 记录用户订阅使用
            $stmt = $pdo->prepare("SELECT * FROM user_subscriptions WHERE user_id = ? AND subscription_id = ?");
            $stmt->execute([$user_id, $sid]);
            $userSub = $stmt->fetch();

            if ($userSub) {
                $stmt = $pdo->prepare("UPDATE user_subscriptions SET sub_count = sub_count + 1, last_used = NOW() WHERE id = ?");
                $stmt->execute([$userSub['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO user_subscriptions (user_id, subscription_id, sub_count, last_used) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$user_id, $sid]);
            }

            // 记录 IP 地址
            $stmt = $pdo->prepare("INSERT IGNORE INTO user_ips (user_id, subscription_id, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $sid, $ipAddress]);

            // 统计不同的 IP 数量
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip_address) AS unique_ip_count FROM user_ips WHERE user_id = ? AND subscription_id = ?");
            $stmt->execute([$user_id, $sid]);
            $uniqueIpCount = $stmt->fetchColumn();

            // 更新 unique_ip_count 字段
            $stmt = $pdo->prepare("UPDATE user_subscriptions SET unique_ip_count = ? WHERE user_id = ? AND subscription_id = ?");
            $stmt->execute([$uniqueIpCount, $user_id, $sid]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("记录订阅信息时发生错误: " . $e->getMessage());
            http_response_code(500); // Internal Server Error
            header('Content-Type: text/plain; charset=UTF-8');
            echo "";
            exit;
        }
    }
} catch (Exception $e) {
    error_log("数据库连接或查询错误: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    header('Content-Type: text/plain; charset=UTF-8');
    echo "服务器内部错误";
    exit;
}

// 定义 fetchContent 函数
function fetchContent($url, $userAgent) {
    // 初始化 cURL 会话
    $ch = curl_init();

    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 设置超时时间（秒）
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 允许重定向

    // 启用 SSL 证书验证（生产环境中确保安全）
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    // 执行 cURL 请求
    $content = curl_exec($ch);

    // 检查 cURL 请求是否成功
    if ($content === false) {
        $error = curl_error($ch);
        $errorNo = curl_errno($ch);
        error_log("cURL 错误 ({$errorNo}): {$error}");
        curl_close($ch);
        http_response_code(502); // Bad Gateway
        header('Content-Type: text/plain; charset=UTF-8');
        echo '';
        exit;
    }

    // 获取 HTTP 状态码
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 获取 Content-Type
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    // 检查 HTTP 状态码
    if ($httpCode !== 200) {
        error_log("订阅链接返回的 HTTP 状态码: {$httpCode}");
        http_response_code(502); // Bad Gateway
        header('Content-Type: text/plain; charset=UTF-8');
        echo '';
        exit;
    }

    return ['content' => $content, 'contentType' => $contentType];
}
?>
