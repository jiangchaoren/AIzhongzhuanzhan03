<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $platformName = trim((string) ($_POST['platform_name'] ?? ''));
    $balanceLabel = trim((string) ($_POST['balance_label'] ?? '余额'));
    $maxEditImages = max(1, min(16, (int) ($_POST['max_edit_images'] ?? 4)));
    $maxEditImageMb = max(1, min(50, (int) ($_POST['max_edit_image_mb'] ?? 10)));
    $maxEditImageDimension = max(512, min(12000, (int) ($_POST['max_edit_image_dimension'] ?? 8000)));
    $storageDriver = (string) ($_POST['storage_driver'] ?? 'local');
    $generationTimeout = max(30, min(900, (int) ($_POST['generation_timeout'] ?? 300)));
    $photoRetentionDays = max(0, min(3650, (int) ($_POST['photo_retention_days'] ?? 0)));
    $sessionLifetime = max(0, min(2592000, (int) ($_POST['session_lifetime'] ?? 86400)));
    $generationNotice = trim((string) ($_POST['generation_notice'] ?? ''));
    $videoNotice = trim((string) ($_POST['video_notice'] ?? ''));
    $icpNumber = trim((string) ($_POST['icp_number'] ?? ''));
    $orderTimeoutMinutes = max(1, min(1440, (int) ($_POST['order_timeout_minutes'] ?? 30)));

    if ($platformName === '') { flash('error', '网站名称不能为空。'); redirect('/admin/settings'); }
    if ($balanceLabel === '') { flash('error', '余额名称不能为空。'); redirect('/admin/settings'); }
    if (!in_array($storageDriver, ['local', 'qiniu', 'aliyun', 'tencent', 'ftp'], true)) { flash('error', '存储驱动不合法。'); redirect('/admin/settings'); }

    set_app_setting('platform_name', $platformName);
    set_app_setting('balance_label', $balanceLabel);
    set_app_setting('max_edit_images', (string) $maxEditImages);
    set_app_setting('max_edit_image_mb', (string) $maxEditImageMb);
    set_app_setting('max_edit_image_dimension', (string) $maxEditImageDimension);
    set_app_setting('storage_driver', $storageDriver);
    set_app_setting('generation_timeout', (string) $generationTimeout);
    set_app_setting('photo_retention_days', (string) $photoRetentionDays);
    set_app_setting('session_lifetime', (string) $sessionLifetime);
    set_app_setting('generation_notice', $generationNotice);
    set_app_setting('video_notice', $videoNotice);
    set_app_setting('icp_number', $icpNumber);
    set_app_setting('order_timeout_minutes', (string) $orderTimeoutMinutes);
    set_app_setting('homepage_enabled', !empty($_POST['homepage_enabled']) ? 'on' : 'off');
    set_app_setting('email_verify_enabled', !empty($_POST['email_verify_enabled']) ? 'on' : 'off');

    // 七牛云配置
    set_app_setting('qiniu_access_key', trim((string) ($_POST['qiniu_access_key'] ?? '')));
    set_app_setting('qiniu_secret_key', trim((string) ($_POST['qiniu_secret_key'] ?? '')));
    set_app_setting('qiniu_bucket', trim((string) ($_POST['qiniu_bucket'] ?? '')));
    set_app_setting('qiniu_domain', rtrim(trim((string) ($_POST['qiniu_domain'] ?? '')), '/'));
    set_app_setting('qiniu_region', (string) ($_POST['qiniu_region'] ?? 'z0'));

    // 阿里云 OSS 配置
    set_app_setting('aliyun_access_key_id', trim((string) ($_POST['aliyun_access_key_id'] ?? '')));
    set_app_setting('aliyun_access_key_secret', trim((string) ($_POST['aliyun_access_key_secret'] ?? '')));
    set_app_setting('aliyun_bucket', trim((string) ($_POST['aliyun_bucket'] ?? '')));
    set_app_setting('aliyun_endpoint', trim((string) ($_POST['aliyun_endpoint'] ?? '')));
    set_app_setting('aliyun_domain', rtrim(trim((string) ($_POST['aliyun_domain'] ?? '')), '/'));

    // 腾讯云 COS 配置
    set_app_setting('tencent_secret_id', trim((string) ($_POST['tencent_secret_id'] ?? '')));
    set_app_setting('tencent_secret_key', trim((string) ($_POST['tencent_secret_key'] ?? '')));
    set_app_setting('tencent_bucket', trim((string) ($_POST['tencent_bucket'] ?? '')));
    set_app_setting('tencent_region', trim((string) ($_POST['tencent_region'] ?? '')));
    set_app_setting('tencent_domain', rtrim(trim((string) ($_POST['tencent_domain'] ?? '')), '/'));

    // FTP 配置
    set_app_setting('ftp_host', trim((string) ($_POST['ftp_host'] ?? '')));
    set_app_setting('ftp_port', trim((string) ($_POST['ftp_port'] ?? '21')));
    set_app_setting('ftp_username', trim((string) ($_POST['ftp_username'] ?? '')));
    set_app_setting('ftp_password', trim((string) ($_POST['ftp_password'] ?? '')));
    set_app_setting('ftp_base_path', trim((string) ($_POST['ftp_base_path'] ?? '')));
    set_app_setting('ftp_base_url', rtrim(trim((string) ($_POST['ftp_base_url'] ?? '')), '/'));
    set_app_setting('ftp_ssl', ($_POST['ftp_ssl'] ?? '0') === '1' ? '1' : '0');

    $imageGeneratePath = trim((string) ($_POST['image_generate_path'] ?? ''));
    $imageEditPath = trim((string) ($_POST['image_edit_path'] ?? ''));
    if ($imageGeneratePath !== '') set_app_setting('image_generate_path', $imageGeneratePath);
    if ($imageEditPath !== '') set_app_setting('image_edit_path', $imageEditPath);
    $videoGeneratePath = trim((string) ($_POST['video_generate_path'] ?? ''));
    if ($videoGeneratePath !== '') set_app_setting('video_generate_path', $videoGeneratePath);

    flash('success', '系统配置已保存。');
    redirect('/admin/settings');
}

