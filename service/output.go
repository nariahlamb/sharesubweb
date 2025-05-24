package service

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io/ioutil"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
)

// OutputService 输出服务
type OutputService struct {
	cfg          *config.Config
	nodeService  *NodeService
	subService   *SubscriptionService
}

// NewOutputService 创建输出服务
func NewOutputService(cfg *config.Config, nodeService *NodeService, subService *SubscriptionService) *OutputService {
	return &OutputService{
		cfg:          cfg,
		nodeService:  nodeService,
		subService:   subService,
	}
}

// GenerateOutputs 生成所有输出
func (s *OutputService) GenerateOutputs() error {
	// 重命名节点
	s.nodeService.RenameNodes()
	
	// 过滤节点
	nodes := s.nodeService.FilterNodes()
	if len(nodes) == 0 {
		return errors.New("没有可用节点")
	}
	
	// 根据配置生成不同格式的输出
	var errs []string
	for _, format := range s.cfg.Output.Formats {
		if !format.Enable {
			continue
		}
		
		var err error
		switch format.Type {
		case "clash", "mihomo":
			err = s.generateClashConfig(nodes, format.Type)
		case "singbox":
			err = s.generateSingBoxConfig(nodes)
		case "v2ray":
			err = s.generateV2rayConfig(nodes)
		default:
			err = fmt.Errorf("不支持的输出格式: %s", format.Type)
		}
		
		if err != nil {
			errs = append(errs, fmt.Sprintf("%s: %v", format.Type, err))
		}
	}
	
	// 始终生成base64格式的订阅
	if err := s.generateBase64Config(nodes); err != nil {
		errs = append(errs, fmt.Sprintf("base64: %v", err))
	}
	
	if len(errs) > 0 {
		return fmt.Errorf("生成输出错误: %s", strings.Join(errs, "; "))
	}
	
	return nil
}

// 生成Clash/Mihomo配置
func (s *OutputService) generateClashConfig(nodes []*model.ProxyNode, outputType string) error {
	// 创建基本配置
	config := map[string]interface{}{
		"port":               7890,
		"socks-port":         7891,
		"allow-lan":          true,
		"mode":               "rule",
		"log-level":          "info",
		"external-controller": "127.0.0.1:9090",
	}
	
	// 添加DNS配置
	config["dns"] = map[string]interface{}{
		"enable":     true,
		"ipv6":       false,
		"nameserver": []string{"223.5.5.5", "114.114.114.114"},
	}
	
	// 转换节点为Clash格式
	proxies := make([]map[string]interface{}, 0, len(nodes))
	for _, node := range nodes {
		if !node.Active {
			continue
		}
		
		// 如果有原始数据，直接使用
		if node.RawData != nil && node.Type == "ss" || node.Type == "vmess" || node.Type == "trojan" {
			// 克隆原始数据
			proxy := make(map[string]interface{})
			for k, v := range node.RawData {
				proxy[k] = v
			}
			
			// 更新名称（已经通过RenameNodes更新过）
			proxy["name"] = node.Name
			
			proxies = append(proxies, proxy)
			continue
		}
		
		// 否则根据节点类型创建新的配置
		proxy := map[string]interface{}{
			"name":   node.Name,
			"server": node.Server,
			"port":   node.Port,
			"type":   node.Type,
		}
		
		// 根据节点类型添加特定字段
		switch node.Type {
		case "ss":
			proxy["cipher"] = node.Cipher
			proxy["password"] = node.Password
			proxy["udp"] = node.UDP
		case "vmess":
			proxy["uuid"] = node.UUID
			proxy["alterId"] = 0
			proxy["cipher"] = node.Cipher
			proxy["udp"] = node.UDP
			proxy["tls"] = node.TLS
			proxy["network"] = node.Network
		case "trojan":
			proxy["password"] = node.Password
			proxy["udp"] = node.UDP
			proxy["tls"] = true
			if node.Network != "" {
				proxy["network"] = node.Network
			}
		}
		
		proxies = append(proxies, proxy)
	}
	
	config["proxies"] = proxies
	
	// 添加代理组
	proxyGroups := []map[string]interface{}{
		{
			"name":     "PROXY",
			"type":     "select",
			"proxies":  []string{"AUTO", "DIRECT"},
		},
		{
			"name":     "AUTO",
			"type":     "url-test",
			"url":      "http://www.gstatic.com/generate_204",
			"interval": 300,
			"proxies":  getProxyNames(proxies),
		},
	}
	
	// 为特定API添加代理组
	if s.cfg.NodeCheck.APITest.Enable {
		for _, target := range s.cfg.NodeCheck.APITest.Targets {
			apiProxies := []string{}
			for _, node := range nodes {
				if node.Active && node.APIConnectivity[target.Name] {
					apiProxies = append(apiProxies, node.Name)
				}
			}
			
			if len(apiProxies) > 0 {
				proxyGroups = append(proxyGroups, map[string]interface{}{
					"name":     target.Name,
					"type":     "select",
					"proxies":  append([]string{"DIRECT"}, apiProxies...),
				})
			}
		}
	}
	
	config["proxy-groups"] = proxyGroups
	
	// 添加规则
	rules := []string{
		"DOMAIN-SUFFIX,openai.com,OpenAI",
		"DOMAIN-SUFFIX,googleapis.com,Gemini",
		"GEOIP,CN,DIRECT",
		"MATCH,PROXY",
	}
	config["rules"] = rules
	
	// 转换为YAML
	data, err := yaml.Marshal(config)
	if err != nil {
		return err
	}
	
	// 保存文件
	var filename string
	if outputType == "mihomo" {
		filename = "mihomo.yaml"
	} else {
		filename = "clash.yaml"
	}
	
	return s.saveOutput(filename, data)
}

