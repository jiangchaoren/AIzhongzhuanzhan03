<?php

/**
 * OpenAI 兼容 — 图片生成端点
 *
 * POST /api/v1/images/generations
 * Authorization: Bearer <api_token>
 * Content-Type: application/json
 *
 * 请求体（OpenAI 标准格式）：
 * {
 *   "model": "dall-e-3",           // 可选，不传则用默认模型
 *   "prompt": "一只可爱的猫",       // 必填
 *   "n": 1,                        // 可选，仅支持 1
 *   "size": "1024x1024",           // 可选
 *   "quality": "standard",         // 可选，standard|hd
 *   "response_format": "url"       // 可选，url|b64_json
 * }
 *
 * 响应（OpenAI 标准格式）：
 * {
 *   "created": 1234567890,
 *   "data": [
 *     {
 *       "url": "https://ai.kbl6.cn/uploads/generations/202605/xxx.png",
 *       "revised_prompt": "优化后的提示词"
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/api_token.php';
require_once __DIR__ . '/../../../src/api_middleware.php';
require_once __DIR__ . '/../../../src/image/generation.php';
require_once __DIR__ . '/../../../src/migration.php';

// CORS
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
    // API Token 鉴权
    $token = require_api_token();
    require_api_permission('generate_image');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        api_openai_error('请求体必须为 JSON 格式。', 'invalid_request_error', 400);
    }

    $prompt = trim((string) ($input['prompt'] ?? ''));
    if ($prompt === '') {
        api_openai_error('prompt 字段不能为空。', 'invalid_request_error', 400);
    }

    // 解析 OpenAI 参数 → 内部参数
    $size = (string) ($input['size'] ?? '1024x1024');
    $quality = (string) ($input['quality'] ?? 'standard');
    $responseFormat = (string) ($input['response_format'] ?? 'url');

    // 转换 size：OpenAI 格式 "1024x1024" → 内部格式 "1:1"
    $sizeMap = [
        '1024x1024' => '1:1',
        '1024x1792' => '9:16',
        '1792x1024' => '16:9',
    ];
    $internalSize = $sizeMap[$size] ?? 'auto';

    // 转换 quality
    $internalQuality = ($quality === 'hd') ? 'high' : 'standard';

    // 可选处理 response_format
    if (!in_array($responseFormat, ['url', 'b64_json'], true)) {
        $responseFormat = 'url';
    }

    // 构造内部参数
    $mode = 'draw';
    $_POST = [
        'prompt'        => $prompt,
        'mode'          => $mode,
        'size'          => $internalSize,
        'quality'       => $internalQuality,
        'output_format' => 'png',
        'csrf_token'    => '',
    ];

    $params = generation_input_from_request($_POST, []);

    // 提示词审核
    require_once __DIR__ . '/../../../src/prompt_moderation.php';
    $moderation = ai_moderate_prompt($params['prompt']);
    if (!$moderation['passed']) {
        api_openai_error($moderation['reason'], 'content_filter', 400);
    }

    // 检查积分
    $credits = generation_cost_for($mode);
    if ((int) $token['user_info']['credits'] < $credits) {
        api_openai_error('积分不足，需要 ' . $credits . ' 积分。', 'insufficient_quota', 402);
    }

    $created = create_generation_record($token['user_id'], $params, 'queued');
    $recordId = (int) $created['id'];

    // 返回 OpenAI 标准格式
    $resultUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ai.kbl6.cn') . '/records?id=' . $recordId;

    api_openai_response([
        'created' => time(),
        'data' => [[
            'url'           => $resultUrl,
            'revised_prompt' => $prompt,
        ]],
    ]);

} catch (RuntimeException $e) {
    api_openai_error($e->getMessage(), 'invalid_request_error', 400);
} catch (Throwable $e) {
    api_openai_error('服务器内部错误。', 'server_error', 500);
}
