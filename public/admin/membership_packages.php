<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/membership.php';

$admin = require_admin();
ensure_all_tables();

// ── POST 处理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $pkgId = $action === 'update' ? (int) ($_POST['package_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $durationUnit = (string) ($_POST['duration_unit'] ?? 'month');
        $durationValue = max(1, (int) ($_POST['duration_value'] ?? 1));
        $dailyQuota = max(0, (int) ($_POST['daily_quota'] ?? 0));
        $monthlyQuota = max(0, (int) ($_POST['monthly_quota'] ?? 0));
        $yearlyQuota = max(0, (int) ($_POST['yearly_quota'] ?? 0));
        $price = max(0.01, (float) ($_POST['price'] ?? 0.01));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive = (int) ($_POST['is_active'] ?? 1);
        $modelIds = trim((string) ($_POST['model_ids'] ?? ''));
        // 验证 model_ids 为逗号分隔的数字或空
        if ($modelIds !== '' && !preg_match('/^(\d+)(,\d+)*$/', $modelIds)) {
            $modelIds = '';
        }

        if ($name === '') {
            flash('error', '套餐名称不能为空。');
            redirect('/admin/membership_packages');
        }
        if (!in_array($durationUnit, ['day', 'month', 'year'], true)) {
            flash('error', '时长单位不合法。');
            redirect('/admin/membership_packages');
        }

        if ($action === 'create') {
            $stmt = db()->prepare(
                'INSERT INTO membership_packages (name, description, duration_unit, duration_value, daily_quota, monthly_quota, yearly_quota, model_ids, price, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $description, $durationUnit, $durationValue, $dailyQuota, $monthlyQuota, $yearlyQuota, $modelIds, $price, $sortOrder, $isActive]);
            flash('success', '会员套餐已创建。');
        } else {
            if ($pkgId < 1) { flash('error', '参数不合法。'); redirect('/admin/membership_packages'); }
            $stmt = db()->prepare(
                'UPDATE membership_packages SET name=?, description=?, duration_unit=?, duration_value=?, daily_quota=?, monthly_quota=?, yearly_quota=?, model_ids=?, price=?, sort_order=?, is_active=? WHERE id=?'
            );
            $stmt->execute([$name, $description, $durationUnit, $durationValue, $dailyQuota, $monthlyQuota, $yearlyQuota, $modelIds, $price, $sortOrder, $isActive, $pkgId]);
            flash('success', '会员套餐已更新。');
        }
        redirect('/admin/membership_packages');
    }

    if ($action === 'delete') {
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        if ($pkgId < 1) { flash('error', '参数不合法。'); redirect('/admin/membership_packages'); }
        db()->prepare('DELETE FROM membership_packages WHERE id = ?')->execute([$pkgId]);
        flash('success', '套餐已删除。');
        redirect('/admin/membership_packages');
    }

    flash('error', '未知操作。');
    redirect('/admin/membership_packages');
}

// ── 获取数据 ──
$stmt = db()->query('SELECT * FROM membership_packages ORDER BY sort_order ASC, id ASC');
$packages = $stmt->fetchAll();
$activeCount = count(array_filter($packages, fn($p) => (int)$p['is_active'] === 1));

// ── 构建 JS 数据 ──
$pkgsJson = [];
foreach ($packages as $p) {
    $pkgsJson[(int)$p['id']] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'description' => $p['description'] ?: '',
        'duration_unit' => $p['duration_unit'],
        'duration_value' => (int)$p['duration_value'],
        'daily_quota' => (int)$p['daily_quota'],
        'monthly_quota' => (int)$p['monthly_quota'],
        'yearly_quota' => (int)$p['yearly_quota'],
        'price' => (float)$p['price'],
        'sort_order' => (int)$p['sort_order'],
        'is_active' => (int)$p['is_active'],
        'model_ids' => trim((string)($p['model_ids'] ?? '')),
    ];
}

