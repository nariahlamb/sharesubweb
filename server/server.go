package server

import (
	"context"
	"fmt"
	"html/template"
	"net/http"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
	"github.com/nariahlamb/sharesubweb/service"
)

// Server HTTP服务器
type Server struct {
	cfg          *config.Config
	subService   *service.SubscriptionService
	nodeService  *service.NodeService
	outputService *service.OutputService
	httpServer   *http.Server
}

// NewServer 创建HTTP服务器
func NewServer(cfg *config.Config, subService *service.SubscriptionService, nodeService *service.NodeService) *Server {
	// 创建输出服务
	outputService := service.NewOutputService(cfg, nodeService, subService)
	
	server := &Server{
		cfg:          cfg,
		subService:   subService,
		nodeService:  nodeService,
		outputService: outputService,
	}
	
	// 设置订阅服务
	nodeService.SetSubscriptionService(subService)
	
	return server
}

// Start 启动服务器
func (s *Server) Start() error {
	// 初始化路由
	r := gin.Default()
	
	// 配置中间件
	r.Use(gin.Recovery())
	
	// 静态文件
	r.Static("/static", "./web/static")
	
	// 加载HTML模板
	r.SetFuncMap(template.FuncMap{
		"unescapeHTML": func(s string) template.HTML {
			return template.HTML(s)
		},
	})
	r.LoadHTMLGlob("./web/template/*")
	
	// 首页
	r.GET("/", func(c *gin.Context) {
		c.Redirect(http.StatusFound, "/dashboard")
	})
	
	// 面板相关路由
	dashboard := r.Group("/dashboard")
	{
		dashboard.GET("", s.handleDashboard)
		dashboard.GET("/subscriptions", s.handleListSubscriptions)
		dashboard.POST("/subscriptions", s.handleAddSubscription)
		dashboard.POST("/subscriptions/batch", s.handleBatchAddSubscription) // 批量添加订阅
		dashboard.GET("/subscriptions/edit/:id", s.handleEditSubscription)
		dashboard.POST("/subscriptions/:id", s.handleUpdateSubscription)
		dashboard.POST("/subscriptions/:id/delete", s.handleDeleteSubscription)
		dashboard.POST("/subscriptions/:id/refresh", s.handleRefreshSubscription)
		dashboard.POST("/subscriptions/refresh-all", s.handleRefreshAllSubscriptions)
		
		dashboard.GET("/nodes", s.handleListNodes)
		dashboard.POST("/nodes/check-all", s.handleCheckAllNodes)
		dashboard.POST("/nodes/rename", s.handleRenameNodes)
		
		dashboard.POST("/output/generate", s.handleGenerateOutput)
	}
	
	// 订阅路由
	sub := r.Group("/sub")
	{
		sub.GET("/clash.yaml", s.handleGetClashConfig)
		sub.GET("/mihomo.yaml", s.handleGetMihomoConfig)
		sub.GET("/singbox.json", s.handleGetSingBoxConfig)
		sub.GET("/v2ray.txt", s.handleGetV2rayConfig)
		sub.GET("/base64.txt", s.handleGetBase64Config)
	}
	
	// 启动HTTP服务器
	addr := fmt.Sprintf(":%d", s.cfg.App.Port)
	s.httpServer = &http.Server{
		Addr:    addr,
		Handler: r,
	}
	
	// 启动节点服务
	s.nodeService.Start()
	
	// 启动HTTP服务器
	return s.httpServer.ListenAndServe()
}

// Stop 停止服务器
func (s *Server) Stop() {
	// 停止节点服务
	s.nodeService.Stop()
	
	// 关闭HTTP服务器
	if s.httpServer != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		s.httpServer.Shutdown(ctx)
	}
}

// Dashboard handlers

// 处理仪表盘页面
func (s *Server) handleDashboard(c *gin.Context) {
	subs := s.subService.GetSubscriptions()
	
	// 统计数据
	totalNodes := 0
	activeNodes := 0
	for _, sub := range subs {
		totalNodes += sub.TotalNodes
		activeNodes += sub.ActiveNodes
	}
	
	// 构建基础URL
	baseUrl := fmt.Sprintf("http://%s", c.Request.Host)
	
	c.HTML(http.StatusOK, "dashboard.html", gin.H{
		"title":       s.cfg.App.Name,
		"version":     "1.0.0",
		"subCount":    len(subs),
		"totalNodes":  totalNodes,
		"activeNodes": activeNodes,
		"baseUrl":     baseUrl,
	})
}

