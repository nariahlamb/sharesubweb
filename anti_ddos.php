<?php
/**
 * Enhanced Anti-DDoS Protection System
 * 
 * 这个文件提供核心DDoS防护功能，应该被包含在所有PHP入口点
 * 包括IP限流、请求监控、缓存控制和基本DDoS防御机制
 * 增强版支持Redis缓存和高负载下的人机验证
 * 新增IP黑名单列表功能，支持IPv4和IPv6
 */

class AntiDDoS {
    // 配置参数
    private $config = [
        'request_limit' => 30,           // 单IP在时间窗口内的最大请求数
        'time_window' => 60,             // 时间窗口（秒）
        'ban_time' => 1800,              // IP封禁时间（秒）
        'cache_time' => 300,             // 登录状态等缓存时间（秒）
        'high_load_threshold' => 5,      // 服务器高负载阈值
        'medium_load_threshold' => 3,    // 中等负载阈值，启用JS挑战
        'suspicious_patterns' => [        // 可疑请求模式
            '/eval\(/i',
            '/base64_decode\(/i',
            '/fromCharCode/i',
            '/admin.*login/i',
            '/etc\/passwd/i'
        ],
        'log_path' => '/tmp/ddos_log.txt',  // 日志路径
        'temp_dir' => null,               // 临时文件夹，null表示使用系统默认
        'whitelist' => [],                // IP白名单
        'use_apcu' => false,             // 是否使用APCu缓存
        'use_redis' => true,            // 是否使用Redis
        'redis_host' => '127.0.0.1',     // Redis主机
        'redis_port' => 6379,            // Redis端口
        'redis_auth' => null,            // Redis认证密码
        'redis_db' => 15,                 // Redis数据库编号
        'redis_prefix' => 'antiddos:',   // Redis键前缀
        'pow_difficulty' => 4,           // PoW难度（前导0的个数）
        'challenge_timeout' => 30,       // 挑战有效期（秒）
        'cookie_name' => 'ddos_token',   // 验证Cookie名称
        'cookie_lifetime' => 120,        // 验证Cookie有效期（秒）
        'ipv4_blacklist_path' => '/www/wwwroot/share.lzf.email/ip/ban.txt',    // IPv4黑名单文件路径
        'ipv6_blacklist_path' => '/www/wwwroot/share.lzf.email/ip/banv6.txt',  // IPv6黑名单文件路径
        'blacklist_cache_time' => 300    // 黑名单缓存刷新间隔（秒）
    ];

    // 客户端信息
    private $client = [];
    
    // 缓存实例
    private $redis = null;
    
    // 静态缓存，避免重复计算
    private static $loadChecked = false;
    private static $loadLevel = 0; // 0=正常, 1=中等, 2=高负载
    private static $challengeVerified = false;
    private static $blacklistsLoaded = false;
    private static $ipv4Blacklist = [];
    private static $ipv6Blacklist = [];
    private static $blacklistLastLoaded = 0;
    private static $networkCacheResults = []; // 缓存IP网络检查结果

    /**
     * 构造函数
     * @param array $customConfig 自定义配置
     */
    public function __construct($customConfig = []) {
        // 合并自定义配置
        if (!empty($customConfig) && is_array($customConfig)) {
            $this->config = array_merge($this->config, $customConfig);
        }

        // 如果没有指定临时目录，使用系统默认
        if ($this->config['temp_dir'] === null) {
            $this->config['temp_dir'] = sys_get_temp_dir();
        }
        
        // 优先尝试Redis
        if ($this->config['use_redis'] || (
            !isset($customConfig['use_redis']) && 
            class_exists('Redis')
        )) {
            $this->initRedis();
        }
        // 如果Redis不可用，尝试APCu
        elseif (!isset($customConfig['use_apcu']) && 
                function_exists('apcu_enabled') && 
                apcu_enabled()) {
            $this->config['use_apcu'] = true;
        }

        // 初始化客户端信息
        $this->initClientInfo();
        
        // 验证挑战令牌（如果存在）
        $this->verifyChallengeToken();
        
        // 加载IP黑名单
        $this->loadBlacklists();
    }

    /**
     * 初始化Redis连接
     */
    private function initRedis() {
        try {
            $redis = new Redis();
            if ($redis->connect(
                $this->config['redis_host'], 
                $this->config['redis_port'], 
                2.0 // 连接超时时间
            )) {
                // 认证（如果需要）
                if ($this->config['redis_auth']) {
                    $redis->auth($this->config['redis_auth']);
                }
                
                // 选择数据库
                if ($this->config['redis_db'] > 0) {
                    $redis->select($this->config['redis_db']);
                }
                
                // 测试连接
                if ($redis->ping()) {
                    $this->redis = $redis;
                    $this->config['use_redis'] = true;
                    return;
                }
            }
        } catch (\Exception $e) {
            // 连接Redis失败，记录日志但继续执行
            error_log("Redis连接失败: " . $e->getMessage());
        }
        
        // Redis连接失败，禁用Redis
        $this->redis = null;
        $this->config['use_redis'] = false;
    }

