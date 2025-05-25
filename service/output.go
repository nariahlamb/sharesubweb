package service

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
	yaml "gopkg.in/yaml.v3"
)

// OutputGenerator 输出生成服务
type OutputGenerator struct {
	cfg *config.Config
}

// SingBoxOutbound SingBox出站配置
type SingBoxOutbound struct {
	Tag      string                 `json:"tag"`
	Type     string                 `json:"type"`
	Server   string                 `json:"server"`
	Port     int                    `json:"server_port"`
	Password string                 `json:"password,omitempty"`
	UUID     string                 `json:"uuid,omitempty"`
	Method   string                 `json:"method,omitempty"`
	Network  string                 `json:"network,omitempty"`
	TLS      *SingBoxTLS            `json:"tls,omitempty"`
	UDP      bool                   `json:"udp"`
	Settings map[string]interface{} `json:"settings,omitempty"`
	Transport *SingBoxTransport     `json:"transport,omitempty"`
}

// SingBoxTLS SingBox TLS配置
type SingBoxTLS struct {
	Enabled    bool     `json:"enabled"`
	ServerName string   `json:"server_name,omitempty"`
	ALPN       []string `json:"alpn,omitempty"`
}

// SingBoxTransport SingBox传输配置
type SingBoxTransport struct {
	Type           string `json:"type"`
	Path           string `json:"path,omitempty"`
	Host           string `json:"host,omitempty"`
	ServiceName    string `json:"service_name,omitempty"`
}

// SingBoxConfig SingBox配置
type SingBoxConfig struct {
	Version     string           `json:"version"`
	Outbounds   []SingBoxOutbound `json:"outbounds"`
}

// ClashProxyGroup Clash代理组
type ClashProxyGroup struct {
	Name     string   `yaml:"name"`
	Type     string   `yaml:"type"`
	URL      string   `yaml:"url,omitempty"`
	Interval int      `yaml:"interval,omitempty"`
	Proxies  []string `yaml:"proxies"`
}

// ClashConfig Clash配置
type ClashConfig struct {
	Port               int                `yaml:"port"`
	SocksPort          int                `yaml:"socks-port"`
	AllowLAN           bool               `yaml:"allow-lan"`
	Mode               string             `yaml:"mode"`
	LogLevel           string             `yaml:"log-level"`
	ExternalController string             `yaml:"external-controller"`
	Proxies            []map[string]interface{} `yaml:"proxies"`
	ProxyGroups        []ClashProxyGroup  `yaml:"proxy-groups"`
	Rules              []string           `yaml:"rules"`
}

// NewOutputGenerator 创建输出生成服务
func NewOutputGenerator(cfg *config.Config) *OutputGenerator {
	return &OutputGenerator{
		cfg: cfg,
	}
}

// SaveOutput 保存输出文件
func (g *OutputGenerator) SaveOutput(nodes []*model.ProxyNode) error {
	// 创建输出目录
	if _, err := os.Stat(g.cfg.Output.LocalPath); os.IsNotExist(err) {
		if err := os.MkdirAll(g.cfg.Output.LocalPath, 0755); err != nil {
			return fmt.Errorf("创建输出目录失败: %v", err)
		}
	}

	// 生成并保存各种格式的配置
	for _, format := range g.cfg.Output.Formats {
		if !format.Enable {
			continue
		}

		var err error
		var content string

		// 根据格式生成配置内容
		switch format.Type {
		case "clash":
			content, err = g.GenerateClashConfig(nodes)
		case "singbox":
			content, err = g.GenerateSingBoxConfig(nodes)
		case "v2ray":
			content, err = g.GenerateBase64Config(nodes)
		default:
			continue
		}

		if err != nil {
			fmt.Printf("生成%s格式配置失败: %v\n", format.Type, err)
			continue
		}

		// 保存到文件
		filename := fmt.Sprintf("%s.%s", format.Type, getFileExtension(format.Type))
		filePath := filepath.Join(g.cfg.Output.LocalPath, filename)
		if err := ioutil.WriteFile(filePath, []byte(content), 0644); err != nil {
			fmt.Printf("保存%s格式配置失败: %v\n", format.Type, err)
		}
	}

	return nil
}

// getFileExtension 获取文件扩展名
func getFileExtension(formatType string) string {
	switch formatType {
	case "clash":
		return "yaml"
	case "singbox":
		return "json"
	case "v2ray":
		return "txt"
	default:
		return "txt"
	}
}