// 生成SingBox配置
func (s *OutputService) generateSingBoxConfig(nodes []*model.ProxyNode) error {
	// 创建基本配置
	config := map[string]interface{}{
		"log": map[string]interface{}{
			"level": "info",
		},
		"dns": map[string]interface{}{
			"servers": []map[string]interface{}{
				{
					"tag":     "dns_default",
					"address": "114.114.114.114",
				},
				{
					"tag":     "dns_local",
					"address": "223.5.5.5",
					"detour":  "direct",
				},
			},
		},
		"inbounds": []map[string]interface{}{
			{
				"type":        "tun",
				"tag":         "tun_in",
				"interface_name": "tun0",
				"inet4_address": "172.19.0.1/30",
				"auto_route":  true,
				"stack":       "system",
			},
			{
				"type":        "mixed",
				"tag":         "mixed_in",
				"listen":      "127.0.0.1",
				"listen_port": 2080,
			},
		},
	}
	
	// 转换节点为SingBox格式
	outbounds := []map[string]interface{}{
		{
			"type": "direct",
			"tag":  "direct",
		},
		{
			"type": "block",
			"tag":  "block",
		},
	}
	
	for _, node := range nodes {
		if !node.Active {
			continue
		}
		
		outbound := map[string]interface{}{
			"tag":     node.Name,
			"server":  node.Server,
			"server_port": node.Port,
		}
		
		// 根据节点类型添加特定字段
		switch node.Type {
		case "ss":
			outbound["type"] = "shadowsocks"
			outbound["method"] = node.Cipher
			outbound["password"] = node.Password
		case "vmess":
			outbound["type"] = "vmess"
			outbound["uuid"] = node.UUID
			outbound["security"] = node.Cipher
			if node.TLS {
				outbound["tls"] = map[string]interface{}{
					"enabled": true,
				}
			}
			if node.Network != "" {
				outbound["transport"] = map[string]interface{}{
					"type": node.Network,
				}
			}
		case "trojan":
			outbound["type"] = "trojan"
			outbound["password"] = node.Password
			outbound["tls"] = map[string]interface{}{
				"enabled": true,
			}
		}
		
		outbounds = append(outbounds, outbound)
	}
	
	// 添加代理选择器
	selector := map[string]interface{}{
		"type":     "selector",
		"tag":      "proxy",
		"outbounds": getProxyNames(convertToMapSlice(outbounds)),
		"default":  outbounds[2]["tag"], // 默认选择第一个节点
	}
	outbounds = append(outbounds, selector)
	
	config["outbounds"] = outbounds
	
	// 添加路由规则
	config["route"] = map[string]interface{}{
		"rules": []map[string]interface{}{
			{
				"domain":    []string{"geosite:cn"},
				"outbound":  "direct",
			},
			{
				"ip_cidr":   []string{"geoip:cn"},
				"outbound":  "direct",
			},
			{
				"domain":    []string{"openai.com"},
				"outbound":  "proxy",
			},
			{
				"domain":    []string{"googleapis.com"},
				"outbound":  "proxy",
			},
		},
		"final":     "proxy",
	}
	
	// 转换为JSON
	data, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		return err
	}
	
	// 保存文件
	return s.saveOutput("singbox.json", data)
}

