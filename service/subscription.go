package service

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io/ioutil"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/google/uuid"
	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
)

// SubscriptionService 订阅服务
type SubscriptionService struct {
	cfg          *config.Config
	subscriptions map[string]*model.Subscription
	mutex        sync.RWMutex
}

// NewSubscriptionService 创建订阅服务
func NewSubscriptionService(cfg *config.Config) *SubscriptionService {
	service := &SubscriptionService{
		cfg:          cfg,
		subscriptions: make(map[string]*model.Subscription),
	}
	
	// 初始化订阅
	for _, subCfg := range cfg.Subscriptions {
		if subCfg.URL == "" {
			continue
		}
		
		sub := &model.Subscription{
			ID:          uuid.New().String(),
			Name:        subCfg.Name,
			URL:         subCfg.URL,
			Type:        subCfg.Type,
			Remarks:     subCfg.Remarks,
			LastUpdate:  time.Time{},
		}
		
		service.subscriptions[sub.ID] = sub
	}
	
	return service
}

// GetSubscriptions 获取所有订阅
func (s *SubscriptionService) GetSubscriptions() []*model.Subscription {
	s.mutex.RLock()
	defer s.mutex.RUnlock()
	
	subs := make([]*model.Subscription, 0, len(s.subscriptions))
	for _, sub := range s.subscriptions {
		subs = append(subs, sub)
	}
	
	return subs
}

// GetSubscription 获取指定订阅
func (s *SubscriptionService) GetSubscription(id string) (*model.Subscription, error) {
	s.mutex.RLock()
	defer s.mutex.RUnlock()
	
	sub, ok := s.subscriptions[id]
	if !ok {
		return nil, errors.New("订阅不存在")
	}
	
	return sub, nil
}

// AddSubscription 添加订阅
func (s *SubscriptionService) AddSubscription(sub *model.Subscription) error {
	s.mutex.Lock()
	defer s.mutex.Unlock()
	
	if sub.ID == "" {
		sub.ID = uuid.New().String()
	}
	
	s.subscriptions[sub.ID] = sub
	return nil
}

// UpdateSubscription 更新订阅
func (s *SubscriptionService) UpdateSubscription(sub *model.Subscription) error {
	s.mutex.Lock()
	defer s.mutex.Unlock()
	
	if _, ok := s.subscriptions[sub.ID]; !ok {
		return errors.New("订阅不存在")
	}
	
	s.subscriptions[sub.ID] = sub
	return nil
}

// DeleteSubscription 删除订阅
func (s *SubscriptionService) DeleteSubscription(id string) error {
	s.mutex.Lock()
	defer s.mutex.Unlock()
	
	if _, ok := s.subscriptions[id]; !ok {
		return errors.New("订阅不存在")
	}
	
	delete(s.subscriptions, id)
	return nil
}

// RefreshSubscription 刷新订阅
func (s *SubscriptionService) RefreshSubscription(id string) error {
	sub, err := s.GetSubscription(id)
	if err != nil {
		return err
	}
	
	return s.FetchSubscriptionContent(sub)
}

// RefreshAllSubscriptions 刷新所有订阅
func (s *SubscriptionService) RefreshAllSubscriptions() map[string]error {
	subs := s.GetSubscriptions()
	results := make(map[string]error)
	
	for _, sub := range subs {
		err := s.FetchSubscriptionContent(sub)
		results[sub.ID] = err
	}
	
	return results
}

// FetchSubscriptionContent 获取订阅内容
func (s *SubscriptionService) FetchSubscriptionContent(sub *model.Subscription) error {
	if sub.URL == "" {
		return errors.New("订阅地址为空")
	}
	
	// 发送HTTP请求
	client := &http.Client{
		Timeout: time.Second * 30,
	}
	
	req, err := http.NewRequest("GET", sub.URL, nil)
	if err != nil {
		return err
	}
	
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36")
	
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("HTTP请求错误: %d", resp.StatusCode)
	}
	
	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return err
	}
	
	// 解析订阅
	switch sub.Type {
	case "clash":
		return s.parseClashSubscription(sub, body)
	case "v2ray":
		return s.parseV2raySubscription(sub, body)
	default:
		return fmt.Errorf("不支持的订阅类型: %s", sub.Type)
	}
}

