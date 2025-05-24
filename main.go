package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/nariahlamb/sharesubweb/config"
	"github.com/nariahlamb/sharesubweb/server"
	"github.com/nariahlamb/sharesubweb/service"
)

var (
	configFile string
	version    = "1.0.0"
)

func init() {
	flag.StringVar(&configFile, "c", "config/config.yaml", "配置文件路径")
	flag.StringVar(&configFile, "config", "config/config.yaml", "配置文件路径")
	flag.Parse()
}

func main() {
	fmt.Printf("ShareSubWeb 版本 %s\n", version)
	fmt.Println("正在启动...")

	// 加载配置
	cfg, err := config.LoadConfig(configFile)
	if err != nil {
		log.Fatalf("加载配置失败: %v", err)
	}

	// 创建必要的目录
	if err := os.MkdirAll(cfg.Output.LocalPath, 0755); err != nil {
		log.Fatalf("创建输出目录失败: %v", err)
	}

	// 初始化订阅服务
	subService := service.NewSubscriptionService(cfg)
	
	// 初始化节点检测服务
	nodeService := service.NewNodeService(cfg)
	
	// 启动HTTP服务器
	srv := server.NewServer(cfg, subService, nodeService)
	go func() {
		if err := srv.Start(); err != nil {
			log.Fatalf("启动服务器失败: %v", err)
		}
	}()

	// 等待退出信号
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	<-sigCh
	
	fmt.Println("正在关闭服务...")
	srv.Stop()
	fmt.Println("服务已关闭")
} 