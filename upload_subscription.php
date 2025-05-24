<?php
// api/upload_subscription.php
require_once 'utils.php';
require 'csrf.php';

function validateSubscriptionLink($link) {
    // 移除空白字符
    $link = trim($link);

    // 检查链接是否为空
    if (empty($link)) {
        return [false, "Subscription link cannot be empty"];
    }

    // 解析 URL
    $parsedUrl = parse_url($link);
    if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
        return [false, "Invalid URL format"];
    }

    $scheme = strtolower($parsedUrl['scheme']);

    // 支持的协议（前缀）列表，包括 hysteria2://
    $supportedSchemes = [
        'ss',          // Shadowsocks
        'ssr',         // ShadowsocksR
        'vmess',       // VMess
        'vless',       // VLESS
        'trojan',      // Trojan
        'socks',       // SOCKS5（有些使用 'socks' 而非 'socks5'）
        'socks5',      // SOCKS5
        'http',        // HTTP
        'https',       // HTTPS
        'hysteria',    // Hysteria（hy1）
        'hy',          // Hysteria（备用前缀）
        'hy1',         // Hysteria1
        'hy2',         // Hysteria2
        'hysteria2'    // Hysteria2
    ];

    // 检查协议是否在支持列表中
    if (!in_array($scheme, $supportedSchemes)) {
        return [false, "Unsupported subscription protocol: $scheme"];
    }

    // 可选：检查链接是否以 scheme:// 开头
    if (strpos($link, $scheme . '://') !== 0) {
        return [false, "Invalid link format for protocol: $scheme"];
    }

    // 一切正常
    return [true, "Valid subscription link"];
}

header('Content-Type: application/json');
// 检查用户是否已认证
checkAuth();
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证 CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(["error" => "CSRF validation failed"]);
        exit;
    }

    // 获取并验证输入
    $name = trim($_POST['name'] ?? '');
    $source = trim($_POST['source'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $availableTraffic = floatval($_POST['available_traffic'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    $expirationDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;

    // 验证必填字段
    if (empty($name) || empty($link)) {
        http_response_code(400);
        echo json_encode(["error" => "Name and link are required"]);
        exit;
    }

    // 验证订阅链接格式
    list($isValid, $message) = validateSubscriptionLink($link);
    if (!$isValid) {
        http_response_code(400);
        echo json_encode(["error" => $message]);
        exit;
    }

    try {
        // 尝试插入数据库
        $stmt = $pdo->prepare("INSERT INTO subscriptions (name, source, link, available_traffic, remark, expiration_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $source, $link, $availableTraffic, $remark, $expirationDate, $_SESSION['user_id']]);
        
        http_response_code(201);
        echo json_encode(["message" => "Subscription link uploaded successfully"]);
    } catch (PDOException $e) {
        // 检查是否违反了唯一约束（重复的链接）
        if ($e->getCode() == '23000') { // 23000 是通用的唯一约束违例错误码，实际错误码可能需要从 $e->errorInfo 获取
            http_response_code(400);
            echo json_encode(["error" => "This subscription link already exists"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to upload subscription link"]);
            // 可以在日志中记录详细的错误信息
            error_log("Database error: " . $e->getMessage());
        }
    }
} else {
    http_response_code(405);
    echo json_encode(["error" => "Invalid request method"]);
}
?>