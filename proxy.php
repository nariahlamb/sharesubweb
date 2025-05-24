<?php
// 优化版本 - 包含缓存管理、隔离和IP过滤
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// CORS 头设置
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 请求方法验证
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '仅支持 GET 请求。';
    exit;
}

require_once 'utils.php';
require_once 'clash_processor.php';

// IP过滤器类 - 优化版
class IPFilter {
    private $banListIPv4 = [];
    private $banListIPv6 = [];
    private $whiteListIPv4 = [];
    private $whiteListIPv6 = [];
    private $banListLoaded = false;
    private $whiteListLoaded = false;
    private $lastLoadTime = 0;
    private $reloadInterval = 300; // 5分钟重新加载一次列表
    
    // 缓存结果
    private $ipCheckCache = [];
    private $ipCacheTTL = 600; // IP检查结果缓存10分钟
    private $ipCacheTimestamps = [];
    
    // IPv4 CIDR缓存
    private $ipv4Ranges = [];
    
    // 单例模式
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->loadLists();
    }
    
    // 加载黑白名单
    private function loadLists() {
        $currentTime = time();
        // 如果距离上次加载不到设定的间隔时间，且列表已加载过，则不重新加载
        if ($this->banListLoaded && $this->whiteListLoaded && ($currentTime - $this->lastLoadTime < $this->reloadInterval)) {
            return;
        }
        
        // 加载IPv4黑名单
        $this->banListIPv4 = $this->loadIPList('/www/wwwroot/share.lzf.email/ip/ban.txt');
        
        // 加载IPv6黑名单
        $this->banListIPv6 = $this->loadIPList('/www/wwwroot/share.lzf.email/ip/banv6.txt');
        
        // 加载IPv4白名单
        $this->whiteListIPv4 = $this->loadIPList('/www/wwwroot/share.lzf.email/ip/unban.txt');
        
        // 加载IPv6白名单
        $this->whiteListIPv6 = $this->loadIPList('/www/wwwroot/share.lzf.email/ip/unbanv6.txt');
        
        // 预处理IPv4 CIDR范围以提高查询性能
        $this->preprocessIPv4Ranges();
        
        $this->banListLoaded = true;
        $this->whiteListLoaded = true;
        $this->lastLoadTime = $currentTime;
        
        // 清理过期的IP缓存
        $this->cleanIPCache($currentTime);
    }
    
    // 预处理IPv4 CIDR范围
    private function preprocessIPv4Ranges() {
        $this->ipv4Ranges = [
            'ban' => [],
            'white' => []
        ];
        
        // 处理黑名单
        foreach ($this->banListIPv4 as $entry) {
            if (strpos($entry, '/') !== false) {
                list($subnet, $bits) = explode('/', $entry);
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $netBinary = ip2long($subnet);
                    $mask = -1 << (32 - (int)$bits);
                    $this->ipv4Ranges['ban'][] = [
                        'net' => $netBinary & $mask,
                        'mask' => $mask
                    ];
                }
            }
        }
        
        // 处理白名单
        foreach ($this->whiteListIPv4 as $entry) {
            if (strpos($entry, '/') !== false) {
                list($subnet, $bits) = explode('/', $entry);
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $netBinary = ip2long($subnet);
                    $mask = -1 << (32 - (int)$bits);
                    $this->ipv4Ranges['white'][] = [
                        'net' => $netBinary & $mask,
                        'mask' => $mask
                    ];
                }
            }
        }
    }
    
    // 加载IP列表文件
    private function loadIPList($filePath) {
        $ipList = [];
        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && $line[0] !== '#') { // 忽略注释行
                    $ipList[] = $line;
                }
            }
        }
        return $ipList;
    }
    
    // 清理过期的IP缓存
    private function cleanIPCache($currentTime) {
        foreach ($this->ipCacheTimestamps as $ip => $timestamp) {
            if ($currentTime - $timestamp > $this->ipCacheTTL) {
                unset($this->ipCheckCache[$ip]);
                unset($this->ipCacheTimestamps[$ip]);
            }
        }
    }
    
    // 检查IP是否在列表中 - 优化版
    private function isIPInList($ip, $list, $type = 'ban') {
        // 先检查是否是单个IP (不包含CIDR)
        if (in_array($ip, $list, true)) {
            return true;
        }
        
        // 分别处理IPv4和IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipBinary = ip2long($ip);
            
            // 使用预处理的CIDR范围
            foreach ($this->ipv4Ranges[$type] as $range) {
                if (($ipBinary & $range['mask']) === $range['net']) {
                    return true;
                }
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // 对于IPv6，我们仍然使用逐条检查，但考虑到IPv6列表通常较小，这是可接受的
            $ipBin = inet_pton($ip);
            
            foreach ($list as $entry) {
                if (strpos($entry, '/') === false) {
                    continue; // 单个IP已在前面检查过
                }
                
                list($subnet, $bits) = explode('/', $entry);
                if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    continue;
                }
                
                $subnetBin = inet_pton($subnet);
                
                // 比较前缀位
                $bytesToCompare = ceil((int)$bits / 8);
                $bitsInLastByte = (int)$bits % 8;
                
                // 完整字节比较
                if ($bytesToCompare > 0) {
                    if (strncmp($ipBin, $subnetBin, $bytesToCompare - ($bitsInLastByte > 0 ? 1 : 0)) !== 0) {
                        continue;
                    }
                }
                
                // 比较最后一个不完整字节
                if ($bitsInLastByte > 0) {
                    $mask = 0xFF & (0xFF << (8 - $bitsInLastByte));
                    if ((ord($ipBin[$bytesToCompare - 1]) & $mask) !== (ord($subnetBin[$bytesToCompare - 1]) & $mask)) {
                        continue;
                    }
                }
                
                return true;
            }
        }
        
        return false;
    }
    
    // 检查IP是否被允许 - 优化版
    public function isAllowed($ip) {
        // 检查缓存
        $currentTime = time();
        if (isset($this->ipCheckCache[$ip])) {
            $this->ipCacheTimestamps[$ip] = $currentTime; // 更新时间戳
            return $this->ipCheckCache[$ip];
        }
        
        // 重新加载列表(如果需要)
        $this->loadLists();
        
        // 优先检查白名单
        $result = false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->isIPInList($ip, $this->whiteListIPv4, 'white')) {
                $result = true;
            } elseif ($this->isIPInList($ip, $this->banListIPv4, 'ban')) {
                $result = false;
            } else {
                $result = true; // 默认允许
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->isIPInList($ip, $this->whiteListIPv6, 'white')) {
                $result = true;
            } elseif ($this->isIPInList($ip, $this->banListIPv6, 'ban')) {
                $result = false;
            } else {
                $result = true; // 默认允许
            }
        } else {
            $result = true; // 非有效IP地址，默认允许
        }
        
        // 缓存结果
        $this->ipCheckCache[$ip] = $result;
        $this->ipCacheTimestamps[$ip] = $currentTime;
        
        return $result;
    }
}

