<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    set_app_setting('checkin_enabled', !empty($_POST['checkin_enabled']) ? '1' : '0');
    set_app_setting('checkin_base_reward', (string) max(1, (int) ($_POST['checkin_base_reward'] ?? 5)));
    set_app_setting('checkin_multiplier', (string) max(0, (float) ($_POST['checkin_multiplier'] ?? 0.2)));
    set_app_setting('checkin_max_daily', (string) max(1, (int) ($_POST['checkin_max_daily'] ?? 100)));
    set_app_setting('checkin_custom_rewards', trim((string) ($_POST['checkin_custom_rewards'] ?? '')));
    set_app_setting('checkin_watermark_points', (string) max(0, (int) ($_POST['checkin_watermark_points'] ?? 0)));

    flash('success', '签到配置已保存。');
    redirect('/admin/checkin');
}

$checkinEnabled      = app_setting('checkin_enabled', '1') === '1';
$checkinBaseReward   = (int) app_setting('checkin_base_reward', '5');
$checkinMultiplier   = (float) app_setting('checkin_multiplier', '0.2');
$checkinMaxDaily     = (int) app_setting('checkin_max_daily', '100');
$checkinCustomRewards = app_setting('checkin_custom_rewards', '');
$checkinWp = (int) app_setting('checkin_watermark_points', '0');

ensure_checkin_table();
$todayStr = date('Y-m-d');
$stmt = db()->prepare('SELECT COUNT(*) FROM checkin_records WHERE checkin_date = ?');
$stmt->execute([$todayStr]);
$todayCount = (int) $stmt->fetchColumn();
$stmt = db()->query('SELECT COUNT(DISTINCT user_id) AS users FROM checkin_records');
$totalUsers = (int) $stmt->fetch()['users'];

render_header('签到管理', 'admin');
render_admin_nav('checkin');
?>

<style>
/* ── settings-section（复用 settings.php 布局体系）── */
.settings-page { max-width: 780px; margin: 0 auto; }

.settings-section {
    background: var(--card-bg, var(--main-surface));
    border: 1px solid var(--line);
    border-radius: var(--radius-lg, 16px);
    margin-bottom: 20px;
    overflow: hidden;
}
.settings-section.disabled { opacity: 0.55; }
.settings-section-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
}
.settings-section-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px; height: 38px;
    border-radius: 10px;
    font-size: 18px;
    flex-shrink: 0;
}
.settings-section-icon.green  { background: #d1fae5; color: #059669; }
.settings-section-icon.amber { background: #fef3c7; color: #d97706; }
.settings-section-header .info { min-width: 0; }
.settings-section-header .info h3 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text); }
.settings-section-header .info p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

.settings-section-body { padding: 20px 24px 24px; }

/* fields */
.settings-field { margin-bottom: 18px; }
.settings-field:last-child { margin-bottom: 0; }
.settings-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}
.settings-field input,
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
.settings-field textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.settings-field textarea { resize: vertical; min-height: 80px; }
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

/* grid */
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
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
    margin-top: 20px;
}
.settings-switch-row .left h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--text); }
.settings-switch-row .left p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

