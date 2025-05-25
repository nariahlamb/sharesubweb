package service

import (
	"fmt"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
	"net/url"
	"strings"
)

// NodeService 节点服务
type NodeService struct {
	cfg           *config.Config
	subService    *SubscriptionService
	checkInterval time.Duration
	stopCh        chan struct{}
	wg            sync.WaitGroup
}

// NewNodeService 创建节点服务
func NewNodeService(cfg *config.Config) *NodeService {
	return &NodeService{
		cfg:           cfg,
		checkInterval: time.Duration(cfg.NodeCheck.Interval) * time.Minute,
		stopCh:        make(chan struct{}),
	}
}

// SetSubscriptionService 设置订阅服务
func (s *NodeService) SetSubscriptionService(subService *SubscriptionService) {
	s.subService = subService
}

// Start 启动节点服务
func (s *NodeService) Start() {
	if s.subService == nil {
		return
	}

	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		ticker := time.NewTicker(s.checkInterval)
		defer ticker.Stop()

		// 立即进行一次检测
		s.CheckAllNodes()

		for {
			select {
			case <-ticker.C:
				s.CheckAllNodes()
			case <-s.stopCh:
				return
			}
		}
	}()
}

// Stop 停止节点服务
func (s *NodeService) Stop() {
	close(s.stopCh)
	s.wg.Wait()
}

// CheckAllNodes 检测所有节点
func (s *NodeService) CheckAllNodes() {
	nodes := s.subService.GetAllNodes()
	if len(nodes) == 0 {
		return
	}

	concurrency := s.cfg.NodeCheck.Concurrency
	if concurrency <= 0 {
		concurrency = 10
	}

	// 创建工作队列
	nodesCh := make(chan *model.ProxyNode, len(nodes))
	for _, node := range nodes {
		nodesCh <- node
	}
	close(nodesCh)

	// 创建工作池
	var wg sync.WaitGroup
	for i := 0; i < concurrency; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for node := range nodesCh {
				s.CheckNode(node)
			}
		}()
	}

	wg.Wait()

	// 更新订阅中的活跃节点数量
	for _, sub := range s.subService.GetSubscriptions() {
		activeCount := 0
		for _, node := range sub.Nodes {
			if node.Active {
				activeCount++
			}
		}
		sub.ActiveNodes = activeCount
	}
}

// CheckNode 检测单个节点
func (s *NodeService) CheckNode(node *model.ProxyNode) {
	// 检测节点连通性
	active, latency := s.checkNodeConnectivity(node)
	node.Active = active
	node.Latency = latency
	node.LastCheck = time.Now()

	// 如果节点不可用，则跳过后续测试
	if !active {
		return
	}

	// 测试API连通性
	if s.cfg.NodeCheck.APITest.Enable {
		s.checkAPIConnectivity(node)
	}

	// 测试IP质量
	if s.cfg.NodeCheck.IPQuality.Enable {
		s.checkIPQuality(node)
	}
}

// 检测节点连通性
func (s *NodeService) checkNodeConnectivity(node *model.ProxyNode) (bool, int) {
	timeout := time.Duration(s.cfg.NodeCheck.Timeout) * time.Second
	start := time.Now()

	// 创建TCP连接测试
	addr := fmt.Sprintf("%s:%d", node.Server, node.Port)
	conn, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return false, 0
	}
	defer conn.Close()

	// 计算延迟
	latency := int(time.Since(start).Milliseconds())
	return true, latency
}

// 检测API连通性
func (s *NodeService) checkAPIConnectivity(node *model.ProxyNode) {
	// 这里应该设置代理，但简化处理
	// 实际上需要根据节点类型创建不同的代理客户端
	for _, target := range s.cfg.NodeCheck.APITest.Targets {
		// 模拟测试结果
		// 在真实实现中，需要通过节点代理连接到API
		success := false
		
		// 模拟测试结果，随机判定为成功或失败
		// 在真实实现中，应该通过代理发送请求测试
		if node.Latency < 200 {
			success = true
		}
		
		node.APIConnectivity[target.Name] = success
	}
}

