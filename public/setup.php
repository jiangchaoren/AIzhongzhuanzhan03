<?php

declare(strict_types=1);

/**
 * AI 图片视频创作系统 三步安装向导
 *
 * 步骤1: PHP 环境检测
 * 步骤2: 数据库配置
 * 步骤3: 管理员 + 平台设置
 */

session_name('image_platform_setup');
session_start();

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
$databaseSqlPath = $root . '/database.sql';
$errors = [];
$installed = false;

function setup_e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function setup_value(string $key, $default = ''): string { return setup_e($_POST[$key] ?? $_SESSION['setup_' . $key] ?? $default); }
function setup_token(): string {
    if (empty($_SESSION['setup_token'])) $_SESSION['setup_token'] = bin2hex(random_bytes(32));
    return $_SESSION['setup_token'];
}
function setup_verify_token(): void {
    $token = $_POST['setup_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['setup_token'] ?? '', $token))
        throw new RuntimeException('安装令牌无效，请刷新页面后重试。');
}
function setup_quote_identifier(string $id): string { return '`' . str_replace('`', '``', $id) . '`'; }
function setup_split_sql(string $sql): array {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $statements = []; $buffer = ''; $inString = false; $stringQuote = ''; $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i]; $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if (!$inString && $char === '-' && $next === '-') { while ($i < $length && $sql[$i] !== "\n") $i++; continue; }
        if (!$inString && $char === '#') { while ($i < $length && $sql[$i] !== "\n") $i++; continue; }
        if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
            if (!$inString) { $inString = true; $stringQuote = $char; }
            elseif ($stringQuote === $char) { $inString = false; $stringQuote = ''; }
        }
        if (!$inString && $char === ';') { $stmt = trim($buffer); if ($stmt !== '') $statements[] = $stmt; $buffer = ''; continue; }
        $buffer .= $char;
    }
    $stmt = trim($buffer); if ($stmt !== '') $statements[] = $stmt;
    return $statements;
}
function setup_config_content(array $config): string { return "<?php\n\nreturn " . var_export($config, true) . ";\n"; }
function setup_admin_exists(string $configPath): bool {
    if (!is_file($configPath)) return false;
    try { $config = require $configPath; if (!is_array($config) || empty($config['db'])) return false;
        $db = $config['db']; $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

$installed = setup_admin_exists($configPath);
$installedMarker = $root . '/storage/.installed';
if (!$installed && is_file($installedMarker)) $installed = true;

// 当前步骤（第4步为安装完成页）
$step = max(1, min(4, (int) ($_GET['step'] ?? 1)));

// ── 辅助函数：获取数据库中已存在的表列表 ──
function setup_existing_tables(PDO $pdo): array {
    try {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        return array_map(fn($r) => $r[0], $rows);
    } catch (Throwable) { return []; }
}

// ── 辅助函数：从SQL中提取建表语句的表名 ──
function setup_sql_table_names(string $sql): array {
    $names = [];
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
        $names = $m[1];
    }
    return $names;
}

// ── 辅助函数：执行导入并返回摘要 ──
function setup_do_import(PDO $pdo, string $sql, string $mode): array {
    $statements = setup_split_sql($sql);
    $existingNames = setup_existing_tables($pdo);
    $sqlTableNames = setup_sql_table_names($sql);
    $existingSet = array_flip($existingNames);
    $summary = ['total' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

    if ($mode === 'force') {
        // 强制模式：先删所有SQL中定义的表
        foreach ($sqlTableNames as $t) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . setup_quote_identifier($t));
                $summary['details'][] = ['name' => $t, 'result' => 'dropped'];
            } catch (Throwable $e) {
                $summary['details'][] = ['name' => $t, 'result' => 'drop_failed', 'error' => $e->getMessage()];
            }
        }
    }

    // 执行每条SQL语句
    foreach ($statements as $stmt) {
        $summary['total']++;
        $trimmed = trim($stmt);
        // 提取表名
        $tableName = '';
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $stmt, $m)) {
            $tableName = $m[1];
        } elseif (preg_match('/INSERT\s+(?:INTO|)\s+`?(\w+)`?/i', $stmt, $m)) {
            $tableName = $m[1];
        } elseif (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?/i', $stmt, $m)) {
            $tableName = $m[1];
        }

        // 跳过模式：已存在的表跳过CREATE TABLE，INSERT/ALTER照常执行
        if ($mode === 'skip' && $tableName && stripos($trimmed, 'CREATE TABLE') === 0) {
            $actualTables = setup_existing_tables($pdo);
            if (in_array($tableName, $actualTables, true)) {
                $summary['skipped']++;
                $summary['details'][] = ['name' => $tableName, 'result' => 'skipped'];
                continue;
            }
        }

        try {
            $pdo->exec($stmt);
            $summary['created']++;
            if ($tableName) {
                $summary['details'][] = ['name' => $tableName, 'result' => 'ok'];
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $summary['details'][] = ['name' => $tableName ?: '(unknown)', 'result' => 'error', 'error' => $e->getMessage()];
        }
    }
    return $summary;
}

