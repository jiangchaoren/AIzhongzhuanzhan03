<?php

/**
 * OpenAI 兼容 — Chat Completions 端点
 *
 * POST /api/v1/chat/completions
 * Authorization: Bearer <api_token>  或  Web Session
 * Content-Type: application/json
 *
 * 请求体（OpenAI 标准格式）：
 * {
 *   "model": "gpt-4",
 *   "messages": [
 *     {"role": "system", "content": "你是一个助手"},
 *     {"role": "user", "content": "你好"}
 *   ],
 *   "max_tokens": 2048,
 *   "temperature": 0.7
 * }
 *
 * 响应（OpenAI 标准格式）：
 * {
 *   "id": "chatcmpl-xxx",
 *   "object": "chat.completion",
 *   "created": 1234567890,
 *   "model": "gpt-4",
 *   "choices": [
 *     {
 *       "index": 0,
 *       "message": {"role": "assistant", "content": "回复内容"},
 *       "finish_reason": "stop"
 *     }
 *   ],
 *   "usage": {"prompt_tokens": 10, "completion_tokens": 50, "total_tokens": 60}
 * }
 */

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/api_client.php';
require_once __DIR__ . '/../../../src/api_middleware.php';
require_once __DIR__ . '/../../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_openai_error('Method not allowed. Use POST.', 'invalid_request_error', 405);
}

try {
    // 双鉴权：优先 API Token，降级到 Web Session
    $auth = require_dual_auth();
    $userId = (int) $auth['user_id'];
    $userInfo = $auth['user_info'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        api_openai_error('请求体必须为 JSON 格式。', 'invalid_request_error', 400);
    }

    // 提取 messages
    $messages = $input['messages'] ?? [];
    if (!is_array($messages) || empty($messages)) {
        api_openai_error('messages 字段不能为空。', 'invalid_request_error', 400);
    }

    // 兼容 gpt-5.5 纯中文不回复的问题：若用户未提供 system message，补一条英文
    $hasSystem = false;
    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') === 'system') { $hasSystem = true; break; }
    }
    if (!$hasSystem) {
        array_unshift($messages, ['role' => 'system', 'content' => 'You are a helpful assistant. Reply in the same language as the user.']);
    }

    // 提取最后一个 user 消息作为 prompt
    $userMessage = null;
    foreach (array_reverse($messages) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $userMessage = trim((string) ($msg['content'] ?? ''));
            break;
        }
    }
    if ($userMessage === null || $userMessage === '') {
        api_openai_error('messages 中缺少 user 角色的消息。', 'invalid_request_error', 400);
    }

    // 从请求中提取 model 或使用默认模型
    $requestModel = (string) ($input['model'] ?? '');

    // 查找对话模型
    $modelConfig = null;
    if ($requestModel !== '') {
        // 通过 model_id 查找
        $stmt = db()->prepare("SELECT * FROM ai_models WHERE (model_id = ? OR name = ?) AND is_active = 1 AND model_type = 'chat' LIMIT 1");
        $stmt->execute([$requestModel, $requestModel]);
        $modelConfig = $stmt->fetch();
    }
    if (!$modelConfig) {
        // 取默认模型
        $models = active_chat_ai_models();
        $modelConfig = $models[0] ?? null;
    }
    if (!$modelConfig) {
        api_openai_error('管理员尚未配置对话模型。', 'server_error', 503);
    }

    $chatModel   = (string) $modelConfig['model_id'];
    $chatBaseUrl = rtrim((string) $modelConfig['base_url'], '/');
    $chatApiKey  = (string) $modelConfig['api_key'];

    // 参数
    $maxTokens = min(4096, max(1, (int) ($input['max_tokens'] ?? 2048)));
    $temperature = (float) ($input['temperature'] ?? 0.7);
    $chatCost = ($modelConfig && (int)($modelConfig['credits'] ?? 0) > 0) ? (int) $modelConfig['credits'] : 1;

    // 检查积分
    $credits = (int) ($userInfo['credits'] ?? 0);
    if ($credits < $chatCost) {
        api_openai_error('积分不足，每次对话需要 ' . $chatCost . ' 积分。', 'insufficient_quota', 402);
    }

    // 扣减积分
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
        $stmt->execute([$chatCost, $userId, $chatCost]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            api_openai_error('积分不足。', 'insufficient_quota', 402);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        api_openai_error('扣减积分失败。', 'server_error', 500);
    }

    // 调用上游 API（使用 api_build_url 避免双 /v1/）
    $url = api_build_url($chatBaseUrl, 'v1/chat/completions');
    $payload = [
        'model'       => $chatModel,
        'messages'    => $messages,
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
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
            goto record_chat_v1;
        }

        // 2. 标准 JSON 解析
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
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

        $tokens = (int) ($data['usage']['total_tokens'] ?? 0);

        record_chat_v1:

        // 记录对话到数据库
        $stmt = $pdo->prepare(
            'INSERT INTO chat_records (user_id, conversation_id, prompt, reply, tokens, model, credits_cost, user_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $userIp = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

        // 创建一个临时对话用于记录
        $stmtConv = $pdo->prepare(
            'INSERT INTO chat_conversations (user_id, title, model_id) VALUES (?, ?, ?)'
        );
        $convTitle = mb_strlen($userMessage) > 50 ? mb_substr($userMessage, 0, 50) . '…' : $userMessage;
        $stmtConv->execute([$userId, $convTitle, (int) $modelConfig['id']]);
        $conversationId = (int) $pdo->lastInsertId();

        $stmt->execute([$userId, $conversationId, $userMessage, $reply, $tokens, $chatModel, $chatCost, $userIp]);

        // 更新 conversation 的 message_count
        $pdo->prepare('UPDATE chat_conversations SET message_count = message_count + 1 WHERE id = ?')
            ->execute([$conversationId]);

        // 构造 OpenAI 标准响应
        $chatId = 'chatcmpl-' . bin2hex(random_bytes(12));
        $openaiResponse = [
            'id'      => $chatId,
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => $chatModel,
            'choices' => [[
                'index'         => 0,
                'message'       => [
                    'role'    => 'assistant',
                    'content' => $reply,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage'   => [
                'prompt_tokens'     => $tokens > 0 ? (int) ($tokens * 0.3) : 0,
                'completion_tokens' => $tokens > 0 ? (int) ($tokens * 0.7) : 0,
                'total_tokens'      => $tokens,
            ],
        ];

        api_openai_response($openaiResponse);

    } catch (Throwable $e) {
        // 退款
        $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
             ->execute([$chatCost, $userId]);
        api_openai_error($e->getMessage(), 'api_error', 500);
    }

} catch (RuntimeException $e) {
    api_openai_error($e->getMessage(), 'invalid_request_error', 400);
} catch (Throwable $e) {
    api_openai_error('服务器内部错误。', 'server_error', 500);
}
