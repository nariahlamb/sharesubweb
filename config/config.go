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
	Tasks         []TaskConfig        `yaml:"tasks"`   // 计划任务配置
	WebHooks      []WebHookConfig     `yaml:"webhooks"` // WebHook配置
}

// AppConfig 应用基本配置
type AppConfig struct {
	Name    string `yaml:"name"`
	Port    int    `yaml:"port"`
	APIKey  string `yaml:"api-key"`
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
	LocalPort   int           `yaml:"local-port"`    // 本地代理端口，用于测试节点
	API         APIConfig     `yaml:"api"`           // API检测配置
	IPQuality   IPQualityConfig `yaml:"ip-quality"`
}

// APIConfig API检测配置
type APIConfig struct {
	Enable    bool          `yaml:"enable"`    // 是否启用API检测
	Timeout   int           `yaml:"timeout"`   // API请求超时（秒）
	RetryCount int          `yaml:"retry-count"` // 重试次数
	OpenAIKey string        `yaml:"openai-key"`  // OpenAI API密钥
	GeminiKey string        `yaml:"gemini-key"`  // Gemini API密钥
	List      []APIItemConfig `yaml:"list"`      // 待检测API列表
}

// APIItemConfig API项配置
type APIItemConfig struct {
	Name    string            `yaml:"name"`    // API名称
	Enable  bool              `yaml:"enable"`  // 是否启用该API检测
	URL     string            `yaml:"url"`     // API URL
	Headers map[string]string `yaml:"headers"` // API请求头
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
	LocalPath  string        `yaml:"local-path"`
	Formats    []FormatConfig `yaml:"formats"`
	// Gist相关配置
	GistSave   bool          `yaml:"gist-save"`    // 是否启用Gist保存
	GistToken  string        `yaml:"gist-token"`   // GitHub Gist令牌
	GistID     string        `yaml:"gist-id"`      // Gist ID，如果为空则创建新的
	GistDesc   string        `yaml:"gist-desc"`    // Gist描述
	GistFiles  []string      `yaml:"gist-files"`   // 要保存到Gist的文件名
}

// FormatConfig 输出格式配置
type FormatConfig struct {
	Type   string `yaml:"type"`
	Enable bool   `yaml:"enable"`
}

// TaskConfig 计划任务配置
type TaskConfig struct {
	Name      string   `yaml:"name"`       // 任务名称
	Cron      string   `yaml:"cron"`       // Cron表达式 (e.g. "0 */6 * * *")
	Actions   []string `yaml:"actions"`    // 执行的动作，如 "refresh", "check", "save"
}

// WebHookConfig WebHook配置
type WebHookConfig struct {
	URL         string            `yaml:"url"`          // WebHook URL
	Method      string            `yaml:"method"`       // HTTP方法，默认POST
	Headers     map[string]string `yaml:"headers"`      // HTTP头
	ContentType string            `yaml:"content-type"` // 内容类型
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
			APIKey: "", // 默认不启用API认证
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
			LocalPort:   7891, // 默认本地代理端口
			API: APIConfig{
				Enable:    true,
				Timeout:   10,
				RetryCount: 2,
				OpenAIKey: "", // 需要用户配置
				GeminiKey: "", // 需要用户配置
				List: []APIItemConfig{
					{
						Name:   "OpenAI",
						Enable: true,
						URL:    "https://api.openai.com",
						Headers: map[string]string{
							"User-Agent": "ShareSubWeb/1.0",
						},
					},
					{
						Name:   "Gemini",
						Enable: true,
						URL:    "https://generativelanguage.googleapis.com",
						Headers: map[string]string{
							"User-Agent": "ShareSubWeb/1.0",
						},
					},
					{
						Name:   "YouTube",
						Enable: true,
						URL:    "https://www.youtube.com",
						Headers: map[string]string{
							"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
						},
					},
					{
						Name:   "Netflix",
						Enable: true,
						URL:    "https://www.netflix.com/title/80018499",
						Headers: map[string]string{
							"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
						},
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
			LocalPath:  "./output",
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
			GistSave:  false,
			GistToken: "",
			GistID:    "",
			GistDesc:  "ShareSubWeb自动更新的订阅",
			GistFiles: []string{
				"clash.yaml",
				"singbox.json",
				"base64.txt",
			},
		},
		Tasks: []TaskConfig{
			{
				Name:    "每6小时自动更新",
				Cron:    "0 */6 * * *",
				Actions: []string{"refresh", "check", "save"},
			},
		},
		WebHooks: []WebHookConfig{},
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