// 步骤2保存到Session（数据库信息已填好，进入导入或选择模式）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && ($_POST['action'] ?? '') === 'save_db') {
    try {
        setup_verify_token();
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = max(1, min(65535, (int) ($_POST['db_port'] ?? 3306)));
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        if ($dbHost === '' || $dbName === '' || $dbUser === '') throw new RuntimeException('请填写完整的数据库连接信息。');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) throw new RuntimeException('数据库名只能包含字母、数字和下划线。');

        // 连接+创建数据库
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . setup_quote_identifier($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE ' . setup_quote_identifier($dbName));

        // 存Session
        $_SESSION['setup_db_host'] = $dbHost; $_SESSION['setup_db_port'] = $dbPort; $_SESSION['setup_db_name'] = $dbName;
        $_SESSION['setup_db_user'] = $dbUser; $_SESSION['setup_db_pass'] = $dbPass;

        // 检测已有表
        $existingTables = setup_existing_tables($pdo);
        if (empty($existingTables)) {
            // 空数据库 → 直接导入
            $sql = file_get_contents($databaseSqlPath);
            if ($sql === false) throw new RuntimeException('读取 database.sql 失败。');
            $summary = setup_do_import($pdo, $sql, 'skip');
            $_SESSION['setup_import_summary'] = $summary;
            if ($summary['errors'] > 0) throw new RuntimeException('部分SQL执行失败，请查看详情。');
            header('Location: /setup.php?step=3'); exit;
        } else {
            // 已有表 → 展示选择页面
            $sqlTableNames = setup_sql_table_names((string) file_get_contents($databaseSqlPath));
            $existingInSql = array_intersect($existingTables, $sqlTableNames);
            $existingOther = array_diff($existingTables, $sqlTableNames);
            $_SESSION['setup_existing_in_sql'] = $existingInSql;
            $_SESSION['setup_existing_other'] = $existingOther;
            $_SESSION['setup_db_step2_sub'] = 'choose_mode';
            header('Location: /setup.php?step=2&sub=choose'); exit;
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// 步骤2子步骤：执行选择的操作（跳过/强制）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && ($_POST['action'] ?? '') === 'do_import') {
    try {
        setup_verify_token();
        $mode = in_array($_POST['import_mode'] ?? '', ['skip', 'force'], true) ? $_POST['import_mode'] : 'skip';
        $confirmed = !empty($_POST['confirm']);
        if (!$confirmed) throw new RuntimeException('请确认操作后再执行。');

        $dbHost = $_SESSION['setup_db_host'] ?? '';
        $dbPort = $_SESSION['setup_db_port'] ?? 3306;
        $dbName = $_SESSION['setup_db_name'] ?? '';
        $dbUser = $_SESSION['setup_db_user'] ?? '';
        $dbPass = $_SESSION['setup_db_pass'] ?? '';
        if ($dbHost === '') throw new RuntimeException('数据库信息丢失，请重新从步骤 2 开始。');

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents($databaseSqlPath);
        if ($sql === false) throw new RuntimeException('读取 database.sql 失败。');
        $summary = setup_do_import($pdo, $sql, $mode);
        $_SESSION['setup_import_summary'] = $summary;

        if ($summary['errors'] > 0) {
            $errorList = array_filter($summary['details'], fn($d) => $d['result'] === 'error');
            throw new RuntimeException('部分导入失败（' . $summary['errors'] . ' 条错误）：' . implode('; ', array_map(fn($d) => ($d['name'] ?? '?') . ': ' . ($d['error'] ?? 'unknown'), $errorList)));
        }
        header('Location: /setup.php?step=3'); exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// 步骤3：最终安装
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && ($_POST['action'] ?? '') === 'install') {
    try {
        setup_verify_token();
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';
        $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
        $platformName = trim($_POST['platform_name'] ?? 'AI 图片视频创作系统');
        $balanceLabel = trim($_POST['balance_label'] ?? '余额');
        $timeout = max(30, (int) ($_POST['timeout'] ?? 300));
        $workerSleep = max(1, (int) ($_POST['worker_sleep'] ?? 3));

        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $adminUser)) throw new RuntimeException('管理员用户名 3-32 位，字母/数字/下划线。');
        if (strlen($adminPass) < 6) throw new RuntimeException('管理员密码至少 6 位。');
        if (!hash_equals($adminPass, $adminPassConfirm)) throw new RuntimeException('两次密码不一致。');

        // 从Session取DB信息
        $dbHost = $_SESSION['setup_db_host'] ?? ''; $dbPort = $_SESSION['setup_db_port'] ?? 3306;
        $dbName = $_SESSION['setup_db_name'] ?? ''; $dbUser = $_SESSION['setup_db_user'] ?? ''; $dbPass = $_SESSION['setup_db_pass'] ?? '';
        if ($dbHost === '') throw new RuntimeException('数据库信息丢失，请重新从步骤 2 开始。');

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = "admin"'); $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('管理员已存在。');
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, credits) VALUES (?, ?, "admin", 99999)');
        $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

        $upsert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $upsert->execute(['balance_label', $balanceLabel]);
        $upsert->execute(['platform_name', $platformName]);

        $config = [
            'db' => ['host' => $dbHost, 'port' => $dbPort, 'database' => $dbName, 'username' => $dbUser, 'password' => $dbPass, 'charset' => 'utf8mb4'],
            'app' => ['name' => $platformName, 'base_url' => '', 'session_name' => 'image_platform_session', 'timezone' => 'Asia/Shanghai'],
            'generation' => ['platform_name' => $platformName, 'timeout' => $timeout, 'worker_sleep' => $workerSleep, 'stale_running_after' => max($timeout + 120, 420)],
        ];
        if (file_put_contents($configPath, setup_config_content($config), LOCK_EX) === false) throw new RuntimeException('写入 config.php 失败。');
        file_put_contents($installedMarker, date('Y-m-d H:i:s'), LOCK_EX);

        // 清理敏感文件（保留 setup.php 用于显示完成页）
        session_destroy();
        foreach ([$databaseSqlPath => 'database.sql'] as $path => $label) {
            if (is_file($path)) @unlink($path);
        }
        header('Location: /setup.php?step=4');
        exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// PHP环境检测
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '8.1.0', '>=');
$sgLoaded = extension_loaded('SourceGuardian') || extension_loaded('sg11') || extension_loaded('ixed');
$writableDirs = ['根目录' => $root, 'config/' => $root . '/config', 'uploads/' => $root . '/public/uploads'];
foreach ($writableDirs as $k => $d) { if (!is_dir($d)) @mkdir($d, 0755, true); $writableDirs[$k] = is_writable($d); }
$allDirsOk = !in_array(false, $writableDirs, true);
$envOk = $phpOk && $sgLoaded && $allDirsOk;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 图片视频创作系统 安装向导</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        .setup-wrap { max-width: 720px; margin: 0 auto; padding: clamp(24px, 3vw, 48px) clamp(16px, 2.5vw, 32px); }
        .setup-header { text-align: center; margin-bottom: clamp(20px, 2.5vw, 32px); }
        .setup-header h1 { font-size: clamp(22px, 3vw, 30px); font-weight: 800; letter-spacing: -0.02em; margin: 0 0 4px; }
        .setup-header p { color: var(--text-muted); font-size: clamp(13px, 1.5vw, 15px); margin: 0; }
        .step-indicator { display: flex; align-items: center; justify-content: center; margin-bottom: clamp(20px, 2.5vw, 32px); }
        .step-dot { display: flex; align-items: center; gap: 6px; }
        .step-dot .num { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 50%; font-size: 12px; font-weight: 800; transition: all var(--duration-normal) var(--ease-out-expo); }
        .step-dot .num.done { background: var(--success); color: #fff; }
        .step-dot .num.active { background: var(--primary); color: #fff; box-shadow: 0 0 0 4px var(--primary-soft); }
        .step-dot .num.pending { background: var(--main-surface-soft); color: var(--text-muted); }
        .step-dot .label { font-size: 12px; font-weight: 700; color: var(--text-muted); white-space: nowrap; }
        .step-dot.active .label { color: var(--primary); }
        .step-dot.done .label { color: var(--success); }
        .step-line { width: 48px; height: 2px; background: var(--line); margin: 0 6px; border-radius: 1px; }
        .step-line.done { background: var(--success); }
        .step-line.active { background: var(--primary); }
        .card-wizard { border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--main-surface); box-shadow: var(--shadow-card); overflow: hidden; }
        .card-wizard-head { padding: clamp(16px, 2vw, 24px) clamp(20px, 2.5vw, 28px); border-bottom: 1px solid var(--line); }
        .card-wizard-head h2 { margin: 0; font-size: clamp(16px, 2vw, 20px); font-weight: 800; letter-spacing: -0.01em; }
        .card-wizard-head p { margin: 4px 0 0; color: var(--text-muted); font-size: clamp(12px, 1.4vw, 14px); }
        .card-wizard-body { padding: clamp(20px, 2.5vw, 28px); }
        .check-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: clamp(7px, 0.9vw, 10px) 0; border-bottom: 1px solid var(--line); }
        .check-item:last-child { border-bottom: 0; }
        .check-item .left { display: flex; align-items: center; gap: 6px; font-size: clamp(13px, 1.5vw, 14px); font-weight: 600; color: var(--text); min-width: 0; }
        .check-item .left code { font-size: clamp(11px, 1.3vw, 12px); background: var(--main-surface-soft); padding: 1px 6px; border-radius: 4px; }
        .badge-status { min-height: 22px; display: inline-flex; align-items: center; padding: 0 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 800; flex-shrink: 0; }
        .badge-status.pass { background: var(--success-soft); color: oklch(25% 30% 155); }
        .badge-status.fail { background: var(--danger-soft); color: var(--danger); }
        .badge-status.warn { background: var(--warning-soft); color: var(--warning); }
        .field-row { display: grid; gap: clamp(4px, 0.5vw, 6px); }
        .field-row label, .field-row > span.label { color: var(--text-soft); font-size: clamp(12px, 1.4vw, 13px); font-weight: 700; }
        .field-row input, .field-row select { width: 100%; border: 1.5px solid var(--line-strong); border-radius: var(--radius); padding: clamp(8px, 1vw, 11px) clamp(10px, 1.2vw, 14px); font-size: clamp(13px, 1.5vw, 14px); outline: none; background: var(--main-surface); color: var(--text); transition: border-color var(--duration-fast) var(--ease-out-expo), box-shadow var(--duration-fast) var(--ease-out-expo); }
        .field-row input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px oklch(55% 38% 245 / 0.1); }
        .field-row .hint { color: var(--text-muted); font-size: clamp(11px, 1.3vw, 12px); font-weight: 600; margin-top: 2px; }
        .form-grid { display: grid; gap: clamp(12px, 1.5vw, 16px); }
        .form-inline { display: grid; grid-template-columns: 1fr 1fr; gap: clamp(10px, 1.2vw, 16px); }
        @media (max-width: 560px) { .form-inline { grid-template-columns: 1fr; } }
        .btn-next, .btn-install { width: 100%; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: var(--radius); padding: 0 20px; font-size: clamp(14px, 1.6vw, 15px); font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--primary-light), var(--primary)); box-shadow: 0 4px 12px oklch(55% 38% 245 / 0.22); cursor: pointer; transition: all var(--duration-normal) var(--ease-out-expo); text-decoration: none; margin-top: 8px; }
        .btn-next:hover, .btn-install:hover { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); box-shadow: 0 6px 20px oklch(55% 38% 245 / 0.3); transform: translateY(-1px); }
        .msg-box { padding: clamp(10px, 1.2vw, 14px) clamp(12px, 1.5vw, 16px); border-radius: var(--radius); font-size: clamp(13px, 1.5vw, 14px); font-weight: 700; line-height: 1.5; margin-bottom: clamp(14px, 1.8vw, 20px); }
        .msg-box.error { background: var(--danger-soft); color: var(--danger); border: 1px solid oklch(55% 35% 25% / 0.3); }
        .msg-box.success { background: var(--success-soft); color: oklch(25% 30% 155); border: 1px solid oklch(55% 30% 155 / 0.3); }
        .finish-icon { text-align: center; font-size: 52px; margin-bottom: 14px; }
    </style>
