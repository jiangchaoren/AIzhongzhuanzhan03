<?php

/**
 * 数据库存储代理 — 从 storage_files 表读取并输出文件
 */

require_once __DIR__ . '/../src/bootstrap.php';

$key = trim((string) ($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/../src/storage.php';
ensure_storage_files_table();

$pdo = db();
$stmt = $pdo->prepare('SELECT mime_type, data FROM storage_files WHERE storage_key = ? LIMIT 1');
$stmt->execute([$key]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit;
}

// 设置缓存头（文件内容不变，可长期缓存）
$etag = '"' . md5($row['data']) . '"';
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . strlen($row['data']));
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');

// 支持 304 Not Modified
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

echo $row['data'];
