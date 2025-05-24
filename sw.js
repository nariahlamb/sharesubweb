/**
 * 订阅分享平台 Service Worker
 * 实现资源缓存和离线访问功能
 */

// 缓存名称和版本
const CACHE_NAME = 'subscription-platform-cache-v1';

// 需要缓存的资源列表
const RESOURCES_TO_CACHE = [
  '/',
  '/index.html',
  '/assets/js/login.js',
  'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css'
];

// Service Worker 安装
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('缓存已打开');
        return cache.addAll(RESOURCES_TO_CACHE);
      })
      .then(() => self.skipWaiting()) // 强制新SW立即激活
  );
});

// Service Worker 激活
self.addEventListener('activate', event => {
  // 清理旧版本缓存
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(name => {
          if (name !== CACHE_NAME) {
            console.log('删除旧缓存:', name);
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim()) // 接管所有客户端
  );
});

// 网络请求拦截 - 使用网络优先策略，网络失败时使用缓存
self.addEventListener('fetch', event => {
  // 仅处理GET请求，API请求不缓存
  if (event.request.method !== 'GET' || 
      event.request.url.includes('login.php')) {
    return;
  }
  
  event.respondWith(
    // 网络优先策略
    fetch(event.request)
      .then(response => {
        // 检查是否成功获取且是有效的响应
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        
        // 克隆响应 - 因为响应是流，只能使用一次
        const responseToCache = response.clone();
        
        // 缓存新响应
        caches.open(CACHE_NAME)
          .then(cache => {
            cache.put(event.request, responseToCache);
          });
          
        return response;
      })
      .catch(() => {
        // 网络请求失败时返回缓存
        return caches.match(event.request);
      })
  );
});

// 监听推送消息
self.addEventListener('push', event => {
  if (!event.data) return;
  
  const notification = event.data.json();
  
  self.registration.showNotification(notification.title, {
    body: notification.body,
    icon: notification.icon || '/favicon.ico'
  });
});

// 监听通知点击
self.addEventListener('notificationclick', event => {
  event.notification.close();
  
  event.waitUntil(
    clients.openWindow('/')
  );
});