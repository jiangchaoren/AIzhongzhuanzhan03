<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/membership.php';

$admin = require_admin();
ensure_all_tables();

// ── 处理订单超时 ──
process_order_timeouts();

// ── POST 操作：删除/修改状态 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string) ($_POST['admin_action'] ?? '');
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId < 1) {
        flash('error', '参数不合法。');
        redirect('/admin/membership_orders');
    }

    if ($postAction === 'delete') {
        db()->prepare('DELETE FROM membership_orders WHERE id = ?')->execute([$orderId]);
        flash('success', '订单已删除。');
    } elseif ($postAction === 'status') {
        $newStatus = (string) ($_POST['new_status'] ?? '');
        $allowed = ['pending', 'paid', 'failed', 'refunded', 'expired'];
        if (!in_array($newStatus, $allowed, true)) {
            flash('error', '状态不合法。');
            redirect('/admin/membership_orders');
        }
        db()->prepare('UPDATE membership_orders SET status = ? WHERE id = ?')->execute([$newStatus, $orderId]);
        flash('success', '订单状态已更新。');
    } else {
        flash('error', '未知操作。');
    }
    redirect('/admin/membership_orders');
}

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$typeFilter = (string) ($_GET['type'] ?? 'all');
$keyword = trim((string) ($_GET['q'] ?? ''));

$allowedStatuses = ['all', 'pending', 'paid', 'failed', 'refunded', 'expired'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';

$allowedTypes = ['all', 'new', 'renew', 'upgrade', 'topup'];
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = 'all';

$where = []; $params = [];
if ($statusFilter !== 'all') { $where[] = 'mo.status = ?'; $params[] = $statusFilter; }
if ($typeFilter !== 'all') { $where[] = 'mo.order_type = ?'; $params[] = $typeFilter; }
if ($keyword !== '') {
    $where[] = '(mo.order_no LIKE ? OR mo.package_name LIKE ? OR u.username LIKE ?)';
    $like = '%'.$keyword.'%';
    $params = array_merge($params, [$like, $like, $like]);
}
$whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';

$totalStmt = db()->prepare('SELECT COUNT(*) FROM membership_orders mo LEFT JOIN users u ON u.id=mo.user_id'.$whereSql);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $q = http_build_query(['q'=>$keyword,'status'=>$statusFilter,'type'=>$typeFilter,'page'=>$totalPages]);
    redirect('/admin/membership_orders?'.$q);
}
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    'SELECT mo.*, u.username FROM membership_orders mo LEFT JOIN users u ON u.id=mo.user_id'.$whereSql.' ORDER BY mo.created_at DESC LIMIT ? OFFSET ?'
);
$i = 1;
foreach ($params as $p) $stmt->bindValue($i++, $p);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

// 统计
try {
    $stats = db()->query(
        "SELECT COUNT(*) AS total,
         COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS revenue,
         COUNT(CASE WHEN status='pending' THEN 1 END) AS pending,
         COUNT(CASE WHEN status='expired' THEN 1 END) AS expired,
         COUNT(CASE WHEN status='paid' AND order_type='new' THEN 1 END) AS new_members,
         COUNT(CASE WHEN status='paid' AND order_type='topup' THEN 1 END) AS topups
         FROM membership_orders"
    )->fetch();
} catch (Throwable $e) {
    $stats = ['total' => 0, 'revenue' => 0, 'pending' => 0, 'expired' => 0, 'new_members' => 0, 'topups' => 0];
}

$baseQuery = ['q' => $keyword, 'status' => $statusFilter, 'type' => $typeFilter];

