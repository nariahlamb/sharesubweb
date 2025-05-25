package service

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
	"golang.org/x/net/proxy"
)

// APICheckService API检测服务
type APICheckService struct {
	cfg *config.Config
}

// IPLookupResponse IP查询响应
type IPLookupResponse struct {
	Country     string  `json:"country"`
	CountryCode string  `json:"countryCode"`
	Region      string  `json:"region"`
	RegionName  string  `json:"regionName"`
	City        string  `json:"city"`
	Zip         string  `json:"zip"`
	Lat         float64 `json:"lat"`
	Lon         float64 `json:"lon"`
	Timezone    string  `json:"timezone"`
	ISP         string  `json:"isp"`
	Org         string  `json:"org"`
	AS          string  `json:"as"`
	Query       string  `json:"query"`
}

// OpenAIResponse OpenAI API响应
type OpenAIResponse struct {
	ID      string    `json:"id"`
	Object  string    `json:"object"`
	Created int64     `json:"created"`
	Model   string    `json:"model"`
	Choices []Choice  `json:"choices"`
	Usage   Usage     `json:"usage"`
}

// Choice OpenAI API响应选择
type Choice struct {
	Index        int         `json:"index"`
	Message      Message     `json:"message"`
	FinishReason string      `json:"finish_reason"`
}

// Message OpenAI API响应消息
type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// Usage OpenAI API响应用量
type Usage struct {
	PromptTokens     int `json:"prompt_tokens"`
	CompletionTokens int `json:"completion_tokens"`
	TotalTokens      int `json:"total_tokens"`
}

// NewAPICheckService 创建API检查服务
func NewAPICheckService(cfg *config.Config) *APICheckService {
	return &APICheckService{
		cfg: cfg,
	}
}

// CheckProxyNode 检查代理节点的API连通性
func (s *APICheckService) CheckProxyNode(node *model.ProxyNode) {
	// 初始化API连通性映射
	if node.APIConnectivity == nil {
		node.APIConnectivity = make(map[string]bool)
	}
	
	// 根据配置确定要检测的API
	for _, api := range s.cfg.NodeCheck.API.List {
		if !api.Enable {
			continue
		}
		
		// 根据API名称执行不同的检测
		switch api.Name {
		case "OpenAI":
			node.APIConnectivity["OpenAI"] = s.CheckOpenAI(node)
		case "Gemini":
			node.APIConnectivity["Gemini"] = s.CheckGemini(node)
		case "YouTube":
			node.APIConnectivity["YouTube"] = s.CheckYouTube(node)
		case "Netflix":
			node.APIConnectivity["Netflix"] = s.CheckNetflix(node)
		}
	}
	
	// 检查IP信息
	s.CheckIPInfo(node)
}

// 创建基于节点的HTTP客户端
func (s *APICheckService) createProxyHTTPClient(node *model.ProxyNode) (*http.Client, error) {
	// 设置代理地址
	var proxyURL string
	
	switch node.Type {
	case "ss":
		// 对SS节点，使用本地启动的socks代理
		// 这里假设已经有本地服务将SS节点转为socks代理
		proxyURL = fmt.Sprintf("socks5://127.0.0.1:%d", s.cfg.NodeCheck.LocalPort)
	case "vmess", "trojan", "vless":
		// 对于其他类型节点，同样使用本地服务转发的socks代理
		proxyURL = fmt.Sprintf("socks5://127.0.0.1:%d", s.cfg.NodeCheck.LocalPort)
	default:
		return nil, fmt.Errorf("不支持的节点类型: %s", node.Type)
	}
	
	// 解析代理URL
	parsedURL, err := url.Parse(proxyURL)
	if err != nil {
		return nil, err
	}
	
	// 创建socks5代理拨号器
	dialer, err := proxy.FromURL(parsedURL, proxy.Direct)
	if err != nil {
		return nil, err
	}
	
	// 创建自定义传输
	transport := &http.Transport{
		Dial: dialer.Dial,
		DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			return dialer.(proxy.ContextDialer).DialContext(ctx, network, addr)
		},
		TLSHandshakeTimeout: 10 * time.Second,
	}
	
	// 创建HTTP客户端
	client := &http.Client{
		Transport: transport,
		Timeout:   time.Duration(s.cfg.NodeCheck.API.Timeout) * time.Second,
	}
	
	return client, nil
}