</head>
<body>
<main class="setup-wrap">

    <div class="setup-header">
        <h1>📦 AI 图片视频创作系统 安装向导</h1>
        <p>AI 图片视频创作系统 · 三步完成部署</p>
    </div>

    <?php if ($installed): ?>
    <?php if ($step === 4): ?>
    <!-- ════════ 安装成功页（已安装后仍可访问） ════════ -->
    <div class="card-wizard" style="text-align:center;padding:clamp(40px,5vw,60px);">
        <div class="finish-icon" style="font-size:72px;margin-bottom:16px;">🎉</div>
        <h2 style="margin:0 0 8px;font-size:clamp(22px,3vw,28px);">安装成功！</h2>
        <p style="color:var(--text-muted);font-size:15px;margin:0 0 24px;">AI 图片视频创作系统已成功部署，现在可以开始使用。</p>
        <a href="/" class="btn-next" style="display:inline-flex;width:auto;padding:14px 48px;font-size:16px;text-decoration:none;">
            访问首页 →
        </a>
        <p style="color:var(--text-muted);font-size:12px;margin-top:16px;">为安全起见，建议稍后删除 <code>public/setup.php</code> 安装文件。</p>
    </div>
    <?php else: ?>
    <div class="card-wizard" style="text-align:center;padding:clamp(30px,4vw,50px);">
        <div class="finish-icon">✅</div>
        <h2 style="margin:0 0 6px;">系统已安装</h2>
        <p style="color:var(--text-muted);">为安全起见，请删除或禁止访问 <code>public/setup.php</code>。</p>
        <a href="/login" class="btn-next" style="display:inline-flex;width:auto;padding:0 40px;margin-top:16px;">前往登录</a>
    </div>
    <?php endif; ?>
    <?php else: ?>

    <!-- 步骤指示器 -->
    <div class="step-indicator">
        <div class="step-dot <?= $step >= 2 ? 'done' : ($step === 1 ? 'active' : 'pending') ?>">
            <span class="num <?= $step >= 2 ? 'done' : ($step === 1 ? 'active' : 'pending') ?>">1</span>
            <span class="label">环境检测</span>
        </div>
        <div class="step-line <?= $step >= 2 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step >= 3 ? 'done' : ($step === 2 ? 'active' : 'pending') ?>">
            <span class="num <?= $step >= 3 ? 'done' : ($step === 2 ? 'active' : 'pending') ?>">2</span>
            <span class="label">数据库配置</span>
        </div>
        <div class="step-line <?= $step >= 3 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step === 3 ? 'active' : ($step >= 4 ? 'done' : 'pending') ?>">
            <span class="num <?= $step === 3 ? 'active' : ($step >= 4 ? 'done' : 'pending') ?>">3</span>
            <span class="label">管理员设置</span>
        </div>
        <div class="step-line <?= $step >= 4 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step >= 4 ? 'done' : 'pending' ?>">
            <span class="num <?= $step >= 4 ? 'done' : 'pending' ?>">4</span>
            <span class="label">安装完成</span>
        </div>
    </div>

    <!-- 错误消息 -->
    <?php foreach ($errors as $err): ?><div class="msg-box error"><?= setup_e($err) ?></div><?php endforeach; ?>

    <!-- ════════ 步骤 1：环境检测 ════════ -->
    <?php if ($step === 1): ?>
    <div class="card-wizard">
        <div class="card-wizard-head">
            <h2>第一步：环境检测</h2>
            <p>检测服务器 PHP 版本和必需扩展</p>
        </div>
        <div class="card-wizard-body">
            <div class="check-item">
                <span class="left">PHP 版本 <code><?= setup_e($phpVersion) ?></code></span>
                <span class="badge-status <?= $phpOk ? 'pass' : 'fail' ?>"><?= $phpOk ? '≥ 8.1 ✔' : '需要 8.1+' ?></span>
            </div>
            <div class="check-item">
                <span class="left">SourceGuardian Loader</span>
                <span class="badge-status <?= $sgLoaded ? 'pass' : 'fail' ?>"><?= $sgLoaded ? '已安装 ✔' : '未安装 ✘' ?></span>
            </div>
            <?php foreach ($writableDirs as $label => $ok): ?>
            <div class="check-item">
                <span class="left">目录可写 <code><?= setup_e($label) ?></code></span>
                <span class="badge-status <?= $ok ? 'pass' : 'fail' ?>"><?= $ok ? '可写 ✔' : '不可写 ✘' ?></span>
            </div>
            <?php endforeach; ?>

            <?php if ($envOk): ?>
            <a href="/setup.php?step=2" class="btn-next">环境合格，下一步 →</a>
            <?php else: ?>
            <div class="msg-box error" style="margin-top:14px;">请修复以上红色标记的项目后 <a href="/setup.php?step=1" style="font-weight:800;">刷新页面</a> 重新检测。</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ 步骤 2：数据库配置 ════════ -->
    <?php if ($step === 2): ?>
    <?php $sub = $_GET['sub'] ?? ''; ?>
    <?php if ($sub === 'choose'): ?>
    <!-- 子步骤：检测到已有表，让用户选择模式 -->
    <?php
    $existingInSql = $_SESSION['setup_existing_in_sql'] ?? [];
    $existingOther = $_SESSION['setup_existing_other'] ?? [];
    $totalExisting = count($existingInSql) + count($existingOther);
    ?>
    <div class="card-wizard">
        <div class="card-wizard-head">
            <h2>⚠️ 检测到已有数据表</h2>
            <p>目标数据库中已存在 <?= $totalExisting ?> 张表，请选择导入方式</p>
        </div>
        <div class="card-wizard-body">
            <?php if (!empty($existingInSql)): ?>
            <div class="msg-box" style="background:var(--warning-soft);color:var(--warning);border-color:var(--warning);">
                已存在的SQL相关表（<?= count($existingInSql) ?> 张）：<br>
                <code style="font-size:12px;"><?= implode(', ', $existingInSql) ?></code>
            </div>
            <?php endif; ?>
            <?php if (!empty($existingOther)): ?>
            <div class="msg-box" style="background:var(--main-surface-soft);color:var(--text-soft);">
                其他已有表（不在安装SQL中，不会受影响）：<br>
                <code style="font-size:12px;"><?= implode(', ', $existingOther) ?></code>
            </div>
            <?php endif; ?>

            <form method="post" class="form-grid" id="importModeForm">
                <input type="hidden" name="setup_token" value="<?= setup_e(setup_token()) ?>">
                <input type="hidden" name="action" value="do_import">
                <input type="hidden" name="import_mode" id="importModeVal" value="skip">
                <input type="hidden" name="confirm" id="importConfirmVal" value="0">

                <div style="display:grid;gap:12px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:2px solid var(--success);border-radius:var(--radius);cursor:pointer;background:var(--success-soft);" id="modeSkip">
                        <input type="radio" name="mode_radio" value="skip" checked style="margin-top:2px;flex-shrink:0;" onchange="switchImportMode('skip')">
                        <div>
                            <strong style="font-size:15px;">✅ 跳过模式（推荐）</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted);">仅跳过已存在的数据表，继续导入尚未存在的表。已有数据不受影响。</p>
                        </div>
                    </label>
                    <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:2px solid var(--line);border-radius:var(--radius);cursor:pointer;" id="modeForce">
                        <input type="radio" name="mode_radio" value="force" style="margin-top:2px;flex-shrink:0;" onchange="switchImportMode('force')">
                        <div>
                            <strong style="font-size:15px;">💣 强制全新安装</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:var(--danger);">⚠️ 将删除数据库中所有已存在的表，然后重新导入最新结构。<strong>此操作不可逆，所有数据将丢失！</strong></p>
                        </div>
                    </label>
                </div>

                <div id="forceConfirmBox" style="display:none;padding:12px;border:1px solid var(--danger);border-radius:var(--radius);background:var(--danger-soft);">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="forceConfirmCheck" onchange="toggleForceConfirm()">
                        <span style="font-size:13px;font-weight:600;color:var(--danger);">我确认要删除所有数据表并重新安装，此操作不可撤销</span>
                    </label>
                </div>

                <div style="display:flex;gap:12px;">
                    <button type="submit" class="btn-next" id="btnStartImport" style="flex:1;">开始导入</button>
                    <a href="/setup.php?step=2" class="btn-install" style="flex:0 0 auto;width:auto;padding:0 24px;background:var(--main-surface-soft);color:var(--text);box-shadow:none;text-decoration:none;">← 返回</a>
                </div>
            </form>

            <script>
            function switchImportMode(mode) {
                document.getElementById('importModeVal').value = mode;
                document.getElementById('modeSkip').style.borderColor = mode === 'skip' ? 'var(--success)' : 'var(--line)';
                document.getElementById('modeSkip').style.background = mode === 'skip' ? 'var(--success-soft)' : '';
                document.getElementById('modeForce').style.borderColor = mode === 'force' ? 'var(--danger)' : 'var(--line)';
                document.getElementById('modeForce').style.background = mode === 'force' ? 'var(--danger-soft)' : '';
                document.getElementById('forceConfirmBox').style.display = mode === 'force' ? '' : 'none';
                document.getElementById('forceConfirmCheck').checked = false;
                document.getElementById('importConfirmVal').value = mode === 'skip' ? '1' : '0';
                document.getElementById('btnStartImport').textContent = mode === 'force' ? '确认删除并重新安装' : '开始导入';
                document.getElementById('btnStartImport').style.background = mode === 'force' ? 'linear-gradient(135deg, var(--danger), oklch(45% 50% 20))' : '';
            }
            function toggleForceConfirm() {
                var checked = document.getElementById('forceConfirmCheck').checked;
                document.getElementById('importConfirmVal').value = checked ? '1' : '0';
            }
            document.getElementById('importModeForm').addEventListener('submit', function(e) {
                var mode = document.getElementById('importModeVal').value;
                var confirmed = document.getElementById('importConfirmVal').value === '1';
                if (mode === 'force' && !confirmed) {
                    e.preventDefault();
                    alert('请先勾选确认框，确认要删除所有数据并重新安装。');
                }
            });
            </script>
        </div>
    </div>
    <?php else: ?>
    <!-- 正常表单：填写数据库信息 -->
    <div class="card-wizard">
        <div class="card-wizard-head">
            <h2>第二步：数据库配置</h2>
            <p>填写 MySQL 连接信息，系统将自动创建数据库和导入数据表</p>
        </div>
        <div class="card-wizard-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="setup_token" value="<?= setup_e(setup_token()) ?>">
                <input type="hidden" name="action" value="save_db">
                <div class="field-row">
                    <label for="db_host">数据库地址</label>
                    <input type="text" id="db_host" name="db_host" value="<?= setup_value('db_host', '127.0.0.1') ?>" required>
                    <span class="hint">一般填写 127.0.0.1 或 localhost</span>
                </div>
                <div class="form-inline">
                    <div class="field-row">
                        <label for="db_port">端口</label>
                        <input type="number" id="db_port" name="db_port" value="<?= setup_value('db_port', '3306') ?>" required>
                    </div>
                    <div class="field-row">
                        <label for="db_name">数据库名</label>
                        <input type="text" id="db_name" name="db_name" value="<?= setup_value('db_name', 'image_platform') ?>" required>
                        <span class="hint">仅字母、数字、下划线</span>
                    </div>
                </div>
                <div class="field-row">
                    <label for="db_user">数据库用户名</label>
                    <input type="text" id="db_user" name="db_user" value="<?= setup_value('db_user', 'root') ?>" required>
                </div>
                <div class="field-row">
                    <label for="db_pass">数据库密码</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?= setup_value('db_pass') ?>">
                    <span class="hint">留空表示无密码（仅限本地测试环境）</span>
                </div>
                <button type="submit" class="btn-next">测试连接并安装</button>
            </form>
            <a href="/setup.php?step=1" style="display:block;text-align:center;margin-top:12px;color:var(--text-muted);font-size:13px;font-weight:600;">← 返回上一步</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ════════ 步骤 3：管理员设置 ════════ -->
    <?php if ($step === 3): ?>
    <div class="card-wizard">
        <div class="card-wizard-head">
            <h2>第三步：管理员设置</h2>
            <p>创建管理员账号并配置平台参数</p>
        </div>
        <div class="card-wizard-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="setup_token" value="<?= setup_e(setup_token()) ?>">
                <input type="hidden" name="action" value="install">
                <div class="form-inline">
                    <div class="field-row">
                        <label for="admin_user">管理员账号</label>
                        <input type="text" id="admin_user" name="admin_user" value="<?= setup_value('admin_user', 'admin') ?>" pattern="[a-zA-Z0-9_]{3,32}" required>
                    </div>
                    <div class="field-row">
                        <label for="platform_name">平台名称</label>
                        <input type="text" id="platform_name" name="platform_name" value="<?= setup_value('platform_name', 'AI 图片视频创作系统') ?>" required>
                    </div>
                </div>
                <div class="form-inline">
                    <div class="field-row">
                        <label for="admin_pass">管理员密码</label>
                        <input type="password" id="admin_pass" name="admin_pass" minlength="6" required>
                    </div>
                    <div class="field-row">
                        <label for="admin_pass_confirm">确认密码</label>
                        <input type="password" id="admin_pass_confirm" name="admin_pass_confirm" minlength="6" required>
                    </div>
                </div>
                <div class="form-inline">
                    <div class="field-row">
                        <label for="balance_label">余额名称</label>
                        <input type="text" id="balance_label" name="balance_label" value="<?= setup_value('balance_label', '余额') ?>" required>
                    </div>
                </div>
                <div class="form-inline">
                    <div class="field-row">
                        <label for="timeout">生成超时（秒）</label>
                        <input type="number" id="timeout" name="timeout" min="30" value="<?= setup_value('timeout', '300') ?>" required>
                    </div>
                    <div class="field-row">
                        <label for="worker_sleep">Worker 休眠（秒）</label>
                        <input type="number" id="worker_sleep" name="worker_sleep" min="1" value="<?= setup_value('worker_sleep', '3') ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn-install">完成安装</button>
            </form>
            <a href="/setup.php?step=2" style="display:block;text-align:center;margin-top:12px;color:var(--text-muted);font-size:13px;font-weight:600;">← 返回上一步</a>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
