package service

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"time"

	"github.com/nariahlamb/sharesubweb/model"
	"golang.org/x/net/proxy"
)

// ProxyTester 代理测试器
type ProxyTester struct {
	Timeout time.Duration
}

// NewProxyTester 创建新的代理测试器
func NewProxyTester(timeout int) *ProxyTester {
	return &ProxyTester{
		Timeout: time.Duration(timeout) * time.Second,
	}
}

// TestNodeConnectivity 测试节点连通性
func (pt *ProxyTester) TestNodeConnectivity(node *model.ProxyNode) (bool, int) {
	start := time.Now()
	
	// 直接TCP连接测试
	addr := fmt.Sprintf("%s:%d", node.Server, node.Port)
	conn, err := net.DialTimeout("tcp", addr, pt.Timeout)
	if err != nil {
		return false, 0
	}
	defer conn.Close()
	
	// 计算延迟
	latency := int(time.Since(start).Milliseconds())
	return true, latency
}

// CreateProxyDialer 创建代理拨号器
func (pt *ProxyTester) CreateProxyDialer(node *model.ProxyNode) (proxy.Dialer, error) {
	// 基础拨号器
	baseDialer := &net.Dialer{
		Timeout: pt.Timeout,
	}
	
	// 根据节点类型创建不同的代理拨号器
	switch node.Type {
	case "ss":
		return pt.createShadowsocksDialer(node, baseDialer)
	case "vmess", "vless":
		return pt.createV2RayDialer(node, baseDialer)
	case "trojan":
		return pt.createTrojanDialer(node, baseDialer)
	default:
		return nil, fmt.Errorf("不支持的节点类型: %s", node.Type)
	}
}

// createShadowsocksDialer 创建Shadowsocks代理拨号器
func (pt *ProxyTester) createShadowsocksDialer(node *model.ProxyNode, baseDialer *net.Dialer) (proxy.Dialer, error) {
	// 注意：这里简化处理，实际需要使用Shadowsocks库
	// 在实际实现中，应该使用适当的库来处理Shadowsocks协议
	
	// 简化实现，返回一个SOCKS5代理（假设已经有本地SOCKS代理）
	return proxy.SOCKS5("tcp", "127.0.0.1:1080", nil, baseDialer)
}

// createV2RayDialer 创建V2Ray代理拨号器
func (pt *ProxyTester) createV2RayDialer(node *model.ProxyNode, baseDialer *net.Dialer) (proxy.Dialer, error) {
	// 注意：这里简化处理，实际需要使用V2Ray库
	// 在实际实现中，应该使用适当的库来处理V2Ray协议
	
	// 简化实现，返回一个SOCKS5代理（假设已经有本地SOCKS代理）
	return proxy.SOCKS5("tcp", "127.0.0.1:1080", nil, baseDialer)
}

// createTrojanDialer 创建Trojan代理拨号器
func (pt *ProxyTester) createTrojanDialer(node *model.ProxyNode, baseDialer *net.Dialer) (proxy.Dialer, error) {
	// 注意：这里简化处理，实际需要使用Trojan库
	// 在实际实现中，应该使用适当的库来处理Trojan协议
	
	// 简化实现，返回一个SOCKS5代理（假设已经有本地SOCKS代理）
	return proxy.SOCKS5("tcp", "127.0.0.1:1080", nil, baseDialer)
}

// CreateProxyHTTPClient 创建代理HTTP客户端
func (pt *ProxyTester) CreateProxyHTTPClient(node *model.ProxyNode) (*http.Client, error) {
	// 创建代理URL
	proxyURL, err := pt.CreateProxyURL(node)
	if err != nil {
		return nil, err
	}
	
	// 创建HTTP客户端
	return &http.Client{
		Timeout: pt.Timeout,
		Transport: &http.Transport{
			Proxy: http.ProxyURL(proxyURL),
			DialContext: (&net.Dialer{
				Timeout:   pt.Timeout,
				KeepAlive: 30 * time.Second,
			}).DialContext,
			TLSHandshakeTimeout: 10 * time.Second,
			DisableKeepAlives:   true,
		},
	}, nil
}

// CreateProxyURL 创建代理URL
func (pt *ProxyTester) CreateProxyURL(node *model.ProxyNode) (*url.URL, error) {
	// 注意：这里是简化实现，实际使用时需要启动本地代理服务器
	// 并返回本地SOCKS5代理地址
	
	// 例如，假设本地已经有一个SOCKS5代理在运行
	proxyURLStr := "socks5://127.0.0.1:1080"
	
	// 实际情况下，应该根据节点类型启动相应的本地代理服务器
	// 并返回对应的代理URL
	
	return url.Parse(proxyURLStr)
}

// TestAPIConnectivity 测试API连通性
func (pt *ProxyTester) TestAPIConnectivity(node *model.ProxyNode, targetURL string, headers map[string]string, retryCount int) bool {
	// 创建HTTP客户端
	client, err := pt.CreateProxyHTTPClient(node)
	if err != nil {
		return false
	}
	
	// 尝试请求，最多重试指定次数
	for i := 0; i < retryCount; i++ {
		req, err := http.NewRequest("GET", targetURL, nil)
		if err != nil {
			continue
		}
		
		// 设置请求头
		for key, value := range headers {
			req.Header.Set(key, value)
		}
		
		// 设置超时上下文
		ctx, cancel := context.WithTimeout(context.Background(), pt.Timeout)
		req = req.WithContext(ctx)
		
		// 发送请求
		resp, err := client.Do(req)
		cancel()
		
		if err != nil {
			continue
		}
		
		// 读取并关闭响应
		resp.Body.Close()
		
		// 检查响应状态码
		if resp.StatusCode >= 200 && resp.StatusCode < 400 {
			return true
		}
	}
	
	return false
} 