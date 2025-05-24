package config

import (
	"io/ioutil"
	"os"

	"gopkg.in/yaml.v3"
)

// Config 应用配置结构
type Config struct {
	App           AppConfig           `yaml:"app"`
	Subscriptions []SubscriptionConfig `yaml:"subscriptions"`
	NodeCheck     NodeCheckConfig     `yaml:"node-check"`
	NodeProcess   NodeProcessConfig   `yaml:"node-process"`
	Output        OutputConfig        `yaml:"output"`
}

// AppConfig 应用基本配置
type AppConfig struct {
	Name   string `yaml:"name"`
	Port   int    `yaml:"port"`
	APIKey string `yaml:"api-key"`
}

// SubscriptionConfig 订阅配置
type SubscriptionConfig struct {
	Name    string `yaml:"name"`
	URL     string `yaml:"url"`
	Type    string `yaml:"type"`
	Remarks string `yaml:"remarks"`
}

// NodeCheckConfig 节点检测配置
type NodeCheckConfig struct {
	Concurrency int           `yaml:"concurrency"`
	Timeout     int           `yaml:"timeout"`
	Interval    int           `yaml:"interval"`
	APITest     APITestConfig `yaml:"api-test"`
	IPQuality   IPQualityConfig `yaml:"ip-quality"`
}

// APITestConfig API测试配置
type APITestConfig struct {
	Enable  bool        `yaml:"enable"`
	Targets []APITarget `yaml:"targets"`
}

// APITarget API测试目标
type APITarget struct {
	Name string `yaml:"name"`
	URL  string `yaml:"url"`
}

// IPQualityConfig IP质量测试配置
type IPQualityConfig struct {
	Enable  bool `yaml:"enable"`
	Timeout int  `yaml:"timeout"`
}

// NodeProcessConfig 节点处理配置
type NodeProcessConfig struct {
	Rename RenameConfig `yaml:"rename"`
	Filter FilterConfig `yaml:"filter"`
}

// RenameConfig 节点重命名配置
type RenameConfig struct {
	Enable    bool   `yaml:"enable"`
	AddPrefix string `yaml:"add-prefix"`
	AddSuffix string `yaml:"add-suffix"`
	Template  string `yaml:"template"`
}

// FilterConfig 节点过滤配置
type FilterConfig struct {
	Enable          bool     `yaml:"enable"`
	IncludeKeywords []string `yaml:"include-keywords"`
	ExcludeKeywords []string `yaml:"exclude-keywords"`
}

// OutputConfig 输出配置
type OutputConfig struct {
	SaveMethod string        `yaml:"save-method"`
	LocalPath  string        `yaml:"local-path"`
	WebDAV     WebDAVConfig  `yaml:"webdav"`
	Formats    []FormatConfig `yaml:"formats"`
}

// WebDAVConfig WebDAV配置
type WebDAVConfig struct {
	URL       string `yaml:"url"`
	Username  string `yaml:"username"`
	Password  string `yaml:"password"`
	Directory string `yaml:"directory"`
}

// FormatConfig 输出格式配置
type FormatConfig struct {
	Type   string `yaml:"type"`
	Enable bool   `yaml:"enable"`
}

// LoadConfig 从文件加载配置
func LoadConfig(file string) (*Config, error) {
	// 检查文件是否存在
	if _, err := os.Stat(file); os.IsNotExist(err) {
		// 创建默认配置
		return createDefaultConfig(file)
	}

	// 读取配置文件
	data, err := ioutil.ReadFile(file)
	if err != nil {
		return nil, err
	}

	// 解析配置
	config := &Config{}
	if err := yaml.Unmarshal(data, config); err != nil {
		return nil, err
	}

	return config, nil
}

// 创建默认配置
func createDefaultConfig(file string) (*Config, error) {
	// 创建目录
	dir := getDir(file)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return nil, err
	}

	// 默认配置
	config := &Config{
		App: AppConfig{
			Name:   "ShareSubWeb",
			Port:   8199,
			APIKey: "",
		},
		Subscriptions: []SubscriptionConfig{
			{
				Name:    "订阅1",
				URL:     "",
				Type:    "clash",
				Remarks: "我的主要订阅",
			},
		},
		NodeCheck: NodeCheckConfig{
			Concurrency: 50,
			Timeout:     5,
			Interval:    30,
			APITest: APITestConfig{
				Enable: true,
				Targets: []APITarget{
					{
						Name: "OpenAI",
						URL:  "https://api.openai.com",
					},
					{
						Name: "Gemini",
						URL:  "https://generativelanguage.googleapis.com",
					},
				},
			},
			IPQuality: IPQualityConfig{
				Enable:  true,
				Timeout: 10,
			},
		},
		NodeProcess: NodeProcessConfig{
			Rename: RenameConfig{
				Enable:    true,
				AddPrefix: "",
				AddSuffix: "",
				Template:  "{名称}|{国家}{速度}{API}",
			},
			Filter: FilterConfig{
				Enable:          true,
				IncludeKeywords: []string{},
				ExcludeKeywords: []string{},
			},
		},
		Output: OutputConfig{
			SaveMethod: "local",
			LocalPath:  "./output",
			WebDAV: WebDAVConfig{
				URL:       "",
				Username:  "",
				Password:  "",
				Directory: "/",
			},
			Formats: []FormatConfig{
				{
					Type:   "clash",
					Enable: true,
				},
				{
					Type:   "singbox",
					Enable: true,
				},
				{
					Type:   "v2ray",
					Enable: true,
				},
			},
		},
	}

	// 保存到文件
	data, err := yaml.Marshal(config)
	if err != nil {
		return nil, err
	}

	if err := ioutil.WriteFile(file, data, 0644); err != nil {
		return nil, err
	}

	return config, nil
}

// 获取文件所在目录
func getDir(file string) string {
	for i := len(file) - 1; i >= 0; i-- {
		if file[i] == '/' || file[i] == '\\' {
			return file[:i]
		}
	}
	return "./"
} 