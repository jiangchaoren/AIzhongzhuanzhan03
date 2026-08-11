<?php

declare(strict_types=1);

/**
 * 迁移: 添加 generation_records 表的视频任务追踪字段
 */
return function (PDO $pdo): void {
    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM generation_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['video_url'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_url TEXT NULL AFTER mime_type');
    }
    if (empty($columns['video_base64'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_base64 LONGTEXT NULL AFTER video_url');
    }
    if (empty($columns['video_mime_type'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_mime_type VARCHAR(64) NULL AFTER video_base64');
    }
    if (empty($columns['seconds'])) {
        $afterSeconds = empty($columns['resolution_level']) ? 'size' : 'resolution_level';
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN seconds INT UNSIGNED NULL AFTER ' . $afterSeconds);
    }
    if (empty($columns['video_task_id'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_task_id VARCHAR(128) NULL AFTER video_mime_type');
    }
    if (empty($columns['video_task_status'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_task_status VARCHAR(32) NULL AFTER video_task_id');
    }
    if (empty($columns['video_task_response'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN video_task_response LONGTEXT NULL AFTER video_task_status');
    }
    if (empty($columns['updated_at'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN updated_at DATETIME NULL DEFAULT NULL AFTER created_at');
    }
};