// ── 会员统计 ──
try {
    $totalMembers = (int) db()->query("SELECT COUNT(*) FROM user_memberships WHERE status = 'active'")->fetchColumn();
    $expiringSoon = (int) db()->query("SELECT COUNT(*) FROM user_memberships WHERE status = 'active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Throwable $e) {
    $totalMembers = 0;
    $expiringSoon = 0;
}

render_header('会员套餐管理', 'admin');
render_admin_nav('membership_packages');
?>
<style>
@media (max-width: 768px) {
    .mem-pkg-card .pkg-nums { grid-template-columns: 1fr 1fr; }
}
</style>
<main>
    <div class="page-hd">
        <div><h1>会员套餐管理</h1><p>Membership Packages</p></div>
        <button class="btn btn-primary" onclick="openPkgCreate()" style="padding:10px 24px;font-size:14px;">＋ 新增套餐</button>
    </div>

    <div class="mem-pkg-stats">
        <div class="mem-pkg-stat-card"><div class="num"><?= count($packages) ?></div><div class="lbl">总套餐</div></div>
        <div class="mem-pkg-stat-card"><div class="num"><?= $activeCount ?></div><div class="lbl">已上架</div></div>
        <div class="mem-pkg-stat-card"><div class="num"><?= $totalMembers ?></div><div class="lbl">当前会员</div></div>
        <div class="mem-pkg-stat-card"><div class="num" style="color:var(--warning, #f59e0b);"><?= $expiringSoon ?></div><div class="lbl">7天内到期</div></div>
    </div>

    <?php if ($packages): ?>
    <div class="mem-pkg-grid">
        <?php foreach ($packages as $pkg): ?>
        <?php $pkgId = (int) $pkg['id']; $isActive = (int)$pkg['is_active'] === 1; ?>
        <div class="mem-pkg-card<?= !$isActive ? ' inactive' : '' ?>">
            <div class="pkg-name"><?= e($pkg['name']) ?></div>
            <div class="pkg-desc"><?= e($pkg['description'] ?: '—') ?></div>
            <div class="pkg-duration">
                ⏱ <?= (int)$pkg['duration_value'] ?> <?= membership_duration_label($pkg['duration_unit']) ?>
            </div>
            <div class="pkg-nums">
                <?php if ((int)$pkg['daily_quota'] > 0): ?>
                <div class="item"><div class="val"><?= number_format((int)$pkg['daily_quota']) ?></div><div class="lbl">每日配额</div></div>
                <?php endif; ?>
                <?php if ((int)$pkg['monthly_quota'] > 0): ?>
                <div class="item"><div class="val"><?= number_format((int)$pkg['monthly_quota']) ?></div><div class="lbl">每月配额</div></div>
                <?php endif; ?>
                <?php if ((int)$pkg['yearly_quota'] > 0): ?>
                <div class="item"><div class="val"><?= number_format((int)$pkg['yearly_quota']) ?></div><div class="lbl">每年配额</div></div>
                <?php endif; ?>
                <div class="item price-item"><div class="val">¥<?= number_format((float)$pkg['price'], 2) ?></div><div class="lbl">价格</div></div>
            </div>
            <div class="pkg-tags">
                <span class="tag <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? '已上架' : '已下架' ?></span>
            </div>
            <div class="pkg-foot">
                <button class="btn btn-primary btn-sm" onclick="openPkgEdit(<?= $pkgId ?>)">编辑</button>
                <form method="post" onsubmit="return confirm('确定删除「<?= e($pkg['name']) ?>」？')" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="package_id" value="<?= $pkgId ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger);">删除</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted);font-size:15px;">
        <div style="font-size:40px;margin-bottom:12px;">👑</div>
        还没有会员套餐，请点击上方「新增套餐」创建
    </div>
    <?php endif; ?>
</main>

