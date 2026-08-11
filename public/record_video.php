<?php
/**
 * 视频代理端点 —— 中转远程视频流到浏览器
 * 
 * 直接加载远程视频：/record_video?url=https://vidgen.x.ai/xxx.mp4
 * 从记录 ID 加载：   /record_video?id=123
 */

define('ROOT_PATH', dirname(__DIR__));

try {
    require_once ROOT_PATH . '/src/bootstrap.php';

    // 方式1：通过记录 ID
    $recordId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($recordId > 0) {
        $stmt = db()->prepare('SELECT video_url, video_mime_type FROM generation_records WHERE id = ? LIMIT 1');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['video_url'])) {
            http_response_code(404);
            die('Video not found');
        }
        $videoUrl = $row['video_url'];
        $mimeType = $row['video_mime_type'] ?: 'video/mp4';
    } else {
        // 方式2：直接指定 URL
        $videoUrl = $_GET['url'] ?? '';
        $mimeType = 'video/mp4';
        if (empty($videoUrl)) {
            http_response_code(400);
            die('Missing url parameter');
        }
    }

    // 如果已经是本地文件，直接重定向
    if (!preg_match('#^https?://#i', $videoUrl)) {
        header('Location: ' . $videoUrl);
        exit;
    }

    // 远程 URL：尝试代理下载
    $ch = curl_init($videoUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $responseHeaders[] = $header;
            return $len;
        },
    ]);

    $binary = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($binary === false || $httpCode >= 400) {
        // 代理失败：302 重定向到原始 URL（用户浏览器自己试）
        header('Location: ' . $videoUrl);
        exit;
    }

    // 成功：流式输出
    $mime = $contentType ?: $mimeType;
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: public, max-age=86400');
    header('Accept-Ranges: bytes');
    echo $binary;

} catch (Throwable $e) {
    http_response_code(500);
    die('Video proxy error');
}
