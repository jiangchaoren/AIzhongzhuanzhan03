<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/generation.php';

$user = require_login();
$recordId = max(0, (int) ($_GET['id'] ?? 0));
if ($recordId < 1) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare(
    'SELECT id, user_id, image_base64, image_url, mime_type
     FROM generation_records
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$recordId]);
$record = $stmt->fetch();

if (!is_array($record)) {
    http_response_code(404);
    exit;
}
if ($user['role'] !== 'admin' && (int) $record['user_id'] !== (int) $user['id']) {
    http_response_code(403);
    exit;
}
if (!empty($record['image_url'])) {
    $imageUrl = (string) $record['image_url'];
    // 数据库存储：直接输出
    if (str_starts_with($imageUrl, 'db://')) {
        require_once __DIR__ . '/../src/storage.php';
        $storageKey = substr($imageUrl, 5);
        ensure_storage_files_table();
        $stmt = db()->prepare('SELECT mime_type, data FROM storage_files WHERE storage_key = ? LIMIT 1');
        $stmt->execute([$storageKey]);
        $row = $stmt->fetch();
        if ($row) {
            header('Content-Type: ' . $row['mime_type']);
            header('Content-Length: ' . strlen($row['data']));
            header('Cache-Control: public, max-age=86400');
            echo $row['data'];
            exit;
        }
        // 数据库找不到，继续尝试下面的兜底逻辑
    }
    // 兼容已有记录中的远程 URL — 自动下载到本地
    if (is_remote_url($imageUrl)) {
        $imageUrl = ensure_local_image_url($imageUrl, $recordId);
    }
    redirect($imageUrl);
}
if (empty($record['image_base64'])) {
    http_response_code(404);
    exit;
}

$binary = base64_decode((string) $record['image_base64'], true);
if ($binary === false) {
    http_response_code(404);
    exit;
}

$mime = (string) ($record['mime_type'] ?: 'image/png');
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($binary));
echo $binary;
