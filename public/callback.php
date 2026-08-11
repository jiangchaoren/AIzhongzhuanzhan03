<?php

/**
 * 聚合登录 OAuth 回调页
 *
 * OAuth 提供方重定向到此处 → 服务端处理回调 → 自动登录或引导绑定
 */

require_once __DIR__ . '/../src/bootstrap.php';

$type = trim((string) ($_GET['type'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));

// ── 渲染 HTML 页面 ──
function callback_page(string $heading, string $message, bool $isError = false): void
{
    $color = $isError ? '#ef4444' : '#111';
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>登录中...</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', 'PingFang SC', 'Microsoft YaHei', sans-serif;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background: #f9fafb;
            }
            .card {
                text-align: center;
                padding: 48px 32px;
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 4px 24px rgba(0,0,0,0.06);
                max-width: 400px;
                width: 90%;
            }
            .spinner {
                width: 48px; height: 48px;
                border: 4px solid #e5e7eb;
                border-top-color: #000;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                margin: 0 auto 24px;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
            h2 { font-size: 18px; margin-bottom: 8px; color: <?= $color ?>; }
            p { font-size: 14px; color: #6b7280; }
            .btn {
                display: inline-block;
                margin-top: 24px;
                padding: 12px 32px;
                background: #111;
                color: #fff;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
            }
            .btn:hover { background: #333; }
        </style>
    </head>
    <body>
    <div class="card">
        <h2><?= e($heading) ?></h2>
        <p><?= e($message) ?></p>
        <a href="/login" class="btn">返回登录</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ── 参数校验 ──
if ($type === '' || $code === '') {
    callback_page('参数错误', '缺少必要的登录参数', true);
}

// ── 检查聚合登录是否可用 ──
$activeTypes = social_login_active_types();
if (!isset($activeTypes[$type])) {
    callback_page('登录失败', '不支持的登录方式', true);
}

// ── 调用聚合登录回调接口获取用户信息 ──
$apiUrl = (string) app_setting('social_login_api_url', '');
$apiUrl = rtrim($apiUrl, '/') . '/connect.php';
$appid = (string) app_setting('social_login_appid', '');
$appkey = (string) app_setting('social_login_appkey', '');

if ($appid === '' || $appkey === '') {
    callback_page('登录失败', '聚合登录尚未配置', true);
}

$url = $apiUrl . '?act=callback&appid=' . urlencode($appid)
     . '&appkey=' . urlencode($appkey)
     . '&type=' . urlencode($type)
     . '&code=' . urlencode($code);

// 请求聚合登录回调接口获取用户信息
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
        CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
    ]);
    api_curl_apply_ssl_options($ch);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if (!$response || $curlErr !== '') {
        // SSL 证书错误：降级重试（检测 key usage / certificate / ssl 关键词）
        $isSslErr = function($err) { $l = strtolower($err); return strpos($l, 'key usage') !== false || strpos($l, 'certificate') !== false || strpos($l, 'ssl') !== false; };
        if ($curlErr !== '' && $isSslErr($curlErr)) {
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION     => defined('CURL_SSLVERSION_TLSv1_2') ? CURL_SSLVERSION_TLSv1_2 : 6,
                CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
            ]);
            $response = curl_exec($ch2);
            $curlErr  = curl_error($ch2);
            curl_close($ch2);
            if ($response && $curlErr === '') { goto callback_ok; }
            // 最后兜底：跳过对等验证
            $ch3 = curl_init($url);
            curl_setopt_array($ch3, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $response = curl_exec($ch3);
            $curlErr  = curl_error($ch3);
            curl_close($ch3);
        }
        if (!$response || $curlErr !== '') {
            callback_page('登录失败', '请求聚合登录回调失败：' . ($curlErr ?: '无响应'), true);
        }
    }
    callback_ok:
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        callback_page('登录失败', '请求聚合登录回调失败：file_get_contents 请求失败', true);
    }
}

$result = json_decode($response, true);
if (!is_array($result) || ($result['code'] ?? -1) !== 0) {
    callback_page('登录失败', $result['msg'] ?? '获取用户信息失败', true);
}

$socialUid = (string) ($result['social_uid'] ?? '');
if ($socialUid === '') {
    callback_page('登录失败', '未获取到第三方用户标识', true);
}

// ── 检查是否已绑定 ──
ensure_social_logins_table();

$stmt = db()->prepare(
    'SELECT sl.user_id, u.id, u.username, u.email, u.role, u.credits, u.email_verified_at, u.is_active
     FROM social_logins sl
     LEFT JOIN users u ON sl.user_id = u.id
     WHERE sl.type = ? AND sl.social_uid = ?
     LIMIT 1'
);
$stmt->execute([$type, $socialUid]);
$bound = $stmt->fetch();

if ($bound && (int) $bound['is_active'] === 1) {
    // ── 已绑定且账号正常 → 直接登录 ──
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $bound['user_id'];

    // 更新 access_token 等信息
    $accessToken = (string) ($result['access_token'] ?? '');
    if ($accessToken !== '') {
        db()->prepare(
            'UPDATE social_logins SET access_token = ?, nickname = ?, faceimg = ?, updated_at = NOW()
             WHERE type = ? AND social_uid = ?'
        )->execute([$accessToken, $result['nickname'] ?? '', $result['faceimg'] ?? '', $type, $socialUid]);
    }

    $redirect = ($bound['role'] ?? 'user') === 'admin' ? '/admin/dashboard' : '/user/dashboard';
    redirect($redirect);
}

// ── 检查当前是否已登录（个人中心绑定场景） ──
session_start();
$currentUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($currentUserId > 0) {
    // 已登录用户 → 直接绑定
    $accessToken = (string) ($result['access_token'] ?? '');
    db()->prepare(
        'INSERT INTO social_logins (user_id, type, social_uid, access_token, nickname, faceimg)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             access_token = VALUES(access_token),
             nickname = VALUES(nickname),
             faceimg = VALUES(faceimg),
             updated_at = NOW()'
    )->execute([$currentUserId, $type, $socialUid, $accessToken, $result['nickname'] ?? '', $result['faceimg'] ?? '']);

    redirect('/user/ucenter');
}

// ── 未登录且未绑定 → 跳转登录页，带上绑定参数 ──
$bindParams = http_build_query([
    'bind'        => '1',
    'type'        => $type,
    'social_uid'  => $socialUid,
    'access_token' => $result['access_token'] ?? '',
    'nickname'    => $result['nickname'] ?? '',
    'faceimg'     => $result['faceimg'] ?? '',
]);

redirect('/login?' . $bindParams);
