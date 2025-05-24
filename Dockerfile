FROM golang:1.21-alpine AS builder

WORKDIR /app

# 安装依赖
RUN apk add --no-cache git

# 复制go.mod和go.sum
COPY go.mod go.sum ./
RUN go mod download

# 复制源代码
COPY . .

# 编译
RUN CGO_ENABLED=0 GOOS=linux go build -o sharesubweb .

FROM alpine:latest

WORKDIR /app

# 安装必要的运行时依赖
RUN apk add --no-cache ca-certificates tzdata

# 从builder阶段复制编译好的程序
COPY --from=builder /app/sharesubweb /app/
COPY --from=builder /app/web /app/web

# 创建必要的目录
RUN mkdir -p /app/config /app/output

# 设置工作目录
WORKDIR /app

# 暴露端口
EXPOSE 8199

# 设置时区
ENV TZ=Asia/Shanghai

# 启动命令
CMD ["./sharesubweb", "-c", "/app/config/config.yaml"] 