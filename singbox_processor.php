<?php
// singbox_processor.php

class SingboxProcessor {
    // 支持的网络类型
    private static $supportedNetworks = [
        "tcp", "udp", "tcp+udp", "http", "https", "tls", "ws", "grpc"
    ];

    // 支持的协议
    private static $supportedProtocols = [
        "vmess", "vless", "trojan", "shadowsocks", "shadowtls", "socks", "http", "chain", "quic"  
    ];

    // 支持的加密方式
    private static $supportedEncryptions = [
        "none", "auto", "aes-128-gcm", "aes-192-gcm", "aes-256-gcm",
        "chacha20-poly1305", "chacha20-ietf-poly1305", "xchacha20-poly1305","aes-128-cfb", "aes-192-cfb", "aes-256-cfb", "aes-128-ctr", "aes-192-ctr", "aes-256-ctr", "rc4-md5", "chacha20-ietf", "xchacha20"
    ];

    // 处理配置
    public static function process($content) {
        try {
            $config = json_decode($content, true);
            if (!$config) {
                throw new Exception("Invalid JSON config");
            }

            // 检查并升级低版本配置文件
            if (!isset($config['log']['level'])) {
                $config['log']['level'] = "info";
            }
            
            if (!isset($config['dns']['servers'])) {
                $config['dns']['servers'] = ["https://1.1.1.1/dns-query"];
            }
            
            if (!isset($config['outbounds'])) {
                $config['outbounds'] = [
                    [
                        "tag" => "direct",
                        "protocol" => "freedom",
                        "settings" => [
                            "domainStrategy" => "UseIPv4" 
                        ]
                    ]
                ];
            }

            // 处理 inbounds
            if (isset($config['inbounds']) && is_array($config['inbounds'])) {
                foreach ($config['inbounds'] as &$inbound) {
                    // 检查端口
                    if (!isset($inbound['port']) || !is_numeric($inbound['port']) || $inbound['port'] < 1 || $inbound['port'] > 65535) {
                        $inbound['port'] = 1080;
                    }
                    // 其他检查...
                }
            }
            
            // 处理 outbounds
            if (isset($config['outbounds']) && is_array($config['outbounds'])) {
                foreach ($config['outbounds'] as $key => &$outbound) {
                    // 检查必需字段
                    if (!isset($outbound['tag']) || !isset($outbound['protocol'])) {
                        unset($config['outbounds'][$key]);
                        continue;
                    }

                    // 检查协议是否支持  
                    $protocol = strtolower($outbound['protocol']);
                    if (!in_array($protocol, static::$supportedProtocols)) {
                        unset($config['outbounds'][$key]);
                        continue;
                    }

                    // 检查网络类型
                    if (isset($outbound['network'])) {
                        $network = strtolower($outbound['network']);
                        if (!in_array($network, static::$supportedNetworks)) {
                            if ($network === 'none') {
                                unset($config['outbounds'][$key]);
                                continue;
                            }
                            unset($outbound['network']);
                        }
                    }

                    // 特定协议的额外验证和处理  
                    switch ($protocol) {
                        case 'vmess':
                            if (!isset($outbound['settings']['vnext'][0]['address']) ||
                                !isset($outbound['settings']['vnext'][0]['port']) ||
                                !isset($outbound['settings']['vnext'][0]['users'][0]['id'])) {
                                unset($config['outbounds'][$key]);
                            }
                            break;
                        case 'vless':
                            if (!isset($outbound['settings']['vnext'][0]['address']) ||
                                !isset($outbound['settings']['vnext'][0]['port']) ||
                                !isset($outbound['settings']['vnext'][0]['users'][0]['id'])) {
                                unset($config['outbounds'][$key]);  
                            }
                            break;
                        case 'trojan':
                            if (!isset($outbound['settings']['servers'][0]['address']) ||
                                !isset($outbound['settings']['servers'][0]['port']) ||
                                !isset($outbound['settings']['servers'][0]['password'])) {
                                unset($config['outbounds'][$key]);
                            }
                            break;
                        case 'shadowsocks':
                            if (!isset($outbound['settings']['servers'][0]['address']) || 
                                !isset($outbound['settings']['servers'][0]['port']) ||
                                !isset($outbound['settings']['servers'][0]['method']) ||
                                !isset($outbound['settings']['servers'][0]['password'])) {
                                unset($config['outbounds'][$key]);
                            } else {
                                // 检查加密方式是否支持
                                $method = strtolower($outbound['settings']['servers'][0]['method']);
                                if (!in_array($method, static::$supportedEncryptions)) {
                                    unset($config['outbounds'][$key]);
                                }
                            }
                            break;
                        // 其他协议的验证和处理...
                    }
                }
            }

            // 同步删除规则集中的无效规则
            if (isset($config['route']['rules']) && is_array($config['route']['rules'])) {
                $outboundTags = array_column($config['outbounds'], 'tag');
                foreach ($config['route']['rules'] as $key => $rule) {
                    if (isset($rule['outbound']) && !in_array($rule['outbound'], $outboundTags)) {
                        unset($config['route']['rules'][$key]);
                    }
                }
            }

            return json_encode($config, JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            error_log("Singbox processing error: " . $e->getMessage());
            return $content;  
        }
    }
}