// 解析Clash订阅
func (s *SubscriptionService) parseClashSubscription(sub *model.Subscription, data []byte) error {
	var clashConfig map[string]interface{}
	if err := json.Unmarshal(data, &clashConfig); err != nil {
		// 尝试解析YAML
		// 注意：这里简化处理，实际上需要用YAML解析库
		return errors.New("无法解析Clash配置")
	}
	
	// 获取代理列表
	proxies, ok := clashConfig["proxies"].([]interface{})
	if !ok {
		return errors.New("无法获取代理列表")
	}
	
	// 解析代理节点
	nodes := make([]*model.ProxyNode, 0, len(proxies))
	for _, proxy := range proxies {
		proxyMap, ok := proxy.(map[string]interface{})
		if !ok {
			continue
		}
		
		node := &model.ProxyNode{
			ID:       uuid.New().String(),
			RawData:  proxyMap,
			LastCheck: time.Time{},
			APIConnectivity: make(map[string]bool),
		}
		
		// 基本信息
		if name, ok := proxyMap["name"].(string); ok {
			node.Name = name
		}
		
		if server, ok := proxyMap["server"].(string); ok {
			node.Server = server
		}
		
		if port, ok := proxyMap["port"].(float64); ok {
			node.Port = int(port)
		}
		
		if nodeType, ok := proxyMap["type"].(string); ok {
			node.Type = nodeType
		}
		
		// 根据类型解析特定字段
		switch node.Type {
		case "ss":
			if password, ok := proxyMap["password"].(string); ok {
				node.Password = password
			}
			if cipher, ok := proxyMap["cipher"].(string); ok {
				node.Cipher = cipher
			}
		case "vmess":
			if uuid, ok := proxyMap["uuid"].(string); ok {
				node.UUID = uuid
			}
			if cipher, ok := proxyMap["cipher"].(string); ok {
				node.Cipher = cipher
			}
			if network, ok := proxyMap["network"].(string); ok {
				node.Network = network
			}
			if tls, ok := proxyMap["tls"].(bool); ok {
				node.TLS = tls
			}
		case "trojan":
			if password, ok := proxyMap["password"].(string); ok {
				node.Password = password
			}
			if network, ok := proxyMap["network"].(string); ok {
				node.Network = network
			}
			node.TLS = true // Trojan默认启用TLS
		}
		
		nodes = append(nodes, node)
	}
	
	// 更新订阅信息
	s.mutex.Lock()
	defer s.mutex.Unlock()
	
	sub.Nodes = nodes
	sub.TotalNodes = len(nodes)
	sub.LastUpdate = time.Now()
	
	return nil
}

// 解析V2ray订阅
func (s *SubscriptionService) parseV2raySubscription(sub *model.Subscription, data []byte) error {
	// Base64解码
	decoded, err := base64.StdEncoding.DecodeString(string(data))
	if err != nil {
		// 尝试使用URLEncoding
		decoded, err = base64.URLEncoding.DecodeString(string(data))
		if err != nil {
			return errors.New("无法解码Base64数据")
		}
	}
	
	// 分割每行
	lines := strings.Split(string(decoded), "\n")
	nodes := make([]*model.ProxyNode, 0, len(lines))
	
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		
		// 目前只支持vmess://格式
		if strings.HasPrefix(line, "vmess://") {
			node, err := s.parseVmessURL(line)
			if err != nil {
				continue
			}
			nodes = append(nodes, node)
		}
	}
	
	// 更新订阅信息
	s.mutex.Lock()
	defer s.mutex.Unlock()
	
	sub.Nodes = nodes
	sub.TotalNodes = len(nodes)
	sub.LastUpdate = time.Now()
	
	return nil
}

// 解析vmess URL
func (s *SubscriptionService) parseVmessURL(vmessURL string) (*model.ProxyNode, error) {
	// 移除前缀
	vmessURL = strings.TrimPrefix(vmessURL, "vmess://")
	
	// Base64解码
	decoded, err := base64.StdEncoding.DecodeString(vmessURL)
	if err != nil {
		decoded, err = base64.URLEncoding.DecodeString(vmessURL)
		if err != nil {
			return nil, err
		}
	}
	
	// 解析JSON
	var vmessConfig map[string]interface{}
	if err := json.Unmarshal(decoded, &vmessConfig); err != nil {
		return nil, err
	}
	
	node := &model.ProxyNode{
		ID:       uuid.New().String(),
		Type:     "vmess",
		RawData:  vmessConfig,
		LastCheck: time.Time{},
		APIConnectivity: make(map[string]bool),
	}
	
	// 解析基本信息
	if ps, ok := vmessConfig["ps"].(string); ok {
		node.Name = ps
	}
	
	if add, ok := vmessConfig["add"].(string); ok {
		node.Server = add
	}
	
	if port, ok := vmessConfig["port"].(float64); ok {
		node.Port = int(port)
	} else if portStr, ok := vmessConfig["port"].(string); ok {
		var portInt int
		fmt.Sscanf(portStr, "%d", &portInt)
		node.Port = portInt
	}
	
	if id, ok := vmessConfig["id"].(string); ok {
		node.UUID = id
	}
	
	if aid, ok := vmessConfig["aid"].(float64); ok {
		// 可以保存到RawData中
		node.RawData["aid"] = int(aid)
	}
	
	if net, ok := vmessConfig["net"].(string); ok {
		node.Network = net
	}
	
	if tls, ok := vmessConfig["tls"].(string); ok {
		node.TLS = tls == "tls"
	}
	
	return node, nil
}

// GetAllNodes 获取所有节点
func (s *SubscriptionService) GetAllNodes() []*model.ProxyNode {
	s.mutex.RLock()
	defer s.mutex.RUnlock()
	
	var allNodes []*model.ProxyNode
	for _, sub := range s.subscriptions {
		allNodes = append(allNodes, sub.Nodes...)
	}
	
	return allNodes
}

// GetActiveNodes 获取所有可用节点
func (s *SubscriptionService) GetActiveNodes() []*model.ProxyNode {
	s.mutex.RLock()
	defer s.mutex.RUnlock()
	
	var activeNodes []*model.ProxyNode
	for _, sub := range s.subscriptions {
		for _, node := range sub.Nodes {
			if node.Active {
				activeNodes = append(activeNodes, node)
			}
		}
	}
	
	return activeNodes
} 