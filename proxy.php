<?php
// 单用户模式版本 - 移除了用户认证和 IP 过滤等多用户逻辑
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

// 缓存管理类 - 保留但简化
class CacheManager {
    private $maxAge;
    private static $instance = null;
    
    public static function getInstance($maxAge = 7200) {
        if (self::$instance === null) {
            self::$instance = new self($maxAge);
        }
        return self::$instance;
    }
    
    private function __construct($maxAge = 7200) {
        $this->maxAge = $maxAge;
    }
    
    public function generateKey($type, $ids, $target = '') {
        $key = md5($type . '_' . implode('_', (array)$ids) . '_' . $target);
        return "cache_{$key}";
    }
    
    public function get($key) {
        $cacheFile = $this->getCacheFilePath($key);
        if (file_exists($cacheFile) && (filemtime($cacheFile) + $this->maxAge > time())) {
            return unserialize(file_get_contents($cacheFile));
        }
        return false;
    }
    
    public function set($key, $data) {
        $cacheFile = $this->getCacheFilePath($key);
        file_put_contents($cacheFile, serialize($data));
    }
    
    private function getCacheFilePath($key) {
        return sys_get_temp_dir() . '/' . $key;
    }
}

// 数据库连接池 - 保留用于兼容性
class DbConnectionPool {
    private static $instance = null;
    private $pdo;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->pdo = getDbConnection();
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function prepareStatement($sql) {
        return $this->pdo->prepare($sql);
    }
}

// 初始化缓存管理器
$cacheManager = CacheManager::getInstance();

// 参数获取和验证
$sid = $_GET['sid'] ?? '';
$target = $_GET['target'] ?? '';

if (empty($sid)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '请求无效，缺少sid参数。';
    exit;
}

// SID 验证逻辑
if ($sid !== 'all') {
    if (strpos($sid, ',') === false) {
        if (!is_numeric($sid) || intval($sid) <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '请求无效，sid参数格式错误。';
            exit;
        }
        $sid = intval($sid);
    }
}

try {
    $dbPool = DbConnectionPool::getInstance();
    $pdo = $dbPool->getConnection();
    
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Clash/1.0";
    
    if ($sid === 'all') {
        // 处理所有订阅
        handleAllSids($pdo, $target, $userAgent, $cacheManager, $dbPool);
    } elseif (strpos($sid, ',') !== false) {
        // 处理多个特定订阅
        handleMultipleSids($pdo, $sid, $target, $userAgent, $cacheManager, $dbPool);
    } else {
        // 处理单个订阅
        handleSingleSid($pdo, $sid, $target, $userAgent, $cacheManager, $dbPool);
    }
} catch (Exception $e) {
    error_log("代理错误: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '服务器内部错误';
    exit;
}

// 处理多个SID请求
function handleMultipleSids($pdo, $sid, $target, $userAgent, $cacheManager, $dbPool) {
    // 解析多个SID
    $sidArray = array_filter(array_map('intval', explode(',', $sid)));
    
    if (empty($sidArray)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '请求无效，SID参数格式错误。';
        exit;
    }
    
    // 缓存键基于 SID 数组和 target
    $cacheKey = $cacheManager->generateKey("mul_sid", $sidArray, $target);
    
    // 尝试从缓存获取
    $cached = $cacheManager->get($cacheKey);
    if ($cached !== false) {
        outputContent($cached['content'], $cached['contentType']);
        exit;
    }
    
    // 从后端获取内容
    list($content, $contentType) = fetchFromBackend($pdo, $sidArray, $target, $userAgent);
    
    // 存入缓存
    $cacheManager->set($cacheKey, [
        'content' => $content,
        'contentType' => $contentType
    ]);
    
    // 输出内容
    outputContent($content, $contentType);
}

// 处理所有SID请求
function handleAllSids($pdo, $target, $userAgent, $cacheManager, $dbPool) {
    // 缓存键基于 all 和 target
    $cacheKey = $cacheManager->generateKey("all_sids", ['all'], $target);
    
    // 尝试从缓存获取
    $cached = $cacheManager->get($cacheKey);
    if ($cached !== false) {
        outputContent($cached['content'], $cached['contentType']);
        exit;
    }
    
    // 获取所有有效的订阅ID
    $stmt = $dbPool->prepareStatement("SELECT id FROM subscriptions WHERE active = 1");
    $stmt->execute();
    $sids = array_column($stmt->fetchAll(), 'id');
    
    if (empty($sids)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '没有找到有效的订阅。';
        exit;
    }
    
    // 从后端获取内容
    list($content, $contentType) = fetchFromBackend($pdo, $sids, $target, $userAgent);
    
    // 存入缓存
    $cacheManager->set($cacheKey, [
        'content' => $content,
        'contentType' => $contentType
    ]);
    
    // 输出内容
    outputContent($content, $contentType);
}

// 处理单个SID请求
function handleSingleSid($pdo, $sid, $target, $userAgent, $cacheManager, $dbPool) {
    // 缓存键基于 单个sid 和 target
    $cacheKey = $cacheManager->generateKey("single_sid", [$sid], $target);
    
    // 尝试从缓存获取
    $cached = $cacheManager->get($cacheKey);
    if ($cached !== false) {
        outputContent($cached['content'], $cached['contentType']);
        exit;
    }
    
    // 获取订阅信息
    $stmt = $dbPool->prepareStatement("SELECT * FROM subscriptions WHERE id = ? AND active = 1");
    $stmt->execute([$sid]);
    $subscription = $stmt->fetch();
    
    if (!$subscription) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '未找到指定的订阅或订阅已失效。';
        exit;
    }
    
    // 从后端获取内容
    list($content, $contentType) = fetchFromBackend($pdo, [$sid], $target, $userAgent);
    
    // 存入缓存
    $cacheManager->set($cacheKey, [
        'content' => $content,
        'contentType' => $contentType
    ]);
    
    // 输出内容
    outputContent($content, $contentType);
}