    /**
     * 初始化客户端信息
     */
    private function initClientInfo() {
        $this->client = [
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'session_id' => session_id() ?: '',
            'is_ajax' => isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest',
            'is_api' => false
        ];
        
        // 检测是否为API请求
        if (!empty($this->client['request_uri'])) {
            $uri = $this->client['request_uri'];
            $this->client['is_api'] = (
                stripos($uri, '/api/') !== false || 
                stripos($uri, '.json') !== false || 
                stripos($uri, '.xml') !== false ||
                $this->client['is_ajax']
            );
        }
    }

    /**
     * 执行DDoS防护检查
     * @return bool 检查是否通过
     */
    public function protect() {
        // 白名单检查
        if (!empty($this->config['whitelist'])) {
            if (in_array($this->client['ip'], $this->config['whitelist'])) {
                return true;
            }
        }

        // 检查是否是已封禁IP
        if ($this->isIPBanned()) {
            $this->rejectRequest(403, 'IP已被临时封禁，请稍后再试');
            return false;
        }

        // 服务器负载检查
        $loadLevel = $this->checkServerLoad();
        
        // 高负载情况下的特殊处理
        if ($loadLevel > 0) {
            // 仅在高负载(loadLevel >= 2)时检查黑名单
            if ($loadLevel >= 2 && $this->isIPInBlacklist($this->client['ip'])) {
                $this->logAction('blacklist_reject', "高负载下黑名单IP拒绝: {$this->client['ip']}");
                $this->rejectRequest(403, 'IP访问受限');
                return false;
            }
            
            // 中等负载：应用JS挑战
            if ($loadLevel == 1 && !self::$challengeVerified) {
                $this->issueJSChallenge();
                return false;
            }
            
            // 高负载：需要PoW验证
            if ($loadLevel == 2 && !self::$challengeVerified) {
                $this->issuePowChallenge();
                return false;
            }
            
            // 负载过高且验证通过，放行但增加严格限制
            if (self::$challengeVerified) {
                $this->config['request_limit'] = max(5, floor($this->config['request_limit'] / 3));
            }
        }

        // 可疑请求模式检查
        if ($this->isSuspiciousRequest()) {
            $this->banIP();
            $this->rejectRequest(403, '检测到可疑请求');
            return false;
        }

        // 频率限制检查
        if (!$this->checkRateLimit()) {
            $this->banIP();
            $this->rejectRequest(429, '请求频率过高，请稍后再试');
            return false;
        }

        return true;
    }

    /**
     * 加载IP黑名单
     */
    private function loadBlacklists() {
        $now = time();
        
        // 如果黑名单已加载且未过期，直接返回
        if (self::$blacklistsLoaded && 
            ($now - self::$blacklistLastLoaded) < $this->config['blacklist_cache_time']) {
            return;
        }
        
        // 清空现有黑名单
        self::$ipv4Blacklist = [];
        self::$ipv6Blacklist = [];
        self::$networkCacheResults = []; // 清空缓存的网络检查结果
        
        // 尝试从Redis缓存加载黑名单
        if ($this->config['use_redis'] && $this->redis) {
            $ipv4Key = $this->config['redis_prefix'] . 'blacklist:ipv4';
            $ipv6Key = $this->config['redis_prefix'] . 'blacklist:ipv6';
            
            $ipv4Cached = $this->redis->get($ipv4Key);
            $ipv6Cached = $this->redis->get($ipv6Key);
            
            if ($ipv4Cached !== false && $ipv6Cached !== false) {
                self::$ipv4Blacklist = json_decode($ipv4Cached, true) ?: [];
                self::$ipv6Blacklist = json_decode($ipv6Cached, true) ?: [];
                self::$blacklistsLoaded = true;
                self::$blacklistLastLoaded = $now;
                return;
            }
        }
        
        // 从文件加载IPv4黑名单
        if (file_exists($this->config['ipv4_blacklist_path'])) {
            $lines = file($this->config['ipv4_blacklist_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || $line[0] === '#') continue; // 跳过空行和注释
                    self::$ipv4Blacklist[] = $line;
                }
            }
        }
        
        // 从文件加载IPv6黑名单
        if (file_exists($this->config['ipv6_blacklist_path'])) {
            $lines = file($this->config['ipv6_blacklist_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || $line[0] === '#') continue; // 跳过空行和注释
                    self::$ipv6Blacklist[] = $line;
                }
            }
        }
        
        // 缓存到Redis
        if ($this->config['use_redis'] && $this->redis) {
            $ipv4Key = $this->config['redis_prefix'] . 'blacklist:ipv4';
            $ipv6Key = $this->config['redis_prefix'] . 'blacklist:ipv6';
            
            $this->redis->setex($ipv4Key, $this->config['blacklist_cache_time'], json_encode(self::$ipv4Blacklist));
            $this->redis->setex($ipv6Key, $this->config['blacklist_cache_time'], json_encode(self::$ipv6Blacklist));
        }
        
        self::$blacklistsLoaded = true;
        self::$blacklistLastLoaded = $now;
        
        $this->logAction('blacklist_load', "加载黑名单: IPv4=" . count(self::$ipv4Blacklist) . ", IPv6=" . count(self::$ipv6Blacklist));
    }
    