/* ── Toggle Switch ── */
.toggle-switch {
    position: relative;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
    user-select: none;
    flex-shrink: 0;
}
.toggle-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.toggle-track {
    position: relative;
    width: 48px; height: 26px;
    border-radius: 13px;
    background: #d1d5db;
    transition: background 0.3s ease;
}
.toggle-switch input:checked + .toggle-track {
    background: linear-gradient(135deg, #10b981, #059669);
}
.toggle-thumb {
    position: absolute;
    top: 2px; left: 2px;
    width: 22px; height: 22px;
    border-radius: 11px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.toggle-switch input:checked + .toggle-track .toggle-thumb {
    transform: translateX(22px);
}

/* preview */
.preview-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.preview-day {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column;
    font-size: 9px; font-weight: 700;
    background: var(--main-surface);
    border: 1px solid var(--line);
    color: var(--text);
}
.preview-day.custom { border-color: #f59e0b; color: #d97706; background: #fffbeb; }
.preview-day .pd { font-size: 16px; line-height: 1; }

/* submit */
.settings-submit {
    display: flex;
    justify-content: flex-end;
    padding-top: 8px;
}
.settings-submit .btn-save {
    padding: 12px 32px;
    font-size: 14px;
    font-weight: 700;
    background: var(--primary, #3b82f6);
    color: #fff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.settings-submit .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
.settings-submit .btn-save:active { transform: scale(0.98); }
</style>

<main class="settings-page">
    <div class="page-hd" style="margin-bottom:20px;">
        <div>
            <h1>签到管理</h1>
            <p>配置每日签到奖励规则 · 今日 <strong><?= $todayCount ?></strong> 人签到 · 共 <strong><?= $totalUsers ?></strong> 人参与</p>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>

        <!-- 总开关 -->
        <div class="settings-section<?= !$checkinEnabled ? ' disabled' : '' ?>">
            <div class="settings-section-header">
                <div class="settings-section-icon green">📅</div>
                <div class="info">
                    <h3>每日签到</h3>
                    <p>用户每天登录仪表盘签到可领取点数，连续签到奖励递增</p>
                </div>
                <div style="margin-left:auto;">
                    <label class="toggle-switch">
                        <input type="checkbox" name="checkin_enabled" value="1"
                            <?= $checkinEnabled ? 'checked' : '' ?>
                            onchange="document.querySelector('.settings-section').classList.toggle('disabled', !this.checked)">
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>基础奖励（首日）</label>
                        <input type="number" name="checkin_base_reward" min="1" value="<?= $checkinBaseReward ?>">
                        <div class="hint">第一天签到获得的点数</div>
                    </div>
                    <div class="settings-field">
                        <label>倍数增长</label>
                        <input type="number" name="checkin_multiplier" step="0.1" min="0" value="<?= $checkinMultiplier ?>">
                        <div class="hint">每日递增值 = 基础 × 倍数</div>
                    </div>
                    <div class="settings-field">
                        <label>每日上限</label>
                        <input type="number" name="checkin_max_daily" min="1" value="<?= $checkinMaxDaily ?>">
                        <div class="hint">单日最高奖励封顶</div>
                    </div>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>每日水印点（固定）</label>
                        <input type="number" name="checkin_watermark_points" min="0" value="<?= $checkinWp ?>">
                        <div class="hint">每次签到额外赠送的水印点数，设为 0 则不赠送。</div>
                    </div>
                </div>
                <div class="settings-field">
                    <label>阶梯奖励 <span style="font-weight:400;color:var(--text-muted);">— JSON 格式，可选</span></label>
                    <textarea name="checkin_custom_rewards" rows="3" placeholder='{"7":30,"15":80,"30":200}' style="font-family:monospace;font-size:13px;"><?= e($checkinCustomRewards) ?></textarea>
                    <div class="hint">例如 <code>{"7":30,"15":80,"30":200}</code> — 连续7天奖30点、15天奖80点、30天奖200点。阶梯奖励优先级高于公式计算。</div>
                </div>

                <!-- 实时预览 -->
                <div class="settings-switch-row" style="margin-top:16px; background:linear-gradient(135deg, rgba(245,158,11,.06), rgba(245,158,11,.02)); border:1px dashed rgba(245,158,11,.15);">
                    <div class="left">
                        <h4>📊 奖励预览</h4>
                        <p>前 7 天签到奖励（橙色边框 = 阶梯奖励天数）</p>
                    </div>
                    <div class="preview-row" id="previewDays"></div>
                </div>
            </div>
        </div>

        <div class="settings-submit">
            <button type="submit" class="btn-save">💾 保存配置</button>
        </div>
    </form>
</main>

<script>
// ── 奖励预览实时计算 ──
(function() {
    function updatePreview() {
        var base = parseInt(document.querySelector('[name="checkin_base_reward"]').value) || 5;
        var mult = parseFloat(document.querySelector('[name="checkin_multiplier"]').value) || 0;
        var max  = parseInt(document.querySelector('[name="checkin_max_daily"]').value) || 100;
        var customRaw = document.querySelector('[name="checkin_custom_rewards"]').value.trim();
        var custom = {};
        if (customRaw) { try { custom = JSON.parse(customRaw); } catch(e) {} }

        var html = '';
        for (var d = 1; d <= 7; d++) {
            var reward = Math.round(base + base * mult * (d - 1));
            if (custom[String(d)]) reward = Math.max(reward, parseInt(custom[String(d)]));
            reward = Math.min(reward, max);
            var cls = custom[String(d)] ? ' custom' : '';
            html += '<div class="preview-day'+cls+'"><span class="pd">'+reward+'</span>D'+d+'</div>';
        }
        document.getElementById('previewDays').innerHTML = html;
    }
    updatePreview();
    document.querySelectorAll('[name="checkin_base_reward"], [name="checkin_multiplier"], [name="checkin_max_daily"], [name="checkin_custom_rewards"]').forEach(function(el) {
        el.addEventListener('input', updatePreview);
    });
})();
</script>
<?php render_footer(); ?>