$platformName = app_setting('platform_name', trim((string) config('generation.platform_name', '')) ?: 'AI 图片视频创作系统');
$balanceLabel = app_setting('balance_label', '余额');
$maxEditImages = app_setting('max_edit_images', '4');
$maxEditImageMb = app_setting('max_edit_image_mb', '10');
$maxEditImageDimension = app_setting('max_edit_image_dimension', '8000');
$generationNotice = app_setting('generation_notice', '');
$generationTimeout = (int) app_setting('generation_timeout', '300');
$orderTimeoutMinutes = (int) app_setting('order_timeout_minutes', '30');
$photoRetentionDays = (int) app_setting('photo_retention_days', '0');
$sessionLifetime = (int) app_setting('session_lifetime', '86400');
$videoNotice = app_setting('video_notice', '');
$icpNumber = app_setting('icp_number', '');
$homepageEnabled = app_setting('homepage_enabled', 'on') === 'on';
$emailVerifyEnabled = app_setting('email_verify_enabled', 'on') === 'on';
$imageGeneratePath = app_setting('image_generate_path', '');
$imageEditPath = app_setting('image_edit_path', '');
$videoGeneratePath = app_setting('video_generate_path', '');
$storageDriver = app_setting('storage_driver', 'local');
$qiniuAccessKey = app_setting('qiniu_access_key', '');
$qiniuSecretKey = app_setting('qiniu_secret_key', '');
$qiniuBucket     = app_setting('qiniu_bucket', '');
$qiniuDomain     = app_setting('qiniu_domain', '');
$qiniuRegion     = app_setting('qiniu_region', 'z0');
$aliyunAccessKeyId     = app_setting('aliyun_access_key_id', '');
$aliyunAccessKeySecret = app_setting('aliyun_access_key_secret', '');
$aliyunBucket          = app_setting('aliyun_bucket', '');
$aliyunEndpoint        = app_setting('aliyun_endpoint', '');
$aliyunDomain          = app_setting('aliyun_domain', '');
$tencentSecretId  = app_setting('tencent_secret_id', '');
$tencentSecretKey = app_setting('tencent_secret_key', '');
$tencentBucket    = app_setting('tencent_bucket', '');
$tencentRegion    = app_setting('tencent_region', '');
$tencentDomain    = app_setting('tencent_domain', '');
$ftpHost     = app_setting('ftp_host', '');
$ftpPort     = app_setting('ftp_port', '21');
$ftpUsername = app_setting('ftp_username', '');
$ftpPassword = app_setting('ftp_password', '');
$ftpBasePath = app_setting('ftp_base_path', '');
$ftpBaseUrl  = app_setting('ftp_base_url', '');
$ftpSsl      = app_setting('ftp_ssl', '0');

