/**
 * 登录页面JavaScript逻辑 - 防DDoS优化版
 * 包含：
 * - 登录状态管理
 * - 防滥用机制
 * - 指数退避策略
 * - 客户端缓存
 * - 网络状态检测
 * - Modal处理
 */

// 防DDoS优化的JavaScript - 加强版
(function() {
    // 状态变量
    let isCheckingLogin = false;
    let loginChecked = false;
    let isOffline = false;
    let loginAttempts = 0;
    let lastLoginAttempt = 0;
    let modalInstance = null;
    let consecutiveErrors = 0;
    let apiErrorTime = 0;
    
    // 缓存DOM元素
    const loginButton = document.getElementById('loginButton');
    const errorMessage = document.getElementById('errorMessage');
    const offlineNotice = document.getElementById('offlineNotice');
    const modalElement = document.getElementById('userGuidelinesModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    
    // 用于存储登录结果的本地存储键
    const LOGIN_CACHE_KEY = 'login_state_cache';
    const CACHE_EXPIRY_KEY = 'login_cache_expiry';
    const CACHE_DURATION = 60000; // 缓存有效期（毫秒）
    
    // 创建并存储客户端随机ID（用于请求标识）
    const CLIENT_ID_KEY = 'client_session_id';
    let clientId = localStorage.getItem(CLIENT_ID_KEY);
    if (!clientId) {
        clientId = Math.random().toString(36).substring(2, 15) + 
                  Math.random().toString(36).substring(2, 15) + 
                  Date.now().toString(36);
        localStorage.setItem(CLIENT_ID_KEY, clientId);
    }
    
    // 防抖函数
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), wait);
        };
    }
    
    // 节流函数，改进版本带自动重置功能
    function throttle(func, limit, resetTime = limit * 5) {
        let lastCall = 0;
        let timeout;
        
        return function() {
            const context = this;
            const args = arguments;
            const now = Date.now();
            
            clearTimeout(timeout);
            
            if (now - lastCall >= limit) {
                lastCall = now;
                func.apply(context, args);
            }
            
            // 添加自动重置功能
            timeout = setTimeout(() => {
                lastCall = 0;
            }, resetTime);
        };
    }
    
    // 指数退避策略
    function getBackoffTime() {
        // 初始等待时间100ms，最大10秒
        const baseWait = 100;
        const maxWait = 10000;
        const wait = Math.min(baseWait * Math.pow(1.5, consecutiveErrors), maxWait);
        return wait;
    }
    
    // 安全重定向函数
    window.safeRedirect = function(url) {
        // 验证URL格式和域名白名单
        try {
            const urlObj = new URL(url);
            const allowedDomains = [
                'juanzen.linzefeng.top',
                location.hostname
            ];
            
            if (allowedDomains.some(domain => urlObj.hostname === domain || 
                                    urlObj.hostname.endsWith('.' + domain))) {
                window.location.href = url;
            } else {
                console.error("不允许的域名:", urlObj.hostname);
                showError("无效的重定向URL");
            }
        } catch (e) {
            console.error("Invalid URL:", e);
            showError("无效的重定向URL");
        }
    }
    
    // 显示错误信息
    function showError(message, duration = 5000) {
        errorMessage.textContent = message;
        errorMessage.style.display = 'block';
        
        if (duration > 0) {
            setTimeout(() => {
                errorMessage.style.display = 'none';
            }, duration);
        }
    }
    
    // 设置按钮加载状态
    function setButtonLoading(isLoading) {
        if (isLoading) {
            loginButton.disabled = true;
            loginButton.innerHTML = '<span class="spinner"></span> 处理中...';
        } else {
            loginButton.disabled = false;
            loginButton.innerHTML = '使用OAuth2登录';
        }
    }
    
    // 检查网络状态
    function checkNetworkStatus() {
        const prevOffline = isOffline;
        isOffline = !navigator.onLine;
        offlineNotice.style.display = isOffline ? 'block' : 'none';
        
        // 如果从离线恢复到在线，尝试重新检查登录状态
        if (prevOffline && !isOffline && !loginChecked) {
            // 使用延迟，确保网络真正稳定
            setTimeout(checkLoginStatus, 1500);
        }
    }
    
    // 获取缓存的登录结果
    function getCachedLoginResult() {
        try {
            const cachedData = localStorage.getItem(LOGIN_CACHE_KEY);
            const expiryTime = parseInt(localStorage.getItem(CACHE_EXPIRY_KEY) || '0');
            
            // 检查缓存是否有效
            if (cachedData && expiryTime > Date.now()) {
                return JSON.parse(cachedData);
            }
        } catch (e) {
            console.error("读取缓存出错:", e);
        }
        return null;
    }
    
    // 设置缓存的登录结果
    function setCachedLoginResult(data) {
        try {
            localStorage.setItem(LOGIN_CACHE_KEY, JSON.stringify(data));
            localStorage.setItem(CACHE_EXPIRY_KEY, (Date.now() + CACHE_DURATION).toString());
        } catch (e) {
            console.error("写入缓存出错:", e);
        }
    }
    
    // 登录状态检查，带参数防止无效重试
    function checkLoginStatus(forceRefresh = false) {
        if (isCheckingLogin || isOffline) return;
        
        // 如果没有强制刷新，尝试使用缓存
        if (!forceRefresh) {
            const cachedResult = getCachedLoginResult();
            if (cachedResult) {
                handleLoginResult(cachedResult, true);
                return;
            }
        }
        
        isCheckingLogin = true;
        loginChecked = true;
        
        // 防止API滥用
        const now = Date.now();
        if (now - apiErrorTime < getBackoffTime()) {
            isCheckingLogin = false;
            showError("请求过于频繁，请稍后再试");
            return;
        }
        
        // 添加随机参数和时间戳防止缓存
        const timestamp = Date.now();
        const nonce = Math.random().toString(36).substring(2, 15);
        
        // 构建带有校验信息的URL
        const url = `login.php?_=${timestamp}&nonce=${nonce}&client_id=${encodeURIComponent(clientId)}`;
        
        fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' // 标识这是AJAX请求
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                // 根据HTTP状态码处理
                if (response.status === 429) {
                    consecutiveErrors += 2; // 429错误权重更高
                    throw new Error("请求过于频繁，请稍后再试");
                }
                if (response.status === 444) {
                    consecutiveErrors += 1;
                    throw new Error("服务暂时不可用，请稍后再试");
                }
                throw new Error(`网络错误: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            isCheckingLogin = false;
            consecutiveErrors = 0; // 成功重置错误计数
            
            // 缓存结果
            setCachedLoginResult(data);
            
            // 处理结果
            handleLoginResult(data, false);
        })
        .catch(error => {
            isCheckingLogin = false;
            console.error("登录状态检查错误:", error);
            
            // 增加错误计数
            consecutiveErrors++;
            apiErrorTime = Date.now();
            
            // 只有在在线状态才显示错误
            if (!isOffline) {
                showError(error.message || "检查登录状态时出错，请稍后再试");
            }
        });
    }
    
    // 处理登录结果
    function handleLoginResult(data, fromCache) {
        // 如果用户已登录，重定向到主页
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        
        // 如果有错误消息
        if (data.error) {
            showError(data.error);
        }
        
        // 如果是从缓存获取的，安排一个后台刷新
        if (fromCache) {
            setTimeout(() => checkLoginStatus(true), 3000);
        }
    }
    
    // 处理登录请求
    const handleLogin = throttle(function() {
        // 防止频繁点击
        const now = Date.now();
        if (now - lastLoginAttempt < 3000) {
            showError("请勿频繁点击登录按钮");
            return;
        }
        
        // 限制登录尝试次数
        loginAttempts++;
        if (loginAttempts > 5) {
            const waitTime = Math.min(30, Math.pow(2, loginAttempts - 5)) * 1000;
            showError(`登录尝试次数过多，请在${waitTime/1000}秒后再试`);
            setTimeout(() => {
                loginAttempts = Math.max(0, loginAttempts - 1);
            }, waitTime);
            return;
        }
        
        lastLoginAttempt = now;
        
        // 开始登录流程
        setButtonLoading(true);
        
        // 防止API滥用
        if (now - apiErrorTime < getBackoffTime()) {
            setButtonLoading(false);
            showError("请求过于频繁，请稍后再试");
            return;
        }
        
        // 添加随机参数防止缓存
        const timestamp = Date.now();
        const nonce = Math.random().toString(36).substring(2, 15);
        
        fetch(`login.php?action=login&_=${timestamp}&nonce=${nonce}&client_id=${encodeURIComponent(clientId)}`, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' // 标识这是AJAX请求
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                // 根据HTTP状态码处理
                if (response.status === 429) {
                    consecutiveErrors += 2; // 429错误权重更高
                    throw new Error("请求过于频繁，请稍后再试");
                }
                if (response.status === 444) {
                    consecutiveErrors += 1;
                    throw new Error("服务暂时不可用，请稍后再试");
                }
                throw new Error(`网络错误: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            setButtonLoading(false);
            consecutiveErrors = 0; // 成功重置错误计数
            
            if (data.authUrl) {
                // 成功获取授权URL，重定向
                window.location.href = data.authUrl;
            } else if (data.redirect) {
                // 用户已登录，直接重定向
                window.location.href = data.redirect;
            } else if (data.error) {
                // 显示错误
                showError(data.error);
            } else {
                // 未知响应
                showError("收到未知响应，请稍后再试");
            }
        })
        .catch(error => {
            setButtonLoading(false);
            console.error("登录请求错误:", error);
            
            // 增加错误计数
            consecutiveErrors++;
            apiErrorTime = Date.now();
            
            if (isOffline) {
                showError("您当前处于离线状态，请检查网络连接");
            } else {
                showError(error.message || "登录请求失败，请稍后再试");
            }
        });
    }, 2000, 10000); // 2秒节流，10秒自动重置
    
    // 修复Modal关闭时可能的遮罩问题
    function fixModalBackdrop() {
        // 检查并移除任何孤立的modal-backdrop元素
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            backdrop.remove();
        });
        
        // 确保body不再有modal-open类
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
    
    // 初始化函数
    function init() {
        // 检查浏览器支持
        if (!('fetch' in window) || !('localStorage' in window)) {
            alert("您的浏览器版本过低，请升级浏览器后再访问！");
            return;
        }
        
        // 检查网络状态
        checkNetworkStatus();
        
        // 网络状态监听
        window.addEventListener('online', checkNetworkStatus);
        window.addEventListener('offline', checkNetworkStatus);
        
        // 显示用户守则弹出框
        try {
            modalInstance = new bootstrap.Modal(modalElement, {
                backdrop: 'static',  // 防止点击外部关闭
                keyboard: false      // 防止按ESC关闭
            });
            
            // 监听modal隐藏事件
            modalElement.addEventListener('hidden.bs.modal', function() {
                // 修复可能的遮罩问题
                setTimeout(fixModalBackdrop, 100);
                
                // 检查登录状态
                if (!loginChecked && !isOffline) {
                    setTimeout(checkLoginStatus, 300);
                }
            });
            
            // 显示Modal
            modalInstance.show();
        } catch (e) {
            console.error("Modal初始化失败:", e);
            // Modal失败不应阻止页面其他功能
            if (!loginChecked && !isOffline) {
                setTimeout(checkLoginStatus, 300);
            }
        }
        
        // 登录按钮点击事件
        loginButton.addEventListener('click', handleLogin);
        
        // 页面可见性变化检测（用户切换回标签页时）
        document.addEventListener('visibilitychange', debounce(function() {
            if (document.visibilityState === 'visible' && !isOffline) {
                // 用户返回页面时重新检查登录状态
                checkLoginStatus();
            }
        }, 1000));
        
        // 修复关闭按钮
        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', function() {
                modalInstance.hide();
                setTimeout(fixModalBackdrop, 100);
            });
        }
        
        // 防止过多的TCP连接
        const eventsToLimit = ['mousemove', 'scroll'];
        eventsToLimit.forEach(eventName => {
            window.addEventListener(eventName, throttle(() => {}, 500));
        });
    }
    
    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // 设置页面全局错误处理
    window.addEventListener('error', function(event) {
        console.error("页面错误:", event.message);
        // 不向用户显示JS错误
        event.preventDefault();
    });
    
    // Service Worker支持配置
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js').then(function(registration) {
                console.log('Service Worker注册成功:', registration.scope);
            }).catch(function(error) {
                console.log('Service Worker注册失败:', error);
            });
        });
    }
})();