package service

import (
	"context"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
)

// NodeService 节点服务
type NodeService struct {
	cfg           *config.Config
	subService    *SubscriptionService
	checkInterval time.Duration
	stopCh        chan struct{}
	wg            sync.WaitGroup
	proxyTester   *ProxyTester
}

// NewNodeService 创建节点服务
func NewNodeService(cfg *config.Config) *NodeService {
	return &NodeService{
		cfg:           cfg,
		checkInterval: time.Duration(cfg.NodeCheck.Interval) * time.Minute,
		stopCh:        make(chan struct{}),
		proxyTester:   NewProxyTester(cfg.NodeCheck.Timeout),
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
	active, latency := s.proxyTester.TestNodeConnectivity(node)
	node.Active = active
	node.Latency = latency
	node.LastCheck = time.Now()

	// 如果节点不可用，则跳过后续测试
	if !active {
		return
	}

	// 测试API连通性
	if s.cfg.NodeCheck.API.Enable {
		s.checkAPIConnectivity(node)
	}

	// 测试IP质量
	if s.cfg.NodeCheck.IPQuality.Enable {
		s.checkIPQuality(node)
	}
}

// 检测节点连通性
func (s *NodeService) checkNodeConnectivity(node *model.ProxyNode) (bool, int) {
	return s.proxyTester.TestNodeConnectivity(node)
}

// 检测API连通性
func (s *NodeService) checkAPIConnectivity(node *model.ProxyNode) {
	// 初始化API连通性映射
	if node.APIConnectivity == nil {
		node.APIConnectivity = make(map[string]bool)
	}
	
	// 获取API测试的超时配置，如果未配置则使用默认值
	apiTimeout := s.cfg.NodeCheck.API.Timeout
	if apiTimeout <= 0 {
		apiTimeout = s.cfg.NodeCheck.Timeout // 使用通用超时
	}
	
	// 获取重试次数
	retryCount := s.cfg.NodeCheck.API.RetryCount
	if retryCount <= 0 {
		retryCount = 1 // 默认重试1次
	}
	
	// 为每个目标API进行测试
	for _, target := range s.cfg.NodeCheck.API.List {
		// 默认为不可连接
		node.APIConnectivity[target.Name] = false
		
		// 准备请求URL，为特定API添加特殊路径
		requestURL := target.URL
		switch target.Name {
		case "OpenAI":
			requestURL = fmt.Sprintf("%s/v1/models", target.URL) // 使用OpenAI的models接口进行测试
		case "Gemini":
			requestURL = fmt.Sprintf("%s/v1beta/models", target.URL) // 使用Gemini的models接口进行测试
		}
		
		// 准备请求头
		headers := make(map[string]string)
		headers["User-Agent"] = "ShareSubWeb/1.0"
		headers["Content-Type"] = "application/json"
		
		// 添加配置中的自定义请求头
		for key, value := range target.Headers {
			headers[key] = value
		}
		
		// 使用代理测试器测试API连通性
		success := s.proxyTester.TestAPIConnectivity(node, requestURL, headers, retryCount)
		
		// 更新节点的API连通性
		node.APIConnectivity[target.Name] = success
	}
}

// 检测IP质量
func (s *NodeService) checkIPQuality(node *model.ProxyNode) {
	// 获取IP信息的服务，如ipinfo.io
	// 初始化IP信息
	if node.IPInfo == nil {
		node.IPInfo = &model.IPInfo{
			Country: "Unknown",
			CountryCode: "UN",
		}
	}
	
	// 如果服务器地址为空，跳过
	if node.Server == "" {
		return
	}
	
	// 尝试通过节点代理获取IP信息
	client, err := s.proxyTester.CreateProxyHTTPClient(node)
	if err != nil {
		return
	}
	
	// 访问IP信息API
	ipInfoURL := "https://ipinfo.io/json"
	req, err := http.NewRequest("GET", ipInfoURL, nil)
	if err != nil {
		return
	}
	
	timeout := s.cfg.NodeCheck.IPQuality.Timeout
	if timeout <= 0 {
		timeout = 10 // 默认10秒
	}
	
	// 设置超时上下文
	ctx, cancel := context.WithTimeout(context.Background(), time.Duration(timeout)*time.Second)
	req = req.WithContext(ctx)
	defer cancel()
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return
	}
	defer resp.Body.Close()
	
	// 解析响应
	if resp.StatusCode != http.StatusOK {
		return
	}
	
	// 读取响应
	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return
	}
	
	// 解析JSON
	var ipInfo map[string]interface{}
	if err := json.Unmarshal(body, &ipInfo); err != nil {
		return
	}
	
	// 更新IP信息
	if country, ok := ipInfo["country"].(string); ok {
		node.IPInfo.CountryCode = country
	}
	
	if region, ok := ipInfo["region"].(string); ok {
		node.IPInfo.Region = region
	}
	
	if city, ok := ipInfo["city"].(string); ok {
		node.IPInfo.City = city
	}
	
	if org, ok := ipInfo["org"].(string); ok {
		// 通常org格式为 "AS13335 Cloudflare, Inc."
		parts := strings.SplitN(org, " ", 2)
		if len(parts) > 0 {
			node.IPInfo.ASN = parts[0]
		}
		if len(parts) > 1 {
			node.IPInfo.ISP = parts[1]
		} else {
			node.IPInfo.ISP = org
		}
	}
	
	// 获取国家名称
	if node.IPInfo.CountryCode != "" {
		node.IPInfo.Country = getCountryName(node.IPInfo.CountryCode)
	}
}

// getCountryName 根据国家代码获取国家名称
func getCountryName(code string) string {
	countryCodes := map[string]string{
		"CN": "中国",
		"HK": "香港",
		"TW": "台湾",
		"JP": "日本",
		"KR": "韩国",
		"SG": "新加坡",
		"US": "美国",
		"CA": "加拿大",
		"GB": "英国",
		"DE": "德国",
		"FR": "法国",
		"AU": "澳大利亚",
		// 其他常见国家...
	}
	
	if name, ok := countryCodes[code]; ok {
		return name
	}
	return code
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