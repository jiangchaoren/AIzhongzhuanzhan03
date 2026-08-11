<?php
/**
 * 图片广场 — 已迁移至 /user/gallery
 * 保留此文件做 301 重定向，保持旧链接可访问
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
$url = '/user/gallery' . ($qs !== '' ? '?' . $qs : '');
header('HTTP/1.1 301 Moved Permanently');
header('Location: ' . $url);
exit;