render_header('系统配置', 'admin');
render_admin_nav('settings');
?>
<style>
.settings-page { max-width: 860px; margin: 0 auto; }

/* section card */
.settings-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
}
.settings-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.settings-section-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 10px;
    font-size: 18px;
    flex-shrink: 0;
}
.settings-section-icon.blue  { background: #dbeafe; color: #2563eb; }
.settings-section-icon.green { background: #d1fae5; color: #059669; }
.settings-section-icon.purple { background: #ede9fe; color: #7c3aed; }
.settings-section-icon.amber { background: #fef3c7; color: #d97706; }
.settings-section-header .info h3 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text); }
.settings-section-header .info p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

.settings-section-body {
    padding: 24px;
}

/* field rows */
.settings-field {
    margin-bottom: 18px;
}
.settings-field:last-child { margin-bottom: 0; }
.settings-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}
.settings-field input,
.settings-field select,
.settings-field textarea {
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}
.settings-field input:focus,
.settings-field select:focus,
.settings-field textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.settings-field textarea { resize: vertical; min-height: 60px; }
.settings-field select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.settings-field .hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
    line-height: 1.5;
}
.settings-field .hint code {
    font-size: 12px;
    background: var(--main-surface-soft);
    padding: 1px 6px;
    border-radius: 4px;
    border: 1px solid var(--line);
}

/* field grid */
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 18px;
}
.settings-grid:last-child { margin-bottom: 0; }
@media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }
.settings-grid .settings-field { margin-bottom: 0; }

/* switch row */
.settings-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px;
    background: var(--main-surface-soft);
    border-radius: 12px;
    margin-bottom: 16px;
}
.settings-switch-row .left h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--text); }
.settings-switch-row .left p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

/* divider */
.settings-divider {
    border: none;
    border-top: 1px solid var(--line);
    margin: 20px 0;
}