// CheckOpenAI 检测OpenAI API可用性
func (s *APICheckService) CheckOpenAI(node *model.ProxyNode) bool {
	// 创建代理HTTP客户端
	client, err := s.createProxyHTTPClient(node)
	if err != nil {
		return false
	}
	
	// 准备请求体
	requestBody := map[string]interface{}{
		"model": "gpt-3.5-turbo",
		"messages": []map[string]string{
			{
				"role":    "user",
				"content": "Hello",
			},
		},
		"temperature": 0.7,
	}
	
	jsonData, err := json.Marshal(requestBody)
	if err != nil {
		return false
	}
	
	// 创建请求
	req, err := http.NewRequest("POST", "https://api.openai.com/v1/chat/completions", bytes.NewBuffer(jsonData))
	if err != nil {
		return false
	}
	
	// 设置请求头
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", fmt.Sprintf("Bearer %s", s.cfg.NodeCheck.API.OpenAIKey))
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	
	// 检查响应状态
	if resp.StatusCode != http.StatusOK {
		return false
	}
	
	// 读取响应体
	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return false
	}
	
	// 解析响应
	var openAIResp OpenAIResponse
	if err := json.Unmarshal(body, &openAIResp); err != nil {
		return false
	}
	
	// 检查响应是否包含有效内容
	return len(openAIResp.Choices) > 0 && openAIResp.Choices[0].Message.Content != ""
}

// CheckGemini 检测Google Gemini API可用性
func (s *APICheckService) CheckGemini(node *model.ProxyNode) bool {
	// 创建代理HTTP客户端
	client, err := s.createProxyHTTPClient(node)
	if err != nil {
		return false
	}
	
	// 构建API URL
	apiKey := s.cfg.NodeCheck.API.GeminiKey
	if apiKey == "" {
		return false
	}
	
	apiURL := fmt.Sprintf("https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=%s", apiKey)
	
	// 准备请求体
	requestBody := map[string]interface{}{
		"contents": []map[string]interface{}{
			{
				"parts": []map[string]string{
					{
						"text": "Hello",
					},
				},
			},
		},
		"generationConfig": map[string]interface{}{
			"temperature": 0.7,
		},
	}
	
	jsonData, err := json.Marshal(requestBody)
	if err != nil {
		return false
	}
	
	// 创建请求
	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(jsonData))
	if err != nil {
		return false
	}
	
	// 设置请求头
	req.Header.Set("Content-Type", "application/json")
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	
	// 检查响应状态
	return resp.StatusCode == http.StatusOK
}

// CheckYouTube 检测YouTube可用性
func (s *APICheckService) CheckYouTube(node *model.ProxyNode) bool {
	// 创建代理HTTP客户端
	client, err := s.createProxyHTTPClient(node)
	if err != nil {
		return false
	}
	
	// 创建请求
	req, err := http.NewRequest("GET", "https://www.youtube.com/", nil)
	if err != nil {
		return false
	}
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	
	// 检查响应状态
	if resp.StatusCode != http.StatusOK {
		return false
	}
	
	// 读取响应体
	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return false
	}
	
	// 检查响应是否包含YouTube特有内容
	content := string(body)
	return strings.Contains(content, "YouTube") || strings.Contains(content, "youtube")
}

// CheckNetflix 检测Netflix可用性
func (s *APICheckService) CheckNetflix(node *model.ProxyNode) bool {
	// 创建代理HTTP客户端
	client, err := s.createProxyHTTPClient(node)
	if err != nil {
		return false
	}
	
	// 创建请求
	req, err := http.NewRequest("GET", "https://www.netflix.com/title/80018499", nil)
	if err != nil {
		return false
	}
	
	// 设置请求头
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36")
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	
	// 检查状态码不等于403即有效
	// 对于Netflix，如果不可用会返回403，可用会返回200
	return resp.StatusCode != http.StatusForbidden
}

// CheckIPInfo 检查节点出口IP信息
func (s *APICheckService) CheckIPInfo(node *model.ProxyNode) {
	// 创建代理HTTP客户端
	client, err := s.createProxyHTTPClient(node)
	if err != nil {
		return
	}
	
	// 创建请求
	req, err := http.NewRequest("GET", "http://ip-api.com/json/", nil)
	if err != nil {
		return
	}
	
	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		return
	}
	defer resp.Body.Close()
	
	// 检查响应状态
	if resp.StatusCode != http.StatusOK {
		return
	}
	
	// 读取响应体
	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return
	}
	
	// 解析响应
	var ipResp IPLookupResponse
	if err := json.Unmarshal(body, &ipResp); err != nil {
		return
	}
	
	// 设置节点IP信息
	node.OutletIP = ipResp.Query
	node.IPInfo = &model.IPInfo{
		Country:     ipResp.Country,
		CountryCode: ipResp.CountryCode,
		Region:      ipResp.RegionName,
		City:        ipResp.City,
		ISP:         ipResp.ISP,
		ASN:         ipResp.AS,
		Org:         ipResp.Org,
		Lat:         ipResp.Lat,
		Lon:         ipResp.Lon,
		TimeZone:    ipResp.Timezone,
	}
} 