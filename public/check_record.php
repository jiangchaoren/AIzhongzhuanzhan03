<?php

/**
 * 检查生成记录状态（前端轮询用）
 *
 * GET /check_record?id=123
 * 返回：{ ok, status, image_src, record, credits }
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/generation.php';

$user = require_login();
ensure_generation_records_soft_delete();

$recordId = (int) ($_GET['id'] ?? 0);
if ($recordId < 1) {
    json_response(['ok' => false, 'message' => '参数不合法'], 400);
}

try {
    $record = generation_record_by_id($recordId);

    // 只能查看自己的记录
    if ((int) $record['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
        json_response(['ok' => false, 'message' => '无权访问'], 403);
    }

    $status = (string) $record['status'];
    $responseRecord = generation_response_record($record);

    // 检查 worker 写入的刷新标记
    $needsRefresh = false;
    $refreshFile = __DIR__ . '/../storage/refresh/' . (int) $user['id'] . '.txt';
    if (is_file($refreshFile)) {
        $needsRefresh = true;
        @unlink($refreshFile);
    }

    json_response([
        'ok' => true,
        'status' => $status,
        'record_id' => $recordId,
        'image_src' => $responseRecord['image_src'] ?? null,
        'video_src' => $responseRecord['video_src'] ?? null,
        'credits' => current_user_credits((int) $user['id']),
        'needs_refresh' => $needsRefresh,
        'record' => $responseRecord,
    ]);
} catch (Throwable $e) {
    error_log('[CHECK_RECORD] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $msg = config('app.debug', false) ? $e->getMessage() : '记录不存在';
    json_response(['ok' => false, 'message' => $msg], 404);
}
