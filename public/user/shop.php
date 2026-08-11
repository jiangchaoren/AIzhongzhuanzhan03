<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/pay.php';

$user = require_login();
ensure_all_tables();
ensure_watermark_columns();
seed_default_packages();

$stmt = db()->prepare('SELECT * FROM shop_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
$stmt->execute();
$packages = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, order_no, package_name, credits, amount, pay_type, status, paid_at, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$recentOrders = $stmt->fetchAll();

$payTypes = pay_enabled_type_list();
$isPayConfigured = pay_is_configured();
$balanceLabel = balance_label();
$userWp = (int) ($user['watermark_points'] ?? 0);

render_header('商城', 'shop');
?>
<style>
.balance-banner { display: flex; align-items: center; gap: 14px; padding: 18px 22px; background: linear-gradient(135deg, rgba(139,92,246,.08), rgba(59,130,246,.06)); border: 1px solid rgba(139,92,246,.15); border-radius: 16px; margin-bottom: 20px; }
.balance-banner .icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #8b5cf6, #3b82f6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; flex-shrink: 0; }
.balance-banner .info { flex: 1; }
.balance-banner .info .label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.balance-banner .info .value { font-size: 28px; font-weight: 800; color: var(--text); }
.balance-banner .actions { display: flex; gap: 8px; flex-shrink: 0; }
.pricing-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 28px; }
.pricing-card { position: relative; background: var(--main-surface); border: 1.5px solid var(--line); border-radius: 20px; padding: 28px 24px 24px; text-align: center; transition: all .2s; overflow: visible; }
.pricing-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,.08); }
.pricing-card.featured { border-color: #8b5cf6; box-shadow: 0 0 0 1px #8b5cf6; }
.pricing-card.featured::before { content: '热门'; position: absolute; top: 14px; right: -28px; background: linear-gradient(135deg, #8b5cf6, #3b82f6); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 32px; transform: rotate(45deg); }
.pricing-card .pkg-name { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.pricing-card .pkg-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; min-height: 18px; }
.pricing-card .pkg-price { font-size: 36px; font-weight: 800; margin-bottom: 4px; }
.pricing-card .pkg-price sup { font-size: 18px; font-weight: 600; }
.pricing-card .pkg-credits { font-size: 14px; color: var(--primary); font-weight: 600; margin-bottom: 20px; padding: 6px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.pricing-card .pkg-btn { width: 100%; padding: 12px; border-radius: 12px; font-weight: 700; font-size: 14px; border: none; cursor: pointer; transition: all .15s; }
.pricing-card .pkg-btn.primary { background: #111; color: #fff; }
.pricing-card .pkg-btn.primary:hover { background: #333; }
.pricing-card .pkg-btn.outline { background: transparent; border: 1.5px solid var(--line); color: var(--text); }
.pricing-card .pkg-btn:disabled { opacity: .4; cursor: not-allowed; }
/* 秒杀进度条 */
.flash-progress { height: 6px; background: var(--main-surface-soft, #f1f5f9); border-radius: 3px; overflow: hidden; margin-bottom: 8px; }
.flash-progress-bar { height: 100%; background: linear-gradient(90deg, #f97316, #ef4444); border-radius: 3px; transition: width 0.4s ease; }
.flash-stock-text { display: flex; justify-content: space-between; font-size: 10px; color: var(--text-muted); margin-bottom: 3px; }
.flash-stock-text .remain { color: #ef4444; font-weight: 700; }
/* 秒杀标签 */
.pkg-badge.soldout { background: linear-gradient(135deg, #9ca3af, #6b7280); }
/* 已抢光按钮 */
.pkg-btn.sold-out { background: #d1d5db !important; color: #9ca3af !important; cursor: not-allowed !important; }
.order-list { display: flex; flex-direction: column; gap: 6px; }
.order-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; font-size: 13px; transition: all .1s; }
.order-item:hover { border-color: var(--primary-soft); }
.order-item .oid { font-family: monospace; font-size: 11px; color: var(--text-muted); min-width: 140px; word-break: break-all; }
.order-item .pkg { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-item .amt { font-weight: 700; min-width: 70px; text-align: right; }
.order-item .time { font-size: 11px; color: var(--text-muted); min-width: 90px; text-align: right; }
@media (max-width: 768px) {
    .pricing-row { grid-template-columns: 1fr; }
    .order-item { flex-wrap: wrap; gap: 4px 8px; padding: 10px 12px; }
    .order-item .oid { min-width: 0; width: 100%; font-size: 10px; }
    .order-item .pkg { flex: 1 1 auto; min-width: 0; font-size: 12px; }
    .order-item .amt { min-width: auto; font-size: 13px; }
    .order-item .time { min-width: auto; font-size: 10px; }
    .balance-banner { flex-direction: column; align-items: flex-start; }
    .balance-banner .actions { width: 100%; }
}
</style>
<main>
    <div class="page-hd">
        <div><h1>点数商城</h1><p>Shop</p></div>
    </div>

    <!-- 余额横幅 -->
    <div class="balance-banner">
        <div class="icon">💰</div>
        <div class="info">
            <div class="label">当前<?= e($balanceLabel) ?></div>
            <div class="value" data-balance-display><?= number_format((int) $user['credits']) ?></div>
        </div>
        <div class="info">
            <div class="label">水印点</div>
            <div class="value" style="color:#8b5cf6;" data-wp-display><?= number_format($userWp) ?></div>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('redeemDialog').classList.remove('hidden');document.body.classList.add('has-dialog');">🎫 兑换码</button>
        </div>
    </div>

    <?php if (!$isPayConfigured): ?>
        <div class="alert warning"><strong>支付功能暂未配置</strong><span>管理员尚未配置支付接口。</span></div>
    <?php endif; ?>

    <!-- 套餐卡片 -->
    <?php if ($packages): ?>
        <div class="pricing-row">
            <?php foreach ($packages as $pkg):
                $pid = (int) $pkg['id']; $pn = e((string) $pkg['name']); $pd = e((string) ($pkg['description'] ?? ''));
                $pc = (int) $pkg['credits']; $pp = (float) $pkg['price'];
                $pwp = (int) ($pkg['watermark_points'] ?? 0);
                $flashEnabled = (int)($pkg['flash_sale_enabled'] ?? 0);
                $flashStart = (string)($pkg['flash_sale_start_time'] ?? '');
                $flashEnd = (string)($pkg['flash_sale_end_time'] ?? '');
                $flashPrice = (float)($pkg['flash_sale_price'] ?? 0);
                $flashStock = (int)($pkg['flash_sale_stock'] ?? 0);
                $flashSold = (int)($pkg['flash_sale_sold'] ?? 0);
                $groupEnabled = (int)($pkg['group_buy_enabled'] ?? 0);
                $groupMin = max(2, (int)($pkg['group_buy_min_count'] ?? 2));
                $now = date('Y-m-d H:i:s');

                // 秒杀状态判断
                $flashInTime     = $flashEnabled && $flashStart && $flashEnd && $flashStart <= $now && $flashEnd > $now;
                $flashUpcoming   = $flashEnabled && $flashStart && $flashStart > $now;
                $flashSoldOut    = $flashInTime && $flashStock > 0 && $flashSold >= $flashStock;
                $flashActive     = $flashInTime && $flashStock > 0 && $flashSold < $flashStock;
                $flashRemain     = $flashActive ? ($flashStock - $flashSold) : 0;
                $flashPercent    = $flashStock > 0 ? min(100, round(($flashSold / $flashStock) * 100)) : 0;

                // 实际展示价格：秒杀活动中用秒杀价
                $displayPrice = ($flashActive || $flashUpcoming) && $flashPrice > 0 ? $flashPrice : $pp;
                $hasFlashPrice = ($flashActive || $flashUpcoming) && $flashPrice > 0 && $flashPrice < $pp;
            ?>
            <div class="pricing-card <?= $pid === 3 ? 'featured' : '' ?>" style="position:relative;">
                <?php if ($flashActive): ?>
                    <span class="pkg-badge flash" style="position:absolute;top:-8px;right:-8px;background:linear-gradient(135deg,#f97316,#ef4444);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:10px;z-index:2;">⚡ 秒杀</span>
                <?php elseif ($flashSoldOut): ?>
                    <span class="pkg-badge soldout" style="position:absolute;top:-8px;right:-8px;background:linear-gradient(135deg,#9ca3af,#6b7280);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:10px;z-index:2;">已抢光</span>
                <?php elseif ($flashUpcoming): ?>
                    <span class="pkg-badge upcoming" style="position:absolute;top:-8px;right:-8px;background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:10px;z-index:2;">⏰ 即将开抢</span>
                <?php endif; ?>
                <?php if ($groupEnabled): ?>
                    <?php $hasFlashBadge = $flashActive || $flashUpcoming || $flashSoldOut; ?>
                    <span class="pkg-badge group" style="position:absolute;top:<?= $hasFlashBadge?'22px':'-8px' ?>;right:-8px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:10px;z-index:2;">👥 <?= $groupMin ?>人团</span>
                <?php endif; ?>
                <div class="pkg-name"><?= $pn ?></div>
                <div class="pkg-desc"><?= $pd ?: '&nbsp;' ?></div>

                <!-- 价格区域 -->
                <div class="pkg-price">
                    <sup>¥</sup><?= number_format($displayPrice, 2) ?>
                    <?php if ($hasFlashPrice): ?>
                        <span style="font-size:14px;color:var(--text-muted);text-decoration:line-through;font-weight:500;margin-left:6px;">¥<?= number_format($pp, 2) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pkg-credits">+<?= number_format($pc) ?> <?= e($balanceLabel) ?></div>
                <?php if ($pwp > 0): ?>
                <div class="pkg-credits" style="color:#8b5cf6;border-top:none;margin-top:-12px;padding-top:0;">+<?= number_format($pwp) ?> 水印点</div>
                <?php endif; ?>

                <!-- 秒杀倒计时 + 进度条 -->
                <?php if ($flashUpcoming): ?>
                    <div class="flash-countdown" data-start="<?= e($flashStart) ?>" style="text-align:center;font-size:11px;color:#6366f1;font-weight:700;margin-bottom:4px;">距开抢 <span data-countdown-text>--:--:--</span></div>
                <?php elseif ($flashActive): ?>
                    <div class="flash-countdown" data-end="<?= e($flashEnd) ?>" style="text-align:center;font-size:11px;color:#ef4444;font-weight:700;margin-bottom:4px;">剩余 <span data-countdown-text>--:--:--</span></div>
                    <!-- 库存进度条 -->
                    <div style="margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-bottom:3px;">
                            <span>已抢 <?= $flashSold ?> 份</span>
                            <span style="color:#ef4444;font-weight:700;">仅剩 <?= $flashRemain ?> 份</span>
                        </div>
                        <div style="height:6px;background:var(--main-surface-soft,#f1f5f9);border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:<?= $flashPercent ?>%;background:linear-gradient(90deg,#f97316,#ef4444);border-radius:3px;transition:width .3s;"></div>
                        </div>
                    </div>
                <?php elseif ($flashSoldOut): ?>
                    <div style="text-align:center;font-size:11px;color:#6b7280;font-weight:700;margin-bottom:6px;">已售罄，共 <?= $flashSold ?> 份</div>
                <?php endif; ?>

                <?php if ($isPayConfigured): ?>
                    <?php if ($flashSoldOut): ?>
                        <button class="pkg-btn outline" disabled>已抢光</button>
                    <?php elseif ($flashUpcoming): ?>
                        <button class="pkg-btn primary" disabled>即将开始</button>
                    <?php else: ?>
                        <button class="pkg-btn primary" data-package-id="<?= $pid ?>" data-package-name="<?= $pn ?>" data-package-credits="<?= $pc ?>" data-package-price="<?= $displayPrice ?>" data-open-buy>立即购买</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="pkg-btn outline" disabled>暂不可用</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><div class="icon">📦</div><h3>暂无可用套餐</h3></div>
    <?php endif; ?>

    <!-- 最近订单 -->
    <?php if ($recentOrders): ?>
    <section class="card-v3">
        <div class="card-v3-head">
            <div><h3>最近订单</h3><p class="sub">共 <?= count($recentOrders) ?> 条</p></div>
        </div>
        <div class="card-v3-body">
            <div class="order-list">
                <?php foreach ($recentOrders as $o):
                    $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款'][$o['status']] ?? $o['status'];
                    $sc = ['pending'=>'running','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted'][$o['status']] ?? '';
                    $pt = (string) ($o['pay_type'] ?? '');
                ?>
                <div class="order-item">
                    <span class="oid"><?= e($o['order_no']) ?></span>
                    <span class="pkg"><?= e($o['package_name']) ?></span>
                    <span class="status-badge <?= $sc ?>"><?= e($sl) ?></span>
                    <span class="amt">¥<?= number_format((float) $o['amount'], 2) ?></span>
                    <span class="time"><?= e($o['created_at']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- 购买确认弹窗 -->
<div id="buyDialog" class="redeem-dialog hidden">
    <div class="redeem-panel" role="dialog" aria-modal="true">
        <div class="redeem-head">
            <div><h2>确认购买</h2></div>
            <button type="button" class="dialog-close" data-close-buy>关闭</button>
        </div>
        <div class="redeem-form">
            <div class="buy-summary">
                <div class="buy-summary-row"><span>套餐</span><strong id="buyPkgName">-</strong></div>
                <div class="buy-summary-row"><span><?= e($balanceLabel) ?></span><strong id="buyPkgCredits">-</strong></div>
                <div class="buy-summary-row total"><span>支付金额</span><strong id="buyPkgPrice">-</strong></div>
            </div>
            <div class="pay-type-select">
                <span class="field-hint">支付方式</span>
                <div class="pay-type-grid">
                    <?php $firstPayType = ''; ?>
                    <?php foreach ($payTypes as $ptKey => $ptLabel): ?>
                        <?php if ($firstPayType === '') $firstPayType = $ptKey; ?>
                        <label class="pay-type-item">
                            <input type="radio" name="pay_type" value="<?= e($ptKey) ?>" <?= $firstPayType === $ptKey ? 'checked' : '' ?>>
                            <span><?= e($ptLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$payTypes): ?>
                        <p style="color:var(--text-muted);font-size:13px;">管理员暂未启用任何支付方式</p>
                    <?php endif; ?>
                </div>
            </div>
            <form id="orderForm" method="post" action="/order">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="buyPkgId" value="">
                <input type="hidden" name="pay_type" id="buyPayType" value="<?= e($firstPayType) ?>">
                <button class="btn btn-primary" type="submit" style="width:100%;">确认支付</button>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var d = document.querySelector('#buyDialog');
    var id = document.querySelector('#buyPkgId'), nm = document.querySelector('#buyPkgName');
    var cr = document.querySelector('#buyPkgCredits'), pr = document.querySelector('#buyPkgPrice');
    var pt = document.querySelector('#buyPayType');
    document.addEventListener('click', function(e) {
        var b = e.target.closest('[data-open-buy]'); if (!b) return;
        id.value = b.dataset.packageId; nm.textContent = b.dataset.packageName;
        cr.textContent = b.dataset.packageCredits + ' <?= e($balanceLabel) ?>';
        pr.textContent = '¥' + parseFloat(b.dataset.packagePrice).toFixed(2);
        d.classList.remove('hidden'); document.body.classList.add('has-dialog');
    });
    document.addEventListener('click', function(e) {
        if (e.target === d || e.target.closest('[data-close-buy]')) { d.classList.add('hidden'); document.body.classList.remove('has-dialog'); }
    });
    document.querySelectorAll('input[name="pay_type"]').forEach(function(r) {
        r.addEventListener('change', function() { pt.value = this.value; });
    });
    window.addEventListener('keydown', function(e) { if (e.key === 'Escape') { d.classList.add('hidden'); document.body.classList.remove('has-dialog'); } });
})();

// 限时活动倒计时
(function() {
    var cds = document.querySelectorAll('.flash-countdown[data-start], .flash-countdown[data-end]');
    if (!cds.length) return;
    function pad(n) { return n < 10 ? '0' + n : n; }
    function tick() {
        var now = Date.now();
        cds.forEach(function(el) {
            var target, targetTime;
            if (el.dataset.start) {
                targetTime = new Date(el.dataset.start.replace(' ', 'T')).getTime();
            } else if (el.dataset.end) {
                targetTime = new Date(el.dataset.end.replace(' ', 'T')).getTime();
            }
            if (!targetTime) return;
            var diff = Math.max(0, targetTime - now);
            if (diff <= 0) { location.reload(); return; }
            var h = Math.floor(diff / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            var s = Math.floor((diff % 60000) / 1000);
            var txt = el.querySelector('[data-countdown-text]');
            if (txt) txt.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<?php render_footer(); ?>
