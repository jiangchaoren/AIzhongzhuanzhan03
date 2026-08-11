<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/pay.php';

$user = require_login();
ensure_all_tables();
ensure_watermark_columns();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/shop');
}

verify_csrf();

$packageId = (int) ($_POST['package_id'] ?? 0);
$payType = trim((string) ($_POST['pay_type'] ?? 'alipay'));

// 验证支付方式
$allowedTypes = array_keys(pay_enabled_type_list());
if ($payType === '' || !in_array($payType, $allowedTypes, true)) {
    flash('error', '该支付方式暂不可用，请选择其他方式。');
    redirect('/user/shop');
}

// 验证支付配置
if (!pay_is_configured()) {
    flash('error', '支付功能尚未配置，请联系管理员。');
    redirect('/user/shop');
}

// 查询套餐
$stmt = db()->prepare('SELECT * FROM shop_packages WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package) {
    flash('error', '套餐不存在或已下架。');
    redirect('/user/shop');
}

// ── 秒杀校验 ──
$pdo = db();
$flashEnabled = (int)($package['flash_sale_enabled'] ?? 0);
$flashStart   = (string)($package['flash_sale_start_time'] ?? '');
$flashEnd     = (string)($package['flash_sale_end_time'] ?? '');
$flashPrice   = (float)($package['flash_sale_price'] ?? 0);
$flashStock   = (int)($package['flash_sale_stock'] ?? 0);
$now          = date('Y-m-d H:i:s');
$isFlashSale  = $flashEnabled && $flashStart && $flashEnd
                && $flashStart <= $now && $flashEnd > $now
                && $flashStock > 0 && $flashPrice > 0;

if ($isFlashSale) {
    // 使用行锁原子扣减库存
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT flash_sale_sold FROM shop_packages WHERE id = ? FOR UPDATE');
        $stmt->execute([$packageId]);
        $current = $stmt->fetch();
        $currentSold = (int)($current['flash_sale_sold'] ?? 0);

        if ($currentSold >= $flashStock) {
            $pdo->rollBack();
            flash('error', '下手慢了一步，该秒杀已抢光。');
            redirect('/user/shop');
        }

        $stmt = $pdo->prepare('UPDATE shop_packages SET flash_sale_sold = flash_sale_sold + 1 WHERE id = ? AND flash_sale_sold < flash_sale_stock');
        $stmt->execute([$packageId]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            flash('error', '下手慢了一步，该秒杀已抢光。');
            redirect('/user/shop');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', '系统繁忙，请稍后再试。');
        redirect('/user/shop');
    }

    // 秒杀价覆盖原价
    $package['price'] = $flashPrice;
}

// 生成订单号
$orderNo = pay_generate_order_no();

// 获取支付配置
$pid = pay_config('pid');
$key = pay_config('key');
$baseUrl = pay_base_url();

$notifyUrl = pay_config('notify_url') ?: ($baseUrl . '/pay_notify');
$returnUrl = pay_config('return_url') ?: ($baseUrl . '/pay_return');

// 创建订单记录
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_no, package_id, package_name, credits, watermark_points, amount, pay_type, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([
        $user['id'],
        $orderNo,
        $packageId,
        $package['name'],
        $package['credits'],
        (int) ($package['watermark_points'] ?? 0),
        $package['price'],
        $payType ?: null,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 订单创建失败，回滚秒杀库存
    if ($isFlashSale) {
        try {
            $pdo = db();
            $pdo->prepare('UPDATE shop_packages SET flash_sale_sold = GREATEST(flash_sale_sold - 1, 0) WHERE id = ?')->execute([$packageId]);
        } catch (Throwable $ignore) {}
    }
    flash('error', '创建订单失败：' . $e->getMessage());
    redirect('/user/shop');
}

// ========== 页面跳转支付：生成表单跳转到支付页面 ==========
$formParams = [
    'pid' => (int) $pid,
    'type' => $payType,
    'out_trade_no' => $orderNo,
    'notify_url' => $notifyUrl,
    'return_url' => $returnUrl,
    'name' => $package['name'] . ' - ' . $package['credits'] . e(balance_label()),
    'money' => number_format((float) $package['price'], 2, '.', ''),
    'param' => (string) $orderId,
];
echo pay_build_form($formParams, $key);
exit;
