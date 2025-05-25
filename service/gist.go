package service

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io/ioutil"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
)

// GistService GitHub Gist服务
type GistService struct {
	cfg        *config.Config
	httpClient *http.Client
}

// GistFileContent Gist文件内容
type GistFileContent struct {
	Content string `json:"content"`
}

// GistFiles Gist文件映射
type GistFiles map[string]GistFileContent

// GistRequest Gist请求体
type GistRequest struct {
	Description string   `json:"description"`
	Public      bool     `json:"public"`
	Files       GistFiles `json:"files"`
}

// GistResponse Gist响应体
type GistResponse struct {
	ID    string    `json:"id"`
	URL   string    `json:"html_url"`
	Files GistFiles `json:"files"`
}

// NewGistService 创建GitHub Gist服务
func NewGistService(cfg *config.Config) *GistService {
	return &GistService{
		cfg: cfg,
		httpClient: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
}

// UploadFiles 将文件上传到Gist
func (s *GistService) UploadFiles(files map[string]string) (string, error) {
	if !s.cfg.Output.GistSave {
		return "", errors.New("Gist保存功能未启用")
	}

	if s.cfg.Output.GistToken == "" {
		return "", errors.New("GitHub Token未配置")
	}

	gistFiles := make(GistFiles)
	for filename, content := range files {
		gistFiles[filename] = GistFileContent{
			Content: content,
		}
	}

	request := GistRequest{
		Description: s.cfg.Output.GistDesc,
		Public:      false, // 默认创建私有Gist
		Files:       gistFiles,
	}

	var url string
	var method string

	// 判断是创建还是更新Gist
	if s.cfg.Output.GistID == "" {
		// 创建新的Gist
		url = "https://api.github.com/gists"
		method = "POST"
	} else {
		// 更新现有Gist
		url = fmt.Sprintf("https://api.github.com/gists/%s", s.cfg.Output.GistID)
		method = "PATCH"
	}

	// 序列化请求体
	requestBody, err := json.Marshal(request)
	if err != nil {
		return "", fmt.Errorf("序列化请求失败: %v", err)
	}

	// 创建请求
	req, err := http.NewRequest(method, url, bytes.NewBuffer(requestBody))
	if err != nil {
		return "", fmt.Errorf("创建请求失败: %v", err)
	}

	// 设置请求头
	req.Header.Set("Accept", "application/vnd.github+json")
	req.Header.Set("Authorization", fmt.Sprintf("token %s", s.cfg.Output.GistToken))
	req.Header.Set("Content-Type", "application/json")

	// 发送请求
	resp, err := s.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("发送请求失败: %v", err)
	}
	defer resp.Body.Close()

	// 读取响应
	respBody, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("读取响应失败: %v", err)
	}

	// 处理响应状态码
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusCreated {
		return "", fmt.Errorf("API请求失败: %s, 状态码: %d", string(respBody), resp.StatusCode)
	}

	// 解析响应
	var gistResp GistResponse
	if err := json.Unmarshal(respBody, &gistResp); err != nil {
		return "", fmt.Errorf("解析响应失败: %v", err)
	}

	// 返回Gist ID和URL
	return gistResp.URL, nil
}

// SaveSubscriptionToGist 将订阅保存到Gist
func (s *GistService) SaveSubscriptionToGist(subService *SubscriptionService, outputGenerator *OutputGenerator) (string, error) {
	// 检查是否启用Gist保存
	if !s.cfg.Output.GistSave {
		return "", errors.New("Gist保存功能未启用")
	}

	// 准备保存的文件
	files := make(map[string]string)

	// 读取本地文件并添加到Gist
	for _, filename := range s.cfg.Output.GistFiles {
		switch {
		case strings.Contains(filename, "clash"):
			// 生成Clash配置
			content, err := outputGenerator.GenerateClashConfig(subService.GetActiveNodes())
			if err != nil {
				continue
			}
			files[filepath.Base(filename)] = content

		case strings.Contains(filename, "singbox"):
			// 生成SingBox配置
			content, err := outputGenerator.GenerateSingBoxConfig(subService.GetActiveNodes())
			if err != nil {
				continue
			}
			files[filepath.Base(filename)] = content

		case strings.Contains(filename, "base64"):
			// 生成Base64编码的配置
			content, err := outputGenerator.GenerateBase64Config(subService.GetActiveNodes())
			if err != nil {
				continue
			}
			files[filepath.Base(filename)] = content

		default:
			// 尝试从本地文件读取
			filePath := filepath.Join(s.cfg.Output.LocalPath, filename)
			content, err := ioutil.ReadFile(filePath)
			if err != nil {
				continue
			}
			files[filepath.Base(filename)] = string(content)
		}
	}

	// 如果没有文件要保存，则返回错误
	if len(files) == 0 {
		return "", errors.New("没有可保存的文件")
	}

	// 上传文件到Gist
	return s.UploadFiles(files)
} 