// GenerateClashConfig 生成Clash配置
func (g *OutputGenerator) GenerateClashConfig(nodes []*model.ProxyNode) (string, error) {
	// 构建Clash配置结构
	config := ClashConfig{
		Port:               7890,
		SocksPort:          7891,
		AllowLAN:           true,
		Mode:               "rule",
		LogLevel:           "info",
		ExternalController: "127.0.0.1:9090",
		Proxies:            make([]map[string]interface{}, 0, len(nodes)),
		ProxyGroups: []ClashProxyGroup{
			{
				Name:     "Proxy",
				Type:     "select",
				Proxies:  []string{"Auto", "DIRECT"},
			},
			{
				Name:     "Auto",
				Type:     "url-test",
				URL:      "http://www.gstatic.com/generate_204",
				Interval: 300,
				Proxies:  []string{},
			},
		},
		Rules: []string{
			"DOMAIN-SUFFIX,google.com,Proxy",
			"DOMAIN-SUFFIX,github.com,Proxy",
			"DOMAIN-SUFFIX,openai.com,Proxy",
			"DOMAIN-SUFFIX,githubusercontent.com,Proxy",
			"DOMAIN-KEYWORD,google,Proxy",
			"DOMAIN-KEYWORD,github,Proxy",
			"DOMAIN-KEYWORD,openai,Proxy",
			"MATCH,DIRECT",
		},
	}

	// 添加节点
	nodeNames := make([]string, 0, len(nodes))
	for _, node := range nodes {
		// 如果节点不可用，则跳过
		if !node.Active {
			continue
		}

		// 获取重命名后的节点名称
		name := node.Name
		if g.cfg.NodeProcess.Rename.Enable {
			name = node.RenameNode(g.cfg.NodeProcess.Rename.Template)
		}

		// 根据节点类型构建Clash代理
		proxy := make(map[string]interface{})
		proxy["name"] = name

		// 基本属性
		proxy["server"] = node.Server
		proxy["port"] = node.Port
		
		// 根据节点类型设置特定属性
		switch node.Type {
		case "ss":
			proxy["type"] = "ss"
			proxy["password"] = node.Password
			proxy["cipher"] = node.Cipher
			if node.UDP {
				proxy["udp"] = true
			}
		case "vmess":
			proxy["type"] = "vmess"
			proxy["uuid"] = node.UUID
			proxy["alterId"] = 0
			if node.TLS {
				proxy["tls"] = true
				if node.SNI != "" {
					proxy["servername"] = node.SNI
				}
				if node.ALPN != "" {
					proxy["alpn"] = strings.Split(node.ALPN, ",")
				}
			}
			if node.Network != "" {
				proxy["network"] = node.Network
				switch node.Network {
				case "ws":
					if node.Path != "" {
						wsOpts := map[string]interface{}{
							"path": node.Path,
						}
						if node.Host != "" {
							wsOpts["headers"] = map[string]string{
								"Host": node.Host,
							}
						}
						proxy["ws-opts"] = wsOpts
					}
				case "grpc":
					if node.ServiceName != "" {
						grpcOpts := map[string]interface{}{
							"serviceName": node.ServiceName,
						}
						proxy["grpc-opts"] = grpcOpts
					}
				}
			}
			if node.UDP {
				proxy["udp"] = true
			}
		case "trojan":
			proxy["type"] = "trojan"
			proxy["password"] = node.Password
			proxy["tls"] = true
			if node.SNI != "" {
				proxy["sni"] = node.SNI
			}
			if node.ALPN != "" {
				proxy["alpn"] = strings.Split(node.ALPN, ",")
			}
			if node.UDP {
				proxy["udp"] = true
			}
		default:
			// 对于其他类型，使用原始数据
			for k, v := range node.RawData {
				proxy[k] = v
			}
		}

		config.Proxies = append(config.Proxies, proxy)
		nodeNames = append(nodeNames, name)
	}

	// 添加节点到节点组
	config.ProxyGroups[0].Proxies = append(config.ProxyGroups[0].Proxies, nodeNames...)
	config.ProxyGroups[1].Proxies = append(config.ProxyGroups[1].Proxies, nodeNames...)

	// 序列化为YAML
	buf := new(bytes.Buffer)
	encoder := yaml.NewEncoder(buf)
	encoder.SetIndent(2)
	if err := encoder.Encode(config); err != nil {
		return "", err
	}

	return buf.String(), nil
}