// 处理订阅列表
func (s *Server) handleListSubscriptions(c *gin.Context) {
	subs := s.subService.GetSubscriptions()
	c.HTML(http.StatusOK, "subscriptions.html", gin.H{
		"title":         s.cfg.App.Name,
		"version":       "1.0.0",
		"subscriptions": subs,
	})
}

// 处理添加订阅
func (s *Server) handleAddSubscription(c *gin.Context) {
	var form struct {
		Name    string `form:"name" binding:"required"`
		URL     string `form:"url" binding:"required"`
		Type    string `form:"type" binding:"required"`
		Remarks string `form:"remarks"`
	}
	
	if err := c.ShouldBind(&form); err != nil {
		c.HTML(http.StatusBadRequest, "error.html", gin.H{
			"error": "表单参数错误",
		})
		return
	}
	
	sub := &model.Subscription{
		Name:    form.Name,
		URL:     form.URL,
		Type:    form.Type,
		Remarks: form.Remarks,
	}
	
	if err := s.subService.AddSubscription(sub); err != nil {
		c.HTML(http.StatusInternalServerError, "error.html", gin.H{
			"error": fmt.Sprintf("添加订阅失败: %v", err),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理批量添加订阅
func (s *Server) handleBatchAddSubscription(c *gin.Context) {
	var form struct {
		BatchSubscriptions string `form:"batch_subscriptions" binding:"required"`
	}
	
	if err := c.ShouldBind(&form); err != nil {
		c.HTML(http.StatusBadRequest, "error.html", gin.H{
			"error": "表单参数错误",
		})
		return
	}
	
	// 解析每行数据
	lines := strings.Split(form.BatchSubscriptions, "\n")
	successCount := 0
	errorMessages := []string{}
	
	for lineNum, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		
		// 解析CSV格式：名称,类型,URL,备注(可选)
		parts := strings.Split(line, ",")
		if len(parts) < 3 {
			errorMessages = append(errorMessages, fmt.Sprintf("第%d行格式错误，需要至少3个字段", lineNum+1))
			continue
		}
		
		sub := &model.Subscription{
			Name: strings.TrimSpace(parts[0]),
			Type: strings.TrimSpace(parts[1]),
			URL:  strings.TrimSpace(parts[2]),
		}
		
		// 可选的备注字段
		if len(parts) > 3 {
			sub.Remarks = strings.TrimSpace(parts[3])
		}
		
		if err := s.subService.AddSubscription(sub); err != nil {
			errorMessages = append(errorMessages, fmt.Sprintf("第%d行添加失败: %v", lineNum+1, err))
		} else {
			successCount++
		}
	}
	
	// 返回结果
	if len(errorMessages) > 0 {
		c.HTML(http.StatusOK, "message.html", gin.H{
			"title":   "批量添加结果",
			"message": fmt.Sprintf("成功添加%d个订阅，失败%d个", successCount, len(errorMessages)),
			"details": strings.Join(errorMessages, "<br>"),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理编辑订阅页面
func (s *Server) handleEditSubscription(c *gin.Context) {
	id := c.Param("id")
	
	sub, err := s.subService.GetSubscription(id)
	if err != nil {
		c.HTML(http.StatusNotFound, "error.html", gin.H{
			"error": "订阅不存在",
		})
		return
	}
	
	c.HTML(http.StatusOK, "edit_subscription.html", gin.H{
		"title":        s.cfg.App.Name,
		"version":      "1.0.0",
		"subscription": sub,
	})
}

// 处理更新订阅
func (s *Server) handleUpdateSubscription(c *gin.Context) {
	id := c.Param("id")
	var form struct {
		Name    string `form:"name" binding:"required"`
		URL     string `form:"url" binding:"required"`
		Type    string `form:"type" binding:"required"`
		Remarks string `form:"remarks"`
	}
	
	if err := c.ShouldBind(&form); err != nil {
		c.HTML(http.StatusBadRequest, "error.html", gin.H{
			"error": "表单参数错误",
		})
		return
	}
	
	sub, err := s.subService.GetSubscription(id)
	if err != nil {
		c.HTML(http.StatusNotFound, "error.html", gin.H{
			"error": "订阅不存在",
		})
		return
	}
	
	sub.Name = form.Name
	sub.URL = form.URL
	sub.Type = form.Type
	sub.Remarks = form.Remarks
	
	if err := s.subService.UpdateSubscription(sub); err != nil {
		c.HTML(http.StatusInternalServerError, "error.html", gin.H{
			"error": fmt.Sprintf("更新订阅失败: %v", err),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理删除订阅
func (s *Server) handleDeleteSubscription(c *gin.Context) {
	id := c.Param("id")
	
	if err := s.subService.DeleteSubscription(id); err != nil {
		c.HTML(http.StatusNotFound, "error.html", gin.H{
			"error": "订阅不存在",
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理刷新订阅
func (s *Server) handleRefreshSubscription(c *gin.Context) {
	id := c.Param("id")
	
	if err := s.subService.RefreshSubscription(id); err != nil {
		c.HTML(http.StatusInternalServerError, "error.html", gin.H{
			"error": fmt.Sprintf("刷新订阅失败: %v", err),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理刷新所有订阅
func (s *Server) handleRefreshAllSubscriptions(c *gin.Context) {
	results := s.subService.RefreshAllSubscriptions()
	
	var errors []string
	for id, err := range results {
		if err != nil {
			sub, _ := s.subService.GetSubscription(id)
			name := "未知"
			if sub != nil {
				name = sub.Name
			}
			errors = append(errors, fmt.Sprintf("%s: %v", name, err))
		}
	}
	
	if len(errors) > 0 {
		c.HTML(http.StatusInternalServerError, "error.html", gin.H{
			"error": fmt.Sprintf("刷新订阅失败: %s", strings.Join(errors, "; ")),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard/subscriptions")
}

// 处理节点列表
func (s *Server) handleListNodes(c *gin.Context) {
	nodes := s.subService.GetAllNodes()
	c.HTML(http.StatusOK, "nodes.html", gin.H{
		"title": s.cfg.App.Name,
		"nodes": nodes,
		"version": "1.0.0",
	})
}

// 处理检测所有节点
func (s *Server) handleCheckAllNodes(c *gin.Context) {
	s.nodeService.CheckAllNodes()
	c.Redirect(http.StatusFound, "/dashboard/nodes")
}

// 处理重命名节点
func (s *Server) handleRenameNodes(c *gin.Context) {
	s.nodeService.RenameNodes()
	c.Redirect(http.StatusFound, "/dashboard/nodes")
}

// 处理生成输出
func (s *Server) handleGenerateOutput(c *gin.Context) {
	err := s.outputService.GenerateOutputs()
	if err != nil {
		c.HTML(http.StatusInternalServerError, "error.html", gin.H{
			"error": fmt.Sprintf("生成输出失败: %v", err),
		})
		return
	}
	
	c.Redirect(http.StatusFound, "/dashboard")
}

// Subscription handlers

// 处理获取Clash配置
func (s *Server) handleGetClashConfig(c *gin.Context) {
	path := filepath.Join(s.cfg.Output.LocalPath, "clash.yaml")
	c.File(path)
}

// 处理获取Mihomo配置
func (s *Server) handleGetMihomoConfig(c *gin.Context) {
	path := filepath.Join(s.cfg.Output.LocalPath, "mihomo.yaml")
	c.File(path)
}

// 处理获取SingBox配置
func (s *Server) handleGetSingBoxConfig(c *gin.Context) {
	path := filepath.Join(s.cfg.Output.LocalPath, "singbox.json")
	c.File(path)
}

// 处理获取V2ray配置
func (s *Server) handleGetV2rayConfig(c *gin.Context) {
	path := filepath.Join(s.cfg.Output.LocalPath, "v2ray.txt")
	c.File(path)
}

// 处理获取Base64配置
func (s *Server) handleGetBase64Config(c *gin.Context) {
	path := filepath.Join(s.cfg.Output.LocalPath, "base64.txt")
	c.File(path)
} 