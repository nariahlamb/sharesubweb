<?php
// admin_batch_check.php
require_once 'utils.php';
checkAdminAuth();

class SubscriptionChecker {
    private $multiHandle;
    private $curlHandles = [];
    private $activeConnections = 0;
    private $queue = [];
    private $results = [];
    private $maxConcurrency;
    private $zeroTrafficIds = []; // 用于存储流量耗尽的订阅ID
    private $inactiveIds = []; // 用于存储不可用的订阅ID
    
    public function __construct(int $maxConcurrency = 20) {
        $this->maxConcurrency = $maxConcurrency;
        $this->multiHandle = curl_multi_init();
        curl_multi_setopt($this->multiHandle, CURLMOPT_MAXCONNECTS, $maxConcurrency);
    }

    private function isValidUrl(string $url): bool {
        if (empty($url)) return false;
        $url = strtolower($url);
        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
    }

    public function addSubscription(array $subscription): void {
        if (!isset($subscription['id'], $subscription['name'], $subscription['link'])) {
            throw new InvalidArgumentException('订阅数据格式不正确');
        }
        
        // 如果不是有效的URL格式，直接标记为可用并返回500ms
        if (!$this->isValidUrl($subscription['link'])) {
            $this->results[] = [
                'id' => $subscription['id'],
                'name' => $subscription['name'],
                'active' => true,
                'error' => '',
                'http_code' => 200,
                'time' => 500,
                'traffic_info' => null
            ];
            return;
        }
        
        $this->queue[] = $subscription;
    }
    