    /**
     * 检查IP是否在黑名单中
     * @param string $ip 要检查的IP
     * @return bool 是否在黑名单中
     */
    private function isIPInBlacklist($ip) {
        // 高负载情况下才检查，否则直接返回false
        if (self::$loadLevel < 2) {
            return false;
        }
        
        // 如果缓存中已有此IP的检查结果，直接返回
        if (isset(self::$networkCacheResults[$ip])) {
            return self::$networkCacheResults[$ip];
        }
        
        // 确保黑名单已加载
        if (!self::$blacklistsLoaded) {
            $this->loadBlacklists();
        }
        
        // 判断是IPv4还是IPv6
        $isIPv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isIPv6 = !$isIPv4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        
        if (!$isIPv4 && !$isIPv6) {
            return false; // 无效的IP格式
        }
        
        // 获取要检查的黑名单
        $blacklist = $isIPv4 ? self::$ipv4Blacklist : self::$ipv6Blacklist;
        
        // 对于IPv4，我们需要检查CIDR格式
        if ($isIPv4) {
            // 将IP转换为长整数，方便比较
            $ipLong = ip2long($ip);
            
            foreach ($blacklist as $range) {
                // 检查是否是CIDR格式
                if (strpos($range, '/') !== false) {
                    list($subnet, $bits) = explode('/', $range);
                    
                    // 计算子网掩码
                    $mask = -1 << (32 - $bits);
                    $subnetLong = ip2long($subnet);
                    
                    // 检查IP是否在范围内
                    if (($ipLong & $mask) == ($subnetLong & $mask)) {
                        // 缓存结果
                        self::$networkCacheResults[$ip] = true;
                        return true;
                    }
                } 
                // 直接比较单个IP
                elseif ($range === $ip) {
                    // 缓存结果
                    self::$networkCacheResults[$ip] = true;
                    return true;
                }
            }
        } 
        // IPv6需要特殊处理
        else if ($isIPv6) {
            // 当前暂不支持IPv6 CIDR检查，仅做精确匹配
            if (in_array($ip, $blacklist)) {
                // 缓存结果
                self::$networkCacheResults[$ip] = true;
                return true;
            }
        }
        
        // 缓存结果
        self::$networkCacheResults[$ip] = false;
        return false;
    }
    
