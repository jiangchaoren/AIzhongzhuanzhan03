<?php

/**
 * 余额变动记录查询（管理员用）
 * GET /admin/credit_log?user_id=123
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$user = require_login();
if ($user['role'] !== 'admin') {
    json_response(['ok' => false, 'message' => '无权访问'], 403);
}

$targetId = (int) ($_GET['user_id'] ?? 0);
if ($targetId < 1) {
    json_response(['ok' => false, 'message' => '参数不合法'], 400);
}

$stmt = db()->prepare(
    'SELECT type, amount, description, created_at FROM credit_records
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
);
$stmt->execute([$targetId]);
$records = $stmt->fetchAll();

json_response(['ok' => true, 'records' => $records]);
