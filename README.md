# ShareSubWeb

一个轻量级的个人订阅管理面板，用于管理和优化代理节点订阅。

## 主要功能

- 显示订阅的到期时间和流量余量
- 大规模节点测活（支持5000+节点）
- 测试节点与OpenAI、Gemini的连通性及IP质量
- 聚合节点并支持多种客户端格式输出（SingBox、Mihomo、V2ray等）
- 直观的Web可视化管理界面

## 本地测试部署

### 方式一：源码直接运行（推荐开发测试）

1. **准备Go环境**：
   - 安装Go 1.21或更高版本
   - 设置好GOPATH环境变量

2. **克隆代码**：
   ```bash
   git clone https://github.com/nariahlamb/sharesubweb.git
   cd sharesubweb
   ```

3. **创建配置文件**：
   ```bash
   mkdir -p config
   cp config/config.yaml.example config/config.yaml  # 如果没有示例配置，则手动创建
   ```
   
4. **修改配置文件**：
   - 编辑`config/config.yaml`，添加您的订阅URL和其他配置
   - 配置文件格式参考下方示例

5. **运行程序**：
   ```bash
   go run main.go
   ```

6. **访问面板**：
   - 浏览器打开 http://localhost:8199

### 方式二：Docker本地部署

1. **安装Docker**：
   - 确保已安装Docker和Docker Compose

2. **创建项目目录**：
   ```bash
   mkdir -p sharesubweb/config sharesubweb/output
   cd sharesubweb
   ```

3. **创建配置文件**：
   ```bash
   touch config/config.yaml
   ```

4. **编辑配置文件**：
   - 在`config/config.yaml`中添加基本配置和您的订阅
   - 配置文件格式参考下方示例
   
5. **创建docker-compose.yml**：
   ```bash
   wget -O docker-compose.yml https://raw.githubusercontent.com/nariahlamb/sharesubweb/main/docker-compose.yml
   # 或Windows系统：
   # curl -o docker-compose.yml https://raw.githubusercontent.com/nariahlamb/sharesubweb/main/docker-compose.yml
   ```
   或手动创建docker-compose.yml文件，内容如下：
   ```yaml
   version: "3"
   
   services:
     sharesubweb:
       image: ghcr.io/nariahlamb/sharesubweb:latest
       container_name: sharesubweb
       volumes:
         - ./config:/app/config
         - ./output:/app/output
       ports:
         - "8199:8199"
       environment:
         - TZ=Asia/Shanghai
       restart: always
   ```

6. **启动容器**：
   ```bash
   docker-compose up -d
   ```

7. **访问面板**：
   - 浏览器打开 http://localhost:8199

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

## 配置文件示例

配置文件位于`config/config.yaml`，基本示例如下：

```yaml
# 基本设置
app:
  name: "ShareSubWeb"
  port: 8199
  api-key: "" # 可选的API密钥，为空则不启用验证

# 订阅源配置
subscriptions:
- name: "订阅1"
  url: "您的订阅URL"
  type: "clash" # 可选：clash, v2ray, ss, ssr, trojan
  remarks: "我的主要订阅"

# 节点测试配置
node-check:
  # 并发测试数量
  concurrency: 50
  # 连接超时（秒）
  timeout: 5
```

更多详细配置选项请参考完整文档。

## 使用说明

访问 http://localhost:8199 进入管理面板。

通过Web界面，您可以：
- 添加和管理订阅
- 查看节点状态和测试结果
- 生成不同格式的聚合订阅
- 自定义节点过滤和排序规则

## 免责声明

本工具仅用于学习和研究目的。使用者应自行承担使用风险，并遵守相关法律法规。
