<?php
/**
 * 每日签到 API
 *
 * POST /api/checkin  {action: "do"}        → 执行签到
 * POST /api/checkin  {action: "status"}     → 查询签到状态
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');

function api_ok(array $data = [], string $message = 'ok'): void {
    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function api_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('仅支持 POST 请求', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string) ($input['action'] ?? '');

// ── 查询签到状态 ──
if ($action === 'status') {
    ensure_checkin_table();
    $today = date('Y-m-d');

    // 今日是否已签到
    $stmt = db()->prepare('SELECT id, consecutive_days, reward_credits FROM checkin_records WHERE user_id = ? AND checkin_date = ? LIMIT 1');
    $stmt->execute([$user['id'], $today]);
    $todayRecord = $stmt->fetch();

    // 连续签到天数
    $stmt = db()->prepare('SELECT checkin_date, consecutive_days FROM checkin_records WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $lastRecord = $stmt->fetch();

    $consecutive = 0;
    if ($lastRecord) {
        $lastDate = $lastRecord['checkin_date'];
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($todayRecord) {
            $consecutive = (int) $todayRecord['consecutive_days'];
        } elseif ($lastDate === $yesterday || $lastDate === $today) {
            $consecutive = (int) $lastRecord['consecutive_days'];
        }
    }

    // 计算下次签到预计奖励
    $nextConsecutive = $todayRecord ? $consecutive : ($consecutive + 1);
    $nextReward = calculate_reward($nextConsecutive);

    api_ok([
        'checked_in'     => (bool) $todayRecord,
        'consecutive'    => $consecutive,
        'today_reward'   => $todayRecord ? (int) $todayRecord['reward_credits'] : 0,
        'next_reward'    => $nextReward,
    ]);
}

// ── 执行签到 ──
if ($action === 'do') {
    ensure_checkin_table();

    // 检查功能开关
    $enabled = app_setting('checkin_enabled', '1');
    if ($enabled !== '1') {
        api_error('签到功能暂未开放。');
    }

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // 检查今日是否已签到
    $stmt = db()->prepare('SELECT id FROM checkin_records WHERE user_id = ? AND checkin_date = ? LIMIT 1');
    $stmt->execute([$user['id'], $today]);
    if ($stmt->fetch()) {
        api_error('今日已签到，明天再来吧！');
    }

    // 查询最近一次签到
    $stmt = db()->prepare('SELECT checkin_date, consecutive_days FROM checkin_records WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $lastRecord = $stmt->fetch();

    // 计算连续天数
    $consecutiveDays = 1;
    if ($lastRecord) {
        if ($lastRecord['checkin_date'] === $yesterday) {
            $consecutiveDays = (int) $lastRecord['consecutive_days'] + 1;
        }
        // 如果不是昨天签到 → 连续中断 → 从 1 开始
    }

    // 计算奖励
    $reward = calculate_reward($consecutiveDays);

    // 事务写入
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO checkin_records (user_id, checkin_date, consecutive_days, reward_credits)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$user['id'], $today, $consecutiveDays, $reward]);

        $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
        $stmt->execute([$reward, $user['id']]);
        record_credit_change($user['id'], 'credit_add', $reward, (int)$user['credits'] + $reward, '每日签到奖励');

        // 签到额外送水印点
        $checkinWp = (int) app_setting('checkin_watermark_points', '0');
        if ($checkinWp > 0) {
            $stmt = $pdo->prepare('UPDATE users SET watermark_points = watermark_points + ? WHERE id = ?');
            $stmt->execute([$checkinWp, $user['id']]);
            record_credit_change($user['id'], 'wp_add', $checkinWp, (int)($user['watermark_points'] ?? 0) + $checkinWp, '每日签到水印点');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error('签到失败：' . $e->getMessage(), 500);
    }

    api_ok([
        'consecutive'  => $consecutiveDays,
        'reward'       => $reward,
        'total_credits' => (int) $user['credits'] + $reward,
    ], sprintf('签到成功！连续 %d 天，获得 %d 点数', $consecutiveDays, $reward));
}

api_error('未知操作。');

// ── 奖励计算 ──
function calculate_reward(int $consecutiveDays): int
{
    if ($consecutiveDays < 1) return 0;

    $base       = max(1, (int) app_setting('checkin_base_reward', '5'));
    $multiplier = max(0, (float) app_setting('checkin_multiplier', '0.2'));
    $maxDaily   = max($base, (int) app_setting('checkin_max_daily', '100'));

    // 基础递增奖励: base + base * multiplier * (day - 1)
    $reward = (int) round($base + $base * $multiplier * ($consecutiveDays - 1));

    // 检查阶梯奖励（自定义连续天数特殊奖励）
    $customRaw = app_setting('checkin_custom_rewards', '');
    if ($customRaw !== '') {
        $custom = json_decode($customRaw, true);
        if (is_array($custom) && isset($custom[(string) $consecutiveDays])) {
            $customReward = max($reward, (int) $custom[(string) $consecutiveDays]);
            $reward = $customReward;
        }
    }

    // 上限
    return min($reward, $maxDaily);
}
