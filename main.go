package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/model"
	"github.com/nariahlamb/sharesubweb/service"
	"github.com/gin-gonic/gin"
	"github.com/gin-contrib/cors"
	"github.com/robfig/cron/v3"
)

var (
	// 版本信息，在构建时注入
	Version   = "1.0.0"
	BuildTime = "unknown"
	configFile = flag.String("config", "./config/config.yaml", "配置文件路径")
)

func main() {
	flag.Parse()

	// 加载配置
	fmt.Printf("ShareSubWeb %s (构建时间: %s)\n", Version, BuildTime)
	fmt.Printf("正在加载配置...\n")
	
	cfg, err := config.LoadConfig(*configFile)
	if err != nil {
		log.Fatalf("加载配置失败: %v", err)
	}

	// 创建服务
	fmt.Printf("正在初始化服务...\n")

	// 订阅服务
	subscriptionService := service.NewSubscriptionService(cfg)
	
	// 节点服务
	nodeService := service.NewNodeService(cfg)
	nodeService.SetSubscriptionService(subscriptionService)

	// API检查服务
	apiCheckService := service.NewAPICheckService(cfg)

	// 输出生成服务
	outputGenerator := service.NewOutputGenerator(cfg)

	// Gist服务
	gistService := service.NewGistService(cfg)

	// 启动节点服务
	nodeService.Start()
	defer nodeService.Stop()

	// 设置定时任务
	c := cron.New()
	for _, task := range cfg.Tasks {
		taskName := task.Name
		taskActions := task.Actions
		_, err := c.AddFunc(task.Cron, func() {
			fmt.Printf("执行定时任务: %s\n", taskName)
			for _, action := range taskActions {
				switch action {
				case "refresh":
					subscriptionService.RefreshAllSubscriptions()
				case "check":
					nodeService.CheckAllNodes()
				case "save":
					nodes := nodeService.FilterNodes()
					outputGenerator.SaveOutput(nodes)
					
					// 如果启用了Gist保存，则保存到Gist
					if cfg.Output.GistSave {
						gistURL, err := gistService.SaveSubscriptionToGist(subscriptionService, outputGenerator)
						if err != nil {
							fmt.Printf("保存到Gist失败: %v\n", err)
						} else {
							fmt.Printf("成功保存到Gist: %s\n", gistURL)
						}
					}
				default:
					fmt.Printf("未知的任务动作: %s\n", action)
				}
			}
		})
		if err != nil {
			fmt.Printf("添加定时任务失败: %v\n", err)
		}
	}
	c.Start()
	defer c.Stop()

	// 初始化Gin
	router := setupRouter(cfg, subscriptionService, nodeService, apiCheckService, outputGenerator, gistService)

	// 启动HTTP服务器
	srv := &http.Server{
		Addr:    fmt.Sprintf(":%d", cfg.App.Port),
		Handler: router,
	}

	// 在一个协程中启动服务器
	go func() {
		fmt.Printf("HTTP服务器启动在 http://localhost:%d\n", cfg.App.Port)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("HTTP服务器错误: %v", err)
		}
	}()

	// 等待中断信号以优雅地关闭服务器
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit
	
	fmt.Println("正在关闭服务器...")
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		log.Fatal("服务器关闭错误:", err)
	}
}