// 缓存管理类
class CacheManager {
    private $cacheDir;
    private $maxAge; // 缓存最大存活时间（秒）
    private $memoryCache = []; // 内存缓存
    
    // 单例模式
    private static $instance = null;
    
    public static function getInstance($maxAge = 7200) {
        if (self::$instance === null) {
            self::$instance = new self($maxAge);
        }
        return self::$instance;
    }

    private function __construct($maxAge = 7200) {
        $this->cacheDir = sys_get_temp_dir() . '/subscription_cache';
        $this->maxAge = $maxAge;
        
        // 确保缓存目录存在
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // 清理过期缓存 - 仅在一定概率执行，减少每个请求的负担
        if (mt_rand(1, 100) <= 5) { // 5%概率清理缓存
            $this->cleanExpiredCache();
        }
    }

    // 生成缓存键
    public function generateKey($type, $ids, $target = '') {
        $idString = is_array($ids) ? implode('_', $ids) : $ids;
        return sprintf(
            "cache_%s_%s_%s_%s",
            $type,
            md5($idString),
            $target,
            count((array)$ids)  // 添加数量作为键的一部分，确保不同数量的订阅有不同的缓存
        );
    }

    // 获取缓存 - 优化版本先检查内存缓存
    public function get($key) {
        // 先检查内存缓存
        if (isset($this->memoryCache[$key]) && (time() - $this->memoryCache[$key]['time'] < $this->maxAge)) {
            return $this->memoryCache[$key]['data'];
        }
        
        // 检查文件缓存
        $cacheFile = $this->getCacheFilePath($key);
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->maxAge)) {
            $data = unserialize(file_get_contents($cacheFile));
            // 存入内存缓存
            $this->memoryCache[$key] = [
                'data' => $data,
                'time' => filemtime($cacheFile)
            ];
            return $data;
        }
        return null;
    }

    // 设置缓存 - 同时更新内存缓存和文件缓存
    public function set($key, $data) {
        // 更新内存缓存
        $this->memoryCache[$key] = [
            'data' => $data,
            'time' => time()
        ];
        
        // 更新文件缓存
        $cacheFile = $this->getCacheFilePath($key);
        file_put_contents($cacheFile, serialize($data));
    }

    // 清理过期缓存
    private function cleanExpiredCache() {
        $now = time();
        $cacheFiles = glob($this->cacheDir . '/*');
        if ($cacheFiles) {
            foreach ($cacheFiles as $file) {
                if ($now - filemtime($file) > $this->maxAge) {
                    @unlink($file);
                }
            }
        }
        
        // 清理内存缓存
        foreach ($this->memoryCache as $key => $item) {
            if ($now - $item['time'] > $this->maxAge) {
                unset($this->memoryCache[$key]);
            }
        }
    }

    private function getCacheFilePath($key) {
        return $this->cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
    }
}

