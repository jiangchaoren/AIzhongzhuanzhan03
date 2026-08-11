<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_all_tables();
ensure_watermark_columns();

// 处理新增/编辑/删除
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $pkgId = $action === 'update' ? (int) ($_POST['package_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $credits = max(1, (int) ($_POST['credits'] ?? 1));
        $watermarkPoints = max(0, (int) ($_POST['watermark_points'] ?? 0));
        $price = max(0.01, (float) ($_POST['price'] ?? 0.01));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive = (int) ($_POST['is_active'] ?? 1);

        if ($name === '') {
            flash('error', '套餐名称不能为空。');
            redirect('/admin/packages');
        }

        $flashEnabled = (int)(!empty($_POST['flash_sale_enabled']));
        $flashStart = trim($_POST['flash_sale_start_time'] ?? '') ?: null;
        $flashEnd = trim($_POST['flash_sale_end_time'] ?? '') ?: null;
        $flashPrice = trim($_POST['flash_sale_price'] ?? '') !== '' ? max(0.01, (float)($_POST['flash_sale_price'] ?? 0)) : null;
        $flashStock = max(0, (int)($_POST['flash_sale_stock'] ?? 0));
        $groupEnabled = (int)(!empty($_POST['group_buy_enabled']));
        $groupMin = max(2, (int)($_POST['group_buy_min_count'] ?? 2));

        if ($action === 'create') {
            $stmt = db()->prepare(
                'INSERT INTO shop_packages (name, description, credits, watermark_points, price, sort_order, is_active,
                 flash_sale_enabled, flash_sale_start_time, flash_sale_end_time,
                 flash_sale_price, flash_sale_stock,
                 group_buy_enabled, group_buy_min_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $description, $credits, $watermarkPoints, $price, $sortOrder, $isActive,
                $flashEnabled, $flashStart, $flashEnd, $flashPrice, $flashStock, $groupEnabled, $groupMin]);
            flash('success', '套餐已创建。');
        } else {
            if ($pkgId < 1) { flash('error', '参数不合法。'); redirect('/admin/packages'); }
            $stmt = db()->prepare(
                'UPDATE shop_packages SET name=?, description=?, credits=?, watermark_points=?, price=?, sort_order=?, is_active=?,
                 flash_sale_enabled=?, flash_sale_start_time=?, flash_sale_end_time=?,
                 flash_sale_price=?, flash_sale_stock=?,
                 group_buy_enabled=?, group_buy_min_count=?
                 WHERE id=?'
            );
            $stmt->execute([$name, $description, $credits, $watermarkPoints, $price, $sortOrder, $isActive,
                $flashEnabled, $flashStart, $flashEnd, $flashPrice, $flashStock, $groupEnabled, $groupMin, $pkgId]);
            flash('success', '套餐已更新。');
        }
        redirect('/admin/packages');
    }

    if ($action === 'delete') {
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        if ($pkgId < 1) { flash('error', '参数不合法。'); redirect('/admin/packages'); }
        db()->prepare('DELETE FROM shop_packages WHERE id = ?')->execute([$pkgId]);
        flash('success', '套餐已删除。');
        redirect('/admin/packages');
    }

    flash('error', '未知操作。');
    redirect('/admin/packages');
}

// 获取所有套餐
$stmt = db()->query('SELECT * FROM shop_packages ORDER BY sort_order ASC, id ASC');
$packages = $stmt->fetchAll();
$activeCount = count(array_filter($packages, fn($p) => (int)$p['is_active'] === 1));

// 构建 JS 数据
$pkgsJson = [];
foreach ($packages as $p) {
    $pkgsJson[(int)$p['id']] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'description' => $p['description'] ?: '',
        'credits' => (int)$p['credits'],
        'watermark_points' => (int)($p['watermark_points'] ?? 0),
        'price' => (float)$p['price'],
        'sort_order' => (int)$p['sort_order'],
        'is_active' => (int)$p['is_active'],
        'flash_sale_enabled' => (int)($p['flash_sale_enabled'] ?? 0),
        'flash_sale_start_time' => $p['flash_sale_start_time'] ?? '',
        'flash_sale_end_time' => $p['flash_sale_end_time'] ?? '',
        'flash_sale_price' => (float)($p['flash_sale_price'] ?? 0),
        'flash_sale_stock' => (int)($p['flash_sale_stock'] ?? 0),
        'flash_sale_sold' => (int)($p['flash_sale_sold'] ?? 0),
        'group_buy_enabled' => (int)($p['group_buy_enabled'] ?? 0),
        'group_buy_min_count' => (int)($p['group_buy_min_count'] ?? 2),
    ];
}

