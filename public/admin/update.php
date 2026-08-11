<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();

$currentVersion = defined('VERSION') ? VERSION : '1000';
$message     = '';
$messageType = '';

// 检查更新
$remoteInfo  = checkForUpdates();
$checkError  = '';
if ($remoteInfo === false) {
    $checkError = '无法连接到更新服务器，请稍后重试。';
} elseif (isset($remoteInfo['error'])) {
    $checkError = $remoteInfo['error'];
}

// 执行更新（仅 POST 请求，不跳转直接显示结果）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    verify_csrf();

    $downloadUrl = $remoteInfo['file'] ?? $remoteInfo['download_url'] ?? '';
    if (empty($downloadUrl)) {
        $message     = '更新包下载地址为空。';
        $messageType = 'error';
    } else {

    $expectedMd5 = (string) ($remoteInfo['md5'] ?? '');

    $tmpFile = sys_get_temp_dir() . '/update_' . bin2hex(random_bytes(8)) . '.zip';
    $ch      = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $pkg      = curl_exec($ch);
    $dlHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dlError  = curl_error($ch);
    $dlSize   = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    if ($pkg === false || $pkg === '' || $dlHttpCode !== 200) {
        $message = '下载更新包失败';
        if ($dlError) $message .= '（cURL: ' . $dlError . '）';
        else $message .= '（HTTP ' . $dlHttpCode . '，下载大小 ' . $dlSize . ' 字节）';
        $message     .= '<br><small>下载地址：' . e($downloadUrl) . '</small>';
        $messageType = 'error';
    } else {
        if ($expectedMd5 !== '' && md5($pkg) !== $expectedMd5) {
            $message     = '更新包完整性校验失败，请重试。';
            $messageType = 'error';
        } else {
            file_put_contents($tmpFile, $pkg);
            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                $message     = '无法打开更新包。';
                $messageType = 'error';
            } else {
                $extractTo = ROOT_PATH;
                $count     = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if ($filename === 'config.php' || $filename === 'config/authcode.php') {
                        continue;
                    }
                    $targetPath = $extractTo . '/' . $filename;
                    $dir        = dirname($targetPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    if ($zip->extractTo($extractTo, $filename)) {
                        $count++;
                    }
                }
                $zip->close();
                $message     = '更新完成，共更新 ' . $count . ' 个文件。';
                $messageType = 'success';
            }
            @unlink($tmpFile);
        }
    }
    } // end if (empty($downloadUrl)) else
    // 不跳转，直接渲染页面显示 $message
}

/* ── 提前计算，供下方 HTML 使用 ── */
$hasDlUrl  = !empty($remoteInfo['file']) || !empty($remoteInfo['download_url']);
$canUpdate = $remoteInfo && isset($remoteInfo['version'])
             && version_compare($remoteInfo['version'], $currentVersion, '>')
             && $hasDlUrl;

render_header('在线更新', 'admin');
render_admin_nav('update');
?>
<main>
    <?php if ($message): ?>
        <div class="alert <?= e($messageType === 'success' ? 'success' : 'error') ?>" style="margin-bottom:16px;">
            <strong><?= e($messageType === 'success' ? '更新成功' : '更新失败') ?></strong>
            <span><?= $message ?></span>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <div>
                <p class="eyebrow">System Update</p>
                <h2>在线更新</h2>
            </div>
        </div>

        <div class="field-grid" style="margin-bottom:16px;">
            <!-- 当前版本 + 立即更新按钮 -->
            <div>
                <strong style="display:block;font-size:14px;color:var(--text-soft);margin-bottom:4px;">当前版本</strong>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span style="font-size:24px;font-weight:700;">v<?= e($currentVersion) ?></span>
                    <?php if ($canUpdate): ?>
                        <form method="post" onsubmit="return confirm('确定要升级到 v<?= e($remoteInfo['version']) ?> 吗？更新前请确保已备份数据库。')" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <button class="button primary" type="submit">立即更新到 v<?= e($remoteInfo['version']) ?></button>
                        </form>
                        <span style="font-size:12px;color:var(--text-muted);">更新包大小：<?= e($remoteInfo['size'] ?? '未知') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 最新版本 -->
            <div>
                <strong style="display:block;font-size:14px;color:var(--text-soft);margin-bottom:4px;">最新版本</strong>
                <?php if ($remoteInfo && isset($remoteInfo['version'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:24px;font-weight:700;">v<?= e($remoteInfo['version']) ?></span>
                        <?php if (version_compare($remoteInfo['version'], $currentVersion, '>')): ?>
                            <span class="badge" style="background:var(--success);color:#fff;">有新版本</span>
                        <?php else: ?>
                            <span class="badge" style="background:var(--text-muted);color:#fff;">已是最新</span>
                        <?php endif; ?>
                    </div>
                <?php elseif ($checkError): ?>
                    <span style="font-size:14px;color:var(--danger);">检查失败（<?= e($checkError) ?>）</span>
                <?php else: ?>
                    <span style="font-size:14px;color:var(--text-muted);">正在检查...</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($remoteInfo && !empty($remoteInfo['uplog'])): ?>
            <div style="margin-bottom:16px;">
                <strong style="display:block;font-size:14px;color:var(--text-soft);margin-bottom:8px;">更新日志</strong>
                <div style="background:var(--main-bg);border-radius:var(--radius-sm);padding:12px;font-size:13px;line-height:1.6;white-space:pre-wrap;"><?= e($remoteInfo['uplog']) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($remoteInfo && isset($remoteInfo['version']) && version_compare($remoteInfo['version'], $currentVersion, '>') && !$hasDlUrl): ?>
            <p style="color:var(--warning);font-size:14px;">检测到新版本，但未配置下载地址。</p>
        <?php elseif ($remoteInfo && isset($remoteInfo['version']) && !version_compare($remoteInfo['version'], $currentVersion, '>')): ?>
            <p style="color:var(--text-muted);font-size:14px;">系统已是最新版本，无需更新。</p>
        <?php elseif ($checkError): ?>
            <p style="color:var(--danger);font-size:14px;"><?= e($checkError) ?></p>
        <?php endif; ?>
    </section>
</main>
<?php render_footer(); ?>
