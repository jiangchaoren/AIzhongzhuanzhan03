<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/pay.php';
require_once __DIR__ . '/../../src/membership.php';

$user = require_login();
ensure_all_tables();

// ── 检查会员过期 & 余额重置 ──
membership_check_expiry((int) $user['id']);
membership_check_and_reset_balance((int) $user['id'], (int) $user['credits']);

// 重新获取最新用户数据
$user = current_user();

// ── 获取有效会员 ──
$membership = user_active_membership((int) $user['id']);

// ── 获取套餐 ──
$packages = active_membership_packages();

// ── 判断操作类型 ──
$hasActiveMembership = $membership !== null;
$daysRemaining = $hasActiveMembership ? membership_days_remaining($membership) : 0;

// ── 订单历史 ──
$orders = membership_user_orders((int) $user['id'], 10);

// ── 余额变更日志 ──
$balanceLogs = membership_balance_logs((int) $user['id'], 20);

$payTypes = pay_enabled_type_list();
$isPayConfigured = pay_is_configured();
$balanceLabel = balance_label();

render_header('会员中心', 'membership');
?>
<style>
.member-hero { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #0f172a 100%); border-radius: 20px; padding: 32px; color: #fff; margin-bottom: 24px; position: relative; overflow: hidden; }
.member-hero::before { content: ''; position: absolute; top: -40%; right: -20%; width: 60%; height: 200%; background: radial-gradient(circle, rgba(139,92,246,.2) 0%, transparent 70%); pointer-events: none; }
.member-hero::after { content: ''; position: absolute; bottom: -30%; left: -10%; width: 50%; height: 150%; background: radial-gradient(circle, rgba(59,130,246,.15) 0%, transparent 70%); pointer-events: none; }
.member-hero .inner { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 24px; align-items: center; justify-content: space-between; }
.member-hero .status-area { }
.member-hero .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: .5px; margin-bottom: 12px; }
.member-hero .badge.active { background: rgba(34,197,94,.2); color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
.member-hero .badge.expired { background: rgba(239,68,68,.2); color: #f87171; border: 1px solid rgba(239,68,68,.3); }
.member-hero .badge.none { background: rgba(148,163,184,.2); color: #94a3b8; border: 1px solid rgba(148,163,184,.3); }
.member-hero h2 { font-size: 28px; font-weight: 800; margin: 0 0 4px; }
.member-hero .pkg-name { font-size: 15px; opacity: .85; margin-bottom: 8px; }
.member-hero .expiry { font-size: 13px; opacity: .7; }
.member-hero .expiry strong { color: #fbbf24; }
.member-hero .balance-area { text-align: right; }
.member-hero .balance-area .lbl { font-size: 11px; opacity: .6; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
.member-hero .balance-area .val { font-size: 36px; font-weight: 800; }
.member-hero .balance-area .sub { font-size: 11px; opacity: .5; }
.member-hero .quota-row { display: flex; gap: 16px; margin-top: 8px; }
.member-hero .quota-row .q-item { text-align: center; padding: 6px 14px; background: rgba(255,255,255,.08); border-radius: 10px; }
.member-hero .quota-row .q-item .qv { font-size: 18px; font-weight: 700; color: #93c5fd; }
.member-hero .quota-row .q-item .ql { font-size: 9px; opacity: .6; margin-top: 2px; }

/* 无会员引导区 */
.no-member { text-align: center; padding: 48px 20px; }
.no-member .icon { font-size: 48px; margin-bottom: 12px; }
.no-member h3 { font-size: 20px; margin: 0 0 8px; }
.no-member p { color: var(--text-soft); font-size: 14px; max-width: 420px; margin: 0 auto 24px; }

/* 套餐卡片 */
.pricing-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 28px; }
.pricing-card { position: relative; background: var(--main-surface); border: 2px solid var(--line); border-radius: 20px; padding: 28px 24px 24px; text-align: center; transition: all .2s; }
.pricing-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,.08); }
.pricing-card.current { border-color: #8b5cf6; background: linear-gradient(180deg, rgba(139,92,246,.04) 0%, var(--main-surface) 15%); }
.pricing-card.current::after { content: '当前套餐'; position: absolute; top: -1px; left: 50%; transform: translateX(-50%); background: #8b5cf6; color: #fff; font-size: 10px; font-weight: 700; padding: 3px 16px; border-radius: 0 0 10px 10px; }
.pricing-card .pkg-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.pricing-card .pkg-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 12px; min-height: 18px; }
.pricing-card .pkg-duration { display: inline-flex; padding: 4px 12px; border-radius: 8px; background: var(--primary-soft, #eff6ff); color: var(--primary); font-size: 12px; font-weight: 600; margin-bottom: 14px; }
.pricing-card .pkg-price { font-size: 36px; font-weight: 800; margin-bottom: 12px; }
.pricing-card .pkg-price sup { font-size: 18px; font-weight: 600; }
.pricing-card .pkg-quotas { display: flex; justify-content: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.pricing-card .pkg-quotas .qi { text-align: center; padding: 4px 10px; background: var(--main-surface-soft); border-radius: 8px; }
.pricing-card .pkg-quotas .qi .qv { font-size: 14px; font-weight: 700; color: var(--primary); }
.pricing-card .pkg-quotas .qi .ql { font-size: 9px; color: var(--text-muted); }
.pricing-card .pkg-action { display: flex; flex-direction: column; gap: 8px; }
.pricing-card .pkg-action .btn { width: 100%; padding: 12px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; border: none; transition: all .15s; }
.pricing-card .pkg-action .btn.primary { background: #111; color: #fff; }
.pricing-card .pkg-action .btn.primary:hover { background: #333; }
.pricing-card .pkg-action .btn.purple { background: #8b5cf6; color: #fff; }
.pricing-card .pkg-action .btn.purple:hover { background: #7c3aed; }
.pricing-card .pkg-action .btn.outline { background: transparent; border: 1.5px solid var(--line); color: var(--text); }
.pricing-card .pkg-action .btn:disabled { opacity: .4; cursor: not-allowed; }
.pricing-card .pkg-action .upgrade-mode { display: flex; gap: 6px; }
.pricing-card .pkg-action .upgrade-mode label { flex: 1; padding: 6px; border: 1px solid var(--line); border-radius: 8px; font-size: 10px; cursor: pointer; text-align: center; transition: all .1s; color: var(--text-muted); }
.pricing-card .pkg-action .upgrade-mode input { display: none; }
.pricing-card .pkg-action .upgrade-mode input:checked + span { color: var(--primary); border-color: var(--primary); background: var(--primary-soft); display: block; padding: 6px; border-radius: 8px; }
.upgrade-mode input:checked + span { color: var(--primary); border-color: var(--primary); background: var(--primary-soft); }

/* 加量包 */
.topup-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 28px; }
.topup-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; text-align: center; transition: all .15s; display: flex; flex-direction: column; }
.topup-card:hover { border-color: #f59e0b; box-shadow: 0 4px 12px rgba(245,158,11,.08); }
.topup-card .topup-credits { font-size: 24px; font-weight: 800; color: #f59e0b; }
.topup-card .topup-name { font-size: 14px; font-weight: 600; margin: 4px 0; }
.topup-card .topup-price { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
.topup-card .topup-btn { margin-top: auto; width: 100%; padding: 10px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #f59e0b; background: transparent; color: #f59e0b; cursor: pointer; transition: all .15s; }
.topup-card .topup-btn:hover { background: #f59e0b; color: #fff; }

/* Section 标题 */
.section-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.section-hd h3 { font-size: 18px; margin: 0; }

/* 订单列表 */
.order-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px; }
.order-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; font-size: 13px; transition: all .1s; }
.order-item:hover { border-color: var(--primary-soft); }
.order-item .oid { font-family: monospace; font-size: 11px; color: var(--text-muted); min-width: 140px; word-break: break-all; }
.order-item .pkg { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-item .type-tag { font-size: 10px; padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.order-item .type-tag.new { background: #dcfce7; color: #16a34a; }
.order-item .type-tag.renew { background: #dbeafe; color: #2563eb; }
.order-item .type-tag.upgrade { background: #ede9fe; color: #7c3aed; }
.order-item .type-tag.topup { background: #fef3c7; color: #d97706; }
.order-item .amt { font-weight: 700; min-width: 70px; text-align: right; }
.order-item .time { font-size: 11px; color: var(--text-muted); min-width: 90px; text-align: right; }

/* 日志表格 */
.log-table-wrap { overflow-x: auto; margin-bottom: 24px; }
.log-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.log-table th { text-align: left; padding: 8px 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); border-bottom: 1px solid var(--line); }
.log-table td { padding: 8px 12px; border-bottom: 1px solid var(--line); }
.log-table .add { color: #16a34a; font-weight: 600; }
.log-table .sub { color: #ef4444; font-weight: 600; }

/* 购买弹窗 */
.buy-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 999; align-items: center; justify-content: center; }
.buy-overlay.show { display: flex; }
.buy-panel { background: var(--main-surface); border-radius: 16px; width: 90vw; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.buy-panel .head { padding: 20px 24px 0; display: flex; justify-content: space-between; align-items: center; }
.buy-panel .head h3 { margin: 0; font-size: 18px; }
.buy-panel .body { padding: 16px 24px 24px; }
.buy-summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; border-bottom: 1px solid var(--line); }
.buy-summary-row.total { border-bottom: none; font-size: 16px; font-weight: 700; padding-top: 12px; }
.buy-summary-row.total strong { color: var(--primary); }
.pay-type-grid { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.pay-type-item { padding: 8px 14px; border: 1px solid var(--line); border-radius: 10px; font-size: 12px; cursor: pointer; transition: all .1s; }
.pay-type-item:has(input:checked) { border-color: var(--primary); background: var(--primary-soft); color: var(--primary); }
.pay-type-item input { display: none; }

@media (max-width: 768px) {
    .member-hero .inner { flex-direction: column; }
    .member-hero .balance-area { text-align: left; }
    .pricing-row { grid-template-columns: 1fr; }
    .topup-row { grid-template-columns: 1fr 1fr; }
    .order-item { flex-wrap: wrap; gap: 4px 8px; padding: 10px 12px; }
    .order-item .oid { min-width: 0; width: 100%; font-size: 10px; }
}
</style>
<main>
    <!-- ── 会员状态横幅 ── -->
    <?php if ($hasActiveMembership): ?>
    <?php
    // ── 加载套餐模型和配额信息 ──
    $pkgModelIds = membership_package_model_ids($membership);
    $pkgModels = [];
    if ($pkgModelIds) {
        $placeholders = implode(',', array_fill(0, count($pkgModelIds), '?'));
        $stmt = db()->prepare("SELECT id, name, model_type FROM ai_models WHERE id IN ({$placeholders}) AND is_active = 1");
        $stmt->execute($pkgModelIds);
        $pkgModels = $stmt->fetchAll();
    }
    ?>
    <div class="member-hero">
        <div class="inner">
            <div class="status-area">
                <span class="badge active"><?= $membership['status'] === 'pending_upgrade' ? '待升级' : '会员有效' ?></span>
                <h2><?= e($membership['package_name']) ?></h2>
                <div class="pkg-name">
                    <?= (int) $membership['duration_value'] ?> <?= membership_duration_label($membership['duration_unit']) ?> 套餐
                </div>
                <div class="expiry">
                    有效期至 <strong><?= e($membership['expires_at']) ?></strong> · 剩余 <strong><?= $daysRemaining ?></strong> 天
                </div>
                <?php if ($membership['status'] === 'pending_upgrade' && $membership['upgrade_mode'] === 'deferred'): ?>
                <div style="margin-top:8px;font-size:12px;background:rgba(139,92,246,.2);padding:6px 12px;border-radius:8px;color:#c4b5fd;">
                    ⏳ 到期后将自动升级到新套餐
                </div>
                <?php endif; ?>
            </div>
            <div class="balance-area">
                <div class="lbl">当前<?= e($balanceLabel) ?></div>
                <div class="val"><?= number_format((int) $user['credits']) ?></div>
                <?php if ($pkgModels): ?>
                <div style="margin-top:12px;">
                    <div class="lbl" style="margin-bottom:6px;">📊 模型配额</div>
                    <?php foreach ($pkgModels as $pm):
                        $quota = membership_model_quota((int) $user['id'], (int) $pm['id']);
                        $used = $quota['used'] ?? 0;
                        $limit = $quota['limit'] ?? 0;
                        $pct = $limit > 0 ? round($used / $limit * 100) : 0;
                        $barColor = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#4ade80');
                    ?>
                    <div style="margin-bottom:6px;">
                        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">
                            <span><?= e($pm['name']) ?> <small style="opacity:.5">[<?= $pm['model_type'] === 'video' ? '视频' : '图片' ?>]</small></span>
                            <span><?= $used ?> / <?= $limit > 0 ? $limit : '∞' ?></span>
                        </div>
                        <?php if ($limit > 0): ?>
                        <div style="height:4px;background:rgba(255,255,255,.1);border-radius:2px;overflow:hidden;">
                            <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:2px;transition:width .3s;"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="member-hero" style="background:linear-gradient(135deg,#334155,#475569);">
        <div class="inner">
            <div class="status-area" style="text-align:center;width:100%;">
                <span class="badge none">未开通会员</span>
                <h2>开通会员，畅享无限创作</h2>
                <div class="pkg-name">选择下方套餐，立即开启会员之旅</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── 会员套餐 ── -->
    <?php if ($packages): ?>
    <div class="section-hd">
        <h3><?= $hasActiveMembership ? '续费 / 升级套餐' : '选择会员套餐' ?></h3>
    </div>
    <div class="pricing-row">
        <?php foreach ($packages as $pkg):
            $pid = (int) $pkg['id'];
            $isCurrent = $hasActiveMembership && (int) $membership['package_id'] === $pid;
            $isUpgrade = $hasActiveMembership && !$isCurrent;
        ?>
        <div class="pricing-card <?= $isCurrent ? 'current' : '' ?>">
            <div class="pkg-name"><?= e($pkg['name']) ?></div>
            <div class="pkg-desc"><?= e($pkg['description'] ?: '&nbsp;') ?></div>
            <div class="pkg-duration">
                ⏱ <?= (int)$pkg['duration_value'] ?> <?= membership_duration_label($pkg['duration_unit']) ?>
            </div>
            <div class="pkg-price">
                <sup>¥</sup><?= number_format((float)$pkg['price'], 2) ?>
            </div>
            <div class="pkg-quotas">
                <?php if ((int)$pkg['daily_quota'] > 0): ?>
                <div class="qi"><div class="qv"><?= number_format((int)$pkg['daily_quota']) ?></div><div class="ql">日配额</div></div>
                <?php endif; ?>
                <?php if ((int)$pkg['monthly_quota'] > 0): ?>
                <div class="qi"><div class="qv"><?= number_format((int)$pkg['monthly_quota']) ?></div><div class="ql">月配额</div></div>
                <?php endif; ?>
                <?php if ((int)$pkg['yearly_quota'] > 0): ?>
                <div class="qi"><div class="qv"><?= number_format((int)$pkg['yearly_quota']) ?></div><div class="ql">年配额</div></div>
                <?php endif; ?>
            </div>
            <div class="pkg-action">
                <?php if (!$isPayConfigured): ?>
                    <button class="btn outline" disabled>暂不可用</button>
                <?php elseif ($isCurrent): ?>
                    <button class="btn purple" data-open-buy data-package-id="<?= $pid ?>" data-package-name="<?= e($pkg['name']) ?>" data-package-price="<?= number_format((float)$pkg['price'], 2) ?>" data-package-action="renew">🔄 续费</button>
                <?php elseif ($isUpgrade): ?>
                    <div class="upgrade-mode" data-upgrade-mode-group="<?= $pid ?>">
                        <label><input type="radio" name="upgrade_mode_<?= $pid ?>" value="immediate" checked><span>⚡ 立即生效</span></label>
                        <label><input type="radio" name="upgrade_mode_<?= $pid ?>" value="deferred"><span>⏳ 到期后生效</span></label>
                    </div>
                    <button class="btn purple" data-open-buy data-package-id="<?= $pid ?>" data-package-name="<?= e($pkg['name']) ?>" data-package-price="<?= number_format((float)$pkg['price'], 2) ?>" data-package-action="upgrade" data-upgrade-mode-group="<?= $pid ?>">⬆️ 升级到此套餐</button>
                <?php else: ?>
                    <button class="btn primary" data-open-buy data-package-id="<?= $pid ?>" data-package-name="<?= e($pkg['name']) ?>" data-package-price="<?= number_format((float)$pkg['price'], 2) ?>" data-package-action="new">立即开通</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted);">暂无可用套餐，请联系管理员</div>
    <?php endif; ?>

    <!-- ── 订单历史 ── -->
    <?php if ($orders): ?>
    <div class="section-hd"><h3>📋 订单记录</h3></div>
    <div class="order-list">
        <?php foreach ($orders as $o):
            $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款'][$o['status']] ?? $o['status'];
            $sc = ['pending'=>'running','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted'][$o['status']] ?? '';
            $ot = (string) $o['order_type'];
        ?>
        <div class="order-item">
            <span class="oid"><?= e($o['order_no']) ?></span>
            <span class="pkg"><?= e($o['package_name']) ?></span>
            <span class="type-tag <?= e($ot) ?>"><?= membership_order_type_label($ot) ?></span>
            <span class="status-badge <?= $sc ?>"><?= e($sl) ?></span>
            <span class="amt">¥<?= number_format((float) $o['amount'], 2) ?></span>
            <span class="time"><?= e($o['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── 余额变更日志 ── -->
    <?php if ($balanceLogs): ?>
    <div class="section-hd"><h3>📊 余额变更记录</h3></div>
    <div class="log-table-wrap">
        <table class="log-table">
            <thead>
                <tr><th>时间</th><th>类型</th><th>变更前</th><th>变动</th><th>变更后</th></tr>
            </thead>
            <tbody>
                <?php foreach ($balanceLogs as $log): ?>
                <tr>
                    <td style="font-size:12px;color:var(--text-muted);"><?= e($log['reset_at']) ?></td>
                    <td style="font-size:12px;"><?= membership_reset_type_label($log['reset_type']) ?></td>
                    <td><?= number_format((int)$log['credits_before']) ?></td>
                    <td class="<?= (int)$log['credits_added'] >= 0 ? 'add' : 'sub' ?>">
                        <?= (int)$log['credits_added'] >= 0 ? '+' : '' ?><?= number_format((int)$log['credits_added']) ?>
                    </td>
                    <td style="font-weight:600;"><?= number_format((int)$log['credits_after']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- 购买弹窗 -->
<div id="buyDialog" class="buy-overlay" onclick="if(event.target===this)closeBuy()">
    <div class="buy-panel">
        <div class="head">
            <h3 id="buyTitle">确认购买</h3>
            <button class="modal-close" onclick="closeBuy()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text-muted);line-height:1;">&times;</button>
        </div>
        <div class="body">
            <div id="buySummary">
                <div class="buy-summary-row"><span>套餐</span><strong id="buyPkgName">-</strong></div>
                <div class="buy-summary-row"><span>类型</span><strong id="buyActionLabel">-</strong></div>
                <div class="buy-summary-row total"><span>支付金额</span><strong id="buyPkgPrice">-</strong></div>
            </div>
            <div style="margin-top:12px;">
                <span style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:6px;">支付方式</span>
                <div class="pay-type-grid">
                    <?php $firstPayType = ''; ?>
                    <?php foreach ($payTypes as $ptKey => $ptLabel): ?>
                        <?php if ($firstPayType === '') $firstPayType = $ptKey; ?>
                        <label class="pay-type-item">
                            <input type="radio" name="pay_type" value="<?= e($ptKey) ?>" <?= $firstPayType === $ptKey ? 'checked' : '' ?>>
                            <?= e($ptLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <form id="buyForm" method="post" action="/membership_order">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="buyPkgId" value="">
                <input type="hidden" name="action" id="buyAction" value="">
                <input type="hidden" name="upgrade_mode" id="buyUpgradeMode" value="immediate">
                <input type="hidden" name="pay_type" id="buyPayType" value="<?= e($firstPayType) ?>">
                <button class="btn" type="submit" style="width:100%;margin-top:12px;padding:12px;border-radius:12px;font-weight:700;font-size:14px;background:#111;color:#fff;border:none;cursor:pointer;">确认支付</button>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var dlg = document.getElementById('buyDialog');
    var pkgId = document.getElementById('buyPkgId');
    var pkgName = document.getElementById('buyPkgName');
    var pkgPrice = document.getElementById('buyPkgPrice');
    var action = document.getElementById('buyAction');
    var actionLabel = document.getElementById('buyActionLabel');
    var upgradeMode = document.getElementById('buyUpgradeMode');
    var payType = document.getElementById('buyPayType');
    var title = document.getElementById('buyTitle');

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-open-buy]');
        if (!btn) return;

        var pAction = btn.dataset.packageAction;
        pkgId.value = btn.dataset.packageId;
        pkgName.textContent = btn.dataset.packageName;
        pkgPrice.textContent = '¥' + btn.dataset.packagePrice;
        action.value = pAction;

        var labels = {new: '新开会员', renew: '续费', upgrade: '升级套餐'};
        actionLabel.textContent = labels[pAction] || pAction;
        title.textContent = {
            new: '开通会员', renew: '确认续费', upgrade: '确认升级'
        }[pAction] || '确认购买';

        // 升级模式：读取 radio
        if (pAction === 'upgrade') {
            var group = btn.dataset.upgradeModeGroup;
            var checked = document.querySelector('input[name="upgrade_mode_' + group + '"]:checked');
            upgradeMode.value = checked ? checked.value : 'immediate';
        } else {
            upgradeMode.value = '';
        }

        dlg.classList.add('show');
    });

    // 支付方式切换
    document.querySelectorAll('input[name="pay_type"]').forEach(function(r) {
        r.addEventListener('change', function() { payType.value = this.value; });
    });

    window.closeBuy = function() {
        dlg.classList.remove('show');
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeBuy();
    });
})();
</script>

<?php render_footer(); ?>
