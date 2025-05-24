<?php
// admin_check_subscription.php
require_once 'utils.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// 验证输入
$subId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$subId) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid subscription ID']));
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_PERSISTENT, true);
    
    $stmt = $pdo->prepare("SELECT link FROM subscriptions WHERE id = ? LIMIT 1");
    $stmt->execute([$subId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sub) {
        http_response_code(404);
        exit(json_encode(['error' => '订阅不存在']));
    }

    // 设置 ClashMeta 的 User-Agent
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'method' => 'GET',
            'header' => [
                'User-Agent: ClashMeta/v1.14.5',
                'Accept: */*'
            ]
        ]
    ]);

    $link = $sub['link'];
    $response = @file_get_contents($link, false, $ctx);
    
    if ($response === false) {
        exit(json_encode([
            'status' => 'error',
            'message' => '订阅不可用',
            'code' => 'LINK_ERROR'
        ]));
    }

    $isActive = !empty($response);

    echo json_encode([
        'status' => 'success',
        'message' => $isActive ? '订阅可用' : '订阅不可用',
        'code' => $isActive ? 'ACTIVE' : 'INACTIVE'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log($e->getMessage());
    exit(json_encode(['error' => '数据库错误']));
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    exit(json_encode(['error' => '系统错误']));
}