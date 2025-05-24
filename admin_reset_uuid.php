<?php
require_once 'utils.php';
checkAdminAuth();

header('Content-Type: application/json');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少用户ID']);
    exit;
}

$userId = $_POST['id'];
$pdo = getDbConnection();

try {
    // 生成10位随机UUID（小写字母和数字）
    $newUuid = substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 3)), 0, 10);
    
    $stmt = $pdo->prepare('UPDATE users SET uuid = ? WHERE id = ?');
    $success = $stmt->execute([$newUuid, $userId]);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'UUID重置成功',
            'newUuid' => $newUuid
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '更新数据库失败'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '操作失败：' . $e->getMessage()
    ]);
}
?>