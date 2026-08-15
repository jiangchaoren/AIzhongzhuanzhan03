<?php
//此文件为数据库文件备用版本，如config.php文件不可用时将会使用此文件的数据库配置，删除不影响使用
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'image_platform',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'AI 图片视频创作系统',
        'base_url' => '',
        'session_name' => 'image_platform_session',
        'timezone' => 'Asia/Shanghai',
        // 会话持久时长（秒），默认 86400 = 24 小时，0 = 浏览器关闭即过期
        'session_lifetime' => 86400,
        // 调试模式：开启后显示详细错误信息，生产环境务必关闭
        'debug' => false,
        // SSL 证书验证：生产环境务必保持 true
        // 如果使用自签名证书或内网环境出现 "cURL error 60: SSL certificate problem"，
        // 可临时设为 false，但生产环境务必改回 true
        // 注：系统已内置 CURLSSLOPT_NATIVE_CA + 自动 TLS 降级重试，
        // 可解决 "Certificate key usage inadequate" 等新版本 OpenSSL 兼容性问题
        'ssl_verify' => true,
    ],
    'generation' => [
        'platform_name' => 'AI 图片视频创作系统',
        'timeout' => 300,
        'worker_sleep' => 3,
        'stale_running_after' => 420,
        'version' => '1.0.0',
        'notice' => '注意：因AI算力产图较慢，预计可能3-5分钟不止，请耐心等待，生成失败不消耗次数！',
    ],
    'pay' => [
        'pid' => '',
        'key' => '',
        'notify_url' => '',
        'return_url' => '',
    ],
];
