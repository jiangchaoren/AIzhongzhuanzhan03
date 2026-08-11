<?php

/**
 * API 端点 — 生成图片/视频
 *
 * POST /api/generate
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Body:
 * {
 *   "prompt": "xxx",
 *   "mode": "draw|edit|video",      // 默认 draw
 *   "size": "1024x1024|auto",       // 默认 auto
 *   "quality": "standard|hd",       // 仅图片模式
 *   "output_format": "png|jpeg|webp|mp4|webm"  // 默认根据模式
 * }
 *
 * 返回：
 * {
 *   "ok": true,
 *   "record_id": 123,
 *   "status": "queued",
 *   "credits": 100
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_token.php';
require_once __DIR__ . '/../../src/api_middleware.php';
require_once __DIR__ . '/../../src/prompt_moderation.php';
require_once __DIR__ . '/../../src/image/generation.php';
require_once __DIR__ . '/../../src/migration.php';

// CORS 头（允许跨域调用）
set_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $token = require_api_token();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException('请求体必须为 JSON 格式。');
    }

    $mode = (string) ($input['mode'] ?? 'draw');

    // 根据 mode 检查权限
    if ($mode === 'video') {
        require_api_permission('generate_video');
    } else {
        require_api_permission('generate_image');
    }

    // 构造 $_POST 兼容层（generation_input_from_request 读取 $_POST）
    $_POST = [
        'prompt'        => (string) ($input['prompt'] ?? ''),
        'mode'          => $mode,
        'size'          => (string) ($input['size'] ?? 'auto'),
        'quality'       => (string) ($input['quality'] ?? 'auto'),
        'output_format' => (string) ($input['output_format'] ?? ($mode === 'video' ? 'mp4' : 'png')),
        'csrf_token'    => '', // CSRF 验证在内部可绕过
    ];

    $params = generation_input_from_request($_POST, []);

    $moderation = ai_moderate_prompt($params['prompt']);
    if (!$moderation['passed']) {
        json_response(['ok' => false, 'message' => $moderation['reason']], 422);
    }

    $created = create_generation_record($token['user_id'], $params, 'queued');
    $recordId = (int) $created['id'];

    echo json_encode([
        'ok' => true,
        'record_id' => $recordId,
        'status' => 'queued',
        'credits' => $token['user_info']['credits'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '服务器内部错误。'], JSON_UNESCAPED_UNICODE);
}
