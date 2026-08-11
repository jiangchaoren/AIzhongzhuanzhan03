<?php

/**
 * AI 对话 API
 *
 * POST /api/chat
 * Body: {"prompt": "用户消息", "model_id": 1, "conversation_id": 0}
 *
 * 返回：{ok, reply, credits, tokens, conversation_id, title, error_message}
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_client.php';
require_once __DIR__ . '/../../src/generation.php';
require_once __DIR__ . '/../../src/migration.php';

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

try {

$user = require_login();
ensure_chat_conversations_table();
ensure_chat_records_conversation_column();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

// 先解析 JSON body（必须在 verify_csrf 之前）
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['ok' => false, 'message' => '请求体必须为 JSON 格式。'], 400);
}
if (isset($input['csrf_token'])) {
    $_POST['csrf_token'] = $input['csrf_token'];
}

verify_csrf();

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    json_response(['ok' => false, 'message' => '请输入消息内容。'], 400);
}

// 查找对话模型
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
    json_response(['ok' => false, 'message' => '管理员尚未配置对话模型。'], 503);
}

// 读取计费配置 — 从模型配置读取消耗，未配置默认 1
$chatCost = ((int)($modelConfig['credits'] ?? 0) > 0) ? (int) $modelConfig['credits'] : 1;

$chatModel   = (string) $modelConfig['model_id'];
$chatBaseUrl = rtrim((string) $modelConfig['base_url'], '/');
$chatApiKey  = (string) $modelConfig['api_key'];

// 处理 conversation
$conversationId = (int) ($input['conversation_id'] ?? 0);
$pdo = db();

if ($conversationId > 0) {
    // 验证对话属于当前用户
    $stmt = $pdo->prepare('SELECT id, title FROM chat_conversations WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$conversationId, $user['id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        $conversationId = 0; // 无效对话，重新创建
    }
}

if ($conversationId < 1) {
    // 创建新对话，标题取 prompt 前 50 字
    $title = mb_strlen($prompt) > 50 ? mb_substr($prompt, 0, 50) . '…' : $prompt;
    $stmt = $pdo->prepare(
        'INSERT INTO chat_conversations (user_id, title, model_id) VALUES (?, ?, ?)'
    );
    $stmt->execute([$user['id'], $title, (int) $modelConfig['id']]);
    $conversationId = (int) $pdo->lastInsertId();
    $conv = ['title' => $title];
}

// 加载历史消息
$stmt = $pdo->prepare(
    'SELECT prompt, reply FROM chat_records WHERE conversation_id = ? ORDER BY id ASC'
);
$stmt->execute([$conversationId]);
$history = $stmt->fetchAll();

// 构建 messages 数组（包含历史）
$messages = [];
foreach ($history as $h) {
    $messages[] = ['role' => 'user', 'content' => (string) $h['prompt']];
    $messages[] = ['role' => 'assistant', 'content' => (string) $h['reply']];
}
$messages[] = ['role' => 'user', 'content' => $prompt];

// 兼容 gpt-5.5 纯中文不回复的问题：system message 带英文可绕过
array_unshift($messages, ['role' => 'system', 'content' => 'You are a helpful assistant. Reply in the same language as the user.']);

// 检查积分
$credits = (int) $user['credits'];
if ($credits < $chatCost) {
    json_response(['ok' => false, 'message' => '积分不足，每次对话需要 ' . $chatCost . ' 积分。'], 402);
}

// 扣减积分
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
    $stmt->execute([$chatCost, $user['id'], $chatCost]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => '积分不足。'], 402);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['ok' => false, 'message' => '扣减积分失败。'], 500);
}

// 调用 API（使用 api_build_url 避免 /v1/v1/ 重复）
$url = api_build_url($chatBaseUrl, 'v1/chat/completions');
$payload = [
    'model'      => $chatModel,
    'messages'   => $messages,
    'max_tokens' => 2048,
];

try {
    $response = api_curl_post_json($url, $chatApiKey, $payload, 60);
    $httpCode = (int) $response['http_code'];
    $raw      = $response['raw'];

    // 1. 先尝试解析 SSE 流式响应
    $sse = api_parse_sse_stream((string) $raw);
    if ($sse !== null) {
        if ($sse['content'] === '') {
            throw new RuntimeException('对话模型返回空内容（上游 API 未生成回复，请检查模型配置或切换模型）');
        }
        $reply  = $sse['content'];
        $tokens = (int) ($sse['usage']['total_tokens'] ?? 0);
        goto record_chat;
    }

    // 2. 标准 JSON 解析
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        // SSE 和 JSON 都失败，展示原始响应尾部（SSE 内容累积在末尾）
        $clean = str_replace(["\n", "\r"], ' ', trim((string) $raw));
        $len   = mb_strlen($clean);
        $tail  = $len > 500 ? '…' . mb_substr($clean, -500) : $clean;
        throw new RuntimeException('对话接口返回格式异常（长度' . $len . '，尾部：' . $tail . '）');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = api_error_message($data, '对话接口返回 HTTP ' . $httpCode);
        throw new RuntimeException($errMsg);
    }

    $reply = $data['choices'][0]['message']['content'] ?? '';
    if ($reply === '') {
        throw new RuntimeException('对话接口未返回有效回复。');
    }

    $tokens = 0;
    if (isset($data['usage']['total_tokens'])) {
        $tokens = (int) $data['usage']['total_tokens'];
    }

    record_chat:

    // 更新 conversation 的 message_count 和 title
    $pdo->prepare('UPDATE chat_conversations SET message_count = message_count + 1 WHERE id = ?')
        ->execute([$conversationId]);

    // 记录对话
    $userIp = (string) ($_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '');

    $stmt = $pdo->prepare(
        'INSERT INTO chat_records (user_id, conversation_id, prompt, reply, tokens, model, credits_cost, user_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'], $conversationId, $prompt, $reply, $tokens, $chatModel, $chatCost, $userIp]);

    json_response([
        'ok'              => true,
        'reply'           => $reply,
        'credits'         => $credits - $chatCost,
        'tokens'          => $tokens,
        'model'           => $chatModel,
        'conversation_id' => $conversationId,
        'title'           => $conv['title'],
    ]);

} catch (Throwable $e) {
    // 退款
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
         ->execute([$chatCost, $user['id']]);

    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}

} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '系统错误：' . $e->getMessage()], 500);
}
