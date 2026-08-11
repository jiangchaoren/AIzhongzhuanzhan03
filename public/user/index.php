<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image/generation.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login_optional();
$isGuest = !$user;
if ($isGuest) {
    $user = ['id' => 0, 'username' => '访客', 'role' => 'user', 'credits' => 0, 'watermark_points' => 0, 'is_active' => 1];
}
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
cleanup_stale_running_generation_records();
ensure_watermark_columns();

ensure_ai_models_table();
ensure_ai_models_type_column();
$aiModels = active_ai_models();
$videoModels = active_video_ai_models();
$noActiveModel = empty($aiModels) && empty($videoModels);
$noImageModel = empty($aiModels);

// 图库翻页
$perPage = 6;
$page = max(1, (int) ($_GET['page'] ?? 1));
$stmt = db()->prepare("SELECT COUNT(*) FROM generation_records WHERE user_id = ? AND deleted_at IS NULL AND (mode IS NULL OR mode != 'video')");
$stmt->execute([$user['id']]);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            image_url, mime_type, credits_charged, error_message, started_at, finished_at,
            deleted_at, created_at, image_base64 IS NOT NULL AS has_image_base64
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND (mode IS NULL OR mode != 'video')
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();
$rawNotice = trim((string) app_setting('generation_notice', ''));
// 防御乱码：检测已知乱码模式或 PUA 字符
if ($rawNotice !== '') {
    $knownCorrupted = ['姝ｆ', '鎻愪', '鐢', '鍥', '璇', '缁', '缂', '寮', '褰', '鏀'];
    foreach ($knownCorrupted as $pattern) {
        if (strpos($rawNotice, $pattern) !== false) {
            $rawNotice = '';
            break;
        }
    }
}
if ($rawNotice !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{FFFD}]/u', $rawNotice)) {
    $rawNotice = '';
}
$generationNotice = $rawNotice ?: '注意：因AI算力产图较慢，预计可能3-5分钟不止，请耐心等待，生成失败不消耗次数！';
$balanceLabel = balance_label();
$maxEditImages = max_edit_images();
$drawCost = generation_cost_for('draw');
$editCost = generation_cost_for('edit');
$promptOptimizeEnabled = (bool) app_setting('prompt_optimize_enabled', '0');