/* submit */
.settings-submit {
    display: flex;
    justify-content: flex-end;
    padding-top: 8px;
}
.settings-submit .btn-save {
    padding: 12px 32px;
    font-size: 15px;
    font-weight: 700;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.settings-submit .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
.settings-submit .btn-save:active { transform: scale(0.98); }

.settings-row { display: flex; gap: 16px; flex-wrap: wrap; }
.settings-row .settings-field { flex: 1; min-width: 150px; }
.settings-hint { display: block; font-size: 11px; color: var(--text-muted); margin-top: 3px; }

/* switch — keep existing styles from app.css + JS handled below */
</style>

<main class="settings-page">
    <form method="post">
        <?= csrf_field() ?>

        <!-- 基础设置 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon blue">⚙️</div>
                <div class="info">
                    <h3>基础设置</h3>
                    <p>网站名称、余额名称等全局配置</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>网站名称</label>
                        <input name="platform_name" value="<?= e($platformName) ?>" placeholder="AI 图片视频创作系统" required>
                    </div>
                    <div class="settings-field">
                        <label>余额名称</label>
                        <input name="balance_label" value="<?= e($balanceLabel) ?>" placeholder="积分" required>
                        <div class="hint">显示在前台的余额单位，如"积分""点数""余额"。</div>
                    </div>
                    <div class="settings-field">
                        <label>ICP 备案号</label>
                        <input name="icp_number" value="<?= e($icpNumber) ?>" placeholder="例如：粤ICP备2024XXXXXX号-1">
                        <div class="hint">中国大陆网站备案号，显示在页面底部。</div>
                    </div>
                </div>
                <div class="settings-switch-row" style="margin-top:12px;">
                    <div class="left">
                        <h4>启用网站首页</h4>
                        <p>关闭后打开网站直接显示图片生成界面，未登录用户可浏览但不能使用。</p>
                    </div>
                    <label class="switch <?= $homepageEnabled ? 'active' : '' ?>">
                        <input type="checkbox" name="homepage_enabled" <?= $homepageEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="field">
                    <div class="field-label-inline">
                        <strong>邮箱验证</strong>
                        <p>开启后用户注册需验证邮箱方可登录；关闭后直接注册成功。</p>
                    </div>
                    <label class="switch <?= $emailVerifyEnabled ? 'active' : '' ?>">
                        <input type="checkbox" name="email_verify_enabled" <?= $emailVerifyEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 存储配置 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon purple">☁️</div>
                <div class="info">
                    <h3>存储配置</h3>
                    <p>图片/视频文件的存储后端</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-field">
                    <label>存储驱动</label>
                    <select name="storage_driver" id="storageDriverSelect">
                        <option value="local" <?= $storageDriver === 'local' ? 'selected' : '' ?>>本地存储（默认）</option>
                        <option value="database" <?= $storageDriver === 'database' ? 'selected' : '' ?>>数据库存储</option>
                        <option value="qiniu" <?= $storageDriver === 'qiniu' ? 'selected' : '' ?>>七牛云 Kodo</option>
                        <option value="aliyun" <?= $storageDriver === 'aliyun' ? 'selected' : '' ?>>阿里云 OSS</option>
                        <option value="tencent" <?= $storageDriver === 'tencent' ? 'selected' : '' ?>>腾讯云 COS</option>
                        <option value="ftp" <?= $storageDriver === 'ftp' ? 'selected' : '' ?>>FTP / FTPS</option>
                    </select>
                    <div class="hint">切换存储驱动后，新生成的图片/视频将上传到对应的存储服务。</div>
                </div>

                <!-- 云存储配置区（根据驱动选择动态显示） -->
                <div id="cloudConfigSections">
                    <!-- 七牛云 -->
                    <div class="cloud-config" data-driver="qiniu" style="<?= $storageDriver === 'qiniu' ? '' : 'display:none;' ?>">
                        <hr class="settings-divider">
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">🔑 七牛云密钥</p>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>AccessKey</label>
                                <input name="qiniu_access_key" value="<?= e($qiniuAccessKey) ?>" placeholder="七牛云 AccessKey">
                            </div>
                            <div class="settings-field">
                                <label>SecretKey</label>
                                <input name="qiniu_secret_key" value="<?= e($qiniuSecretKey) ?>" type="password" placeholder="七牛云 SecretKey" autocomplete="off">
                            </div>
                        </div>
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">📦 空间配置</p>
                        <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                            <div class="settings-field">
                                <label>空间名称 (Bucket)</label>
                                <input name="qiniu_bucket" value="<?= e($qiniuBucket) ?>" placeholder="例如：my-images">
                            </div>
                            <div class="settings-field">
                                <label>绑定域名</label>
                                <input name="qiniu_domain" value="<?= e($qiniuDomain) ?>" placeholder="https://cdn.example.com">
                                <div class="hint">CDN 加速域名或默认测试域名。</div>
                            </div>
                            <div class="settings-field">
                                <label>存储区域</label>
                                <select name="qiniu_region">
                                    <option value="z0" <?= $qiniuRegion === 'z0' ? 'selected' : '' ?>>华东 (z0)</option>
                                    <option value="z1" <?= $qiniuRegion === 'z1' ? 'selected' : '' ?>>华北 (z1)</option>
                                    <option value="z2" <?= $qiniuRegion === 'z2' ? 'selected' : '' ?>>华南 (z2)</option>
                                    <option value="na0" <?= $qiniuRegion === 'na0' ? 'selected' : '' ?>>北美 (na0)</option>
                                    <option value="as0" <?= $qiniuRegion === 'as0' ? 'selected' : '' ?>>东南亚 (as0)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 阿里云 OSS -->
                    <div class="cloud-config" data-driver="aliyun" style="<?= $storageDriver === 'aliyun' ? '' : 'display:none;' ?>">
                        <hr class="settings-divider">
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">🔑 阿里云密钥</p>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>AccessKey ID</label>
                                <input name="aliyun_access_key_id" value="<?= e($aliyunAccessKeyId) ?>" placeholder="RAM AccessKeyId">
                            </div>
                            <div class="settings-field">
                                <label>AccessKey Secret</label>
                                <input name="aliyun_access_key_secret" value="<?= e($aliyunAccessKeySecret) ?>" type="password" placeholder="RAM AccessKeySecret" autocomplete="off">
                            </div>
                        </div>
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">📦 空间配置</p>
                        <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                            <div class="settings-field">
                                <label>Bucket 名称</label>
                                <input name="aliyun_bucket" value="<?= e($aliyunBucket) ?>" placeholder="例如：my-images">
                            </div>
                            <div class="settings-field">
                                <label>Endpoint</label>
                                <input name="aliyun_endpoint" value="<?= e($aliyunEndpoint) ?>" placeholder="oss-cn-hangzhou.aliyuncs.com">
                                <div class="hint">OSS 地域节点，不含 Bucket 前缀。</div>
                            </div>
                            <div class="settings-field">
                                <label>CDN 域名（可选）</label>
                                <input name="aliyun_domain" value="<?= e($aliyunDomain) ?>" placeholder="https://cdn.example.com">
                                <div class="hint">留空使用默认 OSS 域名。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 腾讯云 COS -->
                    <div class="cloud-config" data-driver="tencent" style="<?= $storageDriver === 'tencent' ? '' : 'display:none;' ?>">
                        <hr class="settings-divider">
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">🔑 腾讯云密钥</p>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>SecretId</label>
                                <input name="tencent_secret_id" value="<?= e($tencentSecretId) ?>" placeholder="腾讯云 SecretId">
                            </div>
                            <div class="settings-field">
                                <label>SecretKey</label>
                                <input name="tencent_secret_key" value="<?= e($tencentSecretKey) ?>" type="password" placeholder="腾讯云 SecretKey" autocomplete="off">
                            </div>
                        </div>
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">📦 空间配置</p>
                        <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                            <div class="settings-field">
                                <label>Bucket</label>
                                <input name="tencent_bucket" value="<?= e($tencentBucket) ?>" placeholder="mybucket-1250000000">
                                <div class="hint">格式：BucketName-APPID</div>
                            </div>
                            <div class="settings-field">
                                <label>地域</label>
                                <input name="tencent_region" value="<?= e($tencentRegion) ?>" placeholder="ap-guangzhou">
                                <div class="hint">例如 ap-guangzhou / ap-shanghai</div>
                            </div>
                            <div class="settings-field">
                                <label>CDN 域名（可选）</label>
                                <input name="tencent_domain" value="<?= e($tencentDomain) ?>" placeholder="https://cdn.example.com">
                                <div class="hint">留空使用默认 COS 域名。</div>
                            </div>
                        </div>
                    </div>

                    <!-- FTP -->
                    <div class="cloud-config" data-driver="ftp" style="<?= $storageDriver === 'ftp' ? '' : 'display:none;' ?>">
                        <hr class="settings-divider">
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 12px;">🔌 FTP 连接</p>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>FTP 主机</label>
                                <input name="ftp_host" value="<?= e($ftpHost) ?>" placeholder="ftp.example.com">
                            </div>
                            <div class="settings-field">
                                <label>端口</label>
                                <input name="ftp_port" value="<?= e($ftpPort) ?>" placeholder="21">
                            </div>
                        </div>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>用户名</label>
                                <input name="ftp_username" value="<?= e($ftpUsername) ?>" placeholder="ftp_user">
                            </div>
                            <div class="settings-field">
                                <label>密码</label>
                                <input name="ftp_password" value="<?= e($ftpPassword) ?>" type="password" placeholder="FTP 密码" autocomplete="off">
                            </div>
                        </div>
                        <p style="font-size:13px;font-weight:700;color:var(--text);margin:12px 0;">📁 路径配置</p>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label>远程目录</label>
                                <input name="ftp_base_path" value="<?= e($ftpBasePath) ?>" placeholder="/public_html/uploads">
                                <div class="hint">文件上传的服务器根目录。</div>
                            </div>
                            <div class="settings-field">
                                <label>访问 URL 前缀</label>
                                <input name="ftp_base_url" value="<?= e($ftpBaseUrl) ?>" placeholder="https://img.example.com">
                                <div class="hint">拼接在文件路径前的公开访问域名。</div>
                            </div>
                        </div>
                        <div class="settings-switch-row">
                            <div class="left">
                                <h4>FTPS (SSL/TLS)</h4>
                                <p>启用加密传输。</p>
                            </div>
                            <label class="switch<?= $ftpSsl === '1' ? ' active' : '' ?>">
                                <input type="hidden" name="ftp_ssl" value="0">
                                <input type="checkbox" name="ftp_ssl" value="1" <?= $ftpSsl === '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 生成配置 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon blue">⏱️</div>
                <div class="info">
                    <h3>生成配置</h3>
                    <p>AI 图片/视频生成的超时和性能参数</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="settings-field">
                        <label>生成超时（秒）</label>
                        <input name="generation_timeout" type="number" min="30" max="900" value="<?= $generationTimeout ?>">
                        <div class="hint">API 请求的最长等待时间。范围 30~900 秒</div>
                    </div>
                    <div class="settings-field">
                        <label>订单超时（分钟）</label>
                        <input name="order_timeout_minutes" type="number" min="1" max="1440" value="<?= $orderTimeoutMinutes ?>">
                        <div class="hint">超过此时间的 pending 订单自动变为已过期。范围 1~1440 分钟</div>
                    </div>
                    <div class="settings-field">
                        <label>会话有效时长（秒）</label>
                        <input name="session_lifetime" type="number" min="0" max="2592000" value="<?= $sessionLifetime ?>">
                        <div class="hint">登录状态持久时间。默认 86400 秒（24小时），0=浏览器关闭即过期。</div>
                    </div>
                    <div class="settings-field">
                        <label>照片保留天数</label>
                        <input name="photo_retention_days" type="number" min="0" max="3650" value="<?= $photoRetentionDays ?>">
                        <div class="hint">超过此天数的生成照片将被自动删除。设为 0 表示永不过期。</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 上传限制 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon amber">📎</div>
                <div class="info">
                    <h3>上传限制</h3>
                    <p>编辑模式下参考图片的大小和数量限制</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="settings-field">
                        <label>最多上传图片数</label>
                        <input name="max_edit_images" type="number" min="1" max="16" value="<?= e($maxEditImages) ?>">
                    </div>
                    <div class="settings-field">
                        <label>单张最大 (MB)</label>
                        <input name="max_edit_image_mb" type="number" min="1" max="50" value="<?= e($maxEditImageMb) ?>">
                    </div>
                    <div class="settings-field">
                        <label>最大宽高 (px)</label>
                        <input name="max_edit_image_dimension" type="number" min="512" max="12000" value="<?= e($maxEditImageDimension) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- 接口路径 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon green">🔗</div>
                <div class="info">
                    <h3>接口路径</h3>
                    <p>自定义各生成模式的 API 端点路径</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="settings-field">
                        <label>图片生成路径</label>
                        <input name="image_generate_path" value="<?= e($imageGeneratePath) ?>" placeholder="/v1/images/generations">
                        <div class="hint">留空使用系统默认路径。</div>
                    </div>
                    <div class="settings-field">
                        <label>图片编辑路径</label>
                        <input name="image_edit_path" value="<?= e($imageEditPath) ?>" placeholder="/v1/images/edits">
                        <div class="hint">一般同生成接口路径。</div>
                    </div>
                    <div class="settings-field">
                        <label>视频生成路径</label>
                        <input name="video_generate_path" value="<?= e($videoGeneratePath) ?>" placeholder="/v1/videos">
                        <div class="hint">留空使用系统默认路径。</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 公告 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon purple">📢</div>
                <div class="info">
                    <h3>前台公告</h3>
                    <p>在图片/视频生成页顶部显示的通知信息</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>图片生成页公告</label>
                        <textarea name="generation_notice" rows="3" placeholder="留空则不显示公告"><?= e($generationNotice) ?></textarea>
                    </div>
                    <div class="settings-field">
                        <label>视频生成页公告</label>
                        <textarea name="video_notice" rows="3" placeholder="留空则不显示公告"><?= e($videoNotice) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-submit">
            <button type="submit" class="btn-save">💾 保存全部配置</button>
        </div>
    </form>
</main>

<script>
// 开关组件 — 同步 checkbox 和 visual switch
document.querySelectorAll('.switch').forEach(function(sw) {
    var cb = sw.querySelector('input[type="checkbox"]');
    if (!cb) return;
    if (cb.checked) sw.classList.add('active');
    sw.addEventListener('click', function(e) {
        if (e.target === cb) return;
        cb.checked = !cb.checked;
        sw.classList.toggle('active', cb.checked);
    });
    cb.addEventListener('change', function() {
        sw.classList.toggle('active', cb.checked);
    });
});

// 存储驱动选择 → 显示对应云服务商配置
(function() {
    var select = document.getElementById('storageDriverSelect');
    if (!select) return;
    var configs = document.querySelectorAll('.cloud-config');
    function update() {
        var val = select.value;
        configs.forEach(function(c) {
            c.style.display = c.dataset.driver === val ? '' : 'none';
        });
    }
    select.addEventListener('change', update);
    update();
})();
</script>

<?php render_footer(); ?>