// 从后端获取内容
function fetchFromBackend($pdo, $sids, $target, $userAgent) {
    // 获取订阅链接
    $placeholders = implode(',', array_fill(0, count($sids), '?'));
    $stmt = $pdo->prepare("SELECT link FROM subscriptions WHERE id IN ({$placeholders}) AND active = 1");
    $stmt->execute($sids);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '未找到指定的订阅或订阅已失效。';
        exit;
    }
    
    // 提取所有链接并合并为一个URL列表字符串
    $urls = array_column($subscriptions, 'link');
    $encodedUrls = urlencode(implode('|', $urls));
    
    // 构建后端请求参数
    $targetParam = empty($target) ? 'clash' : $target;
    $backendServers = ['http://localhost:25500/sub']; // 假设后端服务在本地
    $backendUrl = $backendServers[array_rand($backendServers)];
    $backendUrl .= "?target=" . urlencode($targetParam) . "&url=" . $encodedUrls;
    
    // 添加过滤选项
    $backendUrl .= "&udp=true&emoji=true&list=false";
    
    // 根据目标格式调整参数
    if (strpos($targetParam, 'clash') !== false) {
        $backendUrl .= "&clash.doh=true";
    }
    
    // 发起请求
    return fetchContentWithRetry($backendUrl, $userAgent);
}

// 带重试的内容获取
function fetchContentWithRetry($url, $userAgent, $maxRetries = 2) {
    $attempt = 0;
    $error = null;
    
    while ($attempt < $maxRetries) {
        try {
            return fetchContent($url, $userAgent);
        } catch (Exception $e) {
            $error = $e;
            $attempt++;
            if ($attempt < $maxRetries) {
                // 等待一小段时间后重试
                usleep(500000); // 500ms
            }
        }
    }
    
    // 所有重试都失败
    throw new Exception("无法获取内容，最后的错误：" . $error->getMessage());
}

// 内容获取核心逻辑
function fetchContent($url, $userAgent) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    
    $content = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    if ($content === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("请求失败: {$error}");
    }
    
    curl_close($ch);
    
    // 处理内容类型
    if (empty($contentType)) {
        $contentType = 'text/plain';
    }
    
    // 根据目标格式，可能需要进行进一步处理
    if (strpos($url, 'target=clash') !== false) {
        // 针对Clash配置进行优化处理
        $content = ClashProcessor::process($content);
        $contentType = 'text/yaml';
    } elseif (strpos($url, 'target=singbox') !== false) {
        // 针对Singbox配置进行优化处理
        require_once 'singbox_processor.php';
        $content = SingboxProcessor::process($content);
        $contentType = 'application/json';
    }
    
    return [$content, $contentType];
}

// 输出内容
function outputContent($content, $contentType) {
    // 设置内容类型
    header("Content-Type: {$contentType}");
    
    // 设置缓存控制
    header('Cache-Control: public, max-age=1800'); // 30分钟缓存
    
    // 输出内容
    echo $content;
    exit;
}