// 设置路由
func setupRouter(
	cfg *config.Config,
	subscriptionService *service.SubscriptionService,
	nodeService *service.NodeService,
	apiCheckService *service.APICheckService,
	outputGenerator *service.OutputGenerator,
	gistService *service.GistService,
) *gin.Engine {
	gin.SetMode(gin.ReleaseMode)
	router := gin.Default()
	
	// 启用CORS
	router.Use(cors.New(cors.Config{
		AllowOrigins:     []string{"*"},
		AllowMethods:     []string{"GET", "POST", "PUT", "DELETE", "OPTIONS"},
		AllowHeaders:     []string{"Origin", "Authorization", "Content-Type"},
		ExposeHeaders:    []string{"Content-Length"},
		AllowCredentials: true,
		MaxAge:           12 * time.Hour,
	}))

	// 静态文件
	router.Static("/static", "./static")
	router.StaticFile("/", "./static/index.html")
	
	// API中间件，用于验证API密钥
	apiAuthMiddleware := func(c *gin.Context) {
		// 如果未配置API密钥，则不需要验证
		if cfg.App.APIKey == "" {
			c.Next()
			return
		}
		
		// 从请求头或查询参数获取API密钥
		key := c.GetHeader("X-API-Key")
		if key == "" {
			key = c.Query("key")
		}
		
		// 验证API密钥
		if key != cfg.App.APIKey {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "无效的API密钥"})
			c.Abort()
			return
		}
		
		c.Next()
	}
	
	// API路由组
	api := router.Group("/api")
	{
		// 不需要验证的API
		api.GET("/version", func(c *gin.Context) {
			c.JSON(http.StatusOK, gin.H{
				"version":    Version,
				"build_time": BuildTime,
			})
		})

		// 需要验证的API
		apiAuth := api.Group("/", apiAuthMiddleware)
		{
			// 订阅相关API
			apiAuth.GET("/subscriptions", func(c *gin.Context) {
				c.JSON(http.StatusOK, subscriptionService.GetSubscriptions())
			})
			
			apiAuth.GET("/subscription/:id", func(c *gin.Context) {
				id := c.Param("id")
				sub, err := subscriptionService.GetSubscription(id)
				if err != nil {
					c.JSON(http.StatusNotFound, gin.H{"error": err.Error()})
					return
				}
				c.JSON(http.StatusOK, sub)
			})
			
			apiAuth.POST("/subscription", func(c *gin.Context) {
				var sub struct {
					Name    string `json:"name"`
					URL     string `json:"url"`
					Type    string `json:"type"`
					Remarks string `json:"remarks"`
				}
				
				if err := c.BindJSON(&sub); err != nil {
					c.JSON(http.StatusBadRequest, gin.H{"error": "无效的请求体"})
					return
				}
				
				newSub := &model.Subscription{
					Name:    sub.Name,
					URL:     sub.URL,
					Type:    sub.Type,
					Remarks: sub.Remarks,
				}
				
				if err := subscriptionService.AddSubscription(newSub); err != nil {
					c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
					return
				}
				
				c.JSON(http.StatusCreated, newSub)
			})
			
			apiAuth.PUT("/subscription/:id", func(c *gin.Context) {
				id := c.Param("id")
				sub, err := subscriptionService.GetSubscription(id)
				if err != nil {
					c.JSON(http.StatusNotFound, gin.H{"error": err.Error()})
					return
				}
				
				var updateSub struct {
					Name    string `json:"name"`
					URL     string `json:"url"`
					Type    string `json:"type"`
					Remarks string `json:"remarks"`
				}
				
				if err := c.BindJSON(&updateSub); err != nil {
					c.JSON(http.StatusBadRequest, gin.H{"error": "无效的请求体"})
					return
				}
				
				sub.Name = updateSub.Name
				sub.URL = updateSub.URL
				sub.Type = updateSub.Type
				sub.Remarks = updateSub.Remarks
				
				if err := subscriptionService.UpdateSubscription(sub); err != nil {
					c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
					return
				}
				
				c.JSON(http.StatusOK, sub)
			})
			
			apiAuth.DELETE("/subscription/:id", func(c *gin.Context) {
				id := c.Param("id")
				if err := subscriptionService.DeleteSubscription(id); err != nil {
					c.JSON(http.StatusNotFound, gin.H{"error": err.Error()})
					return
				}
				
				c.JSON(http.StatusOK, gin.H{"success": true})
			})
			
			apiAuth.POST("/subscription/:id/refresh", func(c *gin.Context) {
				id := c.Param("id")
				if err := subscriptionService.RefreshSubscription(id); err != nil {
					c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
					return
				}
				
				c.JSON(http.StatusOK, gin.H{"success": true})
			})
			
			apiAuth.GET("/nodes", func(c *gin.Context) {
				nodes := nodeService.FilterNodes()
				c.JSON(http.StatusOK, nodes)
			})
			
			apiAuth.POST("/nodes/check", func(c *gin.Context) {
				go nodeService.CheckAllNodes()
				c.JSON(http.StatusOK, gin.H{"success": true, "message": "节点检测已启动"})
			})
			
			apiAuth.GET("/config", func(c *gin.Context) {
				format := c.Query("format")
				if format == "" {
					format = "clash" // 默认格式
				}
				
				var content string
				var err error
				nodes := nodeService.FilterNodes()
				
				switch format {
				case "clash":
					content, err = outputGenerator.GenerateClashConfig(nodes)
				case "singbox":
					content, err = outputGenerator.GenerateSingBoxConfig(nodes)
				case "v2ray":
					content, err = outputGenerator.GenerateBase64Config(nodes)
				default:
					c.JSON(http.StatusBadRequest, gin.H{"error": "不支持的格式"})
					return
				}
				
				if err != nil {
					c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
					return
				}
				
				// 根据格式设置Content-Type
				switch format {
				case "clash":
					c.Header("Content-Type", "text/yaml")
					c.Header("Content-Disposition", "attachment; filename=clash.yaml")
				case "singbox":
					c.Header("Content-Type", "application/json")
					c.Header("Content-Disposition", "attachment; filename=singbox.json")
				case "v2ray":
					c.Header("Content-Type", "text/plain")
					c.Header("Content-Disposition", "attachment; filename=v2ray.txt")
				}
				
				c.String(http.StatusOK, content)
			})
		}
	}
	
	return router
} 