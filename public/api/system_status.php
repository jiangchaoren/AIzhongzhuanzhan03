<?php
/**
 * 系统安装状态检测 — 基于 /storage/.installed 文件
 * GET /api/system_status → {installed: true/false}
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$installed = is_file(__DIR__ . '/../../storage/.installed');

echo json_encode(['installed' => $installed], JSON_UNESCAPED_UNICODE);
