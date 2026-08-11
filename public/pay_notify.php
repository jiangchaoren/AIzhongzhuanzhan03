<?php

/**
 * 彩虹易支付 - 异步通知处理
 *
 * 支付平台以 GET 方式通知此页面
 * 必须返回 "success" 表示已收到通知
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/pay.php';

ensure_all_tables();

// 获取支付配置
$pid = pay_config('pid');
$key = pay_config('key');

// 接收通知参数
$params = $_GET;

// 记录通知日志
$logFile = dirname(__DIR__) . '/public/uploads/pay_notify.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logLine = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

try {
    // 验证签名
    if (!pay_verify_sign($params, $key)) {
        http_response_code(400);
        echo 'fail';
        exit;
    }

    // 验证商户 ID
    if ((int) ($params['pid'] ?? 0) !== (int) $pid) {
        http_response_code(400);
        echo 'fail';
        exit;
    }

    // 检查支付状态
    $tradeStatus = (string) ($params['trade_status'] ?? '');
    if ($tradeStatus !== 'TRADE_SUCCESS') {
        echo 'success';
        exit;
    }

    $orderNo = (string) ($params['out_trade_no'] ?? '');
    $tradeNo = (string) ($params['trade_no'] ?? '');
    $payType = (string) ($params['type'] ?? '');

    if ($orderNo === '') {
        http_response_code(400);
        echo 'fail';
        exit;
    }

    $pdo = db();

    // 开启事务锁
    $pdo->beginTransaction();

    // 先尝试在 orders 表查找
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_no = ? FOR UPDATE');
    $stmt->execute([$orderNo]);
    $order = $stmt->fetch();

    if (!$order) {
        // 尝试在 membership_orders 表查找
        $stmt = $pdo->prepare('SELECT * FROM membership_orders WHERE order_no = ? FOR UPDATE');
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();
    }

    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo 'fail';
        exit;
    }

    // 已经支付成功的
    if ($order['status'] === 'paid') {
        $pdo->commit();
        echo 'success';
        exit;
    }

    // 只有 pending 状态才能标记为 paid
    if ($order['status'] !== 'pending') {
        $pdo->rollBack();
        echo 'success';
        exit;
    }

    // ── 判断是普通订单还是会员订单 ──
    $isMembership = isset($order['order_type']);
    $table = $isMembership ? 'membership_orders' : 'orders';

    // 更新订单状态
    $stmt = $pdo->prepare(
        "UPDATE {$table} SET status = 'paid', trade_no = ?, pay_type = ?, paid_at = NOW() WHERE id = ?"
    );
    $stmt->execute([$tradeNo, $payType ?: null, (int) $order['id']]);

    if ($isMembership) {
        // ── 处理会员订单业务逻辑 ──
        require_once __DIR__ . '/../src/membership.php';

        $orderType = (string) ($order['order_type'] ?? 'new');
        $userId = (int) $order['user_id'];
        $packageId = (int) ($order['package_id'] ?? 0);
        $orderNo = (string) ($order['order_no'] ?? '');

        if ($orderType === 'new') {
            membership_activate_new($userId, $packageId, $orderNo);
        } elseif ($orderType === 'renew') {
            membership_renew($userId, $packageId, $orderNo);
        } elseif ($orderType === 'upgrade') {
            $upgradeMode = (string) ($order['upgrade_mode'] ?? 'immediate');
            membership_upgrade($userId, $packageId, $upgradeMode, $orderNo);
        }

        // 邀请佣金处理（与普通订单分支保持一致）
        try {
            invite_process_commission($userId, (float) $order['amount'], 0, (int) $order['id']);
        } catch (Throwable $e) {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] INVITE_COMMISSION_ERROR: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        $pdo->commit();

        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] SUCCESS: membership_order=' . $orderNo
            . ' user=' . $userId
            . ' type=' . $orderType . "\n",
            FILE_APPEND | LOCK_EX
        );

        echo 'success';
        exit;
    }

    // ── 处理普通订单 ──
    // 给用户加 credits
    $credits = (int) $order['credits'];
    $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
    $stmt->execute([$credits, (int) $order['user_id']]);
    $afterCredits = (int) $pdo->query("SELECT credits FROM users WHERE id = " . (int)$order['user_id'])->fetchColumn();
    record_credit_change((int)$order['user_id'], 'credit_add', $credits, $afterCredits, '充值套餐：' . ($order['package_name'] ?? ''));

    // 给用户加水印点
    $watermarkPoints = (int) ($order['watermark_points'] ?? 0);
    if ($watermarkPoints > 0) {
        $stmt = $pdo->prepare('UPDATE users SET watermark_points = watermark_points + ? WHERE id = ?');
        $stmt->execute([$watermarkPoints, (int) $order['user_id']]);
        $afterWp = (int) $pdo->query("SELECT watermark_points FROM users WHERE id = " . (int)$order['user_id'])->fetchColumn();
        record_credit_change((int)$order['user_id'], 'wp_add', $watermarkPoints, $afterWp, '充值赠送水印点');
    }

    // 邀请佣金处理
    try {
        invite_process_commission((int) $order['user_id'], (float) $order['amount'], $credits, (int) $order['id']);
    } catch (Throwable $e) {
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] INVITE_COMMISSION_ERROR: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }

    $pdo->commit();

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] SUCCESS: order=' . $orderNo
        . ' user=' . $order['user_id']
        . ' credits=+' . $credits . "\n",
        FILE_APPEND | LOCK_EX
    );

    echo 'success';
} catch (Throwable $e) {
    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . "\n",
        FILE_APPEND | LOCK_EX
    );
    http_response_code(500);
    echo 'fail';
}