// 数据库连接池
class DbConnectionPool {
    private static $instance = null;
    private $conn = null;
    private $preparedStatements = [];
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->conn = getDbConnection();
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // 获取预处理语句 - 缓存并重用
    public function prepareStatement($sql) {
        $hash = md5($sql);
        if (!isset($this->preparedStatements[$hash])) {
            $this->preparedStatements[$hash] = $this->conn->prepare($sql);
        }
        return $this->preparedStatements[$hash];
    }
}

// 初始化缓存管理器
$cacheManager = CacheManager::getInstance();

// IP 地址获取
$ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] 
             ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
             ?? $_SERVER['REMOTE_ADDR'];

// 检查IP是否被允许
$ipFilter = IPFilter::getInstance();
if (!$ipFilter->isAllowed($ipAddress)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '您的IP地址已被限制访问。';
    exit;
}

// 参数获取和验证
$uuid = $_GET['uuid'] ?? '';
$sid = $_GET['sid'] ?? '';
$target = $_GET['target'] ?? '';

if (empty($uuid)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '请求无效。';
    exit;
}

// SID 验证逻辑
if ($sid !== 'all') {
    if (strpos($sid, ',') === false) {
        if (!is_numeric($sid) || intval($sid) <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '请求无效。';
            exit;
        }
        $sid = intval($sid);
    }
}