    /**
     * 添加IP到黑名单文件
     * @param string $ip 要添加的IP
     * @return bool 是否成功
     */
    public function addToBlacklist($ip) {
        // 判断是IPv4还是IPv6
        $isIPv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        
        if (!$isIPv4 && !$isIPv6) {
            return false; // 无效的IP格式
        }
        
        $filePath = $isIPv4 ? $this->config['ipv4_blacklist_path'] : $this->config['ipv6_blacklist_path'];
        
        // 确保目录存在
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $this->logAction('blacklist_error', "无法创建黑名单目录: $dir");
                return false;
            }
        }
        
        // 追加IP到黑名单文件
        if (@file_put_contents($filePath, $ip . PHP_EOL, FILE_APPEND | LOCK_EX)) {
            // 更新内存中的黑名单
            if ($isIPv4) {
                self::$ipv4Blacklist[] = $ip;
            } else {
                self::$ipv6Blacklist[] = $ip;
            }
            
            // 更新Redis缓存
            if ($this->config['use_redis'] && $this->redis) {
                $key = $this->config['redis_prefix'] . 'blacklist:' . ($isIPv4 ? 'ipv4' : 'ipv6');
                $list = $isIPv4 ? self::$ipv4Blacklist : self::$ipv6Blacklist;
                $this->redis->setex($key, $this->config['blacklist_cache_time'], json_encode($list));
            }
            
            $this->logAction('blacklist_add', "IP添加到黑名单: $ip");
            return true;
        }
        
        $this->logAction('blacklist_error', "无法写入黑名单文件: $filePath");
        return false;
    }

    /**
     * 验证客户端提交的挑战令牌
     */
    private function verifyChallengeToken() {
        // 检查Cookie中的令牌
        $token = $_COOKIE[$this->config['cookie_name']] ?? null;
        if (!$token) {
            return;
        }
        
        // 检查POST中的PoW令牌
        $powSolution = $_POST['pow_solution'] ?? null;
        
        // 处理PoW验证
        if ($powSolution && $this->verifyPoWSolution($powSolution, $token)) {
            self::$challengeVerified = true;
            return;
        }
        
        // 处理JS挑战验证
        $verificationKey = 'challenge:' . $token;
        $verified = false;
        
        if ($this->config['use_redis'] && $this->redis) {
            $verified = (bool)$this->redis->get($this->config['redis_prefix'] . $verificationKey);
        } elseif ($this->config['use_apcu']) {
            $verified = (bool)apcu_fetch('challenge_' . $token);
        } else {
            $cacheFile = $this->config['temp_dir'] . '/challenge_' . md5($token) . '.cache';
            $verified = file_exists($cacheFile);
        }
        
        if ($verified) {
            self::$challengeVerified = true;
            
            // 更新验证状态的有效期
            if ($this->config['use_redis'] && $this->redis) {
                $this->redis->expire(
                    $this->config['redis_prefix'] . $verificationKey, 
                    $this->config['cookie_lifetime']
                );
            }
        }
    }

    /**
     * 验证PoW解决方案
     * @param string $solution 客户端提供的解决方案
     * @param string $token 原始令牌
     * @return bool 验证是否通过
     */
    private function verifyPoWSolution($solution, $token) {
        // 提取nonce和哈希
        list($nonce, $hash) = explode(':', $solution . ':');
        
        // 验证哈希
        $expectedHash = hash('sha256', $token . $nonce);
        $difficulty = $this->config['pow_difficulty'];
        
        // 检查哈希值是否有足够多的前导零
        if (substr($expectedHash, 0, $difficulty) === str_repeat('0', $difficulty)) {
            // 验证通过，保存验证状态
            $key = 'pow:' . $token;
            
            if ($this->config['use_redis'] && $this->redis) {
                $this->redis->setex(
                    $this->config['redis_prefix'] . $key,
                    $this->config['cookie_lifetime'],
                    1
                );
            } elseif ($this->config['use_apcu']) {
                apcu_store('pow_' . $token, 1, $this->config['cookie_lifetime']);
            } else {
                $cacheFile = $this->config['temp_dir'] . '/pow_' . md5($token) . '.cache';
                file_put_contents($cacheFile, '1', LOCK_EX);
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 发出JavaScript挑战
     * 向客户端发送JavaScript代码，验证浏览器能力
     */
    private function issueJSChallenge() {
        // 生成一个随机令牌
        $token = bin2hex(random_bytes(16));
        $expires = time() + $this->config['cookie_lifetime'];
        
        // 输出JavaScript挑战
        header('Content-Type: text/html; charset=UTF-8');
        
        // JavaScript挑战HTML（计算简单数学问题，阻挡简单爬虫）
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>安全验证</title>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                #message { font-size: 14px; margin-top: 20px; }
                .hidden { display: none; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>请稍候</h1>
                <p>正在验证您的浏览器...</p>
                <div id="message">如果页面没有自动跳转，请确认您的浏览器已启用JavaScript</div>
                <form id="challengeForm" class="hidden" method="post">
                    <input type="hidden" id="challenge_response" name="challenge_response" value="">
                </form>
            </div>
            <script>
                (function() {
                    function setCookie(name, value, days) {
                        var expires = "";
                        if (days) {
                            var date = new Date();
                            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                            expires = "; expires=" + date.toUTCString();
                        }
                        document.cookie = name + "=" + value + expires + "; path=/";
                    }
                    
                    function solve() {
                        // 简单计算证明这是真实浏览器
                        var a = ' . rand(1, 100) . ';
                        var b = ' . rand(1, 100) . ';
                        var c = ' . rand(1, 100) . ';
                        var result = a + (b * c);
                        
                        // 使用了混淆和动态生成计算方式，使简单爬虫难以分析
                        var token = "' . $token . '";
                        var challengeResponse = token + ":" + result;
                        
                        // 设置Cookie和提交表单
                        setCookie("' . $this->config['cookie_name'] . '", token, ' . ($this->config['cookie_lifetime'] / 86400) . ');
                        
                        // 提交回当前URL
                        var form = document.getElementById("challengeForm");
                        var input = document.getElementById("challenge_response");
                        input.value = challengeResponse;
                        
                        setTimeout(function() {
                            // 提交表单或直接重定向
                            window.location.reload(true);
                        }, 500);
                    }
                    
                    // 延迟执行以模拟验证过程
                    setTimeout(solve, 1000);
                })();
            </script>
        </body>
        </html>';
        
        // 保存客户端需要完成的挑战
        $challengeKey = 'challenge:' . $token;
        
        if ($this->config['use_redis'] && $this->redis) {
            $this->redis->setex(
                $this->config['redis_prefix'] . $challengeKey,
                $this->config['challenge_timeout'],
                1
            );
        } elseif ($this->config['use_apcu']) {
            apcu_store('challenge_' . $token, 1, $this->config['challenge_timeout']);
        } else {
            $cacheFile = $this->config['temp_dir'] . '/challenge_' . md5($token) . '.cache';
            file_put_contents($cacheFile, '1', LOCK_EX);
            
            // 设置文件过期清理
            $this->scheduleFileDeletion($cacheFile, $this->config['challenge_timeout']);
        }
        
        exit();
    }

    /**
     * 发出PoW挑战
     * 向客户端发送工作量证明挑战
     */
    private function issuePowChallenge() {
        // 生成一个随机令牌
        $token = bin2hex(random_bytes(16));
        $expires = time() + $this->config['cookie_lifetime'];
        
        // 设置Cookie
        setcookie(
            $this->config['cookie_name'],
            $token,
            [
                'expires' => $expires,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
        
        // 如果是API请求，返回JSON响应
        if ($this->client['is_api']) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'challenge',
                'message' => '服务器当前负载较高，需要验证',
                'token' => $token,
                'difficulty' => $this->config['pow_difficulty'],
                'type' => 'pow'
            ]);
            exit();
        }
        
        // 发送HTML页面和PoW JavaScript
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>安全验证</title>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                #status { margin: 20px 0; }
                #progress { 
                    width: 100%; 
                    height: 20px;
                    background-color: #444;
                    border-radius: 10px;
                    overflow: hidden;
                    margin-top: 10px;
                }
                #bar {
                    height: 100%;
                    width: 0%;
                    background-color: #4CAF50;
                    transition: width 0.3s;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>安全验证</h1>
                <p>服务器当前负载较高，需要进行验证</p>
                <div id="status">正在计算验证码，请稍候...</div>
                <div id="progress"><div id="bar"></div></div>
                <form id="powForm" method="post" style="display:none;">
                    <input type="hidden" name="pow_solution" id="powSolution">
                </form>
            </div>
            <script>
                (function() {
                    // SHA-256 哈希实现
                    async function sha256(message) {
                        const msgBuffer = new TextEncoder().encode(message);
                        const hashBuffer = await crypto.subtle.digest("SHA-256", msgBuffer);
                        const hashArray = Array.from(new Uint8Array(hashBuffer));
                        return hashArray.map(b => b.toString(16).padStart(2, "0")).join("");
                    }
                    
                    // PoW 实现
                    async function findProofOfWork() {
                        const token = "' . $token . '";
                        const difficulty = ' . $this->config['pow_difficulty'] . ';
                        const target = "0".repeat(difficulty);
                        let nonce = 0;
                        let hash = "";
                        let attempts = 0;
                        const statusEl = document.getElementById("status");
                        const barEl = document.getElementById("bar");
                        const maxAttempts = 5000; // 进度条最大尝试次数
                        
                        const startTime = Date.now();
                        
                        while(true) {
                            hash = await sha256(token + nonce);
                            
                            // 更新进度
                            attempts++;
                            if(attempts % 10 === 0) {
                                barEl.style.width = Math.min(100, (attempts / maxAttempts) * 100) + "%";
                                statusEl.textContent = "计算中: 已尝试 " + attempts + " 次...";
                            }
                            
                            // 检查是否找到符合条件的哈希
                            if(hash.substr(0, difficulty) === target) {
                                const solution = nonce + ":" + hash;
                                document.getElementById("powSolution").value = solution;
                                
                                // 显示完成状态
                                barEl.style.width = "100%";
                                statusEl.textContent = "验证成功! 正在重定向...";
                                
                                // 提交表单
                                setTimeout(function() {
                                    document.getElementById("powForm").submit();
                                }, 500);
                                
                                break;
                            }
                            
                            nonce++;
                            
                            // 为了避免浏览器卡死，每1000次计算让出一次主线程
                            if(nonce % 1000 === 0) {
                                await new Promise(resolve => setTimeout(resolve, 0));
                            }
                        }
                    }
                    
                    // 启动 PoW 计算
                    findProofOfWork();
                })();
            </script>
        </body>
        </html>';
        
        exit();
    }

    /**
     * 获取客户端真实IP
     * @return string IP地址
     */
    public function getClientIP() {
        // 直接使用优化过的IP获取逻辑
        return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * 检查服务器负载级别
     * @return int 负载级别 (0=正常, 1=中等, 2=高)
     */
    private function checkServerLoad() {
        // 使用静态缓存避免频繁检查
        if (self::$loadChecked) {
            return self::$loadLevel;
        }
        
        // 标记为已检查
        self::$loadChecked = true;
        self::$loadLevel = 0;
        
        // 尝试获取系统负载
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            
            // 高负载级别
            if ($load[0] > $this->config['high_load_threshold']) {
                return self::$loadLevel = 2;
            }
            
            // 中等负载级别
            if ($load[0] > $this->config['medium_load_threshold']) {
                return self::$loadLevel = 1;
            }
        }
        
        return self::$loadLevel;
    }

    /**
     * 检查IP频率限制
     * @return bool 是否通过限制检查
     */
    private function checkRateLimit() {
        $ip = $this->client['ip'];
        $current = time();
        $timeWindow = $this->config['time_window'];
        $maxRequests = $this->config['request_limit'];
        $requestHistory = [];
        
        // 根据存储方式获取请求历史
        if ($this->config['use_redis'] && $this->redis) {
            $key = $this->config['redis_prefix'] . 'rate:' . $ip;
            
            // 使用Redis的有序集合(ZSet)存储请求时间
            $this->redis->zAdd($key, $current, $current . ':' . microtime(true));
            
            // 清理过期的请求记录
            $this->redis->zRemRangeByScore($key, 0, $current - $timeWindow);
            
            // 设置过期时间
            $this->redis->expire($key, $timeWindow * 2);
            
            // 获取当前时间窗口内的请求数
            $requestCount = $this->redis->zCard($key);
            
            return $requestCount <= $maxRequests;
        }
        elseif ($this->config['use_apcu']) {
            $cacheKey = 'ratelimit_' . md5($ip);
            $requestHistory = apcu_fetch($cacheKey) ?: [];
            
            // 清理过期的请求记录
            $requestHistory = array_filter($requestHistory, function($timestamp) use ($current, $timeWindow) {
                return ($current - $timestamp) <= $timeWindow;
            });
            
            // 添加当前请求记录
            $requestHistory[] = $current;
            
            // 保存请求记录
            apcu_store($cacheKey, $requestHistory, $timeWindow);
            
            return count($requestHistory) <= $maxRequests;
        }
        else {
            // 回退到文件缓存
            $cacheFile = $this->config['temp_dir'] . '/ratelimit_' . md5($ip) . '.cache';
            
            if (file_exists($cacheFile)) {
                // 文件缓存可能存在，但可能已损坏，添加错误处理
                $fileData = @file_get_contents($cacheFile);
                if (!empty($fileData)) {
                    $requestHistory = @unserialize($fileData) ?: [];
                    
                    // 只保留有效期内的请求
                    $requestHistory = array_filter($requestHistory, function($timestamp) use ($current, $timeWindow) {
                        return ($current - $timestamp) <= $timeWindow;
                    });
                }
            }
            
            // 添加当前请求记录
            $requestHistory[] = $current;
            
            // 保存请求记录 - 使用临时文件和重命名避免文件锁和并发问题
            $tempFile = $cacheFile . '.' . uniqid();
            if (@file_put_contents($tempFile, serialize($requestHistory), LOCK_EX) !== false) {
                @rename($tempFile, $cacheFile);
            }
            
            // 设置文件过期清理
            $this->scheduleFileDeletion($cacheFile, $timeWindow * 2);
            
            return count($requestHistory) <= $maxRequests;
        }
    }

    /**
     * 检查IP是否已被封禁
     * @return bool 是否被封禁
     */
    private function isIPBanned() {
        $ip = $this->client['ip'];
        $current = time();
        
        // 只在高负载状态下检查黑名单
        if (self::$loadLevel >= 2 && $this->isIPInBlacklist($ip)) {
            $this->logAction('banned_check', "高负载下黑名单检查通过: $ip");
            return true;
        }
        
        // 检查动态封禁状态
        if ($this->config['use_redis'] && $this->redis) {
            $key = $this->config['redis_prefix'] . 'banned:' . $ip;
            return (bool)$this->redis->get($key);
        }
        elseif ($this->config['use_apcu']) {
            $cacheKey = 'banned_' . md5($ip);
            $banExpiry = apcu_fetch($cacheKey);
            
            // 检查是否存在有效的封禁
            if ($banExpiry && $current < (int)$banExpiry) {
                return true;
            }
            
            // 如果已过期，清除记录
            if ($banExpiry) {
                apcu_delete($cacheKey);
            }
            
            return false;
        }
        else {
            // 回退到文件缓存
            $banFile = $this->config['temp_dir'] . '/banned_' . md5($ip) . '.cache';
            
            if (file_exists($banFile)) {
                $banExpiry = @file_get_contents($banFile);
                
                // 如果封禁已过期，删除封禁记录
                if ($current > (int)$banExpiry) {
                    @unlink($banFile);
                    return false;
                }
                
                return true;
            }
            
            return false;
        }
    }

    /**
     * 封禁IP
     */
    private function banIP() {
        $ip = $this->client['ip'];
        $banExpiry = time() + $this->config['ban_time'];
        
        // 根据存储方式设置IP封禁
        if ($this->config['use_redis'] && $this->redis) {
            $key = $this->config['redis_prefix'] . 'banned:' . $ip;
            $this->redis->setex($key, $this->config['ban_time'], $banExpiry);
        }
        elseif ($this->config['use_apcu']) {
            apcu_store('banned_' . md5($ip), $banExpiry, $this->config['ban_time']);
        }
        else {
            // 回退到文件缓存
            $banFile = $this->config['temp_dir'] . '/banned_' . md5($ip) . '.cache';
            @file_put_contents($banFile, $banExpiry, LOCK_EX);
            
            // 设置文件过期清理
            $this->scheduleFileDeletion($banFile, $this->config['ban_time'] + 60);
        }
        
        // 如果是严重违规，添加到永久黑名单
        if ($this->isSuspiciousRequest() || $this->checkServerLoad() >= 2) {
            $this->addToBlacklist($ip);
        }
        
        // 记录封禁日志
        $this->logAction('ban_ip', "IP $ip 已被封禁至 " . date('Y-m-d H:i:s', $banExpiry));
    }

    /**
     * 检查是否为可疑请求
     * @return bool 是否为可疑请求
     */
    private function isSuspiciousRequest() {
        // 预先合并请求数据
        $requestData = [
            json_encode($_GET),
            json_encode($_POST),
            json_encode($_COOKIE),
            $this->client['request_uri'],
            $this->client['user_agent']
        ];
        
        $requestString = implode(' ', $requestData);
        
        // 使用正则模式的联合检查
        if (!empty($this->config['suspicious_patterns'])) {
            foreach ($this->config['suspicious_patterns'] as $pattern) {
                if (preg_match($pattern, $requestString)) {
                    $this->logAction('suspicious_request', "检测到可疑请求: {$this->client['ip']} - {$this->client['request_uri']}");
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 拒绝请求并返回错误信息
     * @param int $statusCode HTTP状态码
     * @param string $message 错误信息
     */
    private function rejectRequest($statusCode, $message) {
        // 设置HTTP状态码
        http_response_code($statusCode);
        
        // 检测API请求
        if ($this->client['is_api']) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $message, 'status' => 'rejected']);
        } else {
            // 预先缓存HTML模板
            static $htmlTemplate = "<!DOCTYPE html>
            <html>
            <head>
                <title>请求被拒绝</title>
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
                </style>
            </head>
            <body>
                <div class='container'>
                    <h1>请求被拒绝</h1>
                    <p>%s</p>
                </div>
            </body>
            </html>";
            
            echo sprintf($htmlTemplate, $message);
        }
        
        // 记录拒绝请求日志
        $this->logAction('reject_request', "$statusCode: {$this->client['ip']} - {$this->client['request_uri']} - $message");
        
        exit();
    }

    /**
     * 记录操作日志
     * @param string $action 操作类型
     * @param string $message 日志消息
     */
    private function logAction($action, $message) {
        static $logQueue = [];
        static $registered = false;
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$action] $message";
        
        // 将日志添加到队列
        $logQueue[] = $logMessage;
        
        // 注册关闭时写入日志的处理程序
        if (!$registered) {
            register_shutdown_function(function() use (&$logQueue) {
                if ($logQueue) {
                    @file_put_contents(
                        $this->config['log_path'], 
                        implode(PHP_EOL, $logQueue) . PHP_EOL, 
                        FILE_APPEND | LOCK_EX
                    );
                }
            });
            $registered = true;
        }
        
        // 如果队列达到一定大小或者在关键操作时，立即写入日志
        $shouldFlush = count($logQueue) >= 10 || 
                      $action === 'ban_ip' || 
                      $action === 'reject_request';
        
        if ($shouldFlush) {
            @file_put_contents(
                $this->config['log_path'], 
                implode(PHP_EOL, $logQueue) . PHP_EOL, 
                FILE_APPEND | LOCK_EX
            );
            $logQueue = []; // 清空队列
        }
    }

    /**
     * 获取缓存内容
     * @param string $key 缓存键
     * @return mixed 缓存内容或false
     */
    public function getCache($key) {
        // 根据存储方式获取缓存
        if ($this->config['use_redis'] && $this->redis) {
            $value = $this->redis->get($this->config['redis_prefix'] . 'cache:' . $key);
            return $value !== false ? unserialize($value) : false;
        }
        elseif ($this->config['use_apcu']) {
            $success = false;
            $value = apcu_fetch('cache_' . $key, $success);
            return $success ? $value : false;
        }
        else {
            // 回退到文件缓存
            $cacheFile = $this->config['temp_dir'] . '/cache_' . md5($key) . '.cache';
            
            if (file_exists($cacheFile)) {
                // 检查缓存是否过期
                if ((time() - @filemtime($cacheFile)) > $this->config['cache_time']) {
                    @unlink($cacheFile);
                    return false;
                }
                
                $cachedData = @file_get_contents($cacheFile);
                return $cachedData !== false ? @unserialize($cachedData) : false;
            }
            
            return false;
        }
    }

    /**
     * 设置缓存内容
     * @param string $key 缓存键
     * @param mixed $data 缓存数据
     * @param int $expiry 过期时间(秒)
     * @return bool 是否成功
     */
    public function setCache($key, $data, $expiry = null) {
        if ($expiry === null) {
            $expiry = $this->config['cache_time'];
        }
        
        // 根据存储方式设置缓存
        if ($this->config['use_redis'] && $this->redis) {
            return $this->redis->setex(
                $this->config['redis_prefix'] . 'cache:' . $key,
                $expiry,
                serialize($data)
            );
        }
        elseif ($this->config['use_apcu']) {
            return apcu_store('cache_' . $key, $data, $expiry);
        }
        else {
            // 回退到文件缓存
            $cacheFile = $this->config['temp_dir'] . '/cache_' . md5($key) . '.cache';
            
            // 使用临时文件和重命名避免并发问题
            $tempFile = $cacheFile . '.' . uniqid();
            if (@file_put_contents($tempFile, serialize($data), LOCK_EX) !== false) {
                if (@rename($tempFile, $cacheFile)) {
                    // 设置文件过期清理
                    $this->scheduleFileDeletion($cacheFile, $expiry + 60);
                    return true;
                }
                @unlink($tempFile); // 如果重命名失败，删除临时文件
            }
            
            return false;
        }
    }

    /**
     * 清除指定缓存
     * @param string $key 缓存键
     * @return bool 是否成功
     */
    public function clearCache($key) {
        // 根据存储方式清除缓存
        if ($this->config['use_redis'] && $this->redis) {
            return (bool)$this->redis->del($this->config['redis_prefix'] . 'cache:' . $key);
        }
        elseif ($this->config['use_apcu']) {
            return apcu_delete('cache_' . $key);
        }
        else {
            // 回退到文件缓存
            $cacheFile = $this->config['temp_dir'] . '/cache_' . md5($key) . '.cache';
            if (file_exists($cacheFile)) {
                return @unlink($cacheFile);
            }
            return true;
        }
    }

    /**
     * 安排文件在未来某个时间被删除
     * @param string $filePath 文件路径
     * @param int $seconds 多少秒后删除
     */
    private function scheduleFileDeletion($filePath, $seconds) {
        // 在共享主机环境下，我们不能依赖cron或系统任务
        // 创建一个标记文件，记录删除时间
        $markerFile = $filePath . '.expires';
        $expiryTime = time() + $seconds;
        @file_put_contents($markerFile, $expiryTime);
        
        // 在请求结束时清理过期文件
        static $cleanupRegistered = false;
        
        if (!$cleanupRegistered) {
            register_shutdown_function(function() {
                // 限制每次清理的文件数量，避免性能问题
                $maxCleanup = 10;
                $cleaned = 0;
                $now = time();
                
                // 扫描临时目录中的过期标记
                $files = glob($this->config['temp_dir'] . '/*.expires');
                
                foreach ($files as $markerFile) {
                    if ($cleaned >= $maxCleanup) break;
                    
                    $expiryTime = (int)@file_get_contents($markerFile);
                    
                    if ($now > $expiryTime) {
                        // 删除原始文件和标记文件
                        $originalFile = substr($markerFile, 0, -8); // 去掉.expires
                        @unlink($originalFile);
                        @unlink($markerFile);
                        $cleaned++;
                    }
                }
            });
            
            $cleanupRegistered = true;
        }
    }

    /**
     * 生成请求令牌（防止CSRF）
     * @param string $action 操作名称
     * @return string 令牌
     */
    public function generateToken($action = '') {
        $token = bin2hex(random_bytes(16));
        $tokenKey = 'token_' . ($action ? $action . '_' : '') . $this->client['session_id'];
        $this->setCache($tokenKey, [
            'token' => $token,
            'time' => time()
        ]);
        return $token;
    }

    /**
     * 验证请求令牌
     * @param string $token 令牌
     * @param string $action 操作名称
     * @return bool 是否有效
     */
    public function validateToken($token, $action = '') {
        $tokenKey = 'token_' . ($action ? $action . '_' : '') . $this->client['session_id'];
        $storedToken = $this->getCache($tokenKey);
        
        if ($storedToken && $storedToken['token'] === $token) {
            // 验证成功后立即删除令牌（防止重放攻击）
            $this->clearCache($tokenKey);
            
            // 检查令牌是否已过期（10分钟有效期）
            return (time() - $storedToken['time']) <= 600;
        }
        
        return false;
    }
}