render_header('套餐管理', 'admin');
render_admin_nav('packages');
?>
<style>
.pkg-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
.pkg-stat-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.pkg-stat-card .num { font-size: 26px; font-weight: 800; color: var(--text); }
.pkg-stat-card .lbl { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .5px; margin-top: 2px; }
.pkg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.pkg-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 16px; padding: 20px; position: relative; transition: all .15s; }
.pkg-card:hover { border-color: var(--primary-soft); box-shadow: 0 4px 16px rgba(0,0,0,.04); }
.pkg-card.inactive { opacity: .45; }
.pkg-card .pkg-name { font-size: 17px; font-weight: 700; margin-bottom: 4px; }
.pkg-card .pkg-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; min-height: 18px; }
.pkg-card .pkg-nums { display: flex; gap: 20px; margin-bottom: 14px; }
.pkg-card .pkg-nums .item { text-align: center; }
.pkg-card .pkg-nums .item .val { font-size: 22px; font-weight: 800; color: var(--primary); }
.pkg-card .pkg-nums .item .lbl { font-size: 10px; color: var(--text-muted); font-weight: 600; }
.pkg-card .pkg-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.pkg-card .pkg-tags .tag { font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: 600; }
.pkg-card .pkg-tags .tag.active { background: var(--success-soft, #dcfce7); color: var(--success, #16a34a); }
.pkg-card .pkg-tags .tag.inactive { background: var(--line); color: var(--text-muted); }
.pkg-card .pkg-tags .tag.flash { background: #fef3c7; color: #d97706; }
.pkg-card .pkg-tags .tag.group { background: #ede9fe; color: #7c3aed; }
.pkg-card .pkg-foot { display: flex; gap: 8px; border-top: 1px solid var(--line); padding-top: 12px; }
.pkg-card .pkg-foot button { flex: 1; }

/* 模态 */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 999; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-card { background: var(--main-surface); border-radius: 16px; width: 90vw; max-width: 540px; max-height: 88vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.modal-head { padding: 20px 24px 0; display: flex; justify-content: space-between; align-items: center; }
.modal-head h3 { margin: 0; font-size: 18px; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); line-height: 1; padding: 0; }
.modal-body { padding: 16px 24px 24px; }
.modal-body .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.modal-body .field label { font-size: 12px; font-weight: 600; color: var(--text-soft); text-transform: uppercase; letter-spacing: .03em; }
.modal-body .field input,
.modal-body .field select,
.modal-body .field textarea { padding: 8px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; background: var(--main-surface); }
.modal-body .field input:focus,
.modal-body .field select:focus,
.modal-body .field textarea:focus { outline: none; border-color: var(--primary); }
.modal-body .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.modal-body .field-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.modal-body .field-hint { font-size: 10px; color: var(--text-muted); }
.modal-body .section { border-top: 1px solid var(--line); padding-top: 12px; margin-top: 12px; }
.modal-body .section h4 { font-size: 13px; font-weight: 700; margin: 0 0 8px; }
.modal-foot { padding: 0 24px 20px; display: flex; gap: 10px; justify-content: flex-end; }
@media (max-width: 768px) {
    .modal-card { max-width: 96vw; }
    .modal-body .field-row,
    .modal-body .field-row-3 { grid-template-columns: 1fr; }
    .pkg-grid { grid-template-columns: 1fr; }
}
</style>
<main>
    <div class="page-hd">
        <div><h1>套餐管理</h1><p>Packages</p></div>
        <button class="btn btn-primary" onclick="openPkgCreate()" style="padding:10px 24px;font-size:14px;">＋ 新增套餐</button>
    </div>

    <div class="pkg-stats">
        <div class="pkg-stat-card"><div class="num"><?= count($packages) ?></div><div class="lbl">总套餐</div></div>
        <div class="pkg-stat-card"><div class="num"><?= $activeCount ?></div><div class="lbl">已上架</div></div>
        <div class="pkg-stat-card"><div class="num"><?= count($packages) - $activeCount ?></div><div class="lbl">已下架</div></div>
    </div>

    <?php if ($packages): ?>
    <div class="pkg-grid">
        <?php foreach ($packages as $pkg): ?>
        <?php $pkgId = (int) $pkg['id']; $isActive = (int)$pkg['is_active'] === 1; ?>
        <div class="pkg-card<?= !$isActive ? ' inactive' : '' ?>">
            <div class="pkg-name"><?= e($pkg['name']) ?></div>
            <div class="pkg-desc"><?= e($pkg['description'] ?: '—') ?></div>
            <div class="pkg-nums">
                <div class="item"><div class="val">+<?= number_format((int)$pkg['credits']) ?></div><div class="lbl"><?= e(balance_label()) ?></div></div>
                <div class="item"><div class="val">+<?= number_format((int)($pkg['watermark_points'] ?? 0)) ?></div><div class="lbl">水印点</div></div>
                <div class="item"><div class="val">¥<?= number_format((float)$pkg['price'], 2) ?></div><div class="lbl">价格</div></div>
            </div>
            <div class="pkg-tags">
                <span class="tag <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? '已上架' : '已下架' ?></span>
                <?php if (!empty($pkg['flash_sale_enabled'])): ?><span class="tag flash">⚡ 秒杀</span><?php endif; ?>
                <?php if (!empty($pkg['group_buy_enabled'])): ?><span class="tag group">👥 拼团</span><?php endif; ?>
            </div>
            <div class="pkg-foot">
                <button class="btn btn-primary btn-sm" onclick="openPkgEdit(<?= $pkgId ?>)">✏️ 编辑</button>
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
        <div style="font-size:40px;margin-bottom:12px;">📦</div>
        还没有套餐，请点击上方「新增套餐」创建
    </div>
    <?php endif; ?>
</main>

<!-- 新增/编辑套餐弹窗 -->
<div id="pkgModal" class="modal-overlay" onclick="if(event.target===this)closePkgModal()">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="pkgModalTitle">新增套餐</h3>
            <button class="modal-close" onclick="closePkgModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="pkgForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="pkgAction" value="create">
                <input type="hidden" name="package_id" id="pkgId" value="">

                <div class="field-row">
                    <div class="field">
                        <label>套餐名称</label>
                        <input name="name" id="pkgName" placeholder="例：入门套餐" required>
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
                <div class="field-row-3">
                    <div class="field">
                        <label><?= e(balance_label()) ?></label>
                        <input name="credits" id="pkgCredits" type="number" min="1" value="10" required>
                    </div>
                    <div class="field">
                        <label>水印点</label>
                        <input name="watermark_points" id="pkgWp" type="number" min="0" value="0">
                    </div>
                    <div class="field">
                        <label>价格（元）</label>
                        <input name="price" id="pkgPrice" type="number" step="0.01" min="0.01" value="1.00" required>
                    </div>
                </div>
                <div class="field">
                    <label>状态</label>
                    <select name="is_active" id="pkgActive">
                        <option value="1">已上架</option>
                        <option value="0">已下架</option>
                    </select>
                </div>

                <!-- 营销设置 -->
                <div class="section">
                    <h4>⚡ 限时秒杀</h4>
                    <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="flash_sale_enabled" id="pkgFlashOn" value="1" onchange="document.getElementById('flashFields').style.display=this.checked?'block':'none'">
                        启用秒杀
                    </label>
                    <div id="flashFields" style="display:none;">
                        <div class="field-row" style="margin-bottom:8px;">
                            <div class="field"><label>开始</label><input type="datetime-local" name="flash_sale_start_time" id="pkgFlashStart"></div>
                            <div class="field"><label>结束</label><input type="datetime-local" name="flash_sale_end_time" id="pkgFlashEnd"></div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label>秒杀价</label><input type="number" name="flash_sale_price" id="pkgFlashPrice" step="0.01" min="0.01" placeholder="¥"></div>
                            <div class="field"><label>限量</label><input type="number" name="flash_sale_stock" id="pkgFlashStock" min="0" value="0" placeholder="份数"></div>
                        </div>
                    </div>
                </div>
                <div class="section">
                    <h4>👥 拼团</h4>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="group_buy_enabled" id="pkgGroupOn" value="1" onchange="document.getElementById('groupFields').style.display=this.checked?'flex':'none'">
                        启用拼团
                    </label>
                    <div id="groupFields" style="display:none;align-items:center;gap:8px;margin-top:8px;">
                        <span style="font-size:13px;">满</span>
                        <input type="number" name="group_buy_min_count" id="pkgGroupMin" min="2" value="2" style="width:60px;padding:6px;text-align:center;">
                        <span style="font-size:13px;">人开团</span>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closePkgModal()">取消</button>
            <button type="submit" class="btn btn-primary" form="pkgForm">💾 保存</button>
        </div>
    </div>
</div>

<script>
var _pkgs = <?= json_encode($pkgsJson, JSON_UNESCAPED_UNICODE) ?>;

function openPkgCreate() {
    document.getElementById('pkgAction').value = 'create';
    document.getElementById('pkgId').value = '';
    document.getElementById('pkgModalTitle').textContent = '新增套餐';
    document.getElementById('pkgName').value = '';
    document.getElementById('pkgSort').value = 0;
    document.getElementById('pkgDesc').value = '';
    document.getElementById('pkgCredits').value = 10;
    document.getElementById('pkgWp').value = 0;
    document.getElementById('pkgPrice').value = 1.00;
    document.getElementById('pkgActive').value = 1;
    document.getElementById('pkgFlashOn').checked = false;
    document.getElementById('pkgFlashStart').value = '';
    document.getElementById('pkgFlashEnd').value = '';
    document.getElementById('pkgFlashPrice').value = '';
    document.getElementById('pkgFlashStock').value = 0;
    document.getElementById('pkgGroupOn').checked = false;
    document.getElementById('pkgGroupMin').value = 2;
    document.getElementById('flashFields').style.display = 'none';
    document.getElementById('groupFields').style.display = 'none';
    document.getElementById('pkgModal').classList.add('show');
}

function openPkgEdit(id) {
    var p = _pkgs[id];
    if (!p) return;
    document.getElementById('pkgAction').value = 'update';
    document.getElementById('pkgId').value = p.id;
    document.getElementById('pkgModalTitle').textContent = '编辑套餐';
    document.getElementById('pkgName').value = p.name;
    document.getElementById('pkgSort').value = p.sort_order;
    document.getElementById('pkgDesc').value = p.description;
    document.getElementById('pkgCredits').value = p.credits;
    document.getElementById('pkgWp').value = p.watermark_points;
    document.getElementById('pkgPrice').value = p.price.toFixed(2);
    document.getElementById('pkgActive').value = p.is_active;

    document.getElementById('pkgFlashOn').checked = p.flash_sale_enabled === 1;
    document.getElementById('pkgFlashStart').value = (p.flash_sale_start_time || '').replace(' ', 'T');
    document.getElementById('pkgFlashEnd').value = (p.flash_sale_end_time || '').replace(' ', 'T');
    document.getElementById('pkgFlashPrice').value = p.flash_sale_price > 0 ? p.flash_sale_price.toFixed(2) : '';
    document.getElementById('pkgFlashStock').value = p.flash_sale_stock;
    document.getElementById('flashFields').style.display = p.flash_sale_enabled === 1 ? 'block' : 'none';

    document.getElementById('pkgGroupOn').checked = p.group_buy_enabled === 1;
    document.getElementById('pkgGroupMin').value = p.group_buy_min_count;
    document.getElementById('groupFields').style.display = p.group_buy_enabled === 1 ? 'flex' : 'none';

    document.getElementById('pkgModal').classList.add('show');
}

function closePkgModal() {
    document.getElementById('pkgModal').classList.remove('show');
}
</script>

<?php render_footer(); ?>