try {
    $dbPool = DbConnectionPool::getInstance();
    $pdo = $dbPool->getConnection();
    
    // 用户验证
    $stmt = $dbPool->prepareStatement("SELECT id, is_blocked FROM users WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '用户未找到。';
        exit;
    }

    if ($user['is_blocked']) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '您的账户已被禁用。';
        exit;
    }

    $user_id = $user['id'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Clash/1.0";

    // 处理逻辑分支
    if (strpos($sid, ',') !== false) {
        handleMultipleSids($pdo, $sid, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool);
    } elseif ($sid === 'all') {
        handleAllSids($pdo, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool);
    } else {
        handleSingleSid($pdo, $sid, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool);
    }

} catch (Exception $e) {
    error_log("错误: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "服务器内部错误";
    exit;
}

// 处理多个SID的函数
function handleMultipleSids($pdo, $sid, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool) {
    $sidArray = array_filter(array_map('intval', explode(',', $sid)));
    
    if (empty($sidArray)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '请求无效，SID 参数格式错误。';
        exit;
    }

    sort($sidArray); // 确保相同的SID组合生成相同的缓存键
    $cacheKey = $cacheManager->generateKey('multiple', $sidArray, $target);
    
    $cacheData = $cacheManager->get($cacheKey);
    if ($cacheData) {
        $content = $cacheData['content'];
        $contentType = $cacheData['contentType'];
    } else {
        $result = fetchFromBackend($pdo, $sidArray, $target, $userAgent);
        $content = $result['content'];
        $contentType = $result['contentType'];

        $cacheManager->set($cacheKey, [
            'content' => $content,
            'contentType' => $contentType
        ]);
    }

    if (($target === 'clash' || $target === 'clashr') && extension_loaded('yaml')) {
        $content = ClashProcessor::process($content);
    }

    outputContent($content, $contentType);
    
    // 使用非阻塞方式更新统计
    updateSubscriptionStatsAsync($pdo, $sidArray, $user_id, $ipAddress, $dbPool);
}

// 处理所有SID的函数
function handleAllSids($pdo, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool) {
    if (empty($target)) {
        $target = 'clash';
    }

    $cacheKey = $cacheManager->generateKey('all', 'all', $target);
    
    $cacheData = $cacheManager->get($cacheKey);
    if ($cacheData) {
        $content = $cacheData['content'];
        $contentType = $cacheData['contentType'];
    } else {
        $stmt = $dbPool->prepareStatement("SELECT id, link FROM subscriptions");
        $stmt->execute();
        $subscriptions = $stmt->fetchAll();
        
        if (!$subscriptions) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '没有可用的订阅。';
            exit;
        }

        $ids = array_column($subscriptions, 'id');
        $result = fetchFromBackend($pdo, $ids, $target, $userAgent);
        $content = $result['content'];
        $contentType = $result['contentType'];

        $cacheManager->set($cacheKey, [
            'content' => $content,
            'contentType' => $contentType
        ]);
    }

    if (($target === 'clash' || $target === 'clashr') && extension_loaded('yaml')) {
        $content = ClashProcessor::process($content);
    }

    outputContent($content, $contentType);
    
    $stmt = $dbPool->prepareStatement("SELECT id FROM subscriptions");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll();
    
    // 使用非阻塞方式更新统计
    updateSubscriptionStatsAsync($pdo, array_column($subscriptions, 'id'), $user_id, $ipAddress, $dbPool);
}

// 处理单个SID的函数
function handleSingleSid($pdo, $sid, $target, $user_id, $userAgent, $ipAddress, $cacheManager, $dbPool) {
    $stmt = $dbPool->prepareStatement("SELECT * FROM subscriptions WHERE id = ?");
    $stmt->execute([$sid]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '订阅未找到。';
        exit;
    }

    if (!empty($target)) {
        $cacheKey = $cacheManager->generateKey('single', $sid, $target);
        
        $cacheData = $cacheManager->get($cacheKey);
        if ($cacheData) {
            $content = $cacheData['content'];
            $contentType = $cacheData['contentType'];
        } else {
            $result = fetchFromBackend($pdo, [$sid], $target, $userAgent);
            $content = $result['content'];
            $contentType = $result['contentType'];

            $cacheManager->set($cacheKey, [
                'content' => $content,
                'contentType' => $contentType
            ]);
        }

        if (($target === 'clash' || $target === 'clashr') && extension_loaded('yaml')) {
            $content = ClashProcessor::process($content);
        }
    } else {
        $result = fetchOriginalContent($subscription['link'], $userAgent);
        $content = $result['content'];
        $contentType = $result['contentType'];
    }

    outputContent($content, $contentType);
    
    // 使用非阻塞方式更新统计
    updateSubscriptionStatsAsync($pdo, [$sid], $user_id, $ipAddress, $dbPool);
}

