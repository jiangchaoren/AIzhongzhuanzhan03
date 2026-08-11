<?php

/**
 * 图片生成连通性诊断脚本
 * 用法: php worker/diag_api.php
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/generation.php';

echo "=== 图片 API 连通性诊断 ===\n\n";

// 1. 环境检查
echo "[1] PHP 版本: " . PHP_VERSION . "\n";
echo "    cURL 版本: " . (function_exists('curl_version') ? json_encode(curl_version()) : 'NOT AVAILABLE') . "\n";
echo "    SSL 验证: " . (ssl_verify_enabled() ? '开启' : '关闭') . "\n\n";

// 2. 常量检测
echo "[2] cURL 常量检测:\n";
$constants = [
    'CURL_HTTP_VERSION_1_1'  => 'HTTP/1.1',
    'CURLSSLOPT_NATIVE_CA'   => '系统CA库',
    'CURL_SSLVERSION_TLSv1_2'=> 'TLS 1.2',
];
foreach ($constants as $name => $desc) {
    echo "    {$name} ({$desc}): " . (defined($name) ? '✓' : '✗ 未定义') . "\n";
}
echo "\n";

// 3. 模型配置
echo "[3] 活跃模型配置:\n";
$models = db()->query("SELECT id, name, model_id, base_url, api_path, site_type FROM ai_models WHERE is_active = 1 LIMIT 5")->fetchAll();
foreach ($models as $m) {
    echo "    ID={$m['id']} name={$m['name']} model={$m['model_id']}\n";
    echo "      base_url={$m['base_url']} api_path={$m['api_path']} site_type={$m['site_type']}\n";
}
echo "\n";

// 4. 选择一个模型做实际测试
if (empty($models)) {
    echo "[4] 无活跃模型，请先去后台 /admin/ai_models 配置\n";
    exit(1);
}

$model = $models[0];
$baseUrl = rtrim($model['base_url'], '/');
$apiPath = trim($model['api_path'] ?? '');
$url = api_build_url($baseUrl, $apiPath !== '' ? $apiPath : 'v1/images/generations');

echo "[4] 构建请求:\n";
echo "    目标 URL: {$url}\n";
echo "    API Key: " . substr((string)($model['api_key'] ?? ''), 0, 12) . "...\n";
echo "    模型名称: {$model['model_id']}\n\n";

// 5. 连通性测试
echo "[5] TCP 连通性测试:\n";
$parsed = parse_url($url);
$host = $parsed['host'] ?? '';
$port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
if ($host === '') {
    echo "    无法解析 URL\n";
} else {
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($fp) {
        echo "    ✓ TCP 连接成功 {$host}:{$port}\n";
        fclose($fp);
    } else {
        echo "    ✗ TCP 连接失败: {$errstr} (errno={$errno})\n";
    }
}
echo "\n";

// 6. 发送一次 api_curl_post_json（不含图片生成 payload）
echo "[6] 发送测试请求（空 prompt，预期收到 API 错误/参数校验）:\n";
$payload = [
    'model' => $model['model_id'],
    'prompt' => 'test',
    'n' => 1,
    'size' => '1024x1024',
    'response_format' => 'url',
];
echo "    请求体: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";

$start = microtime(true);
$response = api_curl_post_json($url, (string)($model['api_key'] ?? ''), $payload, 30);
$elapsed = round((microtime(true) - $start) * 1000);

echo "    耗时: {$elapsed}ms\n";
echo "    HTTP 状态码: {$response['http_code']}\n";
echo "    Content-Type: {$response['content_type']}\n";
echo "    cURL 错误: " . ($response['error'] ?: '无') . "\n";
$bodyExcerpt = is_string($response['raw']) ? mb_substr($response['raw'], 0, 500) : '(false)';
echo "    响应体(前500字): {$bodyExcerpt}\n\n";

// 7. 使用 response_format: b64_json 再测一次
echo "[7] 发送测试请求（response_format: b64_json）:\n";
$payload2 = $payload;
$payload2['response_format'] = 'b64_json';

$start = microtime(true);
$response2 = api_curl_post_json($url, (string)($model['api_key'] ?? ''), $payload2, 30);
$elapsed = round((microtime(true) - $start) * 1000);

echo "    耗时: {$elapsed}ms\n";
echo "    HTTP 状态码: {$response2['http_code']}\n";
echo "    cURL 错误: " . ($response2['error'] ?: '无') . "\n";
$bodyExcerpt = is_string($response2['raw']) ? mb_substr($response2['raw'], 0, 500) : '(false)';
echo "    响应体(前500字): {$bodyExcerpt}\n\n";

echo "=== 诊断完成 ===\n";
