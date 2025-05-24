<?php
/**
 * 优化防DDoS的OAuth回调处理文件
 * 增加了请求限流、错误处理、安全验证和性能优化
 */

// 启用输出缓冲
ob_start();

// 加载防DDoS保护文件
require_once 'anti_ddos.php';

// 会话安全配置
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

// 启动会话
session_start();

require_once 'utils.php';
$config = require 'config.php';

// 初始化DDoS防护
$ddosConfig = [
    'request_limit' => 10,      // 回调接口更严格的限流
    'time_window' => 60,        // 60秒窗口
    'ban_time' => 1800,         // 30分钟封禁
    'cache_time' => 300,        // 5分钟缓存
    'whitelist' => ['127.0.0.1'] // 本地IP白名单
];

$ddos = new AntiDDoS($ddosConfig);

// 执行DDoS防护检查
$ddos->protect();

// 设置执行时间限制，OAuth交互可能需要更多时间
set_time_limit(15);

/**
 * 显示错误页面并记录日志
 * 
 * @param string $message 错误消息
 * @param int $code HTTP状态码
 * @return void
 */
function showError($message, $code = 400) {
    global $ddos;
    
    // 记录错误
    error_log("OAuth回调错误: " . $message);
    
    // 设置HTTP状态码
    http_response_code($code);
    
    // 输出友好的错误页面
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>登录失败</title>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: linear-gradient(to right, #6a11cb, #2575fc);
                color: white;
                text-align: center;
                padding: 50px;
                line-height: 1.6;
            }
            .container {
                background: rgba(0,0,0,0.4);
                border-radius: 8px;
                padding: 20px;
                max-width: 500px;
                margin: 0 auto;
            }
            h1 { color: #FF6B6B; }
            button {
                background: #4a90e2;
                border: none;
                color: white;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>登录失败</h1>
            <p>" . htmlspecialchars($message) . "</p>
            <button onclick=\"window.location.href='index.html'\">返回登录页</button>
        </div>
        <script>
            // 5秒后自动重定向
            setTimeout(function() {
                window.location.href = 'index.html';
            }, 5000);
        </script>
    </body>
    </html>";
    
    // 结束输出缓冲并退出
    ob_end_flush();
    exit();
}

// 主要处理逻辑
try {
    // 验证必要参数
    if (!isset($_GET['code']) || !isset($_GET['state'])) {
        showError("无效的回调请求：缺少必要参数", 400);
    }
    
    // 验证state防止CSRF攻击
    if (!isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
        showError("安全验证失败：无效的状态参数", 403);
    }
    
    // 检查state是否已过期（30分钟有效期）
    if (isset($_SESSION['oauth_state_time']) && 
        (time() - $_SESSION['oauth_state_time']) > 1800) {
        showError("认证请求已过期，请重新登录", 401);
    }
    
    // 清除state，防止重放攻击
    $state = $_SESSION['oauth_state'];
    unset($_SESSION['oauth_state']);
    if (isset($_SESSION['oauth_state_time'])) {
        unset($_SESSION['oauth_state_time']);
    }
    
    // 获取授权码
    $authCode = trim($_GET['code']);
    if (empty($authCode)) {
        showError("无效的授权码", 400);
    }
    
    // 获取访问令牌 - 添加错误处理和超时
    $postData = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $authCode,
        'redirect_uri' => $config['oauth2']['redirectUri'],
        'client_id' => $config['oauth2']['clientId'],
        'client_secret' => $config['oauth2']['clientSecret'],
    ]);
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => $postData,
            'timeout' => 10, // 10秒超时
            'ignore_errors' => true // 允许捕获HTTP错误响应
        ],
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($config['oauth2']['tokenEndpoint'], false, $context);
    
    // 检查HTTP响应
    if ($response === false) {
        $error = error_get_last();
        showError("无法连接到授权服务器: " . ($error['message'] ?? '未知错误'), 500);
    }
    
    // 获取HTTP状态码
    $httpStatus = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $header, $matches)) {
            $httpStatus = intval($matches[1]);
            break;
        }
    }
    
    if ($httpStatus >= 400) {
        showError("授权服务器返回错误: HTTP {$httpStatus}", 500);
    }
    
    // 解析响应
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        showError("解析授权服务器响应失败: " . json_last_error_msg(), 500);
    }
    
    // 验证访问令牌
    if (empty($data['access_token'])) {
        error_log("授权服务器响应: " . print_r($data, true));
        showError("无法获取访问令牌", 500);
    }
    
    $accessToken = $data['access_token'];
    
    // 获取用户信息 - 添加错误处理
    try {
        $userInfo = getOAuthUser($accessToken);
        
        if (empty($userInfo['id']) || empty($userInfo['username'])) {
            error_log("获取到的用户信息不完整: " . print_r($userInfo, true));
            showError("获取到的用户信息不完整", 500);
        }
    } catch (Exception $e) {
        showError("获取用户信息失败: " . $e->getMessage(), 500);
    }
    
    // 数据库连接 - 使用PDO异常模式并添加错误处理
    try {
        $pdo = getDbConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 使用事务确保数据完整性
        $pdo->beginTransaction();
        
        // 检查用户是否已存在
        $stmt = $pdo->prepare("SELECT * FROM users WHERE oauth_id = ?");
        $stmt->execute([$userInfo['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // 新用户，生成UUID
            $uuid = generateUUID();
            
            // 防止SQL注入
            $stmt = $pdo->prepare("INSERT INTO users (oauth_id, username, uuid) VALUES (?, ?, ?)");
            $stmt->execute([
                htmlspecialchars($userInfo['id']), 
                htmlspecialchars($userInfo['username']), 
                $uuid
            ]);
            
            $user_id = $pdo->lastInsertId();
            $user_uuid = $uuid;
        } else {
            $user_id = $user['id'];
            $user_uuid = $user['uuid'];
            
            // 检查用户是否被封禁
            if (isset($user['is_blocked']) && $user['is_blocked']) {
                // 记录封禁访问尝试
                error_log("封禁用户尝试登录: ID={$user_id}, Username={$userInfo['username']}");
                $pdo->commit(); // 提交事务
                showError("您的账户已被管理员禁用。如有疑问，请联系管理员。", 403);
            }
        }
        
        // 提交事务
        $pdo->commit();
        
    } catch (PDOException $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log("数据库操作失败: " . $e->getMessage());
        showError("数据库操作失败，请稍后再试", 500);
    }
    
    // 设置会话
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = htmlspecialchars($userInfo['username']);
    $_SESSION['uuid'] = $user_uuid;
    $_SESSION['login_time'] = time();
    $_SESSION['ip'] = $ddos->getClientIP();
    
    // 防止会话劫持，记录会话信息
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 记录登录成功
    error_log("用户登录成功: ID={$user_id}, Username={$userInfo['username']}, IP={$ddos->getClientIP()}");
    
    // 清除登录状态缓存
    $cacheKey = 'login_' . md5($ddos->getClientIP() . '_' . session_id());
    $ddos->clearCache($cacheKey);
    
    // 重定向到显示页面
    header("Location: display.html");
    exit();
    
} catch (Exception $e) {
    error_log("未捕获的异常: " . $e->getMessage());
    showError("处理登录请求时遇到错误，请稍后再试", 500);
}

// 确保所有输出被发送
ob_end_flush();