<!-- 新增/编辑套餐弹窗 -->
<div id="pkgModal" class="mem-modal-overlay" onclick="if(event.target===this)closePkgModal()">
    <div class="mem-modal-card">
        <div class="mem-modal-head">
            <h3 id="pkgModalTitle">新增会员套餐</h3>
            <button class="mem-modal-close" onclick="closePkgModal()">&times;</button>
        </div>
        <div class="mem-modal-body">
            <form method="post" id="pkgForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="pkgAction" value="create">
                <input type="hidden" name="package_id" id="pkgId" value="">
                <input type="hidden" name="model_ids" id="pkgModelIds" value="">

                <div class="field-row">
                    <div class="field">
                        <label>套餐名称</label>
                        <input name="name" id="pkgName" placeholder="例：月度专业版" required>
                    </div>
                    <div class="field">
                        <label>排序</label>
                        <input name="sort_order" id="pkgSort" type="number" min="0" value="0">
                    </div>
                </div>
                <div class="field">
                    <label>描述</label>
                    <textarea name="description" id="pkgDesc" rows="2" placeholder="简要描述套餐特点"></textarea>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>时长单位</label>
                        <select name="duration_unit" id="pkgDurationUnit">
                            <option value="day">天</option>
                            <option value="month" selected>月</option>
                            <option value="year">年</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>时长数值</label>
                        <input name="duration_value" id="pkgDurationValue" type="number" min="1" value="1">
                        <span class="field-hint">如 1个月、3个月、1年</span>
                    </div>
                </div>

                <div class="section">
                    <h4>📊 余额配额设置</h4>
                    <p class="field-hint" style="margin-bottom:10px;">配额为每次重置后的余额上限。设为 0 表示该周期不自动重置。优先级：日 > 月 > 年。</p>
                    <div class="field-row-3">
                        <div class="field">
                            <label>每日配额</label>
                            <input name="daily_quota" id="pkgDailyQuota" type="number" min="0" value="0" placeholder="例：100">
                            <span class="field-hint">每日重置到该数值</span>
                        </div>
                        <div class="field">
                            <label>每月配额</label>
                            <input name="monthly_quota" id="pkgMonthlyQuota" type="number" min="0" value="0" placeholder="例：3000">
                            <span class="field-hint">每月初重置</span>
                        </div>
                        <div class="field">
                            <label>每年配额</label>
                            <input name="yearly_quota" id="pkgYearlyQuota" type="number" min="0" value="0" placeholder="例：36000">
                            <span class="field-hint">每年初重置</span>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h4>🎯 支持模型（套餐内模型扣配额不扣余额）</h4>
                    <p class="field-hint" style="margin-bottom:8px;">勾选后，会员使用这些模型时从配额扣除而非余额。不选则对所有模型扣余额。</p>
                    <div id="pkgModelCheckboxes" class="field-row-3" style="max-height:200px;overflow-y:auto;padding:8px;border:1px solid var(--line);border-radius:8px;">
                        <?php
                        $allModels = db()->query("SELECT id, name, model_type FROM ai_models WHERE is_active = 1 ORDER BY model_type, sort_order, id")->fetchAll();
                        foreach ($allModels as $m):
                        ?>
                        <label class="field" style="display:flex;align-items:center;gap:6px;padding:4px 0;font-size:13px;">
                            <input type="checkbox" name="model_ids[]" value="<?= (int)$m['id'] ?>" onchange="syncModelIds()">
                            <?= e($m['name']) ?> <small style="color:var(--text-muted)">[<?= $m['model_type'] === 'video' ? '视频' : '图片' ?>]</small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>价格（元）</label>
                        <input name="price" id="pkgPrice" type="number" step="0.01" min="0.01" value="9.90" required>
                    </div>
                    <div class="field">
                        <label>状态</label>
                        <select name="is_active" id="pkgActive">
                            <option value="1">已上架</option>
                            <option value="0">已下架</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="mem-modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closePkgModal()">取消</button>
            <button type="submit" class="btn btn-primary" form="pkgForm">保存</button>
        </div>
    </div>
</div>

<script>
var _pkgs = <?= json_encode($pkgsJson, JSON_UNESCAPED_UNICODE) ?>;

function syncModelIds() {
    var ids = [];
    document.querySelectorAll('#pkgModelCheckboxes input[type=checkbox]:checked').forEach(function(cb) {
        ids.push(cb.value);
    });
    document.getElementById('pkgModelIds').value = ids.join(',');
}

function loadModelCheckboxes(modelIds) {
    var ids = (modelIds || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);
    document.querySelectorAll('#pkgModelCheckboxes input[type=checkbox]').forEach(function(cb) {
        cb.checked = ids.indexOf(cb.value) >= 0;
    });
    syncModelIds();
}

function openPkgCreate() {
    document.getElementById('pkgAction').value = 'create';
    document.getElementById('pkgId').value = '';
    document.getElementById('pkgModalTitle').textContent = '新增会员套餐';
    document.getElementById('pkgName').value = '';
    document.getElementById('pkgSort').value = 0;
    document.getElementById('pkgDesc').value = '';
    document.getElementById('pkgDurationUnit').value = 'month';
    document.getElementById('pkgDurationValue').value = 1;
    document.getElementById('pkgDailyQuota').value = 0;
    document.getElementById('pkgMonthlyQuota').value = 0;
    document.getElementById('pkgYearlyQuota').value = 0;
    document.getElementById('pkgPrice').value = '9.90';
    document.getElementById('pkgActive').value = 1;
    loadModelCheckboxes('');
    document.getElementById('pkgModal').classList.add('show');
}

function openPkgEdit(id) {
    var p = _pkgs[id];
    if (!p) return;
    document.getElementById('pkgAction').value = 'update';
    document.getElementById('pkgId').value = p.id;
    document.getElementById('pkgModalTitle').textContent = '编辑会员套餐';
    document.getElementById('pkgName').value = p.name;
    document.getElementById('pkgSort').value = p.sort_order;
    document.getElementById('pkgDesc').value = p.description;
    document.getElementById('pkgDurationUnit').value = p.duration_unit;
    document.getElementById('pkgDurationValue').value = p.duration_value;
    document.getElementById('pkgDailyQuota').value = p.daily_quota;
    document.getElementById('pkgMonthlyQuota').value = p.monthly_quota;
    document.getElementById('pkgYearlyQuota').value = p.yearly_quota;
    document.getElementById('pkgPrice').value = p.price.toFixed(2);
    document.getElementById('pkgActive').value = p.is_active;
    loadModelCheckboxes(p.model_ids || '');
    document.getElementById('pkgModal').classList.add('show');
}

function closePkgModal() {
    document.getElementById('pkgModal').classList.remove('show');
}
</script>

<?php render_footer(); ?>