render_header('会员订单管理', 'admin');
render_admin_nav('membership_orders');
?>
<style>
.order-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
.order-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.order-stat .n { font-size: 26px; font-weight: 800; color: var(--text); }
.order-stat .l { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .5px; margin-top: 2px; }
.filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.filter-row input, .filter-row select { padding: 7px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; background: var(--main-surface); }
.filter-row input:focus, .filter-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.filter-row input { flex: 1; max-width: 240px; }
.filter-row .tabs { display: flex; gap: 4px; }
.filter-row .tab { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; text-decoration: none; border: 1px solid var(--line); color: var(--text-muted); background: var(--main-surface); transition: all .12s; }
.filter-row .tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.order-list { display: flex; flex-direction: column; gap: 8px; }
.order-card { display: flex; flex-wrap: wrap; gap: 12px; padding: 14px 18px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; align-items: center; transition: all .12s; }
.order-card:hover { border-color: var(--primary-soft); }
.order-card .id { font-family: monospace; font-size: 12px; color: var(--text-muted); min-width: 160px; word-break: break-all; }
.order-card .id span { display: block; font-size: 10px; margin-top: 2px; }
.order-card .user { font-size: 13px; font-weight: 600; min-width: 80px; }
.order-card .pkg { font-size: 13px; min-width: 120px; }
.order-card .type-tag { font-size: 10px; padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.order-card .type-tag.new { background: #dcfce7; color: #16a34a; }
.order-card .type-tag.renew { background: #dbeafe; color: #2563eb; }
.order-card .type-tag.upgrade { background: #ede9fe; color: #7c3aed; }
.order-card .type-tag.topup { background: #fef3c7; color: #d97706; }
.order-card .amt { font-size: 15px; font-weight: 700; min-width: 70px; text-align: right; }
.order-card .status-col { min-width: 70px; text-align: center; }
.order-card .time { font-size: 11px; color: var(--text-muted); min-width: 100px; text-align: right; }
</style>
<main>
    <div class="page-hd">
        <div><h1>会员订单管理</h1><p>Membership Orders</p></div>
        <a href="/admin/membership_topups" class="btn btn-secondary btn-sm" style="margin-left:auto;">📦 加量包管理</a>
    </div>

    <div class="order-stats">
        <div class="order-stat"><div class="n"><?= (int) $stats['total'] ?></div><div class="l">总订单</div></div>
        <div class="order-stat"><div class="n">¥<?= number_format((float) $stats['revenue'], 2) ?></div><div class="l">总收入</div></div>
        <div class="order-stat"><div class="n"><?= (int) $stats['new_members'] ?></div><div class="l">新开会员</div></div>
        <div class="order-stat"><div class="n"><?= (int) $stats['topups'] ?></div><div class="l">加量包</div></div>
        <div class="order-stat"><div class="n"><?= (int) $stats['pending'] ?></div><div class="l">待处理</div></div>
        <div class="order-stat"><div class="n" style="color:var(--warning,#f59e0b);"><?= (int) ($stats['expired'] ?? 0) ?></div><div class="l">已过期</div></div>
    </div>

    <form method="get" class="filter-row">
        <div class="tabs">
            <?php foreach ($allowedStatuses as $st): ?>
            <a href="/admin/membership_orders?status=<?= $st ?><?= $typeFilter!=='all'?'&type='.$typeFilter:'' ?><?= $keyword?'&q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter===$st ? 'active' : '' ?>"><?= $st==='all'?'全部':['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款','expired'=>'已过期'][$st] ?? $st ?></a>
            <?php endforeach; ?>
        </div>
        <select name="type" onchange="this.form.submit()">
            <?php foreach ($allowedTypes as $tp): ?>
            <option value="<?= $tp ?>" <?= $typeFilter===$tp?'selected':'' ?>><?= $tp==='all'?'全部类型':membership_order_type_label($tp) ?></option>
            <?php endforeach; ?>
        </select>
        <input name="q" value="<?= e($keyword) ?>" placeholder="搜索订单号/套餐/用户...">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <?php if ($keyword !== '' || $statusFilter !== 'all' || $typeFilter !== 'all'): ?>
        <a href="/admin/membership_orders" class="btn btn-secondary btn-sm">重置</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:var(--text-muted);">共 <?= $total ?> 条</span>
    </form>

    <?php if (!$orders): ?>
        <div class="empty-state"><div class="icon">📦</div><h3>暂无会员订单</h3></div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($orders as $o):
                $s = (string) $o['status'];
                $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款','expired'=>'已过期'][$s] ?? $s;
                $sc = ['pending'=>'running','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted','expired'=>'deleted'][$s] ?? '';
                $ot = (string) $o['order_type'];
            ?>
            <div class="order-card">
                <div class="id">
                    <?= e($o['order_no']) ?>
                    <?php if ($o['trade_no']): ?><span>交易号: <?= e($o['trade_no']) ?></span><?php endif; ?>
                </div>
                <div class="user"><?= e($o['username'] ?? '-') ?></div>
                <div class="pkg"><?= e($o['package_name']) ?></div>
                <span class="type-tag <?= e($ot) ?>"><?= membership_order_type_label($ot) ?></span>
                <?php if ($o['upgrade_mode']): ?>
                <span style="font-size:10px;color:var(--text-muted);"><?= $o['upgrade_mode']==='immediate'?'立即生效':'延期生效' ?></span>
                <?php endif; ?>
                <div class="amt">¥<?= number_format((float) $o['amount'], 2) ?></div>
                <div class="status-col"><span class="status-badge <?= $sc ?>"><?= e($sl) ?></span></div>
                <div class="time"><?= e($o['paid_at'] ?: $o['created_at']) ?></div>
                <div style="display:flex;gap:4px;margin-left:auto;">
                    <select onchange="moDoStatus(<?= (int)$o['id'] ?>, this.value)" style="font-size:11px;padding:2px 4px;border:1px solid var(--line);border-radius:6px;background:var(--main-surface);">
                        <option value="">改状态</option>
                        <?php foreach(['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款','expired'=>'已过期'] as $sk=>$sv): ?>
                        <option value="<?= $sk ?>" <?= $s===$sk?'selected':'' ?>><?= $sv ?></option>
                        <?php endforeach; ?>
                    </select>
                    <form method="post" onsubmit="return confirm('确定删除该订单？')" style="display:contents;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="admin_action" value="delete">
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <button type="submit" style="font-size:11px;padding:2px 8px;border:1px solid var(--line);border-radius:6px;background:var(--main-surface);color:var(--danger);cursor:pointer;">删除</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <a class="page-btn <?= $page<=1?'active':'' ?>" href="<?= $page>1?'/admin/membership_orders?'.http_build_query($baseQuery+['page'=>$page-1]):'#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page>=$totalPages?'active':'' ?>" href="<?= $page<$totalPages?'/admin/membership_orders?'.http_build_query($baseQuery+['page'=>$page+1]):'#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>

<script>
(function() {
    var t = document.querySelector('input[name="csrf_token"]');
    window.moDoStatus = function(orderId, newStatus) {
        if (!newStatus) return;
        if (!confirm('确定将订单状态改为「' + (newStatus==='pending'?'待支付':newStatus==='paid'?'已支付':newStatus==='failed'?'失败':newStatus==='refunded'?'已退款':'已过期') + '」？')) return;
        var f = document.createElement('form');
        f.method = 'post';
        f.style.display = 'none';
        f.innerHTML = '<input name="csrf_token" value="' + (t?t.value:'') + '"><input name="admin_action" value="status"><input name="order_id" value="' + orderId + '"><input name="new_status" value="' + newStatus + '">';
        document.body.appendChild(f);
        f.submit();
    };
})();
</script>

<?php render_footer(); ?>
