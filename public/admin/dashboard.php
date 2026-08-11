<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

require_admin();
ensure_gallery_table();

// ── 统计数据 ──
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// 用户统计
$stmt = db()->query('SELECT COUNT(*) AS total FROM users');
$totalUsers = (int) $stmt->fetch()['total'];
$stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?');
$stmt->execute([$today]);
$todayUsers = (int) $stmt->fetchColumn();
$stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
$stmt->execute([$monthStart]);
$monthUsers = (int) $stmt->fetchColumn();

// 生成统计
$stmt = db()->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
    SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) AS failed
FROM generation_records WHERE deleted_at IS NULL");
$gen = $stmt->fetch();
$totalGen = (int)($gen['total'] ?? 0);
$genSuccess = (int)($gen['succeeded'] ?? 0);
$genFailed = (int)($gen['failed'] ?? 0);
$stmt = db()->prepare('SELECT COUNT(*) FROM generation_records WHERE DATE(created_at) = ? AND deleted_at IS NULL');
$stmt->execute([$today]);
$todayGen = (int) $stmt->fetchColumn();
$stmt = db()->prepare('SELECT COUNT(*) FROM generation_records WHERE DATE(created_at) = ? AND status = ? AND deleted_at IS NULL');
$stmt->execute([$today, 'succeeded']);
$todayGenSuccess = (int) $stmt->fetchColumn();

// 订单统计
$stmt = db()->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS revenue
FROM orders");
$ord = $stmt->fetch();
$totalOrders = (int)($ord['total'] ?? 0);
$paidOrders = (int)($ord['paid'] ?? 0);
$totalRevenue = (float)($ord['revenue'] ?? 0);
$stmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?');
$stmt->execute([$today]);
$todayOrders = (int) $stmt->fetchColumn();
$stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = ? AND DATE(paid_at) = ?');
$stmt->execute(['paid', $today]);
$todayRevenue = (float) $stmt->fetchColumn();

// 签到统计
ensure_checkin_table();
$stmt = db()->prepare('SELECT COUNT(*) FROM checkin_records WHERE checkin_date = ?');
$stmt->execute([$today]);
$todayCheckins = (int) $stmt->fetchColumn();
$stmt = db()->query('SELECT COUNT(DISTINCT user_id) AS users FROM checkin_records');
$totalCheckinUsers = (int) $stmt->fetch()['users'];

// 广场统计
$stmt = db()->query('SELECT COUNT(*) FROM gallery');
$galleryTotal = (int) $stmt->fetchColumn();

// 兑换码统计
$stmt = db()->query('SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_active = 1 AND used_count < max_uses THEN 1 ELSE 0 END), 0) AS unused FROM credit_codes');
$codeStats = $stmt->fetch();

// 最近订单 (5条)
$stmt = db()->query('SELECT order_no, package_name, amount, status, created_at FROM orders ORDER BY created_at DESC LIMIT 8');
$recentOrders = $stmt->fetchAll();

// 最近注册 (5条)
$stmt = db()->query('SELECT username, email, created_at FROM users ORDER BY created_at DESC LIMIT 5');
$recentUsers = $stmt->fetchAll();

$balanceLabel = balance_label();

render_header('仪表盘', 'admin');
render_admin_nav('dashboard');
?>

<main>
    <div class="page-hd" style="margin-bottom:18px;">
        <div>
            <h1>管理仪表盘</h1>
            <p><?= date('Y年m月d日') ?> · 数据概览</p>
        </div>
    </div>

    <!-- 核心指标 -->
    <div class="stats-grid">
        <div class="stat-card">
            <span>👥 总用户</span>
            <strong><?= number_format($totalUsers) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">今日 +<?= $todayUsers ?> · 本月 +<?= $monthUsers ?></div>
        </div>
        <div class="stat-card">
            <span>🖼️ 总生成</span>
            <strong><?= number_format($totalGen) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">成功 <?= $genSuccess ?> · 失败 <?= $genFailed ?></div>
        </div>
        <div class="stat-card">
            <span>📦 总订单</span>
            <strong>¥<?= number_format($totalRevenue, 2) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= $paidOrders ?> 笔已支付 / <?= $totalOrders ?> 笔</div>
        </div>
        <div class="stat-card">
            <span>📅 今日签到</span>
            <strong><?= number_format($todayCheckins) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">共 <?= $totalCheckinUsers ?> 人参与过</div>
        </div>
        <div class="stat-card">
            <span>🖼️ 今日生成</span>
            <strong><?= number_format($todayGen) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">成功 <?= $todayGenSuccess ?> 次</div>
        </div>
        <div class="stat-card">
            <span>💳 今日收入</span>
            <strong>¥<?= number_format($todayRevenue, 2) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= $todayOrders ?> 笔订单</div>
        </div>
        <div class="stat-card">
            <span>🌟 广场作品</span>
            <strong><?= number_format($galleryTotal) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">社区公开分享</div>
        </div>
        <div class="stat-card">
            <span>🎫 未用兑换码</span>
            <strong><?= number_format((int)($codeStats['unused'] ?? 0)) ?></strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">共 <?= number_format((int)($codeStats['total'] ?? 0)) ?> 个</div>
        </div>
    </div>

    <!-- 双栏详情 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <!-- 最近订单 -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>最近订单</h3>
                    <p class="sub">Recent Orders</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/admin/orders">全部订单</a>
            </div>
            <div class="card-v3-body" style="padding:4px 8px;">
                <?php if ($recentOrders): ?>
                    <?php foreach ($recentOrders as $o):
                        $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款'][$o['status']] ?? $o['status'];
                        $sc = ['pending'=>'pending','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted'][$o['status']] ?? '';
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;font-size:13px;" onmouseover="this.style.background='var(--main-surface-soft)'" onmouseout="this.style.background=''">
                        <span class="status-badge <?= $sc ?>"><?= $sl ?></span>
                        <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= e($o['package_name']) ?></span>
                        <span style="font-weight:700;">¥<?= number_format((float)$o['amount'], 2) ?></span>
                        <span style="font-size:11px;color:var(--text-muted);"><?= e(substr($o['created_at'], 5)) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center;padding:32px;color:var(--text-muted);font-size:13px;">暂无订单</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- 最近注册 -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>最近注册</h3>
                    <p class="sub">New Users</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/admin/users">用户管理</a>
            </div>
            <div class="card-v3-body" style="padding:4px 8px;">
                <?php if ($recentUsers): ?>
                    <?php foreach ($recentUsers as $u): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;font-size:13px;" onmouseover="this.style.background='var(--main-surface-soft)'" onmouseout="this.style.background=''">
                        <div style="width:32px;height:32px;border-radius:8px;background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--primary);flex-shrink:0;"><?= e(mb_substr($u['username'], 0, 1)) ?></div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:600;"><?= e($u['username']) ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= e($u['email'] ?: '-') ?></div>
                        </div>
                        <span style="font-size:11px;color:var(--text-muted);"><?= e(substr($u['created_at'], 5)) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center;padding:32px;color:var(--text-muted);font-size:13px;">暂无用户</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php render_footer(); ?>
