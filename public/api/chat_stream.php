<?php

/**
 * AI 对话流式 SSE 接口
 *
 * POST /api/chat_stream
 * Body: {"prompt": "用户消息", "model_id": 1, "conversation_id": 0}
 *
 * 实时转发上游 SSE 流，客户端可逐字渲染。
 * 流结束时发送 [DONE] 事件，附带 conversation_id / credits / tokens。
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_client.php';
require_once __DIR__ . '/../../src/generation.php';
require_once __DIR__ . '/../../src/migration.php';

error_reporting(0);
ini_set('display_errors', '0');

// ── 输出缓冲全关，确保 SSE 实时推送 ──
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
while (ob_get_level()) {
    ob_end_flush();
}

function sse_send(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function sse_done(array $final = []): void
{
    sse_send('done', $final + ['ok' => true]);
}

function sse_error(string $message, int $status = 500): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    sse_send('error', ['ok' => false, 'message' => $message]);
}

try {

// ── 认证 ──
$user = require_login();
// 立即关闭 session 写锁，防止阻塞同一用户的其他请求（PHP 默认 session 串行）
session_write_close();
ensure_chat_conversations_table();
ensure_chat_records_conversation_column();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sse_error('Method not allowed', 405);
    exit;
}

// 解析 JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    sse_error('请求体必须为 JSON 格式。', 400);
    exit;
}
if (isset($input['csrf_token'])) {
    $_POST['csrf_token'] = $input['csrf_token'];
}

verify_csrf();

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    sse_error('请输入消息内容。', 400);
    exit;
}

// ── 查找对话模型 ──
$modelId = (int) ($input['model_id'] ?? 0);
$modelConfig = null;

if ($modelId > 0) {
    $stmt = db()->prepare("SELECT * FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$modelId]);
    $modelConfig = $stmt->fetch();
}
if (!$modelConfig) {
    $models = active_chat_ai_models();
    $modelConfig = $models[0] ?? null;
}
if (!$modelConfig) {
    sse_error('管理员尚未配置对话模型。', 503);
    exit;
}

$chatCost   = ((int)($modelConfig['credits'] ?? 0) > 0) ? (int) $modelConfig['credits'] : 1;
$chatModel  = (string) $modelConfig['model_id'];
$chatBaseUrl = rtrim((string) $modelConfig['base_url'], '/');
$chatApiKey = (string) $modelConfig['api_key'];

// ── 会话管理 ──
$conversationId = (int) ($input['conversation_id'] ?? 0);
$pdo = db();

if ($conversationId > 0) {
    $stmt = $pdo->prepare('SELECT id, title FROM chat_conversations WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$conversationId, $user['id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        $conversationId = 0;
    }
}

if ($conversationId < 1) {
    $title = mb_strlen($prompt) > 50 ? mb_substr($prompt, 0, 50) . '…' : $prompt;
    $stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id, title, model_id) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], $title, (int) $modelConfig['id']]);
    $conversationId = (int) $pdo->lastInsertId();
    $conv = ['title' => $title];
}

// ── 加载历史消息 ──
$stmt = $pdo->prepare('SELECT prompt, reply FROM chat_records WHERE conversation_id = ? ORDER BY id ASC');
$stmt->execute([$conversationId]);
$history = $stmt->fetchAll();

$messages = [];
foreach ($history as $h) {
    $messages[] = ['role' => 'user', 'content' => (string) $h['prompt']];
    $messages[] = ['role' => 'assistant', 'content' => (string) $h['reply']];
}
$messages[] = ['role' => 'user', 'content' => $prompt];
array_unshift($messages, ['role' => 'system', 'content' => 'You are a helpful assistant. Reply in the same language as the user.']);

// ── 扣减积分 ──
$credits = (int) $user['credits'];
if ($credits < $chatCost) {
    sse_error('积分不足，每次对话需要 ' . $chatCost . ' 积分。', 402);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
    $stmt->execute([$chatCost, $user['id'], $chatCost]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        sse_error('积分不足。', 402);
        exit;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    sse_error('扣减积分失败。', 500);
    exit;
}

// ── 发送会话元信息（前端可据此更新 sidebar） ──
sse_send('meta', [
    'conversation_id' => $conversationId,
    'title'           => $conv['title'],
    'credits'         => $credits - $chatCost,
]);

// ── 流式调用上游 API ──
$url = api_build_url($chatBaseUrl, 'v1/chat/completions');
$payload = [
    'model'       => $chatModel,
    'messages'    => $messages,
    'max_tokens'  => 2048,
    'stream'      => true,
];

$fullContent  = '';
$tokens       = 0;
$buffer       = '';
$streamError  = null;

$ch = curl_init($url);
$curlOpts = [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => false,        // 关键：不缓存响应，逐块回调
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $chatApiKey,
        'Content-Type: application/json',
        'Accept: text/event-stream',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
    CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
    CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
    CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 2,
];

// SSE 写入回调（保存引用以便 key-usage 错误时重建 cURL 重试）
$sseWriteCallback = function ($ch, $data) use (&$buffer, &$fullContent, &$tokens) {
    $buffer .= $data;

    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        $line = trim($line);

        if ($line === '' || (strlen($line) > 0 && $line[0] === ':')) continue;
        if (strpos($line, 'data:') !== 0) continue;

        $jsonStr = trim(substr($line, 5));
        if ($jsonStr === '[DONE]') continue;

        $chunk = json_decode($jsonStr, true);
        if (!is_array($chunk)) continue;

        $delta = $chunk['choices'][0]['delta']['content']
              ?? $chunk['choices'][0]['message']['content']
              ?? $chunk['choices'][0]['text']
              ?? '';

        if ($delta !== '') {
            $fullContent .= $delta;
            echo "data: " . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }

        if (!empty($chunk['usage'])) {
            $tokens = (int) ($chunk['usage']['total_tokens'] ?? 0);
        }
    }

    return strlen($data);
};
$curlOpts[CURLOPT_WRITEFUNCTION] = $sseWriteCallback;
curl_setopt_array($ch, $curlOpts);

curl_exec($ch);
$curlError  = curl_error($ch);
$httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

// SSL 证书错误：TLS 1.2 降级重试
$isSslErr = function($err) { $l = strtolower($err); return strpos($l, 'key usage') !== false || strpos($l, 'certificate') !== false || strpos($l, 'ssl') !== false; };
if ($httpCode === 0 && $curlError !== '' && $isSslErr($curlError) && ssl_verify_enabled()) {
    curl_close($ch);
    $ch2 = curl_init($url);
    $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
    $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
    $curlOpts[CURLOPT_SSLVERSION]     = defined('CURL_SSLVERSION_TLSv1_2') ? CURL_SSLVERSION_TLSv1_2 : 6;
    $curlOpts[CURLOPT_SSL_OPTIONS]    = defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0;
    $curlOpts[CURLOPT_WRITEFUNCTION]  = $sseWriteCallback;
    curl_setopt_array($ch2, $curlOpts);
    curl_exec($ch2);
    $curlError = curl_error($ch2);
    $httpCode  = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
} else {
    curl_close($ch);
}

// 处理 curl 错误
if ($curlError !== '') {
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
         ->execute([$chatCost, $user['id']]);
    sse_error('网络请求失败：' . $curlError);
    exit;
}

// 处理 HTTP 错误（非流式错误响应）
if ($httpCode >= 400 && $fullContent === '') {
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
         ->execute([$chatCost, $user['id']]);
    sse_error('对话接口返回 HTTP ' . $httpCode . '，请检查模型配置。');
    exit;
}

// 处理空响应
if ($fullContent === '') {
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
         ->execute([$chatCost, $user['id']]);
    sse_error('对话模型未返回任何内容，请检查模型配置或切换模型。');
    exit;
}

// ── 记录对话 ──
try {
    $pdo->prepare('UPDATE chat_conversations SET message_count = message_count + 1 WHERE id = ?')
         ->execute([$conversationId]);

    $userIp = (string) ($_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '');

    $stmt = $pdo->prepare(
        'INSERT INTO chat_records (user_id, conversation_id, prompt, reply, tokens, model, credits_cost, user_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'], $conversationId, $prompt, $fullContent, $tokens, $chatModel, $chatCost, $userIp]);
} catch (Throwable $e) {
    // 记录失败不阻塞流，但退款
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
         ->execute([$chatCost, $user['id']]);
    sse_error('对话记录保存失败，积分已退回。');
    exit;
}

// ── 发送完成事件 ──
sse_done([
    'conversation_id' => $conversationId,
    'title'           => $conv['title'],
    'credits'         => $credits - $chatCost,
    'tokens'          => $tokens,
    'model'           => $chatModel,
]);

} catch (Throwable $e) {
    sse_error($e->getMessage(), 500);
}
