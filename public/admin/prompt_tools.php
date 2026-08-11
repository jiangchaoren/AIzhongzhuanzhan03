<?php

/**
 * 提示词工具管理
 * 包含：提示词优化、AI 提示词审核、邀请功能
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

// ============================================================
// POST 保存
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // ── 提示词优化 ──
    $promptOptimizeEnabled = (string) (int) (!empty($_POST['prompt_optimize_enabled']));
    $promptOptimizeBaseUrl = rtrim(trim((string) ($_POST['prompt_optimize_base_url'] ?? '')), '/');
    $promptOptimizeApiKey = trim((string) ($_POST['prompt_optimize_api_key'] ?? ''));
    $promptOptimizeModel = trim((string) ($_POST['prompt_optimize_model'] ?? ''));
    $promptOptimizeSystemPrompt = trim((string) ($_POST['prompt_optimize_system_prompt'] ?? ''));
    set_app_setting('prompt_optimize_enabled', $promptOptimizeEnabled);
    if ($promptOptimizeBaseUrl !== '') set_app_setting('prompt_optimize_base_url', $promptOptimizeBaseUrl);
    if ($promptOptimizeApiKey !== '') set_app_setting('prompt_optimize_api_key', $promptOptimizeApiKey);
    if ($promptOptimizeModel !== '') set_app_setting('prompt_optimize_model', $promptOptimizeModel);
    if ($promptOptimizeSystemPrompt !== '') set_app_setting('prompt_optimize_system_prompt', $promptOptimizeSystemPrompt);

    // ── 提示词审核 ──
    $promptModerationEnabled = (string) (int) (!empty($_POST['prompt_moderation_enabled']));
    $promptModerationBaseUrl = rtrim(trim((string) ($_POST['prompt_moderation_base_url'] ?? '')), '/');
    $promptModerationApiKey = trim((string) ($_POST['prompt_moderation_api_key'] ?? ''));
    $promptModerationModel = trim((string) ($_POST['prompt_moderation_model'] ?? ''));
    set_app_setting('prompt_moderation_enabled', $promptModerationEnabled);
    if ($promptModerationBaseUrl !== '') set_app_setting('prompt_moderation_base_url', $promptModerationBaseUrl);
    if ($promptModerationApiKey !== '') set_app_setting('prompt_moderation_api_key', $promptModerationApiKey);
    if ($promptModerationModel !== '') set_app_setting('prompt_moderation_model', $promptModerationModel);

    // ── 邀请功能 ──
    $inviteEnabled = !empty($_POST['invite_enabled']) ? 'on' : 'off';
    set_app_setting('invite_enabled', $inviteEnabled);
    set_app_setting('invite_commission_percent', (string) max(0, min(100, (int) ($_POST['invite_commission_percent'] ?? '10'))));
    set_app_setting('invite_bonus_credits', (string) max(0, (int) ($_POST['invite_bonus_credits'] ?? '0')));
    set_app_setting('invite_bonus_watermark_points', (string) max(0, (int) ($_POST['invite_bonus_watermark_points'] ?? '0')));

    flash('success', '提示词工具配置已保存。');
    redirect('/admin/prompt_tools');
}

// ============================================================
// 读取当前配置
// ============================================================
$balanceLabel = app_setting('balance_label', '余额');

// 提示词优化
$promptOptimizeEnabled = (bool) app_setting('prompt_optimize_enabled', '0');
$promptOptimizeBaseUrl = app_setting('prompt_optimize_base_url', '');
$promptOptimizeApiKey = app_setting('prompt_optimize_api_key', '');
$promptOptimizeModel = app_setting('prompt_optimize_model', 'gpt-4o-mini');
$promptOptimizeSystemPrompt = app_setting('prompt_optimize_system_prompt', '');

// 提示词审核
$promptModerationEnabled = app_setting('prompt_moderation_enabled', '0') === '1';
$promptModerationBaseUrl = app_setting('prompt_moderation_base_url', '');
$promptModerationApiKey = app_setting('prompt_moderation_api_key', '');
$promptModerationModel = app_setting('prompt_moderation_model', 'gpt-4o-mini');

// 邀请功能
$inviteEnabled = app_setting('invite_enabled', 'off') === 'on';
$inviteCommissionPercent = (int) app_setting('invite_commission_percent', '10');
$inviteBonusCredits = (int) app_setting('invite_bonus_credits', '0');
$inviteBonusWp = (int) app_setting('invite_bonus_watermark_points', '0');

render_header('提示词工具', 'admin');
render_admin_nav('prompt_tools');
?>
<style>
.tools-page { max-width: 860px; margin: 0 auto; }

.settings-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 0;
    margin-bottom: 20px;
    overflow: hidden;
}
.settings-section-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.settings-section-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.settings-section-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.settings-section-icon.red  { background: linear-gradient(135deg, #fee2e2, #fecaca); }
.settings-section-icon.green { background: linear-gradient(135deg, #dcfce7, #bbf7d0); }
.settings-section-header .info h3 {
    font-size: 16px; font-weight: 700; color: var(--text); margin: 0 0 4px;
}
.settings-section-header .info p {
    font-size: 13px; color: var(--text-muted); margin: 0;
}
.settings-section-body {
    padding: 20px 24px;
}

.settings-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.settings-switch-row .left h4 {
    font-size: 14px; font-weight: 700; color: var(--text); margin: 0 0 4px;
}
.settings-switch-row .left p {
    font-size: 13px; color: var(--text-muted); margin: 0;
}

.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
@media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }

.settings-field { display: flex; flex-direction: column; gap: 6px; }
.settings-field label {
    font-size: 13px; font-weight: 700; color: var(--text);
}
.settings-field input,
.settings-field textarea {
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    font-size: 13px;
    background: var(--main-surface);
    color: var(--text);
    transition: border-color var(--duration-fast);
}
.settings-field input:focus,
.settings-field textarea:focus {
    outline: none;
    border-color: var(--primary);
}
.settings-field textarea { resize: vertical; min-height: 60px; }
.settings-field .hint {
    font-size: 12px; color: var(--text-muted);
    line-height: 1.5;
}
.settings-field .hint strong { color: var(--primary); }

/* Switch */
.switch {
    position: relative;
    width: 48px; height: 28px;
    border-radius: 14px;
    background: var(--line-strong);
    cursor: pointer;
    flex-shrink: 0;
    transition: background var(--duration-fast);
}
.switch input { position: absolute; opacity: 0; pointer-events: none; }
.switch-knob {
    position: absolute;
    top: 3px; left: 3px;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform var(--duration-fast);
}
.switch.active { background: var(--primary); }
.switch.active .switch-knob { transform: translateX(20px); }

