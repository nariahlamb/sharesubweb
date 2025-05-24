<?php

// 检测直接访问 - 在文件最顶部添加这段代码
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    // 检查是否来自正常的 API 调用
    $isApiCall = false;
    
    // 检查 HTTP 头部判断是否为 AJAX 请求或 API 客户端
    if (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        !empty($_GET['action']) // 有API动作参数
    ) {
        $isApiCall = true;
    }
    
    // 如果不是合法的 API 调用，则立即断开连接
    if (!$isApiCall) {
        // 清除所有缓冲区
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 发送 444 状态码并立即断开连接
        header("HTTP/1.1 444 Connection Closed Without Response");
        header("Connection: close");
        ignore_user_abort(true); // 不等待浏览器断开连接
        ob_start();
        echo ""; // 空输出
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush(); // 发送所有内容到客户端
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // 如果可用，使用 FastCGI 立即结束请求
        }
        exit();
    }
}
/**
 * 优化后的login.php文件
 * 包含DDoS防护、性能优化和安全增强
 */

// 在任何输出前启用输出缓冲
ob_start();

// 导入DDoS防护类
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

// 设置响应头
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// 初始化DDoS防护
$ddosConfig = [
    'request_limit' => 30,        // 每个时间窗口允许的请求数
    'time_window' => 60,          // 时间窗口（秒）
    'ban_time' => 900,            // 封禁时间（秒）
    'cache_time' => 300,          // 缓存时间（秒）
    // 可以在此添加白名单IP
    'whitelist' => ['127.0.0.1']  // 本地测试IP
];

$ddos = new AntiDDoS($ddosConfig);

// 执行DDoS防护检查（如果失败，会自动中断请求）
$ddos->protect();

// 设置执行时间限制
set_time_limit(10);

/**
 * 主要处理逻辑
 */
try {
    // 生成缓存键
    $cacheKey = 'login_' . md5($ddos->getClientIP() . '_' . session_id());
    $response = [];
    
    // 非强制刷新请求，尝试使用缓存
    if (empty($_GET['force_refresh']) && empty($_GET['action'])) {
        $cachedResponse = $ddos->getCache($cacheKey);
        if ($cachedResponse !== false) {
            echo json_encode($cachedResponse);
            ob_end_flush();
            exit();
        }
    }
    
    // 检查用户是否已登录
    if (!empty($_SESSION['user_id'])) {
        $response = [
            'redirect' => 'display.html',
            'status' => 'logged_in',
            'cached' => false
        ];
        
        // 缓存登录状态
        $ddos->setCache($cacheKey, $response);
        
        echo json_encode($response);
        ob_end_flush();
        exit();
    }
    
    // 处理登录请求
    if (isset($_GET['action']) && $_GET['action'] === 'login') {
        try {
            // 生成安全的随机state参数
            $state = bin2hex(random_bytes(16));
            $_SESSION['oauth_state'] = $state;
            $_SESSION['oauth_state_time'] = time(); // 记录创建时间，防止重放攻击
            
            // 构建OAuth2授权URL
            $authUrl = $config['oauth2']['authorizationEndpoint'] . '?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $config['oauth2']['clientId'],
                'redirect_uri' => $config['oauth2']['redirectUri'],
                'scope' => 'read', // 根据实际需求调整
                'state' => $state,
            ]);
            
            $response = [
                'authUrl' => $authUrl, 
                'status' => 'auth_redirect',
                'timestamp' => time()
            ];
            
            echo json_encode($response);
            ob_end_flush();
            exit();
        } catch (Exception $e) {
            error_log('OAuth2 URL generation error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => '生成登录链接时出错', 
                'status' => 'error'
            ]);
            ob_end_flush();
            exit();
        }
    }
    
    // 默认返回主页信息
    $response = [
        'message' => '欢迎来到linux do订阅分享平台，通过OAuth2登录并开始分享您的订阅链接。',
        'loginUrl' => '?action=login',
        'status' => 'not_logged_in',
        'timestamp' => time(),
        'cached' => false
    ];
    
    // 缓存结果
    $ddos->setCache($cacheKey, $response);
    
    echo json_encode($response);
} catch (Exception $e) {
    // 记录错误但不暴露详细信息给用户
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => '服务器内部错误', 
        'status' => 'error',
        'retry_after' => 5 // 建议客户端5秒后重试
    ]);
}

// 确保所有输出都被发送
ob_end_flush();