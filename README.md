# ShareSubWeb

一个轻量级的个人订阅管理面板，用于管理和优化代理节点订阅。

## 主要功能

- 显示订阅的到期时间和流量余量
- 大规模节点测活（支持5000+节点）
- 测试节点与OpenAI、Gemini的连通性及IP质量
- 聚合节点并支持多种客户端格式输出（SingBox、Mihomo、V2ray等）
- 直观的Web可视化管理界面

## 部署方式

### Docker部署

```bash
docker run -d \
  --name sharesubweb \
  -p 8199:8199 \
  -v ./config:/app/config \
  -v ./output:/app/output \
  --restart always \
  ghcr.io/nariahlamb/sharesubweb:latest
```

### 源码运行

```bash
go run main.go
```

## 配置文件

配置文件位于`config/config.yaml`，详细配置说明见文档。

## 使用说明

访问 http://localhost:8199 进入管理面板。

## 免责声明

本工具仅用于学习和研究目的。使用者应自行承担使用风险，并遵守相关法律法规。