.submit-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 8px;
}
.btn-save {
    padding: 12px 32px;
    border: none;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity var(--duration-fast);
}
.btn-save:hover { opacity: 0.9; }
</style>

<main>
    <div class="page-hd">
        <div>
            <h1>提示词工具</h1>
            <p>Prompt Tools</p>
        </div>
    </div>

    <div class="tools-page">
        <form method="post" action="/admin/prompt_tools">
        <?= csrf_field() ?>

        <!-- ================================================================ -->
        <!-- 提示词优化 -->
        <!-- ================================================================ -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon blue">✨</div>
                <div class="info">
                    <h3>提示词优化</h3>
                    <p>调用 AI 对用户输入的提示词进行智能润色</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-switch-row">
                    <div class="left">
                        <h4>启用提示词优化</h4>
                        <p>开启后前台提示词框下方显示「优化提示词」按钮。</p>
                    </div>
                    <label class="switch <?= $promptOptimizeEnabled ? 'active' : '' ?>">
                        <input type="checkbox" name="prompt_optimize_enabled" value="1" <?= $promptOptimizeEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>API 地址</label>
                        <input name="prompt_optimize_base_url" value="<?= e($promptOptimizeBaseUrl) ?>" placeholder="留空则使用图片生成 API 地址">
                        <div class="hint">如 <code>https://api.openai.com/v1</code></div>
                    </div>
                    <div class="settings-field">
                        <label>API Key</label>
                        <input name="prompt_optimize_api_key" value="<?= e($promptOptimizeApiKey) ?>" placeholder="留空则使用图片生成 API Key" type="password">
                    </div>
                </div>
                <div class="settings-field" style="margin-top:12px;">
                    <label>优化模型</label>
                    <input name="prompt_optimize_model" value="<?= e($promptOptimizeModel) ?>" placeholder="gpt-4o-mini">
                    <div class="hint">建议 gpt-4o-mini 或更强模型。</div>
                </div>
                <div class="settings-field" style="margin-top:12px;">
                    <label>系统提示词 (System Prompt)</label>
                    <textarea name="prompt_optimize_system_prompt" rows="3" placeholder="留空则使用默认优化提示词"><?= e($promptOptimizeSystemPrompt) ?></textarea>
                    <div class="hint">自定义 AI 优化风格，留空使用系统内置提示词。</div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- AI 提示词审核 -->
        <!-- ================================================================ -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon red">🛡️</div>
                <div class="info">
                    <h3>AI 提示词审核</h3>
                    <p>提交前自动检测违规内容，拦截不合规提示词</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-switch-row">
                    <div class="left">
                        <h4>启用 AI 提示词审核</h4>
                        <p>先经本地关键词过滤，再调 AI 语义审核。审核失败自动放行。</p>
                    </div>
                    <label class="switch <?= $promptModerationEnabled ? 'active' : '' ?>">
                        <input type="checkbox" name="prompt_moderation_enabled" value="1" <?= $promptModerationEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>API 地址</label>
                        <input name="prompt_moderation_base_url" value="<?= e($promptModerationBaseUrl) ?>" placeholder="留空则使用图片生成 API 地址">
                    </div>
                    <div class="settings-field">
                        <label>API Key</label>
                        <input name="prompt_moderation_api_key" value="<?= e($promptModerationApiKey) ?>" placeholder="留空则使用图片生成 API Key" type="password">
                    </div>
                </div>
                <div class="settings-field" style="margin-top:12px;">
                    <label>审核模型</label>
                    <input name="prompt_moderation_model" value="<?= e($promptModerationModel) ?>" placeholder="gpt-4o-mini">
                    <div class="hint">审核接口故障时自动放行，不阻断用户正常使用。</div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 邀请功能 -->
        <!-- ================================================================ -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon green">🎁</div>
                <div class="info">
                    <h3>邀请功能</h3>
                    <p>邀请好友注册充值，双方获得奖励</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-switch-row">
                    <div class="left">
                        <h4>启用邀请功能</h4>
                        <p>开启后注册页显示邀请码输入框，用户可生成邀请码。</p>
                    </div>
                    <label class="switch <?= $inviteEnabled ? 'active' : '' ?>">
                        <input type="checkbox" name="invite_enabled" <?= $inviteEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>充值佣金比例 (%)</label>
                        <input name="invite_commission_percent" type="number" min="0" max="100" value="<?= $inviteCommissionPercent ?>">
                        <div class="hint">被邀请用户充值时，邀请人获得充值<?= e($balanceLabel) ?>的 <strong><?= $inviteCommissionPercent ?>%</strong>。</div>
                    </div>
                    <div class="settings-field">
                        <label>邀请赠送 <?= e($balanceLabel) ?></label>
                        <input name="invite_bonus_credits" type="number" min="0" value="<?= $inviteBonusCredits ?>">
                        <div class="hint">被邀请用户通过邀请码注册后，双方各获 <strong><?= $inviteBonusCredits ?> <?= e($balanceLabel) ?></strong>。</div>
                    </div>
                    <div class="settings-field">
                        <label>邀请赠送水印点</label>
                        <input name="invite_bonus_watermark_points" type="number" min="0" value="<?= $inviteBonusWp ?>">
                        <div class="hint">被邀请用户通过邀请码注册后，双方各获 <strong><?= $inviteBonusWp ?> 水印点</strong>。</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="submit-bar">
            <button type="submit" class="btn-save">💾 保存全部配置</button>
        </div>
        </form>
    </div>
</main>

<script>
document.querySelectorAll('.switch').forEach(function(sw) {
    var cb = sw.querySelector('input[type="checkbox"]');
    if (!cb) return;
    sw.addEventListener('click', function(e) {
        e.preventDefault();
        cb.checked = !cb.checked;
        sw.classList.toggle('active', cb.checked);
        cb.dispatchEvent(new Event('change', {bubbles: true}));
    });
    cb.addEventListener('change', function() {
        sw.classList.toggle('active', cb.checked);
    });
});
</script>
<?php render_footer(); ?>