// 从后端获取内容的函数 - 使用连接池 - 优化超时和错误处理
function fetchFromBackend($pdo, $sids, $target, $userAgent) {
    $dbPool = DbConnectionPool::getInstance();
    $placeholders = implode(',', array_fill(0, count($sids), '?'));
    $stmt = $dbPool->prepareStatement("SELECT link FROM subscriptions WHERE id IN ($placeholders)");
    $stmt->execute($sids);
    $subscriptions = $stmt->fetchAll();

    $encodedUrls = array_map(function($sub) {
        return urlencode($sub['link']);
    }, $subscriptions);
    
    $concatenatedUrls = implode('%7C', $encodedUrls);
    
    // 修改后端服务器配置，同时支持内网和外网地址
    $backendServers = [
        'http://localhost:25500/sub'    // 备用地址
    ];
    
    // 设置重试计数器
    $retryCount = 0;
    $maxRetries = 2;
    $content = null;
    $contentType = null;
    $lastError = '';
    
    // 实现重试逻辑
    while ($retryCount <= $maxRetries) {
        // 如果是重试，随机选择不同的后端服务器
        $backendUrl = $backendServers[$retryCount % count($backendServers)] . 
                     "?target=" . urlencode($target) . 
                     "&url=" . $concatenatedUrls;
        
        try {
            // 使用更可靠的获取内容方法
            $result = fetchContentWithRetry($backendUrl, $userAgent);
            
            if ($result !== false) {
                $content = $result['content'];
                $contentType = $result['contentType'];
                break; // 成功获取内容，退出重试循环
            }
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            error_log("后端请求失败 (尝试 ".($retryCount+1)."/".(1+$maxRetries)."): " . $lastError);
        }
        
        $retryCount++;
        if ($retryCount <= $maxRetries) {
            // 指数退避策略，每次重试等待时间增加
            usleep(5000 * $retryCount); // 第一次5ms, 第二次10ms
        }
    }
    
    // 所有重试都失败
    if ($content === null) {
        error_log("所有后端请求均失败，最后错误: " . $lastError);
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '';
        exit;
    }
    
    return ['content' => $content, 'contentType' => $contentType];
}

// 获取原始内容的函数 - 优化版本
function fetchOriginalContent($url, $userAgent) {
    static $curlHandles = [];
    $handleKey = md5($url . $userAgent);
    
    // 重用curl句柄
    if (!isset($curlHandles[$handleKey])) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_TIMEOUT => 60,      // 设置更合理的超时时间：60秒
            CURLOPT_CONNECTTIMEOUT => 10, // 连接超时设为10秒
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => 1,      // 忽略可能的超时信号
            CURLOPT_FAILONERROR => false // 即使HTTP状态码指示错误也获取内容
        ]);
        $curlHandles[$handleKey] = $ch;
    }
    
    $ch = $curlHandles[$handleKey];
    curl_setopt($ch, CURLOPT_URL, $url);
    
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    if ($content === false) {
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        error_log("cURL错误(原始内容): 错误代码 $errno, 信息: $error, URL: $url");
        curl_reset($ch); // 重置而不是关闭
        
        // 当遇到超时或网络问题时不立即返回502，给出更友好的响应
        if (in_array($errno, [CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST])) {
            http_response_code(504); // Gateway Timeout
            header('Content-Type: text/plain; charset=UTF-8');
            echo '订阅源暂时不可用，请稍后再试。';
        } else {
            http_response_code(502); // Bad Gateway
            header('Content-Type: text/plain; charset=UTF-8');
            echo '无法获取订阅内容。';
        }
        exit;
    }

    if ($httpCode >= 400) {
        error_log("订阅链接返回的 HTTP 状态码: {$httpCode}, URL: $url");
        
        // 根据不同的HTTP状态码提供不同的响应
        if ($httpCode >= 500) {
            http_response_code(502); // Bad Gateway
            header('Content-Type: text/plain; charset=UTF-8');
            echo '订阅源服务器内部错误，请稍后再试。';
        } elseif ($httpCode == 404) {
            http_response_code(404); // Not Found
            header('Content-Type: text/plain; charset=UTF-8');
            echo '订阅内容不存在。';
        } elseif ($httpCode == 403) {
            http_response_code(403); // Forbidden
            header('Content-Type: text/plain; charset=UTF-8');
            echo '订阅源拒绝访问。';
        } else {
            http_response_code(502); // Bad Gateway
            header('Content-Type: text/plain; charset=UTF-8');
            echo '获取订阅内容时发生错误。';
        }
        exit;
    }

    return ['content' => $content, 'contentType' => $contentType];
}

