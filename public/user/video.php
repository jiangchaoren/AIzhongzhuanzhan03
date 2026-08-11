<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/generation.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/video/generation.php';

$user = require_login_optional();
$isGuest = !$user;
if ($isGuest) {
    $user = ["id" => 0, "username" => "访客", "role" => "user", "credits" => 0, "watermark_points" => 0, "is_active" => 1];
}
ensure_generation_records_generation_options();
ensure_generation_records_video_columns();

ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_ai_models_api_columns();
$videoModels = active_video_ai_models();
$noActiveModel = empty($videoModels);

// 视频页当前消耗：只读取“当前可选视频模型”的通用 credits，绝不走图片分辨率分档定价。
$videoModelPayload = [];
foreach ($videoModels as $model) {
    $modelCredits = isset($model['credits']) ? (int) $model['credits'] : 0;
    // 将 agnes_config / grok_config 预解码为数组，避免 JS 端二次 JSON 解析出错
    $agnesCfgDecoded = null;
    $rawCfg = $model['agnes_config'] ?? null;
    if ($rawCfg && is_string($rawCfg)) {
        $decoded = json_decode($rawCfg, true);
        if (is_array($decoded)) $agnesCfgDecoded = $decoded;
    }
    $grokCfgDecoded = null;
    $rawGrok = $model['grok_config'] ?? null;
    if ($rawGrok && is_string($rawGrok)) {
        $decoded = json_decode($rawGrok, true);
        if (is_array($decoded)) $grokCfgDecoded = $decoded;
    }
    $seedanceCfgDecoded = null;
    $rawSeedance = $model['seedance_config'] ?? null;
    if ($rawSeedance && is_string($rawSeedance)) {
        $decoded = json_decode($rawSeedance, true);
        if (is_array($decoded)) $seedanceCfgDecoded = $decoded;
    }
    $videoModelPayload[] = [
        'id'      => (int) $model['id'],
        'credits' => $modelCredits > 0 ? $modelCredits : 1,
        'watermark_point_cost' => (int) ($model['watermark_point_cost'] ?? 0),
        'site_type' => (string) ($model['site_type'] ?? 'standard'),
        'agnes_config' => $agnesCfgDecoded,
        'grok_config'  => $grokCfgDecoded,
        'seedance_config' => $seedanceCfgDecoded,
    ];
}
// 计算初始消耗：Agnes/Grok 模型按默认分辨率 + 默认时长计算，非 Agnes/Grok 取基础 credits
$videoCost = $videoModelPayload[0]['credits'] ?? 1;
$firstIsAgnes = false;
$firstIsGrok = false;
$firstIsSeedance = false;
if (!empty($videoModels)) {
    $firstModel = $videoModels[0];
    if (($firstModel['site_type'] ?? 'standard') === 'agnes') {
        $firstIsAgnes = true;
        $agnesCfg = json_decode((string) ($firstModel['agnes_config'] ?? '{}'), true) ?: [];
        $defaultRes = '480p';
        foreach (['480p', '720p', '1080p'] as $r) {
            if (!empty($agnesCfg[$r]['enabled'])) { $defaultRes = $r; break; }
        }
        $resCfg = $agnesCfg[$defaultRes] ?? ['credits' => 5];
        $resCost = max(1, (int) ($resCfg['credits'] ?? 5));
        $durCfg = $agnesCfg['_duration'] ?? ['mode' => 'custom', 'max_seconds' => 15, 'price_per_second' => 1, 'tiers' => []];
        $durCost = 0;
        if (($durCfg['mode'] ?? 'custom') === 'custom') {
            $durCost = 5 * max(1, (int) ($durCfg['price_per_second'] ?? 1));
        } else {
            $tiers = $durCfg['tiers'] ?? [];
            $durCost = !empty($tiers) ? max(1, (int) ($tiers[0]['credits'] ?? 0)) : 0;
        }
        $videoCost = $resCost + $durCost;
    } elseif (($firstModel['site_type'] ?? 'standard') === 'grok') {
        $firstIsGrok = true;
        $gCfg = json_decode((string) ($firstModel['grok_config'] ?? '{}'), true) ?: [];
        $gDefaultRes = '480p';
        foreach (['480p', '720p'] as $r) {
            if (!empty($gCfg[$r]['enabled'])) { $gDefaultRes = $r; break; }
        }
        $gResCost = max(1, (int) ($gCfg[$gDefaultRes]['credits'] ?? 5));
        $gDurCfg = $gCfg['_duration'] ?? ['max_seconds' => 15, 'price_per_second' => 2];
        $gPps = max(1, (int) ($gDurCfg['price_per_second'] ?? 2));
        $gDurCost = 4 * $gPps; // 默认 4 秒
        $videoCost = $gResCost + $gDurCost;
    } elseif (($firstModel['site_type'] ?? 'standard') === 'seedance') {
        $firstIsSeedance = true;
        $sdCfg = json_decode((string) ($firstModel['seedance_config'] ?? '{}'), true) ?: [];
        $sdDefaultRes = '720p';
        foreach (['480p', '720p', '1080p'] as $r) {
            if (!empty($sdCfg[$r]['enabled'])) { $sdDefaultRes = $r; break; }
        }
        $sdResCost = max(1, (int) ($sdCfg[$sdDefaultRes]['credits'] ?? 5));
        $sdDurCfg = $sdCfg['_duration'] ?? ['mode' => 'fixed', 'tiers' => [['seconds' => 5, 'credits' => 15]], 'max_seconds' => 15, 'price_per_second' => 2];
        $sdDurCost = 0;
        if (($sdDurCfg['mode'] ?? 'fixed') === 'custom') {
            $sdDurCost = 5 * max(1, (int) ($sdDurCfg['price_per_second'] ?? 2));
        } else {
            $tiers = $sdDurCfg['tiers'] ?? [];
            $sdDurCost = !empty($tiers) ? max(1, (int) ($tiers[0]['credits'] ?? 15)) : 15;
        }
        $videoCost = $sdResCost + $sdDurCost;
    }
}

// 只查视频记录 — 带翻页
$perPage = 6;
$page = max(1, (int) ($_GET['page'] ?? 1));
$stmt = db()->prepare("SELECT COUNT(*) FROM generation_records WHERE user_id = ? AND deleted_at IS NULL AND mode = 'video'");
$stmt->execute([$user['id']]);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            image_url, mime_type, credits_charged, error_message, started_at, finished_at,
            deleted_at, created_at,
            video_url, video_base64, video_mime_type
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND mode = 'video'
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

$balanceLabel = balance_label();
$videoNotice = trim((string) app_setting('video_notice', ''));
// 防御：数据库可能残留旧乱码数据，检测到包含已知乱码模式就回退
if ($videoNotice !== '') {
    $knownCorrupted = ['姝ｆ', '鎻愪', '鐢', '鍥', '璇', '缁', '缂', '寮', '褰', '鏀'];
    foreach ($knownCorrupted as $pattern) {
        if (strpos($videoNotice, $pattern) !== false) {
            $videoNotice = '';
            break;
        }
    }
}
// 还可能包含 PUA 字符（双重重编码产物）
if ($videoNotice !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{FFFD}]/u', $videoNotice)) {
    $videoNotice = '';
}
$generationNotice = $videoNotice ?: '注意：视频生成耗时较长，请耐心等待，生成失败不消耗次数！';
$promptOptimizeEnabled = (bool) app_setting('prompt_optimize_enabled', '0');