render_header('图片生成器', 'app');
?>
<main>
    <!-- Page Header with Balance Badge -->
    <div class="page-hd">
        <div>
            <p><?= e($platformName ?? platform_name()) ?></p>
            <h1>图片生成</h1>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="badge-balance" title="前往商城充值">
                <span>
                    <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </span>
                <strong class="num" data-balance-display data-balance-label="<?= e($balanceLabel) ?>"><?= number_format((int) $user['credits']) ?></strong>
                <span class="label"><?= e($balanceLabel) ?></span>
            </a>
            <div class="badge-balance" style="border-color:#8b5cf6;cursor:default;" title="水印点">
                <span style="color:#8b5cf6;">💧</span>
                <strong class="num" data-wp-display><?= number_format((int) ($user['watermark_points'] ?? 0)) ?></strong>
                <span class="label">水印点</span>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Left Column: Generation Settings -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>生成设置</h3>
                    <p class="sub">Settings</p>
                </div>
            </div>
            <div class="card-v3-body">
                <?php if ($generationNotice !== ''): ?>
                    <div class="generation-notice"><?= e($generationNotice) ?></div>
                <?php endif; ?>
                <?php if ($noActiveModel): ?>
                    <div class="alert error">
                        <strong>系统错误</strong>
                        <span>管理员尚未配置可用的 AI 模型，请前往后台 → AI模型 添加并启用至少一个模型。</span>
                    </div>
                <?php elseif ($noImageModel): ?>
                    <div class="alert warning">
                        <strong>暂无图片模型</strong>
                        <span>管理员尚未配置图片生成模型，当前仅支持视频生成。</span>
                    </div>
                <?php endif; ?>
                <form id="generateForm" class="form<?= $noImageModel ? ' form-disabled' : '' ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mode-toggle" role="group" aria-label="生成模式">
                        <label>
                            <input type="radio" name="mode" value="draw" checked>
                            <span>🎨 绘画</span>
                        </label>
                        <label>
                            <input type="radio" name="mode" value="edit">
                            <span>✏️ 编辑</span>
                        </label>
                    </div>
                    <div class="field-v3 edit-upload-field hidden" data-edit-upload>
                        <label>参考图片（最多 <?= $maxEditImages ?> 张）</label>
                        <div class="edit-upload-box" data-edit-upload-box>
                            <input name="edit_images[]" type="file" accept="image/png,image/jpeg,image/webp" multiple data-max-files="<?= $maxEditImages ?>">
                            <div class="edit-upload-icon" aria-hidden="true">+</div>
                            <div>
                                <strong>点击上传参考图片</strong>
                                <small data-edit-upload-hint>支持 PNG / JPG / WEBP，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" data-edit-preview></div>
                    </div>
                    <div class="field-v3">
                        <label for="prompt">提示词</label>
                        <textarea name="prompt" id="prompt" rows="7" placeholder="描述你想生成的图片..." required></textarea>
                        <?php if ($promptOptimizeEnabled): ?>
                        <div class="prompt-optimize-bar">
                            <button id="optimizePromptBtn" class="btn btn-secondary" type="button" style="width:100%">✨ 优化提示词</button>
                            <span id="optimizePromptStatus" class="hint hidden"></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="field-v3">
                        <label>画面比例</label>
                        <div class="size-selector" data-size-selector>
                            <?php
                            $sizes = generation_allowed_sizes();
                            $sizeLabels = [
                                'auto' => '自适应',
                                '1:1' => '1:1',
                                '3:2' => '3:2',
                                '2:3' => '2:3',
                                '4:3' => '4:3',
                                '3:4' => '3:4',
                                '5:4' => '5:4',
                                '4:5' => '4:5',
                                '16:9' => '16:9',
                                '9:16' => '9:16',
                                '2:1' => '2:1',
                                '1:2' => '1:2',
                                '21:9' => '21:9',
                                '9:21' => '9:21',
                            ];
                            $sizeIcons = [
                                'auto' => '🔄',
                                '1:1' => '⬜',
                                '3:2' => '🖼️',
                                '2:3' => '🖼️',
                                '4:3' => '🖼️',
                                '3:4' => '🖼️',
                                '5:4' => '🖼️',
                                '4:5' => '🖼️',
                                '16:9' => '🖥️',
                                '9:16' => '📱',
                                '2:1' => '🖼️',
                                '1:2' => '🖼️',
                                '21:9' => '🎬',
                                '9:21' => '📱',
                            ];
                            $sizeWidthRatios = [
                                'auto' => 1, '1:1' => 1, '3:2' => 1.5, '2:3' => 0.67,
                                '4:3' => 1.33, '3:4' => 0.75, '5:4' => 1.25, '4:5' => 0.8,
                                '16:9' => 1.78, '9:16' => 0.56, '2:1' => 2, '1:2' => 0.5,
                                '21:9' => 2.33, '9:21' => 0.43,
                            ];
                            ?>
                            <input type="hidden" name="size" value="auto" data-size-input>
                            <?php foreach ($sizes as $s): ?>
                            <button type="button" class="size-chip <?= $s === 'auto' ? 'active' : '' ?>" data-size="<?= e($s) ?>" title="<?= e($sizeLabels[$s] ?? $s) ?>">
                                <span class="size-ratio-visual" style="width:<?= (int)max(14, min(36, round(24 * ($sizeWidthRatios[$s] ?? 1)))) ?>px;aspect-ratio:<?= e($s) === 'auto' ? '1' : str_replace(':', '/', $s) ?>;"></span>
                                <span class="size-label-text"><?= e($sizeLabels[$s] ?? $s) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="field-v3" id="resolutionField">
                        <label>分辨率</label>
                        <input type="hidden" name="resolution_level" value="1K" data-resolution-input>
                        <div class="resolution-options" data-resolution-selector>
                            <button type="button" class="res-chip active" data-res="1K">🖼️ 1K<span class="res-hint">≤1280px</span></button>
                            <button type="button" class="res-chip" data-res="2K">🖥️ 2K<span class="res-hint">≤2048px</span></button>
                            <button type="button" class="res-chip" data-res="4K">📺 4K<span class="res-hint">≤3840px</span></button>
                        </div>
                    </div>
                    <?php if ($aiModels): ?>
                    <div class="field-v3">
                        <label for="ai_model">AI 模型</label>
                        <div class="model-chip">
                            <select name="ai_model_id" id="ai_model"
                        data-image-models="<?= e(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['name'], 'credits' => (int)($m['credits'] ?? 0), 'resolution_levels' => (string)($m['resolution_levels'] ?? '1K'), 'price_1k' => (int)($m['credits_1k'] ?? 0), 'price_2k' => (int)($m['credits_2k'] ?? 0), 'price_4k' => (int)($m['credits_4k'] ?? 0), 'watermark_point_cost' => (int)($m['watermark_point_cost'] ?? 0), 'site_type' => (string)($m['site_type'] ?? 'standard')]; }, $aiModels))) ?>"
                    >
                                <?php foreach ($aiModels as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>" <?= (int)($m['credits'] ?? 0) > 0 ? 'data-credits="'.(int)$m['credits'].'"' : '' ?> data-site-type="<?= e($m['site_type'] ?? 'standard') ?>"><?= e($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="cost-hint" data-cost-display data-draw-cost="<?= $drawCost ?>" data-edit-cost="<?= $editCost ?>">
                        当前消耗：<strong data-cost-value="<?= $drawCost ?>"><?= $drawCost ?></strong> <?= e($balanceLabel) ?>/张
                        <span data-cost-edit class="hidden">（编辑模式 <strong><?= $editCost ?></strong> <?= e($balanceLabel) ?>/张）</span>
                    </div>
                    <?php 
                    $antiWmEnabled = app_setting('anti_watermark_enabled', 'off') === 'on';
                    $watermarkEnabled = app_setting('watermark_enabled', 'off') === 'on';
                    $userWp = (int) ($user['watermark_points'] ?? 0);
                    ?>
                    <script>window._wpBalance = <?= $userWp ?>;</script>
                    <?php if ($antiWmEnabled && $watermarkEnabled): ?>
                    <div class="field-v3" id="antiWatermarkField" data-anti-watermark-toggle>
                        <label class="toggle-row">
                            <input type="checkbox" name="anti_watermark" value="1" id="antiWatermarkCheck" data-anti-watermark-check>
                            <span data-wp-toggle-label>关闭去水印：节省 <strong data-wp-cost-display>0</strong> 水印点</span>
                        </label>
                        <small class="hint" data-wp-balance>当前水印点：<?= $userWp ?></small>
                    </div>
                    <?php endif; ?>
                    <button id="generateButton" class="btn btn-primary btn-lg" type="submit" style="width:100%;">生成图片</button>
                </form>
                <div id="generateMessage" class="inline-message hidden"></div>
            </div>
        </section>

        <!-- Right Column: My Gallery -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>我的图库</h3>
                    <p class="sub">My Gallery</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/records">查看全部</a>
            </div>
            <div class="card-v3-body">
                <div id="historyList" class="grid-auto" data-gallery>
                        <?php if (!$records): ?>
                            <div class="history-empty-inline" style="grid-column:1/-1;">暂无生成记录，开始你的第一次创作吧</div>
                        <?php endif; ?>
                        <?php foreach ($records as $record): ?>
                            <?php $src = record_image_src($record); ?>
                            <?php $videoSrc = generation_record_video_src($record); ?>
                            <?php $inputImageCount = generation_input_image_count($record); ?>
                            <?php $isVideo = ($record['mode'] ?? 'draw') === 'video'; ?>
                            <article
                                class="media-card"
                                tabindex="0"
                                data-record-id="<?= (int) $record['id'] ?>"
                                data-status="<?= e($record['status']) ?>"
                                data-mode="<?= e($record['mode'] ?? 'draw') ?>"
                                data-model="<?= e($record['model'] ?? '') ?>"
                                data-prompt="<?= e($record['prompt']) ?>"
                                data-size="<?= e($record['size']) ?>"
                                data-quality="<?= e($record['quality']) ?>"
                                data-format="<?= e($record['output_format']) ?>"
                                data-credits="<?= (int) $record['credits_charged'] ?>"
                                data-created="<?= e($record['created_at']) ?>"
                                data-finished="<?= e($record['finished_at'] ?: '-') ?>"
                                data-error="<?= e($record['error_message'] ?: '') ?>"
                                data-input-count="<?= $inputImageCount ?>"
                                style="cursor:pointer;"
                            >
                                <?php if ($isVideo && $videoSrc): ?>
                                    <video src="<?= e($videoSrc) ?>" controls></video>
                                <?php elseif ($src): ?>
                                    <img src="<?= e($src) ?>" alt="生成图片">
                                <?php else: ?>
                                    <div style="width:100%;aspect-ratio:1;display:grid;place-items:center;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;font-size:13px;">
                                        <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="media-card-body">
                                    <div class="prompt"><?= e($record['prompt']) ?></div>
                                    <div class="meta">
                                        <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                        <span><?= e(mode_display_label((string) ($record['mode'] ?? 'draw'))) ?> / <?= e($record['size']) ?> / <?= e($isVideo ? ($record['output_format'] ?: 'mp4') : $record['quality']) ?></span>
                                    </div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
                                        <time style="font-size:10px;color:var(--text-muted);"><?= e($record['created_at']) ?></time>
                                        <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                            <input type="hidden" name="redirect_to" value="/user/index">
                                            <button type="submit" class="record-delete"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5.3 4V2.7c0-.4.3-.7.7-.7h4c.4 0 .7.3.7.7V4M6.7 7.3v4M9.3 7.3v4M3.3 4l.8 8.6c.1.7.7 1.4 1.5 1.4h4.7c.7 0 1.4-.6 1.5-1.4l.8-8.6"/></svg>删除</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pages" aria-label="图库分页" style="margin-top:16px;">
                            <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? ('/user/index?page=' . ($page - 1)) : '#' ?>">上一页</a>
                            <span style="display:flex;align-items:center;padding:0 12px;font-size:13px;color:var(--text-muted);">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                            <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? ('/user/index?page=' . ($page + 1)) : '#' ?>">下一页</a>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<?php if ($noImageModel): ?>
<style>
.form-disabled { opacity: 0.45; pointer-events: none; user-select: none; }
.form-disabled input, .form-disabled textarea, .form-disabled select, .form-disabled button { pointer-events: none; }
</style>
<?php endif; ?>
<script src="/assets/user.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/user.js') ?: time())) ?>"></script>
<?php if ($isGuest): ?>
<!-- 登录注册弹窗 -->
<div id="authModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeAuthModal()">
    <div class="modal-card" style="background:var(--main-surface);border-radius:16px;width:90vw;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.35);">
        <div style="padding:24px 24px 0;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;">登录 / 注册</h3>
            <button onclick="closeAuthModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);">&times;</button>
        </div>
        <div style="padding:20px 24px 24px;">
            <p style="font-size:14px;color:var(--text-muted);margin-bottom:20px;">请登录后使用完整功能。</p>
            <a href="/login" class="btn btn-primary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;margin-bottom:10px;">🔑 登录</a>
            <a href="/login?register=1" class="btn btn-secondary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;">📝 注册</a>
        </div>
    </div>
</div>
<script>
function showAuthModal() {
    document.getElementById('authModal').style.display = 'flex';
}
function closeAuthModal() {
    document.getElementById('authModal').style.display = 'none';
}
// 访客模式：拦截所有交互按钮
document.addEventListener('click', function(e) {
    var btn = e.target.closest('button, [role="button"], a.btn, .media-card');
    if (!btn) return;
    // 排除弹窗内的按钮、导航链接
    if (btn.closest('#authModal') || btn.closest('nav') || btn.closest('.admin-nav-bar') ||
        btn.tagName === 'A' && btn.getAttribute('href') && btn.getAttribute('href').startsWith('#')) return;
    if (btn.closest('[data-close-dialog]')) return;
    e.preventDefault();
    e.stopPropagation();
    showAuthModal();
}, true);
</script>
<?php endif; ?>
<?php render_footer(); ?>
