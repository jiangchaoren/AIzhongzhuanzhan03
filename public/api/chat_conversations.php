<?php

/**
 * 对话列表 API
 *
 * GET  /api/chat_conversations                 → 获取列表
 * DELETE  /api/chat_conversations?id=123       → 删除对话
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();

// ── DELETE：删除对话 ──
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_method'] ?? '') === 'DELETE')) {
    // 兼容不支持 DELETE 的环境，POST + _method=DELETE 也可以
}

$isDelete = ($_SERVER['REQUEST_METHOD'] === 'DELETE')
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_method'] ?? $_GET['_method'] ?? '') === 'DELETE');

if ($isDelete) {
    $convId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($convId < 1) {
        json_response(['ok' => false, 'message' => '参数不完整。'], 400);
    }

    // 验证对话属于当前用户
    $stmt = db()->prepare('SELECT id FROM chat_conversations WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$convId, $user['id']]);
    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'message' => '对话不存在。'], 404);
    }

    // 删除关联消息
    db()->prepare('DELETE FROM chat_records WHERE conversation_id = ?')->execute([$convId]);
    // 删除对话
    db()->prepare('DELETE FROM chat_conversations WHERE id = ? AND user_id = ?')->execute([$convId, $user['id']]);

    json_response(['ok' => true, 'message' => '已删除。']);
}

// ── GET：获取列表 ──
$stmt = db()->prepare(
    'SELECT c.id, c.title, c.message_count, c.created_at, c.updated_at
     FROM chat_conversations c
     WHERE c.user_id = ?
     ORDER BY c.updated_at DESC
     LIMIT 50'
);
$stmt->execute([(int) $user['id']]);
$conversations = $stmt->fetchAll();

// 格式化数据
$list = [];
foreach ($conversations as $c) {
    $list[] = [
        'id'            => (int) $c['id'],
        'title'         => (string) $c['title'],
        'message_count' => (int) $c['message_count'],
        'created_at'    => (string) $c['created_at'],
        'updated_at'    => (string) $c['updated_at'],
    ];
}

json_response([
    'ok' => true,
    'conversations' => $list,
]);