render_header('视频生成', 'video');
?>
<main>
    <!-- Page Header with Balance Badge -->
    <div class="page-hd">
        <div>
            <p><?= e($platformName ?? platform_name()) ?></p>
            <h1>视频生成</h1>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="badge-balance" title="前往商城充值">
                <span>
                    <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </span>
                <strong class="num" data-balance-display data-balance-label="<?= e($balanceLabel) ?>"><?= number_format((int) $user['credits']) ?></strong>
                <span class="label"><?= e($balanceLabel) ?></span>
            </a>
        </div>
    </div>

    <div class="grid-2">
        <!-- Left Column: Video Generation Settings -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <p class="sub">Settings</p>
                    <h3>视频生成设置</h3>
                </div>
            </div>
            <div class="card-v3-body">
                <?php if ($generationNotice !== ''): ?>
                    <div class="generation-notice"><?= e($generationNotice) ?></div>
                <?php endif; ?>
                <?php if ($noActiveModel): ?>
                    <div class="alert error">
                        <strong>系统错误</strong>
                        <span>管理员尚未配置可用的视频 AI 模型，请前往后台 → AI模型 添加并启用至少一个视频模型。</span>
                    </div>
                <?php endif; ?>
                <form id="videoGenerateForm" class="form" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="video">
                    <div class="field-v3">
                        <label for="prompt">视频描述</label>
                        <textarea id="prompt" name="prompt" rows="5" placeholder="描述你想生成的视频内容..." required></textarea>
                        <?php if ($promptOptimizeEnabled): ?>
                        <div class="prompt-optimize-bar">
                            <button id="optimizePromptBtn" class="btn btn-secondary" type="button" style="width:100%">✨ 优化提示词</button>
                            <span id="optimizePromptStatus" class="hint hidden"></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($videoModels): ?>
                    <div class="field-v3">
                        <label for="ai_model_id">AI 模型</label>
                        <select name="ai_model_id" id="ai_model_id"
                            data-video-models="<?= e(json_encode($videoModelPayload, JSON_UNESCAPED_UNICODE)) ?>"
                        >
                            <?php foreach ($videoModels as $m): ?>
                                <?php $optionCredits = max(1, (int)($m['credits'] ?? 0)); ?>
                                <option value="<?= (int) $m['id'] ?>" data-credits="<?= $optionCredits ?>" data-site-type="<?= e($m['site_type'] ?? 'standard') ?>" data-agnes-config="<?= e($m['agnes_config'] ?? '') ?>" data-grok-config="<?= e($m['grok_config'] ?? '') ?>" data-seedance-config="<?= e($m['seedance_config'] ?? '') ?>"><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <script>
                    // 控件显隐——直接在 select 后面运行
                    (function() {
                        var sel = document.getElementById("ai_model_id");
                        var agnesControls = document.querySelectorAll(".agnes-control");
                        var grokControls = document.querySelectorAll(".grok-control");
                        var seedanceControls = document.querySelectorAll(".seedance-control");
                        var toggle = function() {
                            var opt = sel && sel.selectedOptions[0];
                            var siteType = opt && opt.getAttribute("data-site-type");
                            var isAgnes = siteType === "agnes";
                            var isGrok = siteType === "grok";
                            var isSeedance = siteType === "seedance";
                            agnesControls.forEach(function(el) { el.style.display = isAgnes ? "" : "none"; });
                            grokControls.forEach(function(el) { el.style.display = isGrok ? "" : "none"; });
                            seedanceControls.forEach(function(el) { el.style.display = isSeedance ? "" : "none"; });
                            // 触发 video.js 成本更新
                            if (typeof updateCost === "function") updateCost();
                        };
                        if (sel) { sel.addEventListener("change", toggle); toggle(); }
                    })();
                    </script>
                    <?php endif; ?>
                    <?php 
                    $firstIsAgnes = !empty($videoModels) && ($videoModels[0]['site_type'] ?? 'standard') === 'agnes';
                    // 解析首个模型 Agnes 配置
                    $firstAgnesCfg = [];
                    if ($firstIsAgnes && !empty($videoModels[0]['agnes_config'])) {
                        $firstAgnesCfg = json_decode($videoModels[0]['agnes_config'], true) ?: [];
                    }
                    $durCfg = $firstAgnesCfg['_duration'] ?? ['mode' => 'custom', 'max_seconds' => 15, 'price_per_second' => 1, 'tiers' => []];
                    $isCustomDur = ($durCfg['mode'] ?? 'custom') === 'custom';
                    $maxSec = max(1, (int) ($durCfg['max_seconds'] ?? 15));
                    $advCfg = $firstAgnesCfg['_advanced'] ?? ['frame_rate' => false, 'inference_steps' => false, 'seed' => false, 'negative_prompt' => false];
                    ?>
                    <!-- Agnes 分辨率选择器 -->
                    <div class="field-v3 agnes-control" data-agnes-res-selector<?= $firstIsAgnes ? '' : ' style="display:none;"' ?>>
                        <label>分辨率 <span class="hint">（Agnes）</span></label>
                        <div class="agnes-res-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="agnesResChips">
                            <?php foreach (['480p', '720p', '1080p'] as $res): 
                                $resCfg = $firstAgnesCfg[$res] ?? ['enabled' => ($res === '480p'), 'credits' => 5];
                            ?>
                            <button type="button" class="chip agnes-res-chip<?= !$resCfg['enabled'] ? ' disabled' : '' ?>" 
                                data-agnes-res="<?= $res ?>" <?= $res === '480p' && $resCfg['enabled'] ? 'data-agnes-res-active' : '' ?>
                                onclick="var d=this.parentElement;d.querySelectorAll('button').forEach(function(b){b.removeAttribute('data-agnes-res-active')});this.setAttribute('data-agnes-res-active','');document.querySelector('[data-agnes-resolution-input]').value=this.getAttribute('data-agnes-res');"
                                <?= !$resCfg['enabled'] ? 'style="opacity:0.35;pointer-events:none;"' : '' ?>
                            ><?= $res ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="agnes_resolution" data-agnes-resolution-input value="480p">
                    </div>
                    <!-- 宽高比选择器 -->
                    <div class="field-v3 agnes-control" data-agnes-ar-selector<?= $firstIsAgnes ? '' : ' style="display:none;"' ?>>
                        <label>宽高比 <span class="hint">（Agnes）</span></label>
                        <div class="agnes-ar-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="agnesArChips">
                            <?php foreach (['16:9', '9:16', '1:1', '4:3', '3:4'] as $ar): ?>
                            <button type="button" class="chip agnes-ar-chip" data-agnes-ar="<?= $ar ?>" <?= $ar === '16:9' ? 'data-agnes-ar-active' : '' ?>
                                onclick="var d=this.parentElement;d.querySelectorAll('button').forEach(function(b){b.removeAttribute('data-agnes-ar-active')});this.setAttribute('data-agnes-ar-active','');document.querySelector('[data-agnes-ar-input]').value=this.getAttribute('data-agnes-ar');"
                            ><?= $ar ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="agnes_aspect_ratio" data-agnes-ar-input value="16:9">
                    </div>
                    <!-- 视频时长 -->
                    <div class="field-v3 agnes-control" data-agnes-duration-selector<?= $firstIsAgnes ? '' : ' style="display:none;"' ?>>
                        <label>视频时长 <span class="hint">（Agnes）</span></label>
                        <?php if ($isCustomDur): ?>
                        <div data-agnes-duration-custom>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="agnes_duration_seconds" id="agnesDurationInput" min="1" max="<?= $maxSec ?>" value="5" style="width:80px;text-align:center;font-size:16px;font-weight:700;" placeholder="秒">
                                <span style="font-size:14px;color:var(--text-muted);">秒 <small>(1~<?= $maxSec ?>)</small></span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div data-agnes-duration-fixed>
                            <div class="agnes-duration-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="agnesDurChips">
                                <?php foreach ($durCfg['tiers'] ?? [] as $i => $tier): ?>
                                <button type="button" class="chip agnes-dur-chip" data-agnes-dur="<?= (int)$tier['seconds'] ?>" data-agnes-dur-credits="<?= (int)$tier['credits'] ?>" <?= $i === 0 ? 'data-agnes-duration-active' : '' ?>
                                    onclick="var d=this.parentElement;d.querySelectorAll('button').forEach(function(b){b.removeAttribute('data-agnes-duration-active')});this.setAttribute('data-agnes-duration-active','');document.querySelector('[data-agnes-duration-fixed-input]').value=this.getAttribute('data-agnes-dur');"
                                ><?= (int)$tier['seconds'] ?>s</button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="agnes_duration_seconds" data-agnes-duration-fixed-input value="<?= (int)($durCfg['tiers'][0]['seconds'] ?? 5) ?>">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="agnes-duration-hint" id="agnesDurationHint" style="display:none;font-size:12px;color:#e67e22;margin-top:4px;"></div>
                    <!-- Agnes 生成模式 + 图片上传 -->
                    <div class="field-v3 agnes-control agnes-mode-control" data-agnes-mode-selector<?= $firstIsAgnes ? '' : ' style="display:none;"' ?>>
                        <label>生成模式 <span class="hint">（Agnes）</span></label>
                        <div class="agnes-mode-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;">
                            <button type="button" class="chip agnes-mode-chip" data-agnes-mode="text2vid" data-agnes-mode-active onclick="switchAgnesMode('text2vid')">🎬 文生视频</button>
                            <button type="button" class="chip agnes-mode-chip" data-agnes-mode="ti2vid" onclick="switchAgnesMode('ti2vid')">🖼 图生视频</button>
                            <button type="button" class="chip agnes-mode-chip" data-agnes-mode="multi" onclick="switchAgnesMode('multi')">🖼🖼 多图视频</button>
                            <button type="button" class="chip agnes-mode-chip" data-agnes-mode="keyframes" onclick="switchAgnesMode('keyframes')">🎞 关键帧</button>
                        </div>
                        <input type="hidden" name="agnes_mode" id="agnesModeInput" value="text2vid">
                    </div>
                    <!-- 单图上传（图生视频） -->
                    <div class="field-v3 agnes-control agnes-image-control" data-agnes-image-single style="display:none;">
                        <label>参考图片 <span class="hint">（图生视频）</span></label>
                        <div class="agnes-upload-area" id="agnesSingleUpload" style="border:2px dashed var(--line);border-radius:12px;padding:24px;text-align:center;cursor:pointer;">
                            <div class="agnes-upload-placeholder">📷 点击上传图片<br><small style="color:var(--text-muted);">支持 PNG / JPG / WebP</small></div>
                            <img class="agnes-upload-preview hidden" style="max-width:100%;max-height:200px;border-radius:8px;">
                            <input type="file" name="agnes_images_single" accept="image/png,image/jpeg,image/webp" style="display:none;" id="agnesSingleInput">
                        </div>
                    </div>
                    <!-- 多图上传（多图视频 / 关键帧） -->
                    <div class="field-v3 agnes-control agnes-image-control" data-agnes-image-multi style="display:none;">
                        <label>参考图片 <span class="hint">（可拖拽排序）</span></label>
                        <div class="agnes-multi-list" id="agnesMultiList" style="display:flex;flex-wrap:wrap;gap:10px;min-height:60px;padding:8px;border:2px dashed var(--line);border-radius:12px;">
                            <div class="agnes-multi-add" onclick="document.getElementById('agnesMultiInput').click()" style="width:100px;height:100px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:var(--main-surface);cursor:pointer;font-size:28px;color:var(--text-muted);border:1.5px dashed var(--line);">
                                <span>+</span>
                            </div>
                        </div>
                        <input type="file" name="agnes_images_multi[]" accept="image/png,image/jpeg,image/webp" multiple style="display:none;" id="agnesMultiInput">
                        <input type="hidden" name="agnes_image_order" id="agnesImageOrder" value="">
                    </div>
                    <script>
                    // ── Agnes 模式切换 ──
                    var agnesImageOrder = [];
                    function switchAgnesMode(mode) {
                        document.querySelectorAll('[data-agnes-mode]').forEach(function(b) {
                            b.removeAttribute('data-agnes-mode-active');
                        });
                        var btn = document.querySelector('[data-agnes-mode="' + mode + '"]');
                        if (btn) btn.setAttribute('data-agnes-mode-active', '');
                        document.getElementById('agnesModeInput').value = mode;
                        var showSingle = (mode === 'ti2vid');
                        var showMulti = (mode === 'multi' || mode === 'keyframes');
                        var elSingle = document.querySelector('[data-agnes-image-single]');
                        var elMulti = document.querySelector('[data-agnes-image-multi]');
                        var prompt = document.getElementById('prompt');
                        if (elSingle) elSingle.style.display = showSingle ? '' : 'none';
                        if (elMulti) elMulti.style.display = showMulti ? '' : 'none';
                        if (mode === 'text2vid' && prompt && prompt.placeholder === '上传参考图片即可，提示词可选') {
                            prompt.placeholder = '描述你想生成的视频内容...';
                        }
                    }
                    // 单图上传预览
                    (function() {
                        var up = document.getElementById('agnesSingleUpload');
                        var inp = document.getElementById('agnesSingleInput');
                        if (up && inp) {
                            up.addEventListener('click', function() { inp.click(); });
                            inp.addEventListener('change', function() {
                                var file = inp.files[0];
                                if (!file) return;
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    up.querySelector('.agnes-upload-preview').src = e.target.result;
                                    up.querySelector('.agnes-upload-preview').classList.remove('hidden');
                                    up.querySelector('.agnes-upload-placeholder').classList.add('hidden');
                                    var prompt = document.getElementById('prompt');
                                    if (prompt) prompt.placeholder = '上传参考图片即可，提示词可选（描述运动方式）';
                                };
                                reader.readAsDataURL(file);
                            });
                        }
                    })();
                    // 多图逻辑
                    (function() {
                        var inp = document.getElementById('agnesMultiInput');
                        var list = document.getElementById('agnesMultiList');
                        if (!inp || !list) return;
                        var addBtn = list.querySelector('.agnes-multi-add');
                        inp.addEventListener('change', function() {
                            Array.from(inp.files).forEach(function(file, i) {
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    agnesImageOrder.push({name: file.name, dataUrl: e.target.result, index: agnesImageOrder.length});
                                    renderMultiList();
                                    updateImageOrder();
                                };
                                reader.readAsDataURL(file);
                            });
                            inp.value = '';
                        });
                        function renderMultiList() {
                            var children = list.querySelectorAll('.agnes-multi-item');
                            children.forEach(function(c) { c.remove(); });
                            agnesImageOrder.forEach(function(item, i) {
                                var div = document.createElement('div');
                                div.className = 'agnes-multi-item';
                                div.style.cssText = 'width:100px;height:100px;position:relative;border-radius:10px;overflow:hidden;border:2px solid var(--line);';
                                div.innerHTML = '<img src="' + item.dataUrl + '" style="width:100%;height:100%;object-fit:cover;">' +
                                    '<div style="position:absolute;top:4px;right:4px;display:flex;flex-direction:column;gap:2px;">' +
                                    '<button type="button" onclick="moveAgnesImage(' + i + ',-1)" style="width:20px;height:20px;border:none;background:rgba(0,0,0,0.5);color:#fff;border-radius:4px;font-size:10px;cursor:pointer;line-height:1;">▲</button>' +
                                    '<button type="button" onclick="moveAgnesImage(' + i + ',1)" style="width:20px;height:20px;border:none;background:rgba(0,0,0,0.5);color:#fff;border-radius:4px;font-size:10px;cursor:pointer;line-height:1;">▼</button>' +
                                    '<button type="button" onclick="removeAgnesImage(' + i + ')" style="width:20px;height:20px;border:none;background:rgba(200,0,0,0.7);color:#fff;border-radius:4px;font-size:10px;cursor:pointer;line-height:1;">✕</button>' +
                                    '</div>';
                                list.insertBefore(div, addBtn);
                            });
                            var prompt = document.getElementById('prompt');
                            if (prompt && agnesImageOrder.length > 0) {
                                prompt.placeholder = '描述图片之间的过渡方式（可选）';
                            }
                        }
                        window.moveAgnesImage = function(idx, dir) {
                            var newIdx = idx + dir;
                            if (newIdx < 0 || newIdx >= agnesImageOrder.length) return;
                            var tmp = agnesImageOrder[idx];
                            agnesImageOrder[idx] = agnesImageOrder[newIdx];
                            agnesImageOrder[newIdx] = tmp;
                            renderMultiList();
                            updateImageOrder();
                        };
                        window.removeAgnesImage = function(idx) {
                            agnesImageOrder.splice(idx, 1);
                            renderMultiList();
                            updateImageOrder();
                        };
                        function updateImageOrder() {
                            document.getElementById('agnesImageOrder').value = agnesImageOrder.map(function(item, i) { return i; }).join(',');
                        }
                    })();
                    </script>
                    <script>
                    // ── Agnes 时长限制与智能提示 ──
                    (function() {
                        var resMaxSec = { '480p': 40, '720p': 17, '1080p': 7 };
                        var adminMax = <?= $maxSec ?>;
                        var input = document.getElementById("agnesDurationInput");
                        var hint = document.getElementById("agnesDurationHint");
                        var isCustom = <?= $isCustomDur ? 'true' : 'false' ?>;
                        if (!input || !isCustom) return;

                        var lastHintText = '';

                        var showHint = function(text) {
                            if (!hint || text === lastHintText) return;
                            lastHintText = text;
                            hint.textContent = text;
                            hint.style.display = '';
                            clearTimeout(hint._timer);
                            hint._timer = setTimeout(function() { hint.style.display = 'none'; lastHintText = ''; }, 4000);
                        };

                        var getActiveRes = function() {
                            var chip = document.querySelector('[data-agnes-res-active]');
                            return chip ? chip.getAttribute('data-agnes-res') : '720p';
                        };

                        var clampDuration = function() {
                            var res = getActiveRes();
                            var resMax = resMaxSec[res] || 40;
                            var effectiveMax = Math.min(adminMax, resMax);
                            var val = parseInt(input.value, 10) || 5;

                            // 更新输入框 max 属性
                            input.max = effectiveMax;
                            var rangeEl = input.parentElement.querySelector('small');
                            if (rangeEl) rangeEl.textContent = '(1~' + effectiveMax + ')';

                            if (val > effectiveMax) {
                                input.value = effectiveMax;
                                showHint(res + ' 最多支持 ' + resMax + ' 秒' + (adminMax < resMax ? '（后台限制 ' + adminMax + ' 秒）' : '') + '，已自动调整为 ' + effectiveMax + ' 秒');
                            }
                        };

                        // 分辨率切换时检查
                        document.querySelectorAll('[data-agnes-res]').forEach(function(chip) {
                            chip.addEventListener('click', function() { setTimeout(clampDuration, 50); });
                        });

                        // 输入时检查
                        input.addEventListener('change', clampDuration);
                        input.addEventListener('blur', clampDuration);

                        // 初始执行
                        clampDuration();
                    })();
                    </script>
                    <!-- Agnes 高级参数 -->
                    <?php if (!empty($advCfg['frame_rate']) || !empty($advCfg['inference_steps']) || !empty($advCfg['seed']) || !empty($advCfg['negative_prompt'])): ?>
                    <div class="field-v3 agnes-control agnes-advanced-control" data-agnes-advanced-selector<?= $firstIsAgnes ? '' : ' style="display:none;"' ?>>
                        <label>高级参数 <span class="hint">（可选）</span></label>
                        <div class="field-grid" style="grid-template-columns:1fr 1fr;gap:10px;">
                            <?php if (!empty($advCfg['frame_rate'])): ?>
                            <label class="field">
                                <span>视频FPS <small class="hint">(1~60)</small></span>
                                <input type="number" name="agnes_fps" value="24" min="1" max="60" step="1" placeholder="24">
                            </label>
                            <?php endif; ?>
                            <?php if (!empty($advCfg['inference_steps'])): ?>
                            <label class="field">
                                <span>推理步数</span>
                                <input type="number" name="agnes_inference_steps" value="" min="1" placeholder="留空=自动">
                            </label>
                            <?php endif; ?>
                            <?php if (!empty($advCfg['seed'])): ?>
                            <label class="field">
                                <span>随机种子 <small class="hint">（固定值可复现结果）</small></span>
                                <input type="number" name="agnes_seed" value="" min="0" placeholder="留空=随机">
                            </label>
                            <?php endif; ?>
                            <?php if (!empty($advCfg['negative_prompt'])): ?>
                            <label class="field" style="grid-column:1/-1;">
                                <span>负向提示词 <small class="hint">（描述不想出现的内容）</small></span>
                                <input type="text" name="agnes_negative_prompt" placeholder="例如：blurry, low quality, distorted" style="width:100%;">
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="cost-hint" data-cost-display data-default-cost="<?= $videoCost ?>">
                        当前消耗：<strong data-cost-value><?= $videoCost ?></strong> <?= e($balanceLabel) ?>/次
                    </div>
                    <?php if ($firstIsAgnes): ?>
                    <script>
                    // 确保 Agnes 消耗在页面加载和切换时实时显示
                    (function(){
                        var cv = document.querySelector("[data-cost-value]");
                        var ch = document.querySelector("[data-cost-display]");
                        if (!cv || !ch) return;
                        var cfg = <?= json_encode($firstAgnesCfg, JSON_UNESCAPED_UNICODE) ?>;
                        var durCfg = cfg._duration || {mode:'custom',max_seconds:15,price_per_second:1};
                        var sel = document.getElementById("ai_model_id");
                    var durInput = document.querySelector("#agnesDurationInput");
                        var fixedInput = document.querySelector("[data-agnes-duration-fixed-input]");
                        var compute = function(){
                            var res = "480p";
                            var ar = document.querySelector("[data-agnes-res-active]");
                            if (ar) res = ar.getAttribute("data-agnes-res");
                            var rc = (cfg[res] && cfg[res].credits) ? cfg[res].credits : 5;
                            var dc = 0;
                            if (durCfg.mode === "custom") {
                                var s = parseInt((durInput && durInput.value)||5,10)||5;
                                dc = s * (durCfg.price_per_second||1);
                            } else {
                                var ad = document.querySelector("[data-agnes-duration-active]");
                                if (ad) dc = parseInt(ad.getAttribute("data-agnes-dur-credits")||0,10)||0;
                                else { var t = durCfg.tiers||[]; dc = t.length ? (t[0].credits||0) : 0; }
                            }
                            var cost = Math.max(1, rc + dc);
                            cv.textContent = String(cost);
                            ch.dataset.defaultCost = String(cost);
                        };
                        // 初始计算
                        compute();
                        // 绑定事件
                        document.querySelectorAll("[data-agnes-res]").forEach(function(c){ c.addEventListener("click", function(){ setTimeout(compute,100); }); });
                        if (durInput) durInput.addEventListener("input", compute);
                        if (fixedInput) fixedInput.addEventListener("input", compute);
                        document.querySelectorAll("[data-agnes-dur]").forEach(function(c){ c.addEventListener("click", function(){ setTimeout(compute,100); }); });
                        if (sel) sel.addEventListener("change", function(){ setTimeout(compute,100); });
                    })();
                    </script>
                    <?php endif; ?>
                    <?php 
                    $antiWmEnabledV = app_setting('anti_watermark_enabled', 'off') === 'on';
                    $watermarkEnabledV = app_setting('watermark_enabled', 'off') === 'on';
                    $userWpV = (int) ($user['watermark_points'] ?? 0);
                    ?>
                    <script>window._wpBalance = <?= $userWpV ?>;</script>
                    <?php if ($antiWmEnabledV && $watermarkEnabledV): ?>
                    <div class="field-v3" id="antiWatermarkField" data-anti-watermark-toggle>
                        <label class="toggle-row">
                            <input type="checkbox" name="anti_watermark" value="1" id="antiWatermarkCheck" data-anti-watermark-check>
                            <span data-wp-toggle-label>关闭去水印：节省 <strong data-wp-cost-display>0</strong> 水印点</span>
                        </label>
                        <small class="hint" data-wp-balance>当前水印点：<?= $userWpV ?></small>
                    </div>
                    <?php endif; ?>

                    <!-- ═══ Grok 控件（视频模型为 Grok 时显示） ═══ -->
                    <?php
                    $firstIsGrok = !empty($videoModels) && ($videoModels[0]['site_type'] ?? 'standard') === 'grok';
                    $firstGrokCfg = [];
                    if ($firstIsGrok && !empty($videoModels[0]['grok_config'])) {
                        $firstGrokCfg = json_decode($videoModels[0]['grok_config'], true) ?: [];
                    }
                    $grokDurCfg = $firstGrokCfg['_duration'] ?? ['max_seconds' => 15, 'price_per_second' => 2];
                    $grokMaxSec = max(1, (int) ($grokDurCfg['max_seconds'] ?? 15));
                    $grokPps = max(1, (int) ($grokDurCfg['price_per_second'] ?? 2));
                    ?>
                    <!-- Grok 分辨率 -->
                    <div class="field-v3 grok-control"<?= $firstIsGrok ? '' : ' style="display:none;"' ?>>
                        <label>分辨率 <span class="hint">（Grok）</span></label>
                        <div class="agnes-res-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="grokResChips">
                            <?php foreach (['480p', '720p'] as $res):
                                $grCfg = $firstGrokCfg[$res] ?? ['enabled' => true, 'credits' => 5];
                                $isActive = ($res === '480p' && $grCfg['enabled']);
                            ?>
                            <button type="button"
                                class="chip agnes-res-chip grok-res-chip"
                                data-agnes-res="<?= $res ?>"
                                data-grok-res="<?= $res ?>"
                                <?= $isActive ? 'data-agnes-res-active="1"' : '' ?>
                                <?= !$grCfg['enabled'] ? 'style="opacity:0.35;pointer-events:none;"' : '' ?>
                                onclick="switchGrokRes('<?= $res ?>')"
                            ><?= $res ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="grok_resolution" value="480p">
                    </div>
                    <!-- Grok 宽高比 -->
                    <div class="field-v3 grok-control"<?= $firstIsGrok ? '' : ' style="display:none;"' ?>>
                        <label>宽高比 <span class="hint">（Grok）</span></label>
                        <div class="agnes-ar-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="grokArChips">
                            <?php foreach (['16:9', '9:16', '1:1', '4:3', '3:4', '3:2', '2:3'] as $ar):
                                $arActive = ($ar === '16:9');
                            ?>
                            <button type="button"
                                class="chip agnes-ar-chip grok-ar-chip"
                                data-agnes-ar="<?= $ar ?>"
                                data-grok-ar="<?= $ar ?>"
                                <?= $arActive ? 'data-agnes-ar-active="1"' : '' ?>
                                onclick="switchGrokAr('<?= $ar ?>')"
                            ><?= $ar ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="grok_aspect_ratio" value="16:9">
                    </div>
                    <!-- Grok 时长 -->
                    <div class="field-v3 grok-control"<?= $firstIsGrok ? '' : ' style="display:none;"' ?>>
                        <label>视频时长 <span class="hint">（秒）</span></label>
                        <input type="number" name="grok_duration" value="5" min="1" max="<?= $grokMaxSec ?>"
                            oninput="var v=parseInt(this.value,10)||5;var m=parseInt(this.max,10)||15;if(v>m)this.value=m;if(v<1)this.value=1;updateGrokCost();"
                            style="width:100px;text-align:center;" class="input-v3">
                        <small class="hint" style="margin-left:8px;">文生/单图最长15s，多图最长10s · 每秒 <b id="grokPPSDisplay"><?= $grokPps ?></b> 积分，当前消耗 <b id="grokLiveCost"><?= max(1, (int)(($firstGrokCfg['480p']['credits'] ?? 5) + 5 * $grokPps)) ?></b></small>
                    </div>
                    <!-- Grok 图片上传 -->
                    <div class="field-v3 grok-control" data-grok-image-selector<?= $firstIsGrok ? '' : ' style="display:none;"' ?>>
                        <label>参考图片 <span class="hint">（Grok 图生视频，可选）</span></label>
                        <div class="agnes-upload-area" id="grokImageUpload" style="border:2px dashed var(--line);border-radius:12px;padding:24px;text-align:center;cursor:pointer;">
                            <div class="grok-upload-placeholder">📷 点击上传图片（可选，最多 7 张）<br><small style="color:var(--text-muted);">grok-image-video 支持文生/多图(≤7张,最长10s)，grok-video-1.5 仅单图</small></div>
                            <div class="grok-upload-preview" style="max-width:100%;max-height:200px;border-radius:8px;overflow:hidden;display:none;flex-wrap:wrap;gap:6px;margin-top:8px;"></div>
                            <input type="file" name="grok_images[]" accept="image/png,image/jpeg,image/webp" multiple style="display:none;" id="grokImageInput">
                        </div>
                    </div>
                    <script>
                    // ── Grok 控件交互（全局函数，不依赖 video.js 加载顺序） ──
                    window._grokPPS = <?= $grokPps ?>;
                    window._grokResCredits = {
                        <?php foreach (['480p','720p'] as $r): $rc = $firstGrokCfg[$r] ?? ['credits' => 5]; ?>
                        '<?= $r ?>': <?= max(1, (int)$rc['credits']) ?>,
                        <?php endforeach; ?>
                    };
                    var _grokCalc = function() {
                        var res = document.querySelector('[name="grok_resolution"]').value || '480p';
                        var secs = parseInt(document.querySelector('[name="grok_duration"]').value, 10) || 5;
                        var rc = window._grokResCredits[res] || 5;
                        var pps = window._grokPPS || 2;
                        var total = rc + (secs * pps);
                        var el = document.getElementById('grokLiveCost');
                        if (el) el.textContent = total;
                        // 同步更新全局消耗显示
                        var costVal = document.querySelector('[data-cost-value]');
                        if (costVal) costVal.textContent = total;
                        var costHint = document.querySelector('[data-cost-display]');
                        if (costHint) costHint.setAttribute('data-default-cost', total);
                        return total;
                    };
                    window.switchGrokRes = function(res) {
                        document.querySelectorAll('#grokResChips .grok-res-chip').forEach(function(b) {
                            b.removeAttribute('data-agnes-res-active');
                        });
                        var btn = document.querySelector('#grokResChips .grok-res-chip[data-grok-res="' + res + '"]');
                        if (btn) btn.setAttribute('data-agnes-res-active', '1');
                        document.querySelector('[name="grok_resolution"]').value = res;
                        _grokCalc();
                    };
                    window.switchGrokAr = function(ar) {
                        document.querySelectorAll('#grokArChips .grok-ar-chip').forEach(function(b) {
                            b.removeAttribute('data-agnes-ar-active');
                        });
                        var btn = document.querySelector('#grokArChips .grok-ar-chip[data-grok-ar="' + ar + '"]');
                        if (btn) btn.setAttribute('data-agnes-ar-active', '1');
                        document.querySelector('[name="grok_aspect_ratio"]').value = ar;
                    };
                    window.updateGrokCost = function() { _grokCalc(); };
                    // 初始化消耗
                    _grokCalc();
                    </script>
                    <script>
                    (function() {
                        var upload = document.getElementById("grokImageUpload");
                        var input = document.getElementById("grokImageInput");
                        var preview = document.querySelector(".grok-upload-preview");
                        var placeholder = document.querySelector(".grok-upload-placeholder");
                        var allFiles = new DataTransfer();  // 累积所有文件

                        function rebuildPreview() {
                            preview.innerHTML = "";
                            var files = allFiles.files;
                            if (files && files.length) {
                                placeholder.style.display = "none";
                                preview.style.display = "flex";
                                var maxShow = Math.min(files.length, 9);
                                for (var i = 0; i < maxShow; i++) {
                                    (function(file) {
                                        var r = new FileReader();
                                        r.onload = function(ev) {
                                            var img = document.createElement("img");
                                            img.src = ev.target.result;
                                            img.style.cssText = "width:80px;height:80px;object-fit:cover;border-radius:6px;flex-shrink:0;";
                                            preview.appendChild(img);
                                        };
                                        r.readAsDataURL(file);
                                    })(files[i]);
                                }
                                if (files.length > maxShow) {
                                    var more = document.createElement("span");
                                    more.textContent = "+" + (files.length - maxShow);
                                    more.style.cssText = "display:flex;align-items:center;justify-content:center;width:80px;height:80px;background:var(--main-surface);border-radius:6px;font-size:14px;color:var(--text-muted);flex-shrink:0;";
                                    preview.appendChild(more);
                                }
                                // 继续上传按钮（未达上限时显示）
                                if (files.length < 7) {
                                    var addBox = document.createElement("div");
                                    addBox.className = "grok-add-more";
                                    addBox.style.cssText = "width:80px;height:80px;display:flex;flex-direction:column;align-items:center;justify-content:center;border:2px dashed var(--line);border-radius:6px;cursor:pointer;flex-shrink:0;transition:border-color .2s;";
                                    addBox.innerHTML = '<span style="font-size:22px;line-height:1;color:var(--text-muted);">+</span><span style="font-size:10px;color:var(--text-muted);margin-top:2px;">继续上传</span>';
                                    addBox.onmouseenter = function() { this.style.borderColor = "var(--main)"; };
                                    addBox.onmouseleave = function() { this.style.borderColor = "var(--line)"; };
                                    addBox.addEventListener("click", function(e) {
                                        e.stopPropagation();
                                        if (allFiles.files.length >= 7) { alert("最多 7 张"); return; }
                                        input.click();
                                    });
                                    preview.appendChild(addBox);
                                }
                                // 添加清除按钮
                                var clearBtn = document.getElementById("grokClearBtn");
                                if (!clearBtn) {
                                    clearBtn = document.createElement("button");
                                    clearBtn.id = "grokClearBtn";
                                    clearBtn.type = "button";
                                    clearBtn.textContent = "✕ 清除全部";
                                    clearBtn.style.cssText = "width:100%;margin-top:8px;padding:6px;border:1px solid var(--line);border-radius:8px;background:var(--main-surface);color:var(--text-muted);cursor:pointer;font-size:12px;";
                                    clearBtn.onclick = function() {
                                        allFiles = new DataTransfer();
                                        input.value = "";
                                        rebuildPreview();
                                    };
                                    preview.parentNode.appendChild(clearBtn);
                                }
                                clearBtn.style.display = "";
                            } else {
                                preview.style.display = "none";
                                placeholder.style.display = "";
                                var cb = document.getElementById("grokClearBtn");
                                if (cb) cb.style.display = "none";
                            }
                        }

                        if (upload && input) {
                            upload.addEventListener("click", function() {
                                // 最多 7 张限制
                                if (allFiles.files.length >= 7) {
                                    alert("grok-image-video 多参考图最多支持 7 张。如需更换，请先点击 ✕ 清除全部。");
                                    return;
                                }
                                input.click();
                            });
                            input.addEventListener("change", function() {
                                var newFiles = input.files;
                                if (newFiles) {
                                    var current = allFiles.files.length;
                                    var space = 7 - current;
                                    if (newFiles.length > space) {
                                        alert("多参考图最多 7 张，当前已有 " + current + " 张，还能添加 " + space + " 张。本次跳过超出部分。");
                                    }
                                    var addCount = Math.min(newFiles.length, space);
                                    for (var i = 0; i < addCount; i++) {
                                        allFiles.items.add(newFiles[i]);
                                    }
                                    input.files = allFiles.files;
                                    rebuildPreview();
                                    // 多图时提醒时长限制
                                    if (allFiles.files.length >= 2) {
                                        var durInput = document.querySelector('[name="grok_duration"]');
                                        if (durInput && parseInt(durInput.value) > 10) {
                                            durInput.value = 10;
                                            updateGrokCost();
                                        }
                                    }
                                }
                            });
                        }

                        // 提交前把累积文件塞回 input
                        var form = document.getElementById("videoGenerateForm");
                        if (form) {
                            form.addEventListener("formdata", function(e) {
                                e.formData.delete("grok_images[]");
                                for (var i = 0; i < allFiles.files.length; i++) {
                                    e.formData.append("grok_images[]", allFiles.files[i]);
                                }
                            });
                        }
                    })();
                    </script>

                    <!-- ═══ Seedance 控件（视频模型为 Seedance 时显示） ═══ -->
                    <?php
                    $firstIsSeedance = !empty($videoModels) && ($videoModels[0]['site_type'] ?? 'standard') === 'seedance';
                    $firstSdCfg = [];
                    if ($firstIsSeedance && !empty($videoModels[0]['seedance_config'])) {
                        $firstSdCfg = json_decode($videoModels[0]['seedance_config'], true) ?: [];
                    }
                    $sdDurCfg = $firstSdCfg['_duration'] ?? ['mode' => 'fixed', 'tiers' => [['seconds' => 5, 'credits' => 15]], 'max_seconds' => 15, 'price_per_second' => 2];
                    $sdIsFixed = ($sdDurCfg['mode'] ?? 'fixed') === 'fixed';
                    $sdMaxSec = max(1, (int) ($sdDurCfg['max_seconds'] ?? 15));
                    $sdPps = max(1, (int) ($sdDurCfg['price_per_second'] ?? 2));
                    ?>
                    <!-- Seedance 分辨率 -->
                    <div class="field-v3 seedance-control"<?= $firstIsSeedance ? '' : ' style="display:none;"' ?>>
                        <label>分辨率 <span class="hint">（Seedance）</span></label>
                        <div class="agnes-res-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="seedanceResChips">
                            <?php foreach (['480p', '720p', '1080p'] as $res):
                                $srCfg = $firstSdCfg[$res] ?? ['enabled' => in_array($res, ['480p', '720p']), 'credits' => ['480p'=>5, '720p'=>10, '1080p'=>15][$res]];
                                $sdActive = ($res === '720p' && $srCfg['enabled']) || ($res === '480p' && $srCfg['enabled'] && empty($firstSdCfg['720p']['enabled']));
                            ?>
                            <button type="button"
                                class="chip agnes-res-chip seedance-res-chip"
                                data-agnes-res="<?= $res ?>"
                                data-seedance-res="<?= $res ?>"
                                <?= $sdActive ? 'data-agnes-res-active="1"' : '' ?>
                                <?= !$srCfg['enabled'] ? 'style="opacity:0.35;pointer-events:none;"' : '' ?>
                                onclick="switchSeedanceRes('<?= $res ?>')"
                            ><?= $res ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="seedance_resolution" value="<?= $sdActive ? $res : '720p' ?>">
                    </div>
                    <!-- Seedance 时长 -->
                    <div class="field-v3 seedance-control"<?= $firstIsSeedance ? '' : ' style="display:none;"' ?>>
                        <label>视频时长 <span class="hint">（Seedance）</span></label>
                        <?php if ($sdIsFixed): ?>
                        <div class="seedance-duration-fixed-front">
                            <div class="seedance-duration-chips" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px;" id="seedanceDurChips">
                                <?php foreach ($sdDurCfg['tiers'] ?? [] as $i => $tier): ?>
                                <button type="button" class="chip seedance-dur-chip" data-seedance-dur="<?= (int)$tier['seconds'] ?>" data-seedance-dur-credits="<?= (int)$tier['credits'] ?>" <?= $i === 0 ? 'data-seedance-duration-active' : '' ?>
                                    onclick="switchSeedanceDuration(this)"
                                ><?= (int)$tier['seconds'] ?>s</button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="seedance_duration" value="<?= (int)($sdDurCfg['tiers'][0]['seconds'] ?? 5) ?>">
                            <small class="hint" style="margin-top:4px;">总消耗 = 分辨率积分 + 时长档位积分</small>
                        </div>
                        <?php else: ?>
                        <div class="seedance-duration-custom-front">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="seedance_duration" id="seedanceDurationInput" min="1" max="<?= $sdMaxSec ?>" value="5" style="width:80px;text-align:center;font-size:16px;font-weight:700;" onchange="updateSeedanceCost()">
                                <span style="font-size:14px;color:var(--text-muted);">秒 <small>(1~<?= $sdMaxSec ?>，<?= $sdPps ?> 积分/秒)</small></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Seedance 图片上传 -->
                    <div class="field-v3 seedance-control" data-seedance-image-selector<?= $firstIsSeedance ? '' : ' style="display:none;"' ?>>
                        <label>参考图片 <span class="hint">（Seedance 图生视频，可选）</span></label>
                        <div class="agnes-upload-area" id="seedanceImageUpload" style="border:2px dashed var(--line);border-radius:12px;padding:24px;text-align:center;cursor:pointer;">
                            <div class="seedance-upload-placeholder">📷 点击上传图片（可选）<br><small style="color:var(--text-muted);">支持图生视频，上传 1 张参考图</small></div>
                            <img class="seedance-upload-preview hidden" style="max-width:100%;max-height:200px;border-radius:8px;">
                            <input type="file" name="seedance_image" accept="image/png,image/jpeg,image/webp" style="display:none;" id="seedanceImageInput">
                        </div>
                    </div>
                    <script>
                    // ── Seedance 控件交互 ──
                    window._seedanceResCredits = {
                        <?php foreach (['480p','720p','1080p'] as $r): $src = $firstSdCfg[$r] ?? ['credits' => ['480p'=>5,'720p'=>10,'1080p'=>15][$r]]; ?>
                        '<?= $r ?>': <?= max(1, (int)$src['credits']) ?>,
                        <?php endforeach; ?>
                    };
                    window._seedanceDurMode = '<?= $sdIsFixed ? 'fixed' : 'custom' ?>';
                    window._seedanceDurPPS = <?= $sdPps ?>;
                    window._seedanceDurTiers = <?= json_encode($sdDurCfg['tiers'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

                    var _sdCalc = function() {
                        var res = document.querySelector('[name="seedance_resolution"]').value || '720p';
                        var rc = window._seedanceResCredits[res] || 5;
                        var dc = 0;
                        if (window._seedanceDurMode === 'fixed') {
                            var activeChip = document.querySelector('[data-seedance-duration-active]');
                            if (activeChip) dc = parseInt(activeChip.getAttribute('data-seedance-dur-credits') || 0, 10);
                            else dc = 15;
                        } else {
                            var secs = parseInt(document.querySelector('[name="seedance_duration"]').value, 10) || 5;
                            dc = secs * (window._seedanceDurPPS || 2);
                        }
                        var total = rc + dc;
                        var costVal = document.querySelector('[data-cost-value]');
                        if (costVal) costVal.textContent = total;
                        var costHint = document.querySelector('[data-cost-display]');
                        if (costHint) costHint.setAttribute('data-default-cost', total);
                        return total;
                    };
                    window.switchSeedanceRes = function(res) {
                        document.querySelectorAll('#seedanceResChips .seedance-res-chip').forEach(function(b) {
                            b.removeAttribute('data-agnes-res-active');
                        });
                        var btn = document.querySelector('#seedanceResChips .seedance-res-chip[data-seedance-res="' + res + '"]');
                        if (btn) btn.setAttribute('data-agnes-res-active', '1');
                        document.querySelector('[name="seedance_resolution"]').value = res;
                        _sdCalc();
                    };
                    window.switchSeedanceDuration = function(chip) {
                        document.querySelectorAll('[data-seedance-duration-active]').forEach(function(c) {
                            c.removeAttribute('data-seedance-duration-active');
                        });
                        chip.setAttribute('data-seedance-duration-active', '');
                        document.querySelector('[name="seedance_duration"]').value = chip.getAttribute('data-seedance-dur');
                        _sdCalc();
                    };
                    window.updateSeedanceCost = function() { _sdCalc(); };
                    _sdCalc();
                    </script>
                    <script>
                    // Seedance 图片上传
                    (function() {
                        var upload = document.getElementById("seedanceImageUpload");
                        var input = document.getElementById("seedanceImageInput");
                        if (upload && input) {
                            upload.addEventListener("click", function() { input.click(); });
                            input.addEventListener("change", function() {
                                var file = input.files[0];
                                if (!file) return;
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    var preview = upload.querySelector('.seedance-upload-preview');
                                    preview.src = e.target.result;
                                    preview.classList.remove('hidden');
                                    upload.querySelector('.seedance-upload-placeholder').classList.add('hidden');
                                };
                                reader.readAsDataURL(file);
                            });
                        }
                    })();
                    </script>
                    <button id="generateButton" class="btn btn-primary btn-lg" type="submit" style="width:100%;">生成视频</button>
                </form>
                <div id="generateMessage" class="inline-message hidden"></div>
            </div>
        </section>

        <!-- Right Column: My Videos -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>我的视频</h3>
                    <p class="sub">My Videos</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/records">查看全部</a>
            </div>
            <div class="card-v3-body">
                <div id="videoHistoryList" class="grid-auto" data-gallery>
                        <?php if (!$records): ?>
                            <div class="history-empty-inline">暂无视频记录，开始你的第一次视频创作吧</div>
                        <?php endif; ?>
                        <?php foreach ($records as $record): ?>
                            <?php $videoSrc = generation_record_video_src($record); ?>
                            <?php $isVideo = true; ?>
                            <article
                                class="media-card"
                                tabindex="0"
                                data-record-id="<?= (int) $record['id'] ?>"
                                data-status="<?= e($record['status']) ?>"
                                data-mode="video"
                                data-model="<?= e($record['model'] ?? '') ?>"
                                data-prompt="<?= e($record['prompt']) ?>"
                                data-size="<?= e($record['size']) ?>"
                                data-quality=""
                                data-format="<?= e($record['output_format']) ?>"
                                data-credits="<?= (int) $record['credits_charged'] ?>"
                                data-created="<?= e($record['created_at']) ?>"
                                data-finished="<?= e($record['finished_at'] ?: '-') ?>"
                                data-error="<?= e($record['error_message'] ?: '') ?>"
                                data-input-count="0"
                                data-video-src="<?= e($videoSrc ?: '') ?>"
                            >
                                <?php if ($videoSrc): ?>
                                    <video src="<?= e($videoSrc) ?>" controls></video>
                                <?php else: ?>
                                    <div style="display:flex;align-items:center;justify-content:center;aspect-ratio:1;background:var(--main-surface-soft);color:var(--text-muted);font-size:13px;">
                                        <span><?= e(generation_status_label((string) $record['status'])) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="media-card-body">
                                    <p class="prompt"><?= e($record['prompt']) ?></p>
                                    <div class="meta">
                                        <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                        <span>视频生成 / <?= e($record['size']) ?> / <?= e($record['output_format'] ?: 'mp4') ?></span>
                                    </div>
                                    <div class="record-foot" style="margin-top:6px;display:flex;align-items:center;justify-content:space-between;">
                                        <time style="font-size:11px;color:var(--text-muted);"><?= e($record['created_at']) ?></time>
                                        <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                            <input type="hidden" name="redirect_to" value="/user/video">
                                            <button type="submit" class="record-delete"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5.3 4V2.7c0-.4.3-.7.7-.7h4c.4 0 .7.3.7.7V4M6.7 7.3v4M9.3 7.3v4M3.3 4l.8 8.6c.1.7.7 1.4 1.5 1.4h4.7c.7 0 1.4-.6 1.5-1.4l.8-8.6"/></svg>删除</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pages" aria-label="视频分页" style="margin-top:16px;">
                            <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? ('/user/video?page=' . ($page - 1)) : '#' ?>">上一页</a>
                            <span style="display:flex;align-items:center;padding:0 12px;font-size:13px;color:var(--text-muted);">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                            <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? ('/user/video?page=' . ($page + 1)) : '#' ?>">下一页</a>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="/assets/user.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/user.js') ?: time())) ?>"></script>
<script src="/assets/video.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/video.js') ?: time())) ?>"></script>
<?php if ($isGuest): ?>
<div id="authModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeAuthModal()"><div class="modal-card" style="background:var(--main-surface);border-radius:16px;width:90vw;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.35);"><div style="padding:24px 24px 0;display:flex;justify-content:space-between;align-items:center;"><h3 style="margin:0;">登录 / 注册</h3><button onclick="closeAuthModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);">&times;</button></div><div style="padding:20px 24px 24px;"><p style="font-size:14px;color:var(--text-muted);margin-bottom:20px;">请登录后使用完整功能。</p><a href="/login" class="btn btn-primary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;margin-bottom:10px;">🔑 登录</a><a href="/login?register=1" class="btn btn-secondary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;">📝 注册</a></div></div></div>
<script>function showAuthModal(){document.getElementById("authModal").style.display="flex"}function closeAuthModal(){document.getElementById("authModal").style.display="none"}
document.addEventListener("click",function(e){var btn=e.target.closest("button, [role=button], a.btn, .media-card");if(!btn)return;if(btn.closest("#authModal")||btn.closest("nav")||btn.closest(".admin-nav-bar"))return;if(btn.closest("[data-close-dialog]"))return;e.preventDefault();e.stopPropagation();showAuthModal()},true)</script>
<?php endif; ?>
<?php render_footer(); ?>