// GenerateSingBoxConfig 生成SingBox配置
func (g *OutputGenerator) GenerateSingBoxConfig(nodes []*model.ProxyNode) (string, error) {
	// 构建SingBox配置结构
	config := SingBoxConfig{
		Version:   "2",
		Outbounds: make([]SingBoxOutbound, 0, len(nodes)+1),
	}

	// 添加direct出站
	config.Outbounds = append(config.Outbounds, SingBoxOutbound{
		Tag:  "direct",
		Type: "direct",
	})

	// 添加各个节点为出站
	for i, node := range nodes {
		// 如果节点不可用，则跳过
		if !node.Active {
			continue
		}

		// 重命名处理（直接赋值给outbound.Tag而不是单独声明name变量）
		tag := fmt.Sprintf("proxy_%d", i)
		if g.cfg.NodeProcess.Rename.Enable {
			// 可选：修改标签名称为节点名称
			tag = node.RenameNode(g.cfg.NodeProcess.Rename.Template)
		}

		// 基本出站配置
		outbound := SingBoxOutbound{
			Tag:    tag,
			Server: node.Server,
			Port:   node.Port,
			UDP:    node.UDP,
		}

		// 根据节点类型设置特定属性
		switch node.Type {
		case "ss":
			outbound.Type = "shadowsocks"
			outbound.Method = node.Cipher
			outbound.Password = node.Password
		case "vmess":
			outbound.Type = "vmess"
			outbound.UUID = node.UUID
			
			// 设置传输层
			if node.Network != "" {
				outbound.Transport = &SingBoxTransport{
					Type: node.Network,
				}
				
				// 根据不同传输协议设置特定参数
				switch node.Network {
				case "ws":
					outbound.Transport.Path = node.Path
					outbound.Transport.Host = node.Host
				case "grpc":
					outbound.Transport.ServiceName = node.ServiceName
				}
			}
			
			// 设置TLS
			if node.TLS {
				alpn := []string{}
				if node.ALPN != "" {
					alpn = strings.Split(node.ALPN, ",")
				}
				outbound.TLS = &SingBoxTLS{
					Enabled:    true,
					ServerName: node.SNI,
					ALPN:       alpn,
				}
			}
		case "trojan":
			outbound.Type = "trojan"
			outbound.Password = node.Password
			
			// Trojan默认启用TLS
			alpn := []string{"h2", "http/1.1"}
			if node.ALPN != "" {
				alpn = strings.Split(node.ALPN, ",")
			}
			outbound.TLS = &SingBoxTLS{
				Enabled:    true,
				ServerName: node.SNI,
				ALPN:       alpn,
			}
		default:
			// 跳过不支持的节点类型
			continue
		}

		config.Outbounds = append(config.Outbounds, outbound)
	}

	// 序列化为JSON
	data, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		return "", err
	}

	return string(data), nil
}

// GenerateBase64Config 生成Base64编码的配置
func (g *OutputGenerator) GenerateBase64Config(nodes []*model.ProxyNode) (string, error) {
	// 生成节点URIs
	var uris []string

	for _, node := range nodes {
		// 如果节点不可用，则跳过
		if !node.Active {
			continue
		}

		// 获取重命名后的节点名称
		name := node.Name
		if g.cfg.NodeProcess.Rename.Enable {
			name = node.RenameNode(g.cfg.NodeProcess.Rename.Template)
		}

		var uri string

		// 根据节点类型生成URI
		switch node.Type {
		case "ss":
			// 生成SS URI: ss://BASE64(method:password)@server:port#name
			methodPass := fmt.Sprintf("%s:%s", node.Cipher, node.Password)
			encoded := base64.StdEncoding.EncodeToString([]byte(methodPass))
			uri = fmt.Sprintf("ss://%s@%s:%d#%s", encoded, node.Server, node.Port, url.QueryEscape(name))
		
		case "vmess":
			// 生成VMess URI (JSON格式)
			vmessConfig := map[string]interface{}{
				"v":    "2",
				"ps":   name,
				"add":  node.Server,
				"port": node.Port,
				"id":   node.UUID,
				"aid":  0,
				"net":  node.Network,
				"type": "none",
				"tls":  node.TLS,
			}
			
			if node.Network == "ws" {
				vmessConfig["path"] = node.Path
				vmessConfig["host"] = node.Host
			} else if node.Network == "grpc" {
				vmessConfig["path"] = node.ServiceName
			}
			
			if node.TLS {
				vmessConfig["sni"] = node.SNI
			}
			
			jsonData, err := json.Marshal(vmessConfig)
			if err != nil {
				continue
			}
			
			encoded := base64.StdEncoding.EncodeToString(jsonData)
			uri = fmt.Sprintf("vmess://%s", encoded)
		
		case "trojan":
			// 生成Trojan URI: trojan://password@server:port?sni=xxx#name
			uri = fmt.Sprintf("trojan://%s@%s:%d", node.Password, node.Server, node.Port)
			
			// 添加查询参数
			query := make(url.Values)
			if node.SNI != "" {
				query.Add("sni", node.SNI)
			}
			if node.ALPN != "" {
				query.Add("alpn", node.ALPN)
			}
			
			if len(query) > 0 {
				uri += "?" + query.Encode()
			}
			
			uri += "#" + url.QueryEscape(name)
			
		default:
			// 跳过不支持的节点类型
			continue
		}

		if uri != "" {
			uris = append(uris, uri)
		}
	}

	// 合并所有URI并进行Base64编码
	combinedURIs := strings.Join(uris, "\n")
	encoded := base64.StdEncoding.EncodeToString([]byte(combinedURIs))

	return encoded, nil
}