// 新增：带重试的内容获取函数
function fetchContentWithRetry($url, $userAgent, $maxRetries = 2) {
    $attempts = 0;
    $lastError = '';
    
    do {
        try {
            $result = fetchContent($url, $userAgent);
            return $result;
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            $attempts++;
            if ($attempts <= $maxRetries) {
                // 指数退避策略
                usleep(300000 * pow(2, $attempts - 1)); // 300ms, 600ms, 1200ms...
            }
        }
    } while ($attempts <= $maxRetries);
    
    // 所有重试都失败
    throw new Exception("多次重试后获取内容失败: $lastError");
}

// 获取内容的函数 - 优化版本
function fetchContent($url, $userAgent) {
    static $curlHandles = [];
    $handleKey = md5($url . $userAgent);
    
    // 重用curl句柄
    if (!isset($curlHandles[$handleKey])) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_TIMEOUT => 30,        // 设置更合理的超时：30秒
            CURLOPT_CONNECTTIMEOUT => 10, // 连接超时10秒
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => 1,       // 忽略可能的超时信号
            CURLOPT_FAILONERROR => false, // 即使HTTP状态码指示错误也获取内容
            // 添加TCP保持连接选项，减少连接建立时间
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TCP_KEEPINTVL => 60
        ]);
        $curlHandles[$handleKey] = $ch;
    }
    
    $ch = $curlHandles[$handleKey];
    curl_setopt($ch, CURLOPT_URL, $url);

    $start = microtime(true);
    $content = curl_exec($ch);
    $duration = microtime(true) - $start;
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    // 记录请求耗时，便于分析慢请求
    if ($duration > 5) {
        error_log("慢请求警告: URL {$url} 耗时 {$duration}秒");
    }

    if ($content === false) {
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        error_log("cURL错误: 错误代码 $errno, 信息: $error, URL: $url");
        curl_reset($ch); // 重置而不是关闭句柄
        
        // 抛出异常而不是直接退出，让调用方有机会重试
        throw new Exception("cURL错误($errno): $error");
    }

    if ($httpCode >= 400) {
        error_log("后端链接返回 HTTP 状态码: {$httpCode}, URL: $url");
        curl_reset($ch);
        
        // 抛出异常而不是直接退出，让调用方有机会重试
        throw new Exception("HTTP错误: 状态码 $httpCode");
    }

    return ['content' => $content, 'contentType' => $contentType];
}

// 输出内容函数 - 修改: 完全禁用内容压缩
function outputContent($content, $contentType) {
    // 检查内容不是数字1
    if ($content === "1" || $content === 1) {
        error_log("警告: 内容为数字1，可能是后端响应问题");
        // 输出一个有用的错误消息而不是仅"1"
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "获取订阅内容时发生错误，请稍后再试。";
        exit;
    }
    
    // 设置内容类型
    if ($contentType) {
        header("Content-Type: {$contentType}");
    } else {
        header('Content-Type: application/octet-stream');
    }
    
    // 设置内容长度
    header('Content-Length: ' . strlen($content));
    
    // 设置缓存控制头
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 输出内容
    echo $content;
    
    // 确保输出被立即发送
    if (function_exists('ob_flush')) {
        ob_flush();
    }
    flush();
}

