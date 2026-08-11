<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login();
ensure_all_tables();

$balanceLabel = balance_label();
$userId = (int) $user['id'];

// ── 统计查询 ──
// 生成统计
$stmt = db()->prepare('SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS succeeded,
    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed,
    SUM(CASE WHEN status NOT IN (?,?) THEN 1 ELSE 0 END) AS processing,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
    SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS this_month
FROM generation_records WHERE user_id = ? AND deleted_at IS NULL');
$stmt->execute(['succeeded', 'failed', 'succeeded', 'failed', $userId]);
$genStats = $stmt->fetch();

// 本月消费
$stmt = db()->prepare('SELECT COALESCE(SUM(credits_charged), 0) AS month_cost FROM generation_records WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND deleted_at IS NULL');
$stmt->execute([$userId]);
$monthCost = (int) $stmt->fetchColumn();

// 订单统计
$stmt = db()->prepare('SELECT
    COUNT(*) AS total_orders,
    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS paid_orders,
    COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS total_spent
FROM orders WHERE user_id = ?');
$stmt->execute(['paid', 'paid', $userId]);
$orderStats = $stmt->fetch();

// 广场分享
$stmt = db()->prepare('SELECT COUNT(*) FROM gallery WHERE user_id = ?');
$stmt->execute([$userId]);
$galleryShares = (int) $stmt->fetchColumn();

// 最近生成记录 (5条)
$stmt = db()->prepare('SELECT id, status, mode, prompt, credits_charged, created_at FROM generation_records WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$userId]);
$recentRecords = $stmt->fetchAll();

// 最近订单 (5条)
$stmt = db()->prepare('SELECT id, order_no, package_name, amount, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$userId]);
$recentOrders = $stmt->fetchAll();

// 计算成功率
$genTotal   = (int)($genStats['total'] ?? 0);
$genSuccess = (int)($genStats['succeeded'] ?? 0);
$genFailed  = (int)($genStats['failed'] ?? 0);
$genProcess = (int)($genStats['processing'] ?? 0);
$genToday   = (int)($genStats['today'] ?? 0);
$genMonth   = (int)($genStats['this_month'] ?? 0);
$successRate = $genTotal > 0 ? round(($genSuccess / $genTotal) * 100, 1) : 0;

$orderTotal  = (int)($orderStats['total_orders'] ?? 0);
$orderPaid   = (int)($orderStats['paid_orders'] ?? 0);
$orderSpent  = (float)($orderStats['total_spent'] ?? 0);

// ── 签到状态 ──
$checkinEnabled = app_setting('checkin_enabled', '1') === '1';
$checkinData = null;
if ($checkinEnabled) {
    ensure_checkin_table();
    $today = date('Y-m-d');
    // 今日签到
    $stmt = db()->prepare('SELECT id, consecutive_days, reward_credits FROM checkin_records WHERE user_id = ? AND checkin_date = ? LIMIT 1');
    $stmt->execute([$userId, $today]);
    $todayCheckin = $stmt->fetch();
    // 最近签到（计算连续天数）
    $stmt = db()->prepare('SELECT checkin_date, consecutive_days, reward_credits FROM checkin_records WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1');
    $stmt->execute([$userId]);
    $lastCheckin = $stmt->fetch();
    $consecutive = 0;
    if ($lastCheckin) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($todayCheckin) {
            $consecutive = (int) $todayCheckin['consecutive_days'];
        } elseif ($lastCheckin['checkin_date'] === $yesterday || $lastCheckin['checkin_date'] === $today) {
            $consecutive = (int) $lastCheckin['consecutive_days'];
        }
    }
    $checkedIn = (bool) $todayCheckin;

    // 最近 7 天签到记录
    $stmt = db()->prepare('SELECT checkin_date, reward_credits FROM checkin_records WHERE user_id = ? AND checkin_date >= ? ORDER BY checkin_date ASC');
    $stmt->execute([$userId, date('Y-m-d', strtotime('-6 days'))]);
    $recentCheckins = [];
    foreach ($stmt->fetchAll() as $r) {
        $recentCheckins[$r['checkin_date']] = (int) $r['reward_credits'];
    }

    $checkinData = [
        'checked_in'  => $checkedIn,
        'consecutive' => $consecutive,
        'today_reward'=> $todayCheckin ? (int) $todayCheckin['reward_credits'] : 0,
        'recent'      => $recentCheckins,
    ];
}

render_header('仪表盘', 'dashboard');
?>

<style>
/* ── 仪表盘样式 ── */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
@media (max-width: 1024px) { .dashboard-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px)  { .dashboard-grid { grid-template-columns: 1fr; } }

.stat-card {
    background: var(--main-surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.2s;
}
.stat-card:hover {
    border-color: var(--primary-soft);
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    transform: translateY(-2px);
}
.stat-card .stat-icon {
    width: 40px; height: 40px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.stat-card .stat-label {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.stat-card .stat-value {
    font-size: 28px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.stat-card .stat-sub {
    font-size: 11px;
    color: var(--text-muted);
}

/* 快捷入口 */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
@media (max-width: 768px) { .quick-actions { grid-template-columns: repeat(2, 1fr); } }

.quick-action-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-radius: 14px;
    border: 1px solid var(--line);
    background: var(--main-surface);
    text-decoration: none;
    color: var(--text);
    font-weight: 700;
    font-size: 14px;
    transition: all 0.2s;
}
.quick-action-card:hover {
    border-color: var(--primary);
    background: var(--primary-soft);
    transform: translateY(-2px);
}
.quick-action-card .qa-icon {
    width: 42px; height: 42px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

/* 双栏布局 */
.dash-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 768px) { .dash-two-col { grid-template-columns: 1fr; } }

/* 活动条目 */
.activity-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    transition: background 0.15s;
}
.activity-item:hover { background: var(--main-surface-soft); }
.activity-item .act-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.activity-item .act-dot.success { background: #10b981; }
.activity-item .act-dot.failed  { background: #ef4444; }
.activity-item .act-dot.pending { background: #f59e0b; }
.activity-item .act-dot.paid    { background: #3b82f6; }
.activity-item .act-dot.processing { background: #8b5cf6; }
.activity-item .act-body { flex: 1; min-width: 0; }
.activity-item .act-title {
    font-size: 13px; font-weight: 600; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.activity-item .act-meta {
    font-size: 11px; color: var(--text-muted);
}
.activity-item .act-time {
    font-size: 10px; color: var(--text-muted);
    flex-shrink: 0; text-align: right;
}

.empty-activity {
    text-align: center;
    padding: 32px 16px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 600;
}

/* 签到按钮动画 */
.checkin-btn:active { transform: scale(0.95) !important; }
.checkin-btn.checkin-done { background: linear-gradient(135deg, #10b981, #059669) !important; pointer-events: none; }
</style>

<main>
    <!-- 欢迎横幅 -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <h1 style="font-size:24px;font-weight:800;color:var(--text);margin:0;">
                👋 欢迎回来，<?= e($user['username']) ?>
            </h1>
            <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted);">
                <?= date('Y年m月d日') ?> · 今天已生成 <?= $genToday ?> 次
            </p>
        </div>
        <a href="/user/index" style="padding:10px 20px;background:var(--primary,#3b82f6);color:#fff;border-radius:12px;text-decoration:none;font-size:14px;font-weight:700;transition:opacity .15s;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">🎨 开始创作</a>
    </div>

    <?php if ($checkinData): ?>
    <!-- 每日签到 -->
    <div class="checkin-widget" style="background:var(--main-surface);border:1px solid var(--line);border-radius:16px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;<?= $checkinData['checked_in'] ? 'background:#f0fdf4;' : 'background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(245,158,11,.06));' ?>">
                <?= $checkinData['checked_in'] ? '✅' : '📅' ?>
            </div>
            <div>
                <?php if ($checkinData['checked_in']): ?>
                    <div style="font-size:15px;font-weight:700;color:var(--text);">今日已签到 ✓</div>
                    <div style="font-size:12px;color:var(--text-muted);">获得 <strong style="color:#10b981;">+<?= $checkinData['today_reward'] ?></strong> <?= e($balanceLabel) ?> · 连续 <strong><?= $checkinData['consecutive'] ?></strong> 天</div>
                <?php else: ?>
                    <div style="font-size:15px;font-weight:700;color:var(--text);">每日签到领<?= e($balanceLabel) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);">
                        <?php if ($checkinData['consecutive'] > 0): ?>
                            已连续 <strong><?= $checkinData['consecutive'] ?></strong> 天，继续加油！
                        <?php else: ?>
                            每天签到积累奖励
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <!-- 7 天日历条 -->
            <div style="display:flex;gap:4px;">
                <?php for ($d = 6; $d >= 0; $d--):
                    $dt = date('Y-m-d', strtotime("-{$d} days"));
                    $isChecked = isset($checkinData['recent'][$dt]);
                    $isToday = $dt === date('Y-m-d');
                ?>
                <div style="width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;
                    <?= $isChecked ? 'background:#10b981;color:#fff;' : ($isToday ? 'background:var(--main-surface-soft,#f1f5f9);color:var(--text-muted);border:2px solid #f59e0b;' : 'background:var(--main-surface-soft,#f1f5f9);color:var(--text-muted);') ?>"
                    title="<?= $dt ?>"><?= date('j', strtotime($dt)) ?></div>
                <?php endfor; ?>
            </div>
            <?php if (!$checkinData['checked_in']): ?>
                <button id="checkinBtn" class="checkin-btn" onclick="doCheckin()"
                    style="padding:10px 22px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;"
                    onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'"
                >🎁 签到</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 核心指标卡片 -->
    <div class="dashboard-grid">
        <!-- 点数余额 -->
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(139,92,246,.06));">💰</div>
            <div class="stat-label">当前<?= e($balanceLabel) ?></div>
            <div class="stat-value"><?= number_format((int) $user['credits']) ?></div>
            <div class="stat-sub">
                <?php if ($monthCost > 0): ?>
                    本月消耗 <?= number_format($monthCost) ?>
                <?php else: ?>
                    本月暂无消耗
                <?php endif; ?>
            </div>
        </div>

        <!-- 生成统计 -->
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(59,130,246,.06));">🖼️</div>
            <div class="stat-label">生成统计</div>
            <div class="stat-value"><?= number_format($genMonth) ?></div>
            <div class="stat-sub">本月生成 · 总计 <?= number_format($genTotal) ?> · 成功率 <?= $successRate ?>%</div>
        </div>

        <!-- 订单统计 -->
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(16,185,129,.06));">📦</div>
            <div class="stat-label">累计消费</div>
            <div class="stat-value">¥<?= number_format($orderSpent, 2) ?></div>
            <div class="stat-sub"><?= $orderPaid ?> 笔已支付 / 共 <?= $orderTotal ?> 笔订单</div>
        </div>

        <!-- 广场分享 -->
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(245,158,11,.06));">🌟</div>
            <div class="stat-label">广场分享</div>
            <div class="stat-value"><?= number_format($galleryShares) ?></div>
            <div class="stat-sub">
                <?php if ($galleryShares > 0): ?>
                    你的作品正在被社区浏览
                <?php else: ?>
                    分享作品让更多人看到
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 快捷入口 -->
    <div class="quick-actions">
        <a href="/user/index" class="quick-action-card">
            <div class="qa-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;">🎨</div>
            <div>
                <div style="font-size:14px;font-weight:700;">图片生成</div>
                <div style="font-size:11px;color:var(--text-muted);">AI 绘画 & 编辑</div>
            </div>
        </a>
        <a href="/user/video" class="quick-action-card">
            <div class="qa-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);color:#fff;">🎬</div>
            <div>
                <div style="font-size:14px;font-weight:700;">视频生成</div>
                <div style="font-size:11px;color:var(--text-muted);">AI 视频创作</div>
            </div>
        </a>
        <a href="/user/shop" class="quick-action-card">
            <div class="qa-icon" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;">🛒</div>
            <div>
                <div style="font-size:14px;font-weight:700;">点数商城</div>
                <div style="font-size:11px;color:var(--text-muted);">充值购买套餐</div>
            </div>
        </a>
        <a href="/user/gallery" class="quick-action-card">
            <div class="qa-icon" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;">🖼️</div>
            <div>
                <div style="font-size:14px;font-weight:700;">图片广场</div>
                <div style="font-size:11px;color:var(--text-muted);">浏览社区作品</div>
            </div>
        </a>
    </div>

    <!-- 双栏：最近活动 -->
    <div class="dash-two-col">
        <!-- 最近生成记录 -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>最近生成</h3>
                    <p class="sub">Recent Generations</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/records">查看全部</a>
            </div>
            <div class="card-v3-body" style="padding:8px 12px;">
                <?php if ($recentRecords): ?>
                    <?php foreach ($recentRecords as $r):
                        $statusLabel = ['succeeded' => '成功', 'failed' => '失败', 'pending' => '排队中', 'running' => '处理中', 'queued' => '排队中'][$r['status']] ?? $r['status'];
                        $statusDot = ['succeeded' => 'success', 'failed' => 'failed', 'pending' => 'pending', 'running' => 'processing', 'queued' => 'pending'][$r['status']] ?? 'pending';
                        $modeLabel = ['draw' => '绘画', 'edit' => '编辑', 'video' => '视频'][$r['mode']] ?? $r['mode'];
                    ?>
                    <div class="activity-item">
                        <div class="act-dot <?= $statusDot ?>"></div>
                        <div class="act-body">
                            <div class="act-title"><?= e($r['prompt'] ?: '(无提示词)') ?></div>
                            <div class="act-meta">
                                <?= $modeLabel ?> · <?= $statusLabel ?>
                                <?php if ((int)($r['credits_charged'] ?? 0) > 0): ?>
                                    · -<?= (int)$r['credits_charged'] ?> <?= e($balanceLabel) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="act-time"><?= e(substr($r['created_at'], 5)) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-activity">还没有生成记录，点右上角「开始创作」吧</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- 最近订单 -->
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>最近订单</h3>
                    <p class="sub">Recent Orders</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/credits">使用记录</a>
            </div>
            <div class="card-v3-body" style="padding:8px 12px;">
                <?php if ($recentOrders): ?>
                    <?php foreach ($recentOrders as $o):
                        $oStatusLabel = ['pending' => '待支付', 'paid' => '已支付', 'failed' => '失败', 'refunded' => '已退款'][$o['status']] ?? $o['status'];
                        $oStatusDot = ['pending' => 'pending', 'paid' => 'paid', 'failed' => 'failed', 'refunded' => 'failed'][$o['status']] ?? 'pending';
                    ?>
                    <div class="activity-item">
                        <div class="act-dot <?= $oStatusDot ?>"></div>
                        <div class="act-body">
                            <div class="act-title"><?= e($o['package_name']) ?></div>
                            <div class="act-meta"><?= e(substr($o['order_no'], 0, 16)) ?>... · <?= $oStatusLabel ?></div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div style="font-weight:700;font-size:13px;">¥<?= number_format((float)$o['amount'], 2) ?></div>
                            <div style="font-size:10px;color:var(--text-muted);"><?= e(substr($o['created_at'], 5)) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-activity">还没有订单记录</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php if ($checkinData && !$checkinData['checked_in']): ?>
<script>
async function doCheckin() {
    var btn = document.getElementById('checkinBtn');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '签到中...';
    try {
        var res = await fetch('/api/checkin', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'do'})
        });
        var data = await res.json();
        if (data.ok) {
            btn.textContent = '✅ 已签到 +' + data.data.reward;
            btn.classList.add('checkin-done');
            // 2 秒后刷新页面更新统计数据
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            btn.textContent = data.message || '签到失败';
            btn.style.background = '#ef4444';
            setTimeout(function() {
                btn.textContent = '🎁 签到';
                btn.style.background = '';
                btn.disabled = false;
            }, 2000);
        }
    } catch (e) {
        btn.textContent = '网络错误';
        btn.style.background = '#ef4444';
        setTimeout(function() {
            btn.textContent = '🎁 签到';
            btn.style.background = '';
            btn.disabled = false;
        }, 2000);
    }
}
</script>
<?php endif; ?>
<?php render_footer(); ?>
