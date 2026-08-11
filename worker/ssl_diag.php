<?php

/**
 * SSL 证书兼容性诊断工具
 * 
 * 用法：php worker/ssl_diag.php [可选：测试URL]
 * 
 * 用于排查 "Certificate key usage inadequate for attempted operation" 错误
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/api_client.php';

echo "╔══════════════════════════════════════════╗\n";
echo "║   SSL 证书兼容性诊断工具 v2              ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

// ─── 1. 环境信息 ───
echo "【1. 环境信息】\n";
echo "  PHP 版本:       " . PHP_VERSION . "\n";
echo "  PHP OS:         " . PHP_OS . " / " . php_uname('r') . "\n";
echo "  服务器软件:     " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') . "\n";

$cv = function_exists('curl_version') ? curl_version() : [];
echo "  cURL 版本:      " . ($cv['version'] ?? 'N/A') . "\n";
echo "  SSL 库:         " . ($cv['ssl_version'] ?? 'N/A') . "\n";
echo "  SSL 版本号:     " . ($cv['ssl_version_number'] ?? 'N/A') . "\n";
echo "  cURL 特性:      " . (isset($cv['features']) ? decbin($cv['features']) : 'N/A') . "\n";

echo "  OpenSSL 版本:   " . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'N/A') . "\n";
echo "  ssl_verify:     " . (ssl_verify_enabled() ? 'true' : 'false') . "\n\n";

// ─── 2. cURL 常量检测 ───
echo "【2. cURL 常量检测】\n";
$constants = [
    'CURLOPT_SSL_VERIFYPEER'     => '对等验证',
    'CURLOPT_SSL_VERIFYHOST'     => '主机验证',
    'CURLOPT_SSLVERSION'         => 'SSL版本',
    'CURL_SSLVERSION_TLSv1_2'   => 'TLS 1.2',
    'CURLOPT_SSL_OPTIONS'        => 'SSL选项',
    'CURLSSLOPT_NATIVE_CA'       => '系统CA库',
    'CURLOPT_HTTP_VERSION'       => 'HTTP版本',
    'CURL_HTTP_VERSION_1_1'      => 'HTTP/1.1',
    'CURLOPT_SSL_VERIFYSTATUS'   => 'OCSP验证',
    'CURLOPT_SSL_CTX_FUNCTION'   => 'SSL上下文回调',
];
foreach ($constants as $name => $desc) {
    $defined = defined($name);
    $value = $defined ? constant($name) : '?';
    echo sprintf("  %-28s %s (值=%s)\n", $name, $defined ? '✓' : '✗', $value);
}
echo "\n";

// ─── 3. 测试 URL 连通性 ───
$testUrl = $argv[1] ?? 'https://api.kbl6.cn/v1/models';
echo "【3. 测试连通性】\n";
echo "  目标:           {$testUrl}\n\n";

// 3a. 纯 PHP 测试
echo "  3a. 原始 cURL（无任何 SSL 处理）:\n";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d | errno=%d\n", $raw !== false ? '✓ 成功' : '✗ 失败', $httpCode, $errno);
echo "    curl_error:    " . ($error ?: '(空)') . "\n\n";

// 3b. 带 api_curl_apply_ssl_options（不重试）
echo "  3b. api_curl_apply_ssl_options（仅预防）:\n";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
api_curl_apply_ssl_options($ch);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d | errno=%d\n", $raw !== false ? '✓ 成功' : '✗ 失败', $httpCode, $errno);
echo "    curl_error:    " . ($error ?: '(空)') . "\n\n";

// 3c. 完整重试链路
echo "  3c. api_curl_exec_with_ssl_retry（完整四层重试）:\n";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
api_curl_apply_ssl_options($ch);
$result = api_curl_exec_with_ssl_retry($ch);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d\n", $result['raw'] !== false ? '✓ 成功' : '✗ 失败', $result['http_code']);
echo "    curl_error:    " . ($result['error'] ?: '(空)') . "\n\n";

// 3d. 直接 VERIFYPEER=false（验证是否能绕过）
echo "  3d. 直接 VERIFYPEER=false:\n";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d | errno=%d\n", $raw !== false ? '✓ 成功' : '✗ 失败', $httpCode, $errno);
echo "    curl_error:    " . ($error ?: '(空)') . "\n\n";

// 3e. VERIFYPEER=false + TLS 1.2
echo "  3e. VERIFYPEER=false + TLS 1.2:\n";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSLVERSION     => defined('CURL_SSLVERSION_TLSv1_2') ? CURL_SSLVERSION_TLSv1_2 : 6,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d | errno=%d\n", $raw !== false ? '✓ 成功' : '✗ 失败', $httpCode, $errno);
echo "    curl_error:    " . ($error ?: '(空)') . "\n\n";

// ─── 4. 授权服务器链接测试 ───
$authUrl = defined('AUTH_DOMAIN') ? AUTH_DOMAIN . '/check.php' : 'https://auth.kbl6.cn/check.php';
echo "【4. 授权服务器测试】\n";
echo "  目标:           {$authUrl}\n\n";
echo "  4a. 预检（VERIFYPEER=false）:\n";
$ch = curl_init($authUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo sprintf("    结果: %s | HTTP %d | errno=%d\n", $raw !== false ? '✓ 成功' : '✗ 失败', $httpCode, $errno);
echo "    curl_error:    " . ($error ?: '(空)') . "\n\n";

echo "  4b. xf_get_curl（内部重试）:\n";
$result = xf_get_curl($authUrl . '?url=test.local', '', [], 10, 2);
echo sprintf("    结果: %s\n", $result !== false ? '✓ 成功' : '✗ 失败');
echo "    (注意: /check.php 需要真实参数，HTTP 可能正常但业务返回非 JSON)\n\n";

// ─── 5. 总结 ───
echo "【5. 诊断总结】\n";

// 检查 3a
if ($raw !== false) {
    echo "  ✓ 原始 cURL 直接连接成功 — SSL 没有问题！\n";
} else {
    echo "  ⚠ 原始 cURL 连接失败\n";
    
    // 检查 3d
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw2 = curl_exec($ch);
    $error2 = curl_error($ch);
    curl_close($ch);
    
    if ($raw2 !== false) {
        echo "  ⚠ VERIFYPEER=false 连接成功 — 是 SSL 证书验证问题\n";
        echo "  建议: 在 config.php 中设置 'ssl_verify' => false\n";
        echo "        或检查服务器证书的 Key Usage 扩展\n";
    } else {
        echo "  ✗ VERIFYPEER=false 也失败 — 可能是网络或 TLS 协议问题\n";
        echo "    curl_error: " . ($error2 ?: '(空)') . "\n";
    }
}

echo "\n╔══════════════════════════════════════════╗\n";
echo "║  诊断完成。请将以上输出发回分析。        ║\n";
echo "╚══════════════════════════════════════════╝\n";
