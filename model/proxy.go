package model

import (
	"fmt"
	"strings"
	"time"
)

// ProxyNode 代理节点
type ProxyNode struct {
	ID             string    `json:"id"`              // 节点唯一标识
	Name           string    `json:"name"`            // 节点名称
	Type           string    `json:"type"`            // 节点类型：ss, vmess, trojan等
	Server         string    `json:"server"`          // 服务器地址
	Port           int       `json:"port"`            // 端口
	Password       string    `json:"password"`        // 密码
	UUID           string    `json:"uuid,omitempty"`  // UUID (vmess/vless)
	Cipher         string    `json:"cipher"`          // 加密方式
	TLS            bool      `json:"tls"`             // 是否启用TLS
	Network        string    `json:"network"`         // 传输协议：tcp, ws, grpc等
	UDP            bool      `json:"udp"`             // 是否支持UDP
	Path           string    `json:"path,omitempty"`  // 路径(ws/grpc/h2)
	ALPN           string    `json:"alpn,omitempty"`  // ALPN(tls)
	SNI            string    `json:"sni,omitempty"`   // SNI(tls)
	Host           string    `json:"host,omitempty"`  // Host(ws)
	ServiceName    string    `json:"service_name,omitempty"` // 服务名称(grpc)
	
	// 测试结果
	Latency        int       `json:"latency"`         // 延迟(ms)
	Speed          int       `json:"speed"`           // 速度(KB/s)
	Active         bool      `json:"active"`          // 是否可用
	APIConnectivity map[string]bool `json:"api_connectivity"` // API连通性测试结果
	IPInfo         *IPInfo   `json:"ip_info,omitempty"` // IP信息
	LastCheck      time.Time `json:"last_check"`      // 最后测试时间
	SuccessRate    int       `json:"success_rate"`    // 成功率(0-100)
	OutletIP       string    `json:"outlet_ip"`       // 出口IP
	
	// 原始数据，保存原节点信息，以便输出时使用
	RawData        map[string]interface{} `json:"-"`
	GroupID        string    `json:"groupid,omitempty"`  // 分组ID
}

// IPInfo IP信息
type IPInfo struct {
	Country     string  `json:"country"`      // 国家
	CountryCode string  `json:"country_code"` // 国家代码
	Region      string  `json:"region"`       // 地区
	City        string  `json:"city"`         // 城市
	ISP         string  `json:"isp"`          // ISP
	ASN         string  `json:"asn"`          // ASN
	Org         string  `json:"org"`          // 组织
	Lat         float64 `json:"lat"`          // 纬度
	Lon         float64 `json:"lon"`          // 经度
	TimeZone    string  `json:"timezone"`     // 时区
}

// Subscription 订阅信息
type Subscription struct {
	ID           string    `json:"id"`            // 订阅ID
	Name         string    `json:"name"`          // 订阅名称
	URL          string    `json:"url"`           // 订阅地址
	Type         string    `json:"type"`          // 订阅类型
	Remarks      string    `json:"remarks"`       // 备注
	
	// 订阅信息
	UploadBytes   int64     `json:"upload_bytes"`    // 已用上传流量
	DownloadBytes int64     `json:"download_bytes"`  // 已用下载流量
	TotalBytes    int64     `json:"total_bytes"`     // 总流量
	ExpiryTime    time.Time `json:"expiry_time"`     // 到期时间
	
	// 节点信息
	Nodes        []*ProxyNode `json:"nodes"`       // 节点列表
	ActiveNodes  int          `json:"active_nodes"` // 可用节点数
	TotalNodes   int          `json:"total_nodes"`  // 总节点数
	
	LastUpdate   time.Time    `json:"last_update"`  // 最后更新时间
}

// FormatTraffic 格式化流量
func FormatTraffic(bytes int64) string {
	if bytes < 1024 {
		return fmt.Sprintf("%d B", bytes)
	} else if bytes < 1024*1024 {
		return fmt.Sprintf("%.2f KB", float64(bytes)/1024)
	} else if bytes < 1024*1024*1024 {
		return fmt.Sprintf("%.2f MB", float64(bytes)/(1024*1024))
	} else {
		return fmt.Sprintf("%.2f GB", float64(bytes)/(1024*1024*1024))
	}
}

// GetRemainingTraffic 获取剩余流量
func (s *Subscription) GetRemainingTraffic() int64 {
	used := s.UploadBytes + s.DownloadBytes
	if s.TotalBytes > 0 && s.TotalBytes > used {
		return s.TotalBytes - used
	}
	return 0
}

// GetRemainingTrafficFormatted 获取格式化的剩余流量
func (s *Subscription) GetRemainingTrafficFormatted() string {
	return FormatTraffic(s.GetRemainingTraffic())
}

// GetRemainingDays 获取剩余天数
func (s *Subscription) GetRemainingDays() int {
	if s.ExpiryTime.IsZero() {
		return 0
	}
	days := int(s.ExpiryTime.Sub(time.Now()).Hours() / 24)
	if days < 0 {
		return 0
	}
	return days
}

// RenameNode 重命名节点
func (p *ProxyNode) RenameNode(template string) string {
	// 如果模板为空，返回原名称
	if template == "" {
		return p.Name
	}
	
	// 替换模板中的变量
	name := template
	name = strings.ReplaceAll(name, "{名称}", p.Name)
	
	// 国家信息
	country := ""
	if p.IPInfo != nil && p.IPInfo.CountryCode != "" {
		country = p.IPInfo.CountryCode
	}
	name = strings.ReplaceAll(name, "{国家}", country)
	
	// 速度信息
	speed := ""
	if p.Speed > 0 {
		speed = fmt.Sprintf("%.1fMB/s", float64(p.Speed)/1024)
	}
	name = strings.ReplaceAll(name, "{速度}", speed)
	
	// 延迟
	latency := ""
	if p.Latency > 0 {
		latency = fmt.Sprintf("%dms", p.Latency)
	}
	name = strings.ReplaceAll(name, "{延迟}", latency)
	
	// 成功率
	successRate := ""
	if p.SuccessRate > 0 {
		successRate = fmt.Sprintf("%d%%", p.SuccessRate)
	}
	name = strings.ReplaceAll(name, "{成功率}", successRate)
	
	// API可用性标签
	apiTags := ""
	for api, ok := range p.APIConnectivity {
		if ok {
			if api == "OpenAI" {
				apiTags += "Openai|"
			} else if api == "Gemini" {
				apiTags += "Gemini|"
			} else if api == "YouTube" {
				apiTags += "Youtube|"
			} else if api == "Netflix" {
				apiTags += "Netflix|"
			}
		}
	}
	if len(apiTags) > 0 {
		apiTags = strings.TrimSuffix(apiTags, "|")
	}
	name = strings.ReplaceAll(name, "{API}", apiTags)
	
	return name
} 