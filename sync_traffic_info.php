<?php
/**
 * 订阅流量信息同步脚本
 * 此脚本用于定时同步订阅的流量信息到数据库
 * 
 * 用法: php sync_traffic_info.php
 */

// 设置执行时间上限，避免脚本超时
set_time_limit(300);

// 引入必要的配置和函数
require_once 'utils.php';

// 日志函数
function logMessage($message) {
    $date = date('Y-m-d H:i:s');
    echo "[$date] $message" . PHP_EOL;
    error_log("[$date] $message");
}

/**
 * 订阅检测类
 */
class SubscriptionChecker {
    private $multiHandle;
    private $curlHandles = [];
    private $activeConnections = 0;
    private $queue = [];
    private $results = [];
    private $maxConcurrency;
    
    public function __construct(int $maxConcurrency = 20) {
        $this->maxConcurrency = $maxConcurrency;
        $this->multiHandle = curl_multi_init();
        curl_multi_setopt($this->multiHandle, CURLMOPT_MAXCONNECTS, $maxConcurrency);
    }

    public function addSubscription(array $subscription): void {
        if (!isset($subscription['id'], $subscription['name'], $subscription['link'])) {
            throw new InvalidArgumentException('订阅数据格式不正确');
        }
        
        // 如果不是有效的URL格式，跳过
        if (!$this->isValidUrl($subscription['link'])) {
            logMessage("跳过无效URL: {$subscription['link']}");
            return;
        }
        
        $this->queue[] = $subscription;
    }
    
    private function isValidUrl(string $url): bool {
        if (empty($url)) return false;
        $url = strtolower($url);
        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
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
            CURLOPT_HEADER => true, // 获取响应头
        ]);
        return $ch;
    }
    
    private function parseUserInfo(string $headerContent): array {
        $info = [];
        
        // 使用统一的正则表达式匹配 subscription-userinfo 和常见变体
        if (preg_match('/[Ss]ubscription-[Uu]serinfo:\s*([^\r\n]+)/', $headerContent, $matches)) {
            $userInfoStr = trim($matches[1]);
            
            // 解析键值对格式
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
    
    private function processTrafficInfo(array $rawInfo): array {
        $trafficInfo = [];
        
        // 处理总流量
        if (isset($rawInfo['total'])) {
            $trafficInfo['总流量'] = $this->formatBytes($rawInfo['total']);
            // 保存原始总流量字节数
            $trafficInfo['total_bytes'] = (int)$rawInfo['total'];
        }
        
        // 计算剩余流量
        if (isset($rawInfo['total']) && (isset($rawInfo['upload']) || isset($rawInfo['download']))) {
            $total = (int)$rawInfo['total'];
            $used = (int)($rawInfo['upload'] ?? 0) + (int)($rawInfo['download'] ?? 0);
            $remaining = max(0, $total - $used);
            $trafficInfo['剩余流量'] = $this->formatBytes($remaining);
            // 保存原始剩余流量字节数
            $trafficInfo['remaining_bytes'] = $remaining;
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
        
        // 判断是否可用
        $isActive = !$error && 
                   $info['http_code'] >= 200 && 
                   $info['http_code'] < 400 && 
                   !empty($body);
        
        // 解析流量信息
        $trafficInfo = null;
        $userInfo = $this->parseUserInfo($header);
        
        if (!empty($userInfo)) {
            $trafficInfo = $this->processTrafficInfo($userInfo);
        } else {
            // 备用解析方法：使用另一种正则匹配尝试
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
                }
            }
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
    
    public function check(): array {
        if (empty($this->queue) && empty($this->results)) {
            return [];
        }
        
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
        
        curl_multi_close($this->multiHandle);
        
        return $this->results;
    }
}

// 主函数
function syncTrafficInfo() {
    logMessage("开始同步订阅流量信息");
    
    try {
        // 获取数据库连接
        $pdo = getDbConnection();
        
        // 查询所有订阅
        $stmt = $pdo->prepare("SELECT id, name, link FROM subscriptions ORDER BY id");
        $stmt->execute();
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        logMessage("找到 " . count($subscriptions) . " 个订阅");
        
        // 检查订阅
        $checker = new SubscriptionChecker(20);
        foreach ($subscriptions as $sub) {
            $checker->addSubscription($sub);
        }
        
        $results = $checker->check();
        
        // 更新数据库中的可用流量
        $subscriptionsUpdated = 0;
        foreach ($results as $result) {
            if (!empty($result['traffic_info']) && isset($result['traffic_info']['remaining_bytes'])) {
                // 将字节转换为GB
                $availableTrafficGB = round($result['traffic_info']['remaining_bytes'] / (1024 * 1024 * 1024), 2);
                
                // 更新数据库中的可用流量
                $updateStmt = $pdo->prepare("UPDATE subscriptions SET available_traffic = ? WHERE id = ?");
                $updateStmt->execute([$availableTrafficGB, $result['id']]);
                
                if ($updateStmt->rowCount() > 0) {
                    $subscriptionsUpdated++;
                    logMessage("更新订阅 {$result['name']} (ID: {$result['id']}) 的可用流量为 {$availableTrafficGB} GB");
                }
            } else {
                logMessage("无法获取订阅 {$result['name']} (ID: {$result['id']}) 的流量信息");
            }
        }
        
        logMessage("流量同步完成，共更新了 $subscriptionsUpdated 个订阅的流量信息");
        
        return [
            'status' => 'success',
            'total' => count($subscriptions),
            'updated' => $subscriptionsUpdated
        ];
        
    } catch (PDOException $e) {
        logMessage("数据库错误: " . $e->getMessage());
        return ['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()];
    } catch (Exception $e) {
        logMessage("系统错误: " . $e->getMessage());
        return ['status' => 'error', 'message' => '系统错误: ' . $e->getMessage()];
    }
}

// 执行同步
$result = syncTrafficInfo();

// 如果是命令行运行，打印结果
if (php_sapi_name() === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    // 如果是通过Web访问，返回JSON结果
    header('Content-Type: application/json');
    echo json_encode($result);
}