// 生成V2ray配置
func (s *OutputService) generateV2rayConfig(nodes []*model.ProxyNode) error {
	// V2ray订阅是base64编码的，但格式与singbox不同
	// 这里简单处理，将每个节点转换为URL
	var urls []string
	
	for _, node := range nodes {
		if !node.Active {
			continue
		}
		
		var url string
		switch node.Type {
		case "vmess":
			// 创建vmess链接
			vmessConfig := map[string]interface{}{
				"v":    "2",
				"ps":   node.Name,
				"add":  node.Server,
				"port": node.Port,
				"id":   node.UUID,
				"aid":  0,
				"net":  node.Network,
				"type": "none",
				"tls":  node.TLS,
			}
			
			vmessJSON, err := json.Marshal(vmessConfig)
			if err != nil {
				continue
			}
			
			vmessBase64 := base64.StdEncoding.EncodeToString(vmessJSON)
			url = "vmess://" + vmessBase64
		case "ss":
			// 创建ss链接
			userInfo := base64.StdEncoding.EncodeToString([]byte(fmt.Sprintf("%s:%s", node.Cipher, node.Password)))
			url = fmt.Sprintf("ss://%s@%s:%d#%s", userInfo, node.Server, node.Port, url.QueryEscape(node.Name))
		case "trojan":
			// 创建trojan链接
			url = fmt.Sprintf("trojan://%s@%s:%d#%s", node.Password, node.Server, node.Port, url.QueryEscape(node.Name))
		}
		
		if url != "" {
			urls = append(urls, url)
		}
	}
	
	// 合并所有URL并进行base64编码
	content := strings.Join(urls, "\n")
	encoded := base64.StdEncoding.EncodeToString([]byte(content))
	
	// 保存文件
	return s.saveOutput("v2ray.txt", []byte(encoded))
}

// 生成Base64格式配置
func (s *OutputService) generateBase64Config(nodes []*model.ProxyNode) error {
	// 与V2ray类似，但可能包含更多类型的节点
	var urls []string
	
	for _, node := range nodes {
		if !node.Active {
			continue
		}
		
		var url string
		switch node.Type {
		case "vmess":
			// 创建vmess链接
			vmessConfig := map[string]interface{}{
				"v":    "2",
				"ps":   node.Name,
				"add":  node.Server,
				"port": node.Port,
				"id":   node.UUID,
				"aid":  0,
				"net":  node.Network,
				"type": "none",
				"tls":  node.TLS,
			}
			
			vmessJSON, err := json.Marshal(vmessConfig)
			if err != nil {
				continue
			}
			
			vmessBase64 := base64.StdEncoding.EncodeToString(vmessJSON)
			url = "vmess://" + vmessBase64
		case "ss":
			// 创建ss链接
			userInfo := base64.StdEncoding.EncodeToString([]byte(fmt.Sprintf("%s:%s", node.Cipher, node.Password)))
			url = fmt.Sprintf("ss://%s@%s:%d#%s", userInfo, node.Server, node.Port, url.QueryEscape(node.Name))
		case "trojan":
			// 创建trojan链接
			url = fmt.Sprintf("trojan://%s@%s:%d#%s", node.Password, node.Server, node.Port, url.QueryEscape(node.Name))
		}
		
		if url != "" {
			urls = append(urls, url)
		}
	}
	
	// 合并所有URL并进行base64编码
	content := strings.Join(urls, "\n")
	encoded := base64.StdEncoding.EncodeToString([]byte(content))
	
	// 保存文件
	return s.saveOutput("base64.txt", []byte(encoded))
}

// 保存输出文件
func (s *OutputService) saveOutput(filename string, data []byte) error {
	path := filepath.Join(s.cfg.Output.LocalPath, filename)
	dir := filepath.Dir(path)
	
	// 创建目录
	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}
	
	// 写入文件
	return ioutil.WriteFile(path, data, 0644)
}

// 工具函数：获取代理名称列表
func getProxyNames(proxies []map[string]interface{}) []string {
	names := make([]string, 0, len(proxies))
	for _, proxy := range proxies {
		if name, ok := proxy["name"]; ok {
			if nameStr, ok := name.(string); ok {
				names = append(names, nameStr)
			}
		} else if name, ok := proxy["tag"]; ok {
			if nameStr, ok := name.(string); ok {
				names = append(names, nameStr)
			}
		}
	}
	return names
}

// 工具函数：转换接口切片为map切片
func convertToMapSlice(slice []map[string]interface{}) []map[string]interface{} {
	result := make([]map[string]interface{}, 0, len(slice))
	for _, item := range slice {
		result = append(result, item)
	}
	return result
} 