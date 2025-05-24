<?php
// clash_processor.php

class ClashProcessor {
    // 支持的加密方式
    private static $supportedCiphers = [
        "none", "auto", "dummy",
        "aes-128-gcm", "aes-192-gcm", "aes-256-gcm",
        "lea-128-gcm", "lea-192-gcm", "lea-256-gcm",
        "aes-128-gcm-siv", "aes-256-gcm-siv",
        "2022-blake3-aes-128-gcm", "2022-blake3-aes-256-gcm",
        "aes-128-cfb", "aes-192-cfb", "aes-256-cfb",
        "aes-128-ctr", "aes-192-ctr", "aes-256-ctr",
        "chacha20", "chacha20-ietf", "chacha20-ietf-poly1305",
        "2022-blake3-chacha20-poly1305", "rabbit128-poly1305",
        "xchacha20-ietf-poly1305", "xchacha20",
        "aegis-128l", "aegis-256", "aez-384",
        "deoxys-ii-256-128", "rc4-md5"
    ];

    // 处理配置
    public static function process($content) {
        try {
            // 判断内容是否为 YAML 格式
            if (!static::isYaml($content)) {
                return $content;
            }

            $config = json_decode(json_encode(yaml_parse($content)), true);
            if (!$config) {
                return $content;
            }

            // 验证代理并收集有效的代理名称
            $validProxies = [];
            $validProxyNames = [];
            if (isset($config['proxies']) && is_array($config['proxies'])) {
                foreach ($config['proxies'] as $proxy) {
                    if (static::isValidProxy($proxy)) {
                        $validProxies[] = $proxy;
                        $validProxyNames[] = $proxy['name'];
                    }
                }
            }
            
            $config['proxies'] = $validProxies;

            // 处理代理组
            if (isset($config['proxy-groups']) && is_array($config['proxy-groups'])) {
                $proxyGroupNames = [];
                $specialProxies = ['REJECT', 'DIRECT', 'REJECT-TINYGIF', 'BLACKHOLE'];
                
                // 第一次遍历收集代理组名称
                foreach ($config['proxy-groups'] as $group) {
                    if (isset($group['name'])) {
                        $proxyGroupNames[] = $group['name'];
                    }
                }

                $allValidNames = array_merge($validProxyNames, $proxyGroupNames, $specialProxies);
                $validGroups = [];

                // 第二次遍历处理每个代理组
                foreach ($config['proxy-groups'] as $group) {
                    if (!isset($group['name']) || !isset($group['proxies']) || !is_array($group['proxies'])) {
                        continue;
                    }

                    // 过滤无效的代理引用
                    $validRefs = array_values(array_filter($group['proxies'], function($name) use ($allValidNames) {
                        return in_array($name, $allValidNames);
                    }));

                    // 只有当代理组至少包含一个有效引用时才保留该组
                    if (!empty($validRefs)) {
                        $group['proxies'] = $validRefs;
                        $validGroups[] = $group;
                    }
                }

                $config['proxy-groups'] = $validGroups;
            }

            // 检查并更新规则，移除引用了不存在代理组的规则
            if (isset($config['rules']) && is_array($config['rules'])) {
                $allValidTargets = array_merge($proxyGroupNames, $specialProxies);
                $validRules = array_filter($config['rules'], function($rule) use ($allValidTargets) {
                    $parts = explode(',', $rule);
                    return count($parts) > 1 && in_array(trim(end($parts)), $allValidTargets);
                });
                $config['rules'] = array_values($validRules);
            }

            return yaml_emit($config, YAML_UTF8_ENCODING);
        } catch (Exception $e) {
            error_log("Clash processing error: " . $e->getMessage());
            return $content;
        }
    }

    // 验证代理配置
    private static function isValidProxy($proxy) {
        if (!isset($proxy['type']) || !isset($proxy['name'])) {
            return false;
        }

        // 检查必需字段
        $requiredFields = static::getRequiredFields($proxy['type']);
        foreach ($requiredFields as $field) {
            if (!isset($proxy[$field]) || empty($proxy[$field])) {
                return false;
            }
        }

        // 检查 IPv6
        if (isset($proxy['server']) && strpos($proxy['server'], ':') !== false) {
            return false;
        }

        // 特定类型的额外验证
        switch ($proxy['type']) {
            case 'ss':
                return isset($proxy['cipher']) && in_array($proxy['cipher'], static::$supportedCiphers);
            case 'vmess':
                return isset($proxy['uuid']) && 
                       preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $proxy['uuid']);
            default:
                return true;
        }
    }

    // 获取必需字段
    private static function getRequiredFields($type) {
        $fields = [
            'ss' => ['name', 'type', 'server', 'port', 'cipher', 'password'],
            'vmess' => ['name', 'type', 'server', 'port', 'uuid'],
            'trojan' => ['name', 'type', 'server', 'port', 'password'],
            'snell' => ['name', 'type', 'server', 'port', 'psk'],
            'socks5' => ['name', 'type', 'server', 'port'],
            'http' => ['name', 'type', 'server', 'port']
        ];
        return $fields[$type] ?? ['name', 'type', 'server', 'port'];
    }

    // 检查是否为 YAML 内容
    private static function isYaml($content) {
        // 简单检查是否包含 YAML 的基本结构
        return preg_match('/^(---|proxies:|port:|name:|server:)/m', trim($content));
    }
}