// GenerateSurgeSubscription 生成Surge格式订阅
func (g *OutputGenerator) GenerateSurgeSubscription(nodes []*model.ProxyNode, outType string, option map[string]interface{}) (string, error) {
	var sb strings.Builder

	// 添加订阅信息
	info := option["info"].(map[string]interface{})
	if info != nil {
		sb.WriteString(fmt.Sprintf("# ShareSubWeb Subscription\n"))
		sb.WriteString(fmt.Sprintf("# Update: %s\n", time.Now().Format(time.RFC3339)))
		
		if val, ok := info["upload"]; ok {
			sb.WriteString(fmt.Sprintf("# Upload: %s\n", g.formatBytes(val.(float64))))
		}
		if val, ok := info["download"]; ok {
			sb.WriteString(fmt.Sprintf("# Download: %s\n", g.formatBytes(val.(float64))))
		}
		if val, ok := info["total"]; ok {
			sb.WriteString(fmt.Sprintf("# Total: %s\n", g.formatBytes(val.(float64))))
		}
		if val, ok := info["expire"]; ok {
			sb.WriteString(fmt.Sprintf("# Expire: %s\n", g.formatTime(val.(float64))))
		}
	}
	sb.WriteString("\n")

	// 添加通用配置
	sb.WriteString("[General]\n")
	sb.WriteString("loglevel = notify\n")
	sb.WriteString("bypass-system = true\n")
	sb.WriteString("skip-proxy = 127.0.0.1,192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,100.64.0.0/10,localhost,*.local,e.crashlytics.com,captive.apple.com,::ffff:0:0:0:0/1,::ffff:128:0:0:0/1\n")
	sb.WriteString("bypass-tun = 10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.0.2.0/24,192.88.99.0/24,192.168.0.0/16,198.18.0.0/15,198.51.100.0/24,203.0.113.0/24,224.0.0.0/4,255.255.255.255/32\n")
	sb.WriteString("dns-server = system,119.29.29.29,223.5.5.5\n")
	sb.WriteString("ipv6 = false\n\n")

	// 添加代理配置
	sb.WriteString("[Proxy]\n")
	sb.WriteString("DIRECT = direct\n")
	for _, node := range nodes {
		if !node.Active {
			continue
		}

		// 对于Surge配置，根据代理类型生成不同的配置
		tag := node.Name
		switch strings.ToLower(node.Type) {
		case "ss", "shadowsocks":
			encMethod := node.Cipher
			if encMethod == "" {
				encMethod = "aes-128-gcm" // 默认加密方式
			}
			password := node.Password
			sb.WriteString(fmt.Sprintf("%s = ss, %s, %d, encrypt-method=%s, password=%s", 
				tag, node.Server, node.Port, encMethod, password))
			
			// 添加UDP支持
			if node.UDP {
				sb.WriteString(", udp-relay=true")
			}

		case "vmess":
			sb.WriteString(fmt.Sprintf("%s = vmess, %s, %d, username=%s", 
				tag, node.Server, node.Port, node.UUID))
			
			// 添加加密方式
			if node.Cipher != "" {
				sb.WriteString(fmt.Sprintf(", encrypt-method=%s", node.Cipher))
			} else {
				sb.WriteString(", encrypt-method=auto")
			}
			
			// 添加传输配置
			if node.Network != "" {
				sb.WriteString(fmt.Sprintf(", transport=%s", node.Network))
				
				// 根据传输方式添加相关配置
				switch node.Network {
				case "ws":
					if node.Path != "" {
						sb.WriteString(fmt.Sprintf(", path=%s", node.Path))
					}
					if node.Host != "" {
						sb.WriteString(fmt.Sprintf(", host=%s", node.Host))
					}
				case "h2":
					if node.Path != "" {
						sb.WriteString(fmt.Sprintf(", path=%s", node.Path))
					}
					if node.Host != "" {
						sb.WriteString(fmt.Sprintf(", host=%s", node.Host))
					}
				}
			}

			// TLS配置
			if node.TLS {
				sb.WriteString(", tls=true")
				if node.SNI != "" {
					sb.WriteString(fmt.Sprintf(", sni=%s", node.SNI))
				} else if node.Host != "" {
					sb.WriteString(fmt.Sprintf(", sni=%s", node.Host))
				}
			}

		case "trojan":
			sb.WriteString(fmt.Sprintf("%s = trojan, %s, %d, password=%s", 
				tag, node.Server, node.Port, node.Password))
			
			// 添加TLS配置
			sb.WriteString(", tls=true")
			if node.SNI != "" {
				sb.WriteString(fmt.Sprintf(", sni=%s", node.SNI))
			} else if node.Host != "" {
				sb.WriteString(fmt.Sprintf(", sni=%s", node.Host))
			}

		case "http":
			sb.WriteString(fmt.Sprintf("%s = http, %s, %d", 
				tag, node.Server, node.Port))
			
			// 添加用户认证
			username := getUsernameFromNode(node)
			if username != "" && node.Password != "" {
				sb.WriteString(fmt.Sprintf(", username=%s, password=%s", 
					username, node.Password))
			}
			
			// 添加TLS配置
			if node.TLS {
				sb.WriteString(", tls=true")
				if node.SNI != "" {
					sb.WriteString(fmt.Sprintf(", sni=%s", node.SNI))
				}
			}
		}
		sb.WriteString("\n")
	}
	sb.WriteString("\n")

	// 添加代理组配置
	sb.WriteString("[Proxy Group]\n")
	sb.WriteString("PROXY = select, DIRECT")
	for _, node := range nodes {
		if !node.Active {
			continue
		}
		sb.WriteString(fmt.Sprintf(", %s", node.Name))
	}
	sb.WriteString("\n\n")

	// 添加规则配置
	sb.WriteString("[Rule]\n")
	sb.WriteString("DOMAIN-SUFFIX,google.com,PROXY\n")
	sb.WriteString("DOMAIN-SUFFIX,facebook.com,PROXY\n")
	sb.WriteString("DOMAIN-SUFFIX,twitter.com,PROXY\n")
	sb.WriteString("DOMAIN-SUFFIX,youtube.com,PROXY\n")
	sb.WriteString("DOMAIN-SUFFIX,github.com,PROXY\n")
	sb.WriteString("GEOIP,CN,DIRECT\n")
	sb.WriteString("FINAL,PROXY\n")

	return sb.String(), nil
}

// formatBytes 格式化字节大小
func (g *OutputGenerator) formatBytes(bytes float64) string {
	if bytes < 1024 {
		return fmt.Sprintf("%.2f B", bytes)
	} else if bytes < 1024*1024 {
		return fmt.Sprintf("%.2f KB", bytes/1024)
	} else if bytes < 1024*1024*1024 {
		return fmt.Sprintf("%.2f MB", bytes/1024/1024)
	} else if bytes < 1024*1024*1024*1024 {
		return fmt.Sprintf("%.2f GB", bytes/1024/1024/1024)
	} else {
		return fmt.Sprintf("%.2f TB", bytes/1024/1024/1024/1024)
	}
}

// formatTime 格式化时间戳
func (g *OutputGenerator) formatTime(timestamp float64) string {
	t := time.Unix(int64(timestamp), 0)
	return t.Format("2006-01-02 15:04:05")
}

// hack - 创建一个辅助函数用于获取用户名
func getUsernameFromNode(node *model.ProxyNode) string {
	// 尝试从原始数据中获取用户名
	if node.RawData != nil {
		if username, ok := node.RawData["username"].(string); ok {
			return username
		}
	}
	return ""
} 