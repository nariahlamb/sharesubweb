<?php
// api/check_status.php
require_once 'utils.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// 获取任务ID
$taskId = $_GET['taskId'] ?? '';
if (empty($taskId)) {
    http_response_code(400);
    echo json_encode(["error" => "Task ID is required"]);
    exit;
}

try {
    $queueManager = new QueueManager();
    $status = $queueManager->getTaskStatus($taskId);
    
    switch ($status) {
        case 'pending':
            http_response_code(202);
            echo json_encode([
                "status" => "pending",
                "message" => "Request is waiting in queue"
            ]);
            break;
            
        case 'processing':
            http_response_code(202);
            echo json_encode([
                "status" => "processing",
                "message" => "Request is being processed"
            ]);
            break;
            
        case 'completed':
            $result = $queueManager->redis->hGet("task_status:{$taskId}", 'result');
            http_response_code(200);
            echo json_encode([
                "status" => "completed",
                "result" => json_decode($result, true)
            ]);
            break;
            
        case 'failed':
            $result = $queueManager->redis->hGet("task_status:{$taskId}", 'result');
            http_response_code(500);
            echo json_encode([
                "status" => "failed",
                "error" => json_decode($result, true)
            ]);
            break;
            
        case 'expired':
            http_response_code(410);
            echo json_encode([
                "status" => "expired",
                "message" => "Request has expired"
            ]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                "error" => "Task not found"
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Internal server error",
        "message" => $e->getMessage()
    ]);
}
?>