<?php

/**
 * 水印设置管理页面
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_login();
if ($user['role'] !== 'admin') {
    header('Location: /login');
    exit;
}

ensure_watermark_columns();

$saved = false;
$modelSaved = false;

// 处理基本水印设置保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    verify_csrf();

    $watermarkEnabled = !empty($_POST['watermark_enabled']) ? 'on' : 'off';
    $watermarkText = trim((string) ($_POST['watermark_text'] ?? ''));
    $antiWatermarkEnabled = !empty($_POST['anti_watermark_enabled']) ? 'on' : 'off';

    if ($watermarkText === '') {
        $watermarkText = '在图片右下角添加水印：本图片由【网站名称】生成';
    }

    set_app_setting('watermark_enabled', $watermarkEnabled);
    set_app_setting('watermark_text', $watermarkText);
    set_app_setting('anti_watermark_enabled', $antiWatermarkEnabled);

    $saved = true;
}

// 处理模型水印点消耗保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_model_costs') {
    verify_csrf();

    $costs = $_POST['wp_cost'] ?? [];
    if (is_array($costs)) {
        $stmt = db()->prepare('UPDATE ai_models SET watermark_point_cost = ? WHERE id = ?');
        foreach ($costs as $modelId => $cost) {
            $stmt->execute([max(0, (int)$cost), (int)$modelId]);
        }
    }
    $modelSaved = true;
}

// 读取当前配置
$watermarkEnabled = app_setting('watermark_enabled', 'on') === 'on';
$watermarkText = (string) app_setting('watermark_text', '在图片右下角添加水印：本图片由【网站名称】生成');
$antiWatermarkEnabled = app_setting('anti_watermark_enabled', 'off') === 'on';
$siteName = platform_name();

// 获取所有启用的模型及其水印点消耗
$models = db()->query(
    "SELECT id, name, model_id, model_type, watermark_point_cost
     FROM ai_models WHERE is_active = 1
     ORDER BY FIELD(model_type, 'image', 'video', 'chat'), sort_order ASC, id ASC"
)->fetchAll();

$imageCount = count(array_filter($models, fn($m) => ($m['model_type'] ?? 'image') === 'image'));
$videoCount = count(array_filter($models, fn($m) => ($m['model_type'] ?? '') === 'video'));
$chatCount  = count(array_filter($models, fn($m) => ($m['model_type'] ?? '') === 'chat'));
$modelTypeLabels = ['image' => '图片', 'video' => '视频', 'chat' => '对话'];

render_header('水印设置', 'admin');
render_admin_nav('watermark');
?>
<style>
.wm-card {
    background: var(--main-surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
}
.wm-card-hd { margin-bottom: 20px; }
.wm-card-hd h3 { margin: 0 0 4px; font-size: 17px; display: flex; align-items: center; gap: 8px; }
.wm-card-hd p { margin: 0; font-size: 13px; color: var(--text-muted); }

.wm-split { display: grid; grid-template-columns: 180px 1fr; gap: 24px; align-items: start; }
.wm-switch-block { display: flex; flex-direction: column; gap: 8px; }
.wm-switch-label { font-size: 13px; font-weight: 700; color: var(--text); }
.wm-switch-row { display: flex; align-items: center; gap: 12px; }
.wm-switch-status { font-size: 13px; font-weight: 600; min-width: 50px; transition: color .15s; }
.wm-switch-status.on { color: var(--success, #16a34a); }
.wm-switch-status.off { color: var(--text-muted); }

.wm-divider { border: none; border-top: 1px solid var(--line); margin: 24px 0; }

.wm-preview-box {
    margin-top: 12px;
    padding: 14px 18px;
    background: var(--main-surface-soft);
    border-radius: 12px;
    border: 1px dashed var(--line);
}
.wm-preview-label { font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 8px; }
.wm-preview-content { display: flex; align-items: flex-start; gap: 8px; flex-wrap: wrap; }
.wm-preview-prefix { font-size: 12px; color: var(--text-soft); white-space: nowrap; }
.wm-preview-plus { font-size: 12px; color: var(--text-muted); }
.wm-preview-value {
    font-size: 12px; color: var(--primary); font-weight: 700;
    background: var(--accent-glow); padding: 4px 10px; border-radius: 6px;
    word-break: break-all;
}
.wm-preview-hint { font-size: 11px; color: var(--text-muted); margin-top: 8px; display: block; }
.wm-preview-hint code { font-size: 11px; }

.wm-workflow { font-size: 13px; color: var(--text-soft); line-height: 1.8; padding: 12px 16px; background: var(--main-surface-soft); border-radius: 12px; }

/* 表格 */
.wm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wm-table th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; border-bottom: 2px solid var(--line); }
.wm-table td { padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.wm-table tbody tr:hover { background: rgba(0,0,0,.015); }
.wm-model-name { font-weight: 600; }
.wm-model-id { font-size: 11px; color: var(--text-muted); font-family: monospace; }
.wm-cost-input { width: 80px; text-align: center; padding: 6px 10px; border: 1.5px solid var(--line); border-radius: 8px; font-size: 15px; font-weight: 700; background: var(--main-surface); transition: border-color .15s; }
.wm-cost-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,.1); }
.wm-cost-hint { font-size: 11px; color: var(--text-muted); margin-left: 6px; }
.wm-free-badge { color: var(--success, #16a34a); font-size: 12px; font-weight: 600; }

.wm-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.wm-actions .btn-primary { padding: 9px 24px; font-size: 14px; }

@media (max-width: 768px) {
    .wm-split { grid-template-columns: 1fr; gap: 16px; }
    .wm-switch-row { justify-content: space-between; width: 100%; }
}
</style>

<div class="admin-content">

    <div class="page-notice-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <p class="eyebrow">Watermark</p>
            <h2 style="margin:0;">水印设置</h2>
        </div>
        <?php if ($saved || $modelSaved): ?>
            <div class="toast success" style="position:static;transform:none;margin:0;">
                <?= $saved ? '水印设置已保存' : '' ?>
                <?= $saved && $modelSaved ? ' · ' : '' ?>
                <?= $modelSaved ? '模型水印点已保存' : '' ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ====== 基础水印 + 去水印 ====== -->
    <form method="post" class="wm-card" id="watermarkMainForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <div class="wm-card-hd">
            <h3>🖼️ 基础水印</h3>
            <p>开启后自动在用户提示词末尾追加水印文字，AI 生成图片时将渲染这些文字。</p>
        </div>

        <div class="wm-split">
            <div class="wm-switch-block">
                <span class="wm-switch-label">全局水印开关</span>
                <div class="wm-switch-row">
                    <label class="switch">
                        <input type="checkbox" name="watermark_enabled" value="1"
                            <?= $watermarkEnabled ? 'checked' : '' ?>
                            onchange="var s=this.closest('.wm-split').querySelector('.wm-switch-status');
                                     s.textContent=this.checked?'已开启':'已关闭';
                                     s.className='wm-switch-status '+(this.checked?'on':'off')">
                        <span class="switch-knob"></span>
                    </label>
                    <span class="wm-switch-status <?= $watermarkEnabled ? 'on' : 'off' ?>"><?= $watermarkEnabled ? '已开启' : '已关闭' ?></span>
                </div>
            </div>

            <div>
                <div style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">水印文字内容</label>
                    <input type="text" name="watermark_text"
                           value="<?= e($watermarkText) ?>"
                           placeholder="在图片右下角添加水印：本图片由【网站名称】生成"
                           maxlength="200"
                           style="width:100%;padding:10px 14px;border:1.5px solid var(--line);border-radius:10px;font-size:14px;background:var(--main-surface);"
                           id="watermarkTextInput"
                           oninput="updateWatermarkPreview()">
                </div>

                <div class="wm-preview-box">
                    <span class="wm-preview-label">📝 水印效果预览</span>
                    <div class="wm-preview-content">
                        <span class="wm-preview-prefix">用户提示词</span>
                        <span class="wm-preview-plus">+</span>
                        <span class="wm-preview-value" id="watermarkPreview">
                            <?= e(str_replace('【网站名称】', $siteName, $watermarkText)) ?>
                        </span>
                    </div>
                    <span class="wm-preview-hint">
                        <code>【网站名称】</code> → 自动替换为「<?= e($siteName) ?>」&nbsp;|&nbsp;不含占位符则直接使用
                    </span>
                </div>
            </div>
        </div>

        <hr class="wm-divider">

        <div class="wm-card-hd">
            <h3>🎯 去水印 & 水印点系统</h3>
            <p>用户可使用「水印点」去除图片水印。每模型独立设置消耗，商城套餐可赠送。</p>
        </div>

        <div class="wm-split">
            <div class="wm-switch-block">
                <span class="wm-switch-label">水印点系统</span>
                <div class="wm-switch-row">
                    <label class="switch">
                        <input type="checkbox" name="anti_watermark_enabled" value="1"
                            <?= $antiWatermarkEnabled ? 'checked' : '' ?>
                            onchange="var s=this.closest('.wm-split').querySelector('.wm-switch-status');
                                     s.textContent=this.checked?'已开启':'已关闭';
                                     s.className='wm-switch-status '+(this.checked?'on':'off')">
                        <span class="switch-knob"></span>
                    </label>
                    <span class="wm-switch-status <?= $antiWatermarkEnabled ? 'on' : 'off' ?>"><?= $antiWatermarkEnabled ? '已开启' : '已关闭' ?></span>
                </div>
                <small class="hint" style="margin-top:4px;">前台生成页出现"去水印"按钮</small>
            </div>

            <div class="wm-workflow">
                <strong>操作流程</strong><br>
                ① 开启水印 + 水印点系统<br>
                ② 下方表格设置各模型去水印点数<br>
                ③ 套餐管理设置赠送水印点<br>
                ④ 用户购买 → 获得水印点 → 可选"去水印"
            </div>
        </div>

        <div style="text-align:right;margin-top:20px;">
            <button type="submit" class="btn btn-primary">💾 保存水印设置</button>
        </div>
    </form>

    <!-- ====== 模型水印点消耗 ====== -->
    <?php if ($models): ?>
    <form method="post" class="wm-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_model_costs">

        <div class="wm-card-hd">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <h3>📊 模型水印点配置</h3>
                    <p>
                        <b><?= count($models) ?></b> 个启用模型
                        <?php if ($imageCount): ?> · <?= $imageCount ?> 图片<?php endif; ?>
                        <?php if ($videoCount): ?> · <?= $videoCount ?> 视频<?php endif; ?>
                        <?php if ($chatCount): ?> · <?= $chatCount ?> 对话<?php endif; ?>
                        <span style="opacity:.5;">| 0 = 免水印点</span>
                    </p>
                </div>
                <button type="submit" class="btn btn-primary">💾 保存全部</button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="wm-table">
                <thead>
                    <tr>
                        <th style="width:30%;">模型名称</th>
                        <th style="width:18%;">模型 ID</th>
                        <th style="width:10%;">类型</th>
                        <th style="width:24%;">水印点消耗</th>
                        <th style="width:18%;">说明</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($models as $m): ?>
                    <?php $wpCost = (int) $m['watermark_point_cost']; ?>
                    <tr>
                        <td><span class="wm-model-name"><?= e($m['name']) ?></span></td>
                        <td><span class="wm-model-id"><?= e($m['model_id']) ?></span></td>
                        <td><span class="badge" style="font-size:10px;background:var(--main-surface-soft);"><?= e($modelTypeLabels[$m['model_type']] ?? $m['model_type']) ?></span></td>
                        <td>
                            <input type="number" name="wp_cost[<?= (int)$m['id'] ?>]"
                                   value="<?= $wpCost ?>"
                                   min="0" max="99999"
                                   class="wm-cost-input wp-cost-input"
                                   data-cost="<?= $wpCost ?>">
                            <span class="wm-cost-hint">点/次</span>
                        </td>
                        <td>
                            <?php if ($wpCost === 0): ?>
                                <span class="wm-free-badge">🆓 免水印点</span>
                            <?php else: ?>
                                <span class="wm-cost-hint" style="margin-left:0;">消耗 <?= $wpCost ?> 点</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="text-align:right;margin-top:16px;">
            <button type="submit" class="btn btn-primary">💾 保存模型水印点</button>
        </div>
    </form>
    <?php else: ?>
    <div class="wm-card" style="text-align:center;padding:40px;color:var(--text-muted);">
        <p style="font-size:48px;margin:0 0 12px;">📭</p>
        <p>暂无启用的 AI 模型，请先在 <a href="/admin/ai_models" style="color:var(--primary);">模型管理</a> 中添加。</p>
    </div>
    <?php endif; ?>
</div>

<script>
function updateWatermarkPreview() {
    var input = document.getElementById('watermarkTextInput');
    var preview = document.getElementById('watermarkPreview');
    if (!input || !preview) return;
    var text = input.value.trim() || '在图片右下角添加水印：本图片由【网站名称】生成';
    var siteName = <?= json_encode($siteName, JSON_UNESCAPED_UNICODE) ?>;
    text = text.replace(/【网站名称】/g, siteName);
    preview.textContent = text;
}

document.querySelectorAll('.wp-cost-input').forEach(function(input) {
    input.addEventListener('input', function() {
        var val = parseInt(this.value) || 0;
        var hint = this.parentElement.parentElement.querySelector('td:last-child span');
        if (hint) {
            if (val === 0) { hint.textContent = '🆓 免水印点'; hint.className = 'wm-free-badge'; }
            else { hint.textContent = '消耗 ' + val + ' 点'; hint.className = 'wm-cost-hint'; hint.style.color = 'var(--text-muted)'; }
        }
    });
});
</script>

<?php render_footer(); ?>