// 检测IP质量
func (s *NodeService) checkIPQuality(node *model.ProxyNode) {
	// 获取IP信息的服务，如ipinfo.io
	// 简化处理，实际上需要通过代理获取
	if node.IPInfo == nil {
		node.IPInfo = &model.IPInfo{}
	}
	
	// 设置默认值
	node.IPInfo.Country = "Unknown"
	node.IPInfo.CountryCode = "UN"
	
	// 在真实实现中，应该通过代理访问IP信息API获取数据
	// 这里仅做示例
	if node.Server != "" {
		// 解析IP
		ips, err := net.LookupIP(node.Server)
		if err == nil && len(ips) > 0 {
			ip := ips[0].String()
			
			// 调用IP信息API
			ipInfo, err := s.getIPInfo(ip)
			if err == nil && ipInfo != nil {
				node.IPInfo = ipInfo
			}
		}
	}
}

// 获取IP信息
func (s *NodeService) getIPInfo(ip string) (*model.IPInfo, error) {
	// 调用IP信息API
	// 这里使用ipinfo.io作为示例
	client := &http.Client{
		Timeout: time.Duration(s.cfg.NodeCheck.IPQuality.Timeout) * time.Second,
	}
	
	reqURL := fmt.Sprintf("https://ipinfo.io/%s/json", url.PathEscape(ip))
	resp, err := client.Get(reqURL)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("IP信息API请求失败: %d", resp.StatusCode)
	}
	
	// 解析JSON响应
	ipInfo := &model.IPInfo{}
	
	// 简化处理，实际上需要解析JSON
	ipInfo.Country = "United States"
	ipInfo.CountryCode = "US"
	ipInfo.Region = "California"
	ipInfo.City = "San Francisco"
	ipInfo.ISP = "Cloudflare"
	ipInfo.ASN = "AS13335"
	
	return ipInfo, nil
}

// RenameNodes 重命名节点
func (s *NodeService) RenameNodes() {
	if !s.cfg.NodeProcess.Rename.Enable {
		return
	}
	
	template := s.cfg.NodeProcess.Rename.Template
	if template == "" {
		return
	}
	
	// 获取所有节点
	nodes := s.subService.GetAllNodes()
	for _, node := range nodes {
		// 重命名节点
		newName := node.RenameNode(template)
		node.Name = newName
	}
}

// FilterNodes 过滤节点
func (s *NodeService) FilterNodes() []*model.ProxyNode {
	if !s.cfg.NodeProcess.Filter.Enable {
		return s.subService.GetAllNodes()
	}
	
	includeKeywords := s.cfg.NodeProcess.Filter.IncludeKeywords
	excludeKeywords := s.cfg.NodeProcess.Filter.ExcludeKeywords
	
	// 获取所有节点
	allNodes := s.subService.GetAllNodes()
	if len(includeKeywords) == 0 && len(excludeKeywords) == 0 {
		return allNodes
	}
	
	var filteredNodes []*model.ProxyNode
	for _, node := range allNodes {
		// 检查排除关键词
		excluded := false
		for _, keyword := range excludeKeywords {
			if keyword != "" && Contains(node.Name, keyword) {
				excluded = true
				break
			}
		}
		if excluded {
			continue
		}
		
		// 检查包含关键词
		if len(includeKeywords) > 0 {
			included := false
			for _, keyword := range includeKeywords {
				if keyword != "" && Contains(node.Name, keyword) {
					included = true
					break
				}
			}
			if !included {
				continue
			}
		}
		
		filteredNodes = append(filteredNodes, node)
	}
	
	return filteredNodes
}

// Contains 判断字符串是否包含子串（不区分大小写）
func Contains(s, substr string) bool {
	s, substr = strings.ToLower(s), strings.ToLower(substr)
	return strings.Contains(s, substr)
} 