    private function initializeCurlHandle(array $sub) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $sub['link'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'ClashForAndroid/2.5.12',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Connection: keep-alive'
            ],
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADER => true
        ]);
        return $ch;
    }
    
    private function parseUserInfo(string $headerContent): array {
        $info = [];
        $pattern = '/[Ss]ubscription-[Uu]serinfo:\s*([^\r\n]+)/';
        
        if (preg_match($pattern, $headerContent, $matches)) {
            $userInfoStr = trim($matches[1]);
            $pairs = explode(';', $userInfoStr);
            foreach ($pairs as $pair) {
                if (strpos($pair, '=') !== false) {
                    list($key, $value) = explode('=', $pair, 2);
                    $info[trim($key)] = trim($value);
                }
            }
        }
        
        return $info;
    }
    
    private function formatBytes($bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, (int)$bytes);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function isTrafficExhausted(array $trafficInfo): bool {
        if (empty($trafficInfo)) {
            return false;
        }
        
        if (isset($trafficInfo['剩余流量'])) {
            $remaining = strtolower($trafficInfo['剩余流量']);
            
            $isZero = strpos($remaining, '0 b') === 0 || 
                     strpos($remaining, '0 kb') === 0 || 
                     strpos($remaining, '0 mb') === 0 || 
                     strpos($remaining, '0 gb') === 0 || 
                     $remaining === '0 b' || 
                     $remaining === '0 kb' || 
                     $remaining === '0 mb' || 
                     $remaining === '0 gb' || 
                     $remaining === '0.00 b' || 
                     $remaining === '0.00 kb' || 
                     $remaining === '0.00 mb' || 
                     $remaining === '0.00 gb';
            
            return $isZero;
        }
        
        if (isset($trafficInfo['total']) && (isset($trafficInfo['upload']) || isset($trafficInfo['download']))) {
            $total = (float)$trafficInfo['total'];
            $used = (float)($trafficInfo['upload'] ?? 0) + (float)($trafficInfo['download'] ?? 0);
            $threshold = 1; // 1 byte
            return ($total <= $used + $threshold) || $total <= 0;
        }
        
        return false;
    }
    
    private function processTrafficInfo(array $rawInfo): array {
        $trafficInfo = [];
        
        // 处理总流量
        if (isset($rawInfo['total'])) {
            $trafficInfo['总流量'] = $this->formatBytes($rawInfo['total']);
            $trafficInfo['total_bytes'] = (int)$rawInfo['total'];
        }
        
        // 计算剩余流量
        if (isset($rawInfo['total']) && (isset($rawInfo['upload']) || isset($rawInfo['download']))) {
            $total = (int)$rawInfo['total'];
            $used = (int)($rawInfo['upload'] ?? 0) + (int)($rawInfo['download'] ?? 0);
            $remaining = max(0, $total - $used);
            $trafficInfo['剩余流量'] = $this->formatBytes($remaining);
            $trafficInfo['remaining_bytes'] = $remaining;
        }
        
        // 处理到期时间
        if (isset($rawInfo['expire'])) {
            $expireTimestamp = (int)$rawInfo['expire'];
            if ($expireTimestamp > 0) {
                $trafficInfo['到期时间'] = date('Y-m-d', $expireTimestamp);
                
                // 计算剩余天数
                $now = time();
                if ($expireTimestamp > $now) {
                    $daysLeft = ceil(($expireTimestamp - $now) / 86400);
                    $trafficInfo['剩余'] = $daysLeft . '天';
                } else {
                    $trafficInfo['剩余'] = '已过期';
                }
            }
        }
        
        return $trafficInfo;
    }
    
    private function processCompletedRequest($ch, array $handleData): void {
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $content = curl_multi_getcontent($ch);
        
        // 分离响应头和响应体
        $headerSize = $info['header_size'];
        $header = substr($content, 0, $headerSize);
        $body = substr($content, $headerSize);
        
        // 判断是否可用：HTTP状态码正常且有响应内容
        $isActive = !$error && 
                   $info['http_code'] >= 200 && 
                   $info['http_code'] < 400 && 
                   !empty($body);
        
        // 解析流量信息
        $trafficInfo = null;
        $userInfo = $this->parseUserInfo($header);
        
        if (!empty($userInfo)) {
            $trafficInfo = $this->processTrafficInfo($userInfo);
            
            // 检查流量是否耗尽，如果耗尽则标记为不可用
            if ($isActive && $this->isTrafficExhausted($trafficInfo)) {
                $this->zeroTrafficIds[] = $handleData['sub']['id'];
                $isActive = false; // 流量耗尽的订阅标记为不可用
            }
        } else {
            // 备用解析方法
            $altPattern = '/subscription-userinfo:\s*([^\r\n]+)/i';
            if (preg_match($altPattern, $header, $matches)) {
                $userInfoStr = trim($matches[1]);
                $pairs = explode(';', $userInfoStr);
                $extractedInfo = [];
                
                foreach ($pairs as $pair) {
                    if (strpos($pair, '=') !== false) {
                        list($key, $value) = explode('=', $pair, 2);
                        $extractedInfo[trim($key)] = trim($value);
                    }
                }
                
                if (!empty($extractedInfo)) {
                    $trafficInfo = $this->processTrafficInfo($extractedInfo);
                    
                    if ($isActive && $this->isTrafficExhausted($trafficInfo)) {
                        $this->zeroTrafficIds[] = $handleData['sub']['id'];
                        $isActive = false;
                    }
                }
            }
        }
        
        // 如果订阅不可用，将ID添加到不可用ID列表
        if (!$isActive) {
            $this->inactiveIds[] = $handleData['sub']['id'];
        }
        
        // 添加订阅ID到结果中
        if ($trafficInfo) {
            $trafficInfo['sub_id'] = $handleData['sub']['id'];
        }
        
        $this->results[] = [
            'id' => $handleData['sub']['id'],
            'name' => $handleData['sub']['name'],
            'active' => $isActive,
            'error' => $error,
            'http_code' => $info['http_code'],
            'time' => round($info['total_time'] * 1000),
            'traffic_info' => $trafficInfo
        ];
        
        curl_multi_remove_handle($this->multiHandle, $ch);
        curl_close($ch);
        unset($this->curlHandles[(int)$ch]);
        $this->activeConnections--;
    }
    
    private function addNewConnections(): void {
        while (!empty($this->queue) && $this->activeConnections < $this->maxConcurrency) {
            $sub = array_shift($this->queue);
            $ch = $this->initializeCurlHandle($sub);
            curl_multi_add_handle($this->multiHandle, $ch);
            $this->curlHandles[(int)$ch] = ['handle' => $ch, 'sub' => $sub];
            $this->activeConnections++;
        }
    }
    
    private function processCompletedHandles(): void {
        while ($completed = curl_multi_info_read($this->multiHandle)) {
            $ch = $completed['handle'];
            $this->processCompletedRequest($ch, $this->curlHandles[(int)$ch]);
        }
    }
    
    private function formatResults(int $totalTime): array {
        $html = '<form id="subscription-form" method="POST" action="">';
        $html .= '<ul class="space-y-2">';
        $inactiveIds = [];
        $totalRequests = count($this->results);
        $failedRequests = 0;
        
        foreach ($this->results as $result) {
            $statusClass = $result['active'] ? 'text-green-600' : 'text-red-600';
            $statusText = $result['active'] ? '可用' : '不可用';
            
            if (!$result['active']) {
                $inactiveIds[] = $result['id'];
                $failedRequests++;
            }
            
            // 流量信息展示
            $trafficHtml = '';
            if (!empty($result['traffic_info'])) {
                $trafficHtml = '<div class="text-sm mt-1 flex flex-wrap">';
                
                // 优先展示剩余流量
                if (isset($result['traffic_info']['剩余流量'])) {
                    $trafficHtml .= sprintf(
                        '<span class="mr-3 px-2 py-1 bg-blue-100 rounded font-medium">剩余流量: %s</span>', 
                        $result['traffic_info']['剩余流量']
                    );
                }
                
                // 展示总流量
                if (isset($result['traffic_info']['总流量'])) {
                    $trafficHtml .= sprintf(
                        '<span class="mr-3 px-2 py-1 bg-gray-100 rounded">总流量: %s</span>', 
                        $result['traffic_info']['总流量']
                    );
                }
                
                // 展示到期时间和剩余天数
                if (isset($result['traffic_info']['到期时间'])) {
                    $expiryClass = isset($result['traffic_info']['剩余']) && $result['traffic_info']['剩余'] === '已过期' 
                        ? 'bg-red-100' 
                        : 'bg-green-100';
                        
                    $trafficHtml .= sprintf(
                        '<span class="mr-3 px-2 py-1 %s rounded">到期: %s (%s)</span>', 
                        $expiryClass,
                        $result['traffic_info']['到期时间'],
                        $result['traffic_info']['剩余'] ?? ''
                    );
                }
                
                $trafficHtml .= '</div>';
            } else {
                $trafficHtml = '<div class="text-sm text-gray-500 mt-1">无流量信息</div>';
            }
            
            // 为不可用或流量耗尽的订阅添加保留选项
            $keepCheckbox = '';
            if (!$result['active'] || in_array($result['id'], $this->zeroTrafficIds)) {
                $keepCheckbox = sprintf(
                    '<div class="mt-1">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="keep_sub[]" value="%s" class="keep-sub-checkbox form-checkbox h-4 w-4 text-blue-600 rounded border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">保留此订阅</span>
                        </label>
                    </div>',
                    $result['id']
                );
            }
            
            $html .= sprintf(
                '<li class="flex flex-col p-2 border rounded mb-2">
                    <div class="flex justify-between items-center">
                        <span class="font-medium">%s</span>
                        <span class="%s">%s (%dms)</span>
                    </div>
                    %s
                    %s
                </li>',
                htmlspecialchars($result['name']),
                $statusClass,
                $statusText,
                $result['time'],
                $trafficHtml,
                $keepCheckbox
            );
        }
        $html .= '</ul>';
        
        // 添加全选/取消全选按钮
        $controlButtons = '
        <div class="mb-4 flex space-x-4">
            <button type="button" id="select-all-keep" class="px-3 py-1 bg-blue-100 rounded text-sm">全选保留</button>
            <button type="button" id="deselect-all-keep" class="px-3 py-1 bg-gray-100 rounded text-sm">取消全选</button>
        </div>';

        // 移除提交按钮，只保留隐藏字段和表单封装
        $hiddenFields = '
        <div>
            <input type="hidden" name="action" value="process_deletions">
            <input type="hidden" name="check_completed" value="1">
        </div>';
        
        // 添加JavaScript处理全选/取消全选
        $javascript = '
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const selectAllBtn = document.getElementById("select-all-keep");
                const deselectAllBtn = document.getElementById("deselect-all-keep");
                const checkboxes = document.querySelectorAll(".keep-sub-checkbox");
                
                if (selectAllBtn) {
                    selectAllBtn.addEventListener("click", function() {
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = true;
                        });
                    });
                }
                
                if (deselectAllBtn) {
                    deselectAllBtn.addEventListener("click", function() {
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = false;
                        });
                    });
                }
            });
        </script>';
        
        $html .= $controlButtons . $hiddenFields . '</form>' . $javascript;
        
        return [
            'html' => $html,
            'inactive_ids' => $this->inactiveIds,
            'zero_traffic_ids' => $this->zeroTrafficIds,
            'stats' => [
                'total' => $totalRequests,
                'failed' => $failedRequests,
                'zero_traffic' => count($this->zeroTrafficIds),
                'success_rate' => $totalRequests ? round(($totalRequests - $failedRequests) * 100 / $totalRequests, 1) : 0,
                'total_time_ms' => $totalTime,
                'avg_time_ms' => $totalRequests ? round($totalTime / $totalRequests) : 0
            ]
        ];
    }
    
    public function check(): array {
        if (empty($this->queue) && empty($this->results)) {
            $totalTime = 0;
            return $this->formatResults($totalTime);
        }
        
        $startTime = microtime(true);
        
        while (!empty($this->queue) || $this->activeConnections > 0) {
            $this->addNewConnections();
            
            do {
                $status = curl_multi_exec($this->multiHandle, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            
            $this->processCompletedHandles();
            
            if ($running) {
                curl_multi_select($this->multiHandle, 0.01);
            }
        }
        
        $totalTime = round((microtime(true) - $startTime) * 1000);
        curl_multi_close($this->multiHandle);
        
        $formattedResults = $this->formatResults($totalTime);
        $formattedResults['results'] = $this->results; // 添加原始结果数据
        
        return $formattedResults;
    }
    
    public function getZeroTrafficIds(): array {
        return $this->zeroTrafficIds;
    }
    
    public function getInactiveIds(): array {
        return $this->inactiveIds;
    }
}

try {
    $pdo = getDbConnection();
    
    // 检查请求参数
    $action = $_POST['action'] ?? '';
    
    // 合并所有删除/处理操作为一个统一的操作
    $isProcessingDeletions = ($action === 'process_deletions' && 
                              isset($_POST['check_completed']) && $_POST['check_completed'] === '1') ||
                             ($action === 'delete_inactive'); // 兼容原有的删除不活跃订阅功能
    
    // 获取所有订阅
    $stmt = $pdo->prepare("SELECT id, name, link FROM subscriptions ORDER BY id");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 创建检查器
    $checker = new SubscriptionChecker(20);
    foreach ($subscriptions as $sub) {
        $checker->addSubscription($sub);
    }
    
    // 执行检查
    $results = $checker->check();
    
    // 如果是处理删除操作，执行相应的删除和更新
    if ($isProcessingDeletions) {
        // 获取要保留的订阅ID
        $keepIds = [];
        if (isset($_POST['keep_sub']) && is_array($_POST['keep_sub'])) {
            // 确保所有的ID都是整数，以便统一比较
            $keepIds = array_map('intval', $_POST['keep_sub']);
        }
        
        // 确保零流量IDs和不可用IDs也是整数类型
        $zeroTrafficIds = array_map('intval', $results['zero_traffic_ids']);
        $inactiveIds = array_map('intval', $results['inactive_ids']);
        
        // 合并所有可能需要删除的IDs (不可用的和零流量的)
        $allPossibleDeleteIds = array_unique(array_merge($inactiveIds, $zeroTrafficIds));
        
        // 过滤掉需要保留的订阅ID
        $deleteIds = array_diff($allPossibleDeleteIds, $keepIds);
        
        // 更新数据库中的可用流量
        $subscriptionsUpdated = 0;
        foreach ($results['results'] as $result) {
            if (!empty($result['traffic_info']) && isset($result['traffic_info']['remaining_bytes'])) {
                // 将字节转换为GB
                $availableTrafficGB = round($result['traffic_info']['remaining_bytes'] / (1024 * 1024 * 1024), 2);
                
                // 更新数据库中的可用流量
                $updateStmt = $pdo->prepare("UPDATE subscriptions SET available_traffic = ? WHERE id = ?");
                $updateStmt->execute([$availableTrafficGB, $result['id']]);
                
                if ($updateStmt->rowCount() > 0) {
                    $subscriptionsUpdated++;
                }
            }
        }
        
        // 删除流量耗尽且未标记为保留的订阅
        $deletedCount = 0;
        if (!empty($deleteIds)) {
            $placeHolders = implode(',', array_fill(0, count($deleteIds), '?'));
            $deleteStmt = $pdo->prepare("DELETE FROM subscriptions WHERE id IN ($placeHolders)");
            $deleteStmt->execute($deleteIds);
            
            $deletedCount = $deleteStmt->rowCount();
        }
        
        $results['deleted_count'] = $deletedCount;
        $results['kept_count'] = count($allPossibleDeleteIds) - $deletedCount;
        $results['updated_count'] = $subscriptionsUpdated;
        
        if ($deletedCount > 0 || $subscriptionsUpdated > 0) {
            $results['message'] = sprintf('已清理 %d 个问题订阅，保留了 %d 个标记的订阅，更新了 %d 个订阅的流量信息', 
                                         $deletedCount, $results['kept_count'], $subscriptionsUpdated);
            
            // 添加清理详情
            $zeroTrafficDeleted = count(array_intersect($zeroTrafficIds, $deleteIds));
            $otherInactiveDeleted = $deletedCount - $zeroTrafficDeleted;
            
            $details = [];
            if ($zeroTrafficDeleted > 0) {
                $details[] = sprintf('流量耗尽: %d 个', $zeroTrafficDeleted);
            }
            if ($otherInactiveDeleted > 0) {
                $details[] = sprintf('连接失败: %d 个', $otherInactiveDeleted);
            }
            
            if (!empty($details)) {
                $results['details'] = '清理明细: ' . implode(', ', $details);
            }
        } else if (!empty($allPossibleDeleteIds)) {
            $results['message'] = sprintf('发现 %d 个问题订阅，全部被标记为保留', 
                                         count($allPossibleDeleteIds));
        } else {
            $results['message'] = '所有订阅状态正常，无需清理';
        }
    } else {
        // 如果只是检查操作，返回检查结果
        $inactiveCount = count($results['inactive_ids']);
        $zeroTrafficCount = count($results['zero_traffic_ids']);
        $totalProblemSubscriptions = count(array_unique(array_merge($results['inactive_ids'], $results['zero_traffic_ids'])));
        
        if ($totalProblemSubscriptions > 0) {
            $details = [];
            if ($zeroTrafficCount > 0) {
                $details[] = sprintf('流量耗尽: %d 个', $zeroTrafficCount);
            }
            if ($inactiveCount > 0) {
                $details[] = sprintf('连接失败: %d 个', $inactiveCount);
            }
            
            $results['message'] = sprintf('发现 %d 个问题订阅，请选择需要保留的订阅然后点击"清理订阅"按钮', $totalProblemSubscriptions);
            $results['details'] = implode(', ', $details);
        } else {
            $results['message'] = '所有订阅状态正常，无需清理';
        }
    }
    
    echo json_encode($results);
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error: " . $e->getMessage());
    exit(json_encode(['error' => '数据库错误']));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    error_log("Invalid subscription data: " . $e->getMessage());
    exit(json_encode(['error' => $e->getMessage()]));
} catch (Exception $e) {
    http_response_code(500);
    error_log("System error: " . $e->getMessage());
    exit(json_encode(['error' => '系统错误']));
}