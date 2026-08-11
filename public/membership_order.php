<?php

/**
 * 会员订单处理（创建订单、跳转支付）
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/pay.php';
require_once __DIR__ . '/../src/membership.php';

$user = require_login();
ensure_all_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/membership');
}

verify_csrf();

$action = (string) ($_POST['action'] ?? '');
$packageId = (int) ($_POST['package_id'] ?? 0);
$payType = trim((string) ($_POST['pay_type'] ?? 'alipay'));

// ── 频率限制：1分钟内最多创建3个会员订单 ──
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM membership_orders WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
    );
    $stmt->execute([(int) $user['id']]);
    $recentCount = (int) $stmt->fetchColumn();
    if ($recentCount >= 3) {
        flash('error', '操作过于频繁，请稍后再试。');
        redirect('/user/membership');
    }
}

// ── 检查是否已有同类型待支付订单（复用或拒绝）──
if ($action !== 'topup' && $packageId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id FROM membership_orders WHERE user_id = ? AND package_id = ? AND order_type = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([(int) $user['id'], $packageId, $action]);
    $existing = $stmt->fetch();
    if ($existing) {
        flash('error', '您有一个相同套餐的待支付订单，请先完成支付或等待订单过期。');
        redirect('/user/membership');
    }
}

// 验证支付方式
$allowedTypes = array_keys(pay_enabled_type_list());
if ($payType === '' || !in_array($payType, $allowedTypes, true)) {
    flash('error', '该支付方式暂不可用。');
    redirect('/user/membership');
}

if (!pay_is_configured()) {
    flash('error', '支付功能尚未配置。');
    redirect('/user/membership');
}

if ($packageId < 1) {
    flash('error', '请选择套餐。');
    redirect('/user/membership');
}

$pdo = db();

// ── 加量包购买 ──
if ($action === 'topup') {
    $stmt = $pdo->prepare('SELECT * FROM balance_topup_packages WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$packageId]);
    $package = $stmt->fetch();

    if (!$package) {
        flash('error', '加量包不存在或已下架。');
        redirect('/user/membership');
    }

    $orderNo = pay_generate_order_no();
    $pid = pay_config('pid');
    $key = pay_config('key');
    $baseUrl = pay_base_url();
    $notifyUrl = pay_config('notify_url') ?: ($baseUrl . '/pay_notify');
    $returnUrl = pay_config('return_url') ?: ($baseUrl . '/pay_return');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO membership_orders (user_id, order_no, package_id, package_name, order_type, credits, amount, pay_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")'
        );
        $stmt->execute([
            $user['id'],
            $orderNo,
            $packageId,
            $package['name'],
            'topup',
            (int) $package['credits'],
            (float) $package['price'],
            $payType,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', '创建订单失败。');
        redirect('/user/membership');
    }

    $formParams = [
        'pid' => (int) $pid,
        'type' => $payType,
        'out_trade_no' => $orderNo,
        'notify_url' => $notifyUrl,
        'return_url' => $returnUrl,
        'name' => '加量包 - ' . $package['name'] . ' (+' . $package['credits'] . ')',
        'money' => number_format((float) $package['price'], 2, '.', ''),
        'param' => (string) $orderId,
    ];
    echo pay_build_form($formParams, $key);
    exit;
}

// ── 会员套餐购买/续费/升级 ──
$stmt = $pdo->prepare('SELECT * FROM membership_packages WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package) {
    flash('error', '会员套餐不存在或已下架。');
    redirect('/user/membership');
}

// 判断操作类型
$existingMembership = user_active_membership((int) $user['id']);
if (!$existingMembership) {
    $orderType = 'new';
} elseif ((int) $existingMembership['package_id'] === $packageId) {
    $orderType = 'renew';
} else {
    $orderType = 'upgrade';
}

$upgradeMode = null;
if ($orderType === 'upgrade') {
    $upgradeMode = (string) ($_POST['upgrade_mode'] ?? 'immediate');
    if (!in_array($upgradeMode, ['immediate', 'deferred'], true)) {
        $upgradeMode = 'immediate';
    }
}

$orderNo = pay_generate_order_no();
$pid = pay_config('pid');
$key = pay_config('key');
$baseUrl = pay_base_url();
$notifyUrl = pay_config('notify_url') ?: ($baseUrl . '/pay_notify');
$returnUrl = pay_config('return_url') ?: ($baseUrl . '/pay_return');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO membership_orders
         (user_id, order_no, package_id, package_name, order_type, credits, amount,
          duration_unit, duration_value, daily_quota, monthly_quota, yearly_quota,
          upgrade_mode, pay_type, status)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([
        $user['id'],
        $orderNo,
        $packageId,
        $package['name'],
        $orderType,
        (float) $package['price'],
        $package['duration_unit'],
        (int) $package['duration_value'],
        (int) $package['daily_quota'],
        (int) $package['monthly_quota'],
        (int) $package['yearly_quota'],
        $upgradeMode,
        $payType,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', '创建订单失败。');
    redirect('/user/membership');
}

$typeLabel = membership_order_type_label($orderType);
$formParams = [
    'pid' => (int) $pid,
    'type' => $payType,
    'out_trade_no' => $orderNo,
    'notify_url' => $notifyUrl,
    'return_url' => $returnUrl,
    'name' => "{$typeLabel} - {$package['name']}",
    'money' => number_format((float) $package['price'], 2, '.', ''),
    'param' => (string) $orderId,
];
echo pay_build_form($formParams, $key);
exit;