// 非阻塞方式更新订阅统计 - 优化版本使用批量更新
function updateSubscriptionStatsAsync($pdo, $sids, $user_id, $ipAddress, $dbPool) {
    // 这里使用 PHP 的 fastcgi_finish_request 函数，如果可用的话
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // 将统计更新放在后台进程
    try {
        updateSubscriptionStats($pdo, $sids, $user_id, $ipAddress, $dbPool);
    } catch (Exception $e) {
        error_log("后台更新统计失败: " . $e->getMessage());
        // 不会影响用户体验，因为这是在响应发送后执行的
    }
}

// 更新订阅统计的函数 - 优化版本批量处理多个sid
function updateSubscriptionStats($pdo, $sids, $user_id, $ipAddress, $dbPool) {
    // 使用批处理减少数据库操作次数
    try {
        // 处理所有订阅的总订阅次数更新
        if (!empty($sids)) {
            $placeholders = implode(',', array_fill(0, count($sids), '?'));
            
            // 一次性更新所有订阅的total_subs字段
            $sql = "UPDATE subscriptions SET total_subs = total_subs + 1 WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sids);
            
            // 批量获取用户订阅记录
            $sql = "SELECT subscription_id, id FROM user_subscriptions WHERE user_id = ? AND subscription_id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $params = array_merge([$user_id], $sids);
            $stmt->execute($params);
            $existingRecords = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 开始事务
            $pdo->beginTransaction();
            
            try {
                // 准备批量插入
                $insertsValues = [];
                $insertParams = [];
                // 准备批量更新
                $updateIds = [];
                
                foreach ($sids as $sid) {
                    if (isset($existingRecords[$sid])) {
                        // 需要更新
                        $updateIds[] = $existingRecords[$sid];
                    } else {
                        // 需要插入
                        $insertsValues[] = "(?, ?, 1, NOW())";
                        $insertParams[] = $user_id;
                        $insertParams[] = $sid;
                    }
                    
                    // 记录IP地址 - 使用IGNORE防止重复
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_ips (user_id, subscription_id, ip_address) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $sid, $ipAddress]);
                }
                
                // 执行批量更新
                if (!empty($updateIds)) {
                    $updatePlaceholders = implode(',', array_fill(0, count($updateIds), '?'));
                    $sql = "UPDATE user_subscriptions SET sub_count = sub_count + 1, last_used = NOW() WHERE id IN ($updatePlaceholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($updateIds);
                }
                
                // 执行批量插入
                if (!empty($insertsValues)) {
                    $sql = "INSERT INTO user_subscriptions (user_id, subscription_id, sub_count, last_used) VALUES " . implode(',', $insertsValues);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertParams);
                }
                
                // 批量更新唯一IP计数 - 使用高效的数据库函数
                if (!empty($sids)) {
                    $placeholders = implode(',', array_fill(0, count($sids), '?'));
                    
                    // 直接使用SQL更新唯一IP计数
                    $sql = "UPDATE user_subscriptions us 
                            JOIN (
                                SELECT subscription_id, COUNT(DISTINCT ip_address) AS ip_count 
                                FROM user_ips 
                                WHERE user_id = ? AND subscription_id IN ($placeholders)
                                GROUP BY subscription_id
                            ) counts ON us.subscription_id = counts.subscription_id
                            SET us.unique_ip_count = counts.ip_count
                            WHERE us.user_id = ? AND us.subscription_id IN ($placeholders)";
                    
                    $params = array_merge([$user_id], $sids, [$user_id], $sids);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("记录订阅信息时发生错误: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("记录订阅信息时发生错误: " . $e->getMessage());
        // 不中断输出，仅记录错误
    }
}