<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_watermark_columns();
ensure_ai_models_api_columns();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $modelId = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $modelType = in_array($_POST['model_type'] ?? '', ['image', 'video', 'chat'], true)
            ? $_POST['model_type'] : 'image';
        // 多选分辨率：checkbox 数组 → 逗号分隔字符串
        $resChecks = $_POST['res_check'] ?? [];
        if (!is_array($resChecks)) $resChecks = [];
        $resChecks = array_intersect($resChecks, ['1K', '2K', '4K']);
        $resolutionLevels = $resChecks ? implode(',', $resChecks) : '';

        if ($resolutionLevels === '') {
            flash('error', '请至少选择一项支持的分辨率。');
            redirect('/admin/ai_models');
        }

        if ($name === '' || $modelId === '' || $baseUrl === '' || $apiKey === '') {
            flash('error', '请填写完整信息。');
            redirect('/admin/ai_models');
        }

        $credits   = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $credits1K = $_POST['credits_1k'] !== '' ? max(1, (int) $_POST['credits_1k']) : null;
        $credits2K = $_POST['credits_2k'] !== '' ? max(1, (int) $_POST['credits_2k']) : null;
        $credits4K = $_POST['credits_4k'] !== '' ? max(1, (int) $_POST['credits_4k']) : null;
        $wpCost          = max(0, (int) ($_POST['watermark_point_cost'] ?? 0));
        $apiPath         = trim((string) ($_POST['api_path'] ?? ''));
        $editApiPath     = trim((string) ($_POST['edit_api_path'] ?? ''));
        $timeout         = max(0, (int) ($_POST['timeout'] ?? 0));
        $downloadTimeout = max(0, (int) ($_POST['download_timeout'] ?? 0));
        $siteType        = in_array($_POST['site_type'] ?? '', ['standard', 'agnes', 'grok', 'seedance'], true) ? $_POST['site_type'] : 'standard';
        $agnesConfig     = build_agnes_config($_POST);
        $grokConfig      = build_grok_config($_POST);
        $seedanceConfig  = build_seedance_config($_POST);
        $proxyType       = in_array($_POST['download_proxy_type'] ?? '', ['none', 'free', 'custom'], true) ? $_POST['download_proxy_type'] : 'none';
        $proxyUrl        = trim((string) ($_POST['download_proxy_url'] ?? ''));

        $stmt = db()->prepare(
            'INSERT INTO ai_models (name, model_id, base_url, api_path, edit_api_path, timeout, download_timeout, site_type, agnes_config, grok_config, seedance_config, download_proxy_type, download_proxy_url, api_key, sort_order, model_type, credits, resolution_levels, credits_1k, credits_2k, credits_4k, watermark_point_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $modelId, $baseUrl, $apiPath, $editApiPath, $timeout, $downloadTimeout, $siteType, $agnesConfig, $grokConfig, $seedanceConfig, $proxyType, $proxyUrl, $apiKey, $sortOrder, $modelType, $credits, $resolutionLevels, $credits1K, $credits2K, $credits4K, $wpCost]);
        flash('success', '模型已添加。');
        redirect('/admin/ai_models');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $modelId = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive = (int) ($_POST['is_active'] ?? 1);
        $modelType = in_array($_POST['model_type'] ?? '', ['image', 'video', 'chat'], true)
            ? $_POST['model_type'] : 'image';
        // 多选分辨率：checkbox 数组 → 逗号分隔字符串
        $resChecks = $_POST['res_check'] ?? [];
        if (!is_array($resChecks)) $resChecks = [];
        $resChecks = array_intersect($resChecks, ['1K', '2K', '4K']);
        $resolutionLevels = $resChecks ? implode(',', $resChecks) : '';

        if ($resolutionLevels === '') {
            flash('error', '请至少选择一项支持的分辨率。');
            redirect('/admin/ai_models');
        }

        if ($id < 1 || $name === '' || $modelId === '' || $baseUrl === '') {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }

        $credits   = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $credits1K = $_POST['credits_1k'] !== '' ? max(1, (int) $_POST['credits_1k']) : null;
        $credits2K = $_POST['credits_2k'] !== '' ? max(1, (int) $_POST['credits_2k']) : null;
        $credits4K = $_POST['credits_4k'] !== '' ? max(1, (int) $_POST['credits_4k']) : null;
        $wpCost          = max(0, (int) ($_POST['watermark_point_cost'] ?? 0));
        $apiPath         = trim((string) ($_POST['api_path'] ?? ''));
        $editApiPath     = trim((string) ($_POST['edit_api_path'] ?? ''));
        $timeout         = max(0, (int) ($_POST['timeout'] ?? 0));
        $downloadTimeout = max(0, (int) ($_POST['download_timeout'] ?? 0));
        $siteType        = in_array($_POST['site_type'] ?? '', ['standard', 'agnes', 'grok', 'seedance'], true) ? $_POST['site_type'] : 'standard';
        $agnesConfig     = build_agnes_config($_POST);
        $grokConfig      = build_grok_config($_POST);
        $seedanceConfig  = build_seedance_config($_POST);
        $proxyType       = in_array($_POST['download_proxy_type'] ?? '', ['none', 'free', 'custom'], true) ? $_POST['download_proxy_type'] : 'none';
        $proxyUrl        = trim((string) ($_POST['download_proxy_url'] ?? ''));

        if ($apiKey !== '') {
            $stmt = db()->prepare('UPDATE ai_models SET name=?, model_id=?, base_url=?, api_path=?, edit_api_path=?, timeout=?, download_timeout=?, site_type=?, agnes_config=?, grok_config=?, seedance_config=?, download_proxy_type=?, download_proxy_url=?, api_key=?, sort_order=?, is_active=?, model_type=?, credits=?, resolution_levels=?, credits_1k=?, credits_2k=?, credits_4k=?, watermark_point_cost=? WHERE id=?');
            $stmt->execute([$name, $modelId, $baseUrl, $apiPath, $editApiPath, $timeout, $downloadTimeout, $siteType, $agnesConfig, $grokConfig, $seedanceConfig, $proxyType, $proxyUrl, $apiKey, $sortOrder, $isActive, $modelType, $credits, $resolutionLevels, $credits1K, $credits2K, $credits4K, $wpCost, $id]);
        } else {
            $stmt = db()->prepare('UPDATE ai_models SET name=?, model_id=?, base_url=?, api_path=?, edit_api_path=?, timeout=?, download_timeout=?, site_type=?, agnes_config=?, grok_config=?, seedance_config=?, download_proxy_type=?, download_proxy_url=?, sort_order=?, is_active=?, model_type=?, credits=?, resolution_levels=?, credits_1k=?, credits_2k=?, credits_4k=?, watermark_point_cost=? WHERE id=?');
            $stmt->execute([$name, $modelId, $baseUrl, $apiPath, $editApiPath, $timeout, $downloadTimeout, $siteType, $agnesConfig, $grokConfig, $seedanceConfig, $proxyType, $proxyUrl, $sortOrder, $isActive, $modelType, $credits, $resolutionLevels, $credits1K, $credits2K, $credits4K, $wpCost, $id]);
        }
        flash('success', '模型已更新。');
        redirect('/admin/ai_models');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }
        $stmt = db()->prepare('DELETE FROM ai_models WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', '模型已删除。');
        redirect('/admin/ai_models');
    }

    flash('error', '未知操作。');
    redirect('/admin/ai_models');
}

/**
 * 构建 Agnes 分辨率配置 JSON
 */
function build_agnes_config(array $post): string
{
    $resolutions = [];
    foreach (['480p', '720p', '1080p'] as $res) {
        $key = 'agnes_' . $res;
        $enabled = !empty($post[$key . '_enabled']);
        $credits = max(1, (int) ($post[$key . '_credits'] ?? 1));
        $resolutions[$res] = ['enabled' => $enabled, 'credits' => $credits];
    }

    // 时长配置
    $durationMode = in_array($post['agnes_duration_mode'] ?? '', ['custom', 'fixed'], true)
        ? $post['agnes_duration_mode'] : 'custom';
    $duration = [
        'mode' => $durationMode,
    ];

    if ($durationMode === 'custom') {
        $duration['max_seconds'] = max(1, min(60, (int) ($post['agnes_max_seconds'] ?? 15)));
        $duration['price_per_second'] = max(1, (int) ($post['agnes_price_per_second'] ?? 1));
        $duration['tiers'] = [];
    } else {
        // 固定档位
        $tiers = [];
        $tierCount = max(1, (int) ($post['agnes_tier_count'] ?? 3));
        for ($i = 1; $i <= $tierCount; $i++) {
            $sec = max(1, min(60, (int) ($post['agnes_tier_' . $i . '_sec'] ?? 0)));
            $crd = max(1, (int) ($post['agnes_tier_' . $i . '_credits'] ?? 0));
            if ($sec > 0 && $crd > 0) {
                $tiers[] = ['seconds' => $sec, 'credits' => $crd];
            }
        }
        $duration['tiers'] = $tiers;
        $duration['max_seconds'] = 0;
        $duration['price_per_second'] = 0;
    }

    $resolutions['_duration'] = $duration;

    // 高级参数
    $resolutions['_advanced'] = [
        'frame_rate'        => !empty($post['agnes_adv_fps']),
        'inference_steps'   => !empty($post['agnes_adv_steps']),
        'seed'              => !empty($post['agnes_adv_seed']),
        'negative_prompt'   => !empty($post['agnes_adv_neg']),
    ];

    return json_encode($resolutions, JSON_UNESCAPED_UNICODE);
}

/**
 * 解析 Agnes 分辨率配置 JSON
 * @return array{480p: array, 720p: array, 1080p: array}
 */
function parse_agnes_config(?string $json): array
{
    $default = [
        '480p' => ['enabled' => true, 'credits' => 5],
        '720p' => ['enabled' => true, 'credits' => 10],
        '1080p' => ['enabled' => false, 'credits' => 20],
        '_duration' => ['mode' => 'custom', 'max_seconds' => 15, 'price_per_second' => 1, 'tiers' => []],
        '_advanced' => ['frame_rate' => false, 'inference_steps' => false, 'seed' => false, 'negative_prompt' => false],
    ];
    if ($json === null || $json === '') return $default;
    $data = json_decode($json, true);
    if (!is_array($data)) return $default;
    return array_merge($default, $data);
}

/**
 * 构建 Seedance 分辨率+时长配置 JSON
 *
 * 支持两种计费模式：
 *   fixed:  固定时长档位，按秒计费（如 5s=15积分, 10s=25积分）
 *   custom: 自定义时长，按秒×单价计费（与 Grok 相同逻辑）
 */
function build_seedance_config(array $post): string
{
    $resolutions = [];
    foreach (['480p', '720p', '1080p'] as $res) {
        $key = 'seedance_' . $res;
        $enabled = !empty($post[$key . '_enabled']);
        $credits = max(1, (int) ($post[$key . '_credits'] ?? 1));
        $resolutions[$res] = ['enabled' => $enabled, 'credits' => $credits];
    }

    // 时长模式：fixed（固定档位）或 custom（按秒计费）
    $durationMode = in_array($post['seedance_duration_mode'] ?? '', ['fixed', 'custom'], true)
        ? $post['seedance_duration_mode'] : 'fixed';
    $maxSeconds = max(1, min(60, (int) ($post['seedance_max_seconds'] ?? 15)));
    $pricePerSec = max(1, (int) ($post['seedance_price_per_second'] ?? 2));

    $resolutions['_duration'] = [
        'mode'              => $durationMode,
        'max_seconds'       => $maxSeconds,
        'price_per_second'  => $pricePerSec,
    ];

    // 固定档位模式：收集档位数据
    if ($durationMode === 'fixed') {
        $tierCount = max(1, min(10, (int) ($post['seedance_tier_count'] ?? 3)));
        $tiers = [];
        for ($i = 1; $i <= $tierCount; $i++) {
            $sec = max(1, (int) ($post["seedance_tier_{$i}_sec"] ?? 0));
            $cr = max(1, (int) ($post["seedance_tier_{$i}_credits"] ?? 0));
            if ($sec > 0 && $cr > 0) {
                $tiers[] = ['seconds' => $sec, 'credits' => $cr];
            }
        }
        if (empty($tiers)) {
            $tiers = [
                ['seconds' => 5, 'credits' => 15],
                ['seconds' => 10, 'credits' => 25],
                ['seconds' => 15, 'credits' => 35],
            ];
        }
        $resolutions['_duration']['tiers'] = $tiers;
    }

    return json_encode($resolutions, JSON_UNESCAPED_UNICODE);
}

/**
 * 解析 Seedance 配置 JSON
 */
function parse_seedance_config(?string $json): array
{
    $default = [
        '480p' => ['enabled' => true, 'credits' => 5],
        '720p' => ['enabled' => true, 'credits' => 10],
        '1080p' => ['enabled' => false, 'credits' => 15],
        '_duration' => [
            'mode'              => 'fixed',
            'max_seconds'       => 15,
            'price_per_second'  => 2,
            'tiers'             => [
                ['seconds' => 5, 'credits' => 15],
                ['seconds' => 10, 'credits' => 25],
                ['seconds' => 15, 'credits' => 35],
            ],
        ],
    ];
    if ($json === null || $json === '') return $default;
    $data = json_decode($json, true);
    if (!is_array($data)) return $default;
    return array_merge($default, $data);
}

/**
 * 构建 Grok 配置 JSON（表单提交时）
 */
function build_grok_config(array $post): string
{
    $cfg = [
        '480p' => ['enabled' => !empty($post['grok_480p_enabled']), 'credits' => max(1, (int)($post['grok_480p_credits'] ?? 5))],
        '720p' => ['enabled' => !empty($post['grok_720p_enabled']), 'credits' => max(1, (int)($post['grok_720p_credits'] ?? 10))],
        '_duration' => [
            'max_seconds'      => max(1, (int)($post['grok_max_seconds'] ?? 15)),
            'price_per_second' => max(1, (int)($post['grok_price_per_second'] ?? 2)),
        ],
    ];
    return json_encode($cfg, JSON_UNESCAPED_UNICODE);
}

/**
 * 解析 Grok 配置 JSON
 */
function parse_grok_config(?string $json): array
{
    $default = [
        '480p' => ['enabled' => true, 'credits' => 5],
        '720p' => ['enabled' => true, 'credits' => 10],
        '_duration' => [
            'max_seconds'      => 15,
            'price_per_second' => 2,
        ],
    ];
    if ($json === null || $json === '') return $default;
    $data = json_decode($json, true);
    if (!is_array($data)) return $default;
    return array_merge($default, $data);
}

$stmt = db()->query('SELECT * FROM ai_models ORDER BY model_type, sort_order ASC, id ASC');
$models = $stmt->fetchAll();

// 按模型类型分组
$imageModels = array_filter($models, function($m) { return ($m['model_type'] ?? 'image') === 'image'; });
$videoModels = array_filter($models, function($m) { return ($m['model_type'] ?? '') === 'video'; });
$chatModels  = array_filter($models, function($m) { return ($m['model_type'] ?? '') === 'chat'; });
$totalCount  = count($models);

// 渲染模型行（按类型不同显示不同列）
function renderModelRow(array $m, string $modeType = 'image'): string
{
    $mid      = (int) $m['id'];
    $sort     = (int) $m['sort_order'];
    $name     = e($m['name']);
    $modelId  = e($m['model_id']);
    $baseUrl  = e($m['base_url']);
    $apiPath  = e($m['api_path'] ?? '');
    $timeout  = (int)($m['timeout'] ?? 0);
    $isActive = (int) $m['is_active'];
    $statusBadge = $isActive === 1
        ? '<span class="badge badge-success" style="font-size:11px;">开启</span>'
        : '<span class="badge badge-danger" style="font-size:11px;">关闭</span>';
    $deleteForm = '<form method="post" class="inline-delete-form" onsubmit="return confirm(\'确定删除「' . $name . '」？此操作不可撤销。\')">'
        . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $mid . '">'
        . '<button class="button danger small" type="submit">删除</button></form>';
    $editBtn = '<button class="button secondary small" onclick="openEditModal(' . $mid . ')">编辑</button>';
    $actions = '<div class="table-action-group">' . $editBtn . $deleteForm . '</div>';

    $row  = '<tr>';
    $row .= '<td>' . $sort . '</td>';
    $row .= '<td>' . $name . '</td>';
    $row .= '<td>' . $modelId . '</td>';
    $row .= '<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $baseUrl . '</td>';
    $row .= '<td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . ($apiPath !== '' ? $apiPath : '<span style="color:var(--text-muted);">默认</span>') . '</td>';

    if ($modeType === 'image') {
        $resLevels = e($m['resolution_levels'] ?? '1K');
        $p1k  = (int)($m['credits_1k'] ?? 0) ?: '-';
        $p2k  = (int)($m['credits_2k'] ?? 0) ?: '-';
        $p4k  = (int)($m['credits_4k'] ?? 0) ?: '-';
        $row .= '<td style="font-size:11px;">' . $resLevels . '</td>';
        $row .= '<td>' . e((string)$p1k) . ' / ' . e((string)$p2k) . ' / ' . e((string)$p4k) . '</td>';
    }

    $downloadTimeoutVal = (int)($m['download_timeout'] ?? 0);
    $timeoutDisplay = ($timeout > 0 ? $timeout . 's' : '<span style="color:var(--text-muted);">默认</span>');
    $dlDisplay = ($downloadTimeoutVal > 0 ? $downloadTimeoutVal . 's' : '<span style="color:var(--text-muted);">默认</span>');
    $st = $m['site_type'] ?? 'standard';
    if ($st === 'agnes') {
        $siteLabel = '<span style="font-size:10px;color:var(--accent);">Agnes</span>';
    } elseif ($st === 'grok') {
        $siteLabel = '<span style="font-size:10px;color:#10b981;">Grok</span>';
    } elseif ($st === 'seedance') {
        $siteLabel = '<span style="font-size:10px;color:#f59e0b;">Seedance</span>';
    } else {
        $siteLabel = '<span style="font-size:10px;color:var(--text-muted);">标准</span>';
    }
    $row .= '<td style="font-size:11px;">' . $siteLabel . '</td>';
    $row .= '<td style="font-size:11px;">' . $timeoutDisplay . ' / ' . $dlDisplay . '</td>';
    $row .= '<td>' . $statusBadge . '</td>';
    $row .= '<td>' . $actions . '</td>';
    $row .= '</tr>';
    return $row;
}

// ═══ 预计算模型 JSON（隔离 PHP 数据与 JS 代码） ═══
$modelDataForJs = [];
foreach (array_merge($imageModels, $videoModels, $chatModels) as $m) {
    $modelDataForJs[(int)$m['id']] = [
        'sort_order'           => (int)($m['sort_order'] ?? 0),
        'name'                 => $m['name'] ?? '',
        'model_id'             => $m['model_id'] ?? '',
        'base_url'             => $m['base_url'] ?? '',
        'api_path'             => $m['api_path'] ?? '',
        'edit_api_path'        => $m['edit_api_path'] ?? '',
        'timeout'              => (int)($m['timeout'] ?? 0),
        'download_timeout'     => (int)($m['download_timeout'] ?? 0),
        'site_type'            => $m['site_type'] ?? 'standard',
        'download_proxy_type'  => $m['download_proxy_type'] ?? 'none',
        'download_proxy_url'   => $m['download_proxy_url'] ?? '',
        'agnes_config'         => $m['agnes_config'] ?? null,
        'grok_config'          => $m['grok_config'] ?? null,
        'seedance_config'      => $m['seedance_config'] ?? null,
        'model_type'           => $m['model_type'] ?? 'image',
        'resolution_levels'    => $m['resolution_levels'] ?? '1K',
        'credits_1k'           => (int)($m['credits_1k'] ?? 0),
        'credits_2k'           => (int)($m['credits_2k'] ?? 0),
        'credits_4k'           => (int)($m['credits_4k'] ?? 0),
        'credits'              => (int)($m['credits'] ?? 0),
        'watermark_point_cost' => (int)($m['watermark_point_cost'] ?? 0),
        'is_active'            => (int)($m['is_active'] ?? 0),
    ];
}
$modelDataJson = json_encode($modelDataForJs, JSON_UNESCAPED_UNICODE);
if ($modelDataJson === false) {
    $modelDataJson = '{}';
}

render_header('模型管理', 'admin');
render_admin_nav('ai_models');
?>
<style>
.model-create-form .field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}
.model-create-form .field-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}
.model-create-form .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.model-create-form .field span {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.model-create-form input,
.model-create-form select {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    font-size: 14px;
    border:1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--main-surface);
    color: var(--text);
}
.model-create-form input:focus,
.model-create-form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.table-action-group {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: nowrap;
}
.table-action-group .button {
    white-space: nowrap;
    padding: 5px 12px;
    font-size: 12px;
}
.inline-delete-form {
    display: inline-flex;
}
.model-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-bottom: 4px;
}
.model-table th,
.model-table td {
    padding: 10px 12px;
    vertical-align: middle;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
}
.model-table thead th {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-soft);
    background: var(--main-surface-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
    border-bottom: 2px solid var(--line);
}
.model-table tbody tr:hover {
    background: rgba(0,0,0,.02);
}
/* 图片模型 (10列) — 总计100% */
.model-table .col-sort   { width: 4%; }
.model-table .col-name   { width: 9%; }
.model-table .col-model  { width: 10%; }
.model-table .col-url    { width: 15%; }
.model-table .col-path   { width: 12%; }
.model-table .col-site   { width: 5%; }
.model-table .col-res    { width: 7%; }
.model-table .col-price  { width: 14%; }
.model-table .col-timeout { width: 6%; }
.model-table .col-status { width: 5%; }
.model-table .col-action { width: 13%; min-width: 110px; white-space: nowrap; }
/* 视频/对话模型 (8列) — 总计100% */
.model-table.compact .col-name   { width: 14%; }
.model-table.compact .col-model  { width: 14%; }
.model-table.compact .col-url    { width: 22%; }
.model-table.compact .col-path   { width: 15%; }
.model-table.compact .col-timeout { width: 7%; }
.model-table.compact .col-status { width: 7%; }
.model-table.compact .col-action { width: 16%; }
.agnes-res-card small {
    font-size: 11px;
    color: var(--text-muted);
}
.agnes-config-section .hint,
.edit-agnes-config-section .hint {
    display: block;
    text-align: center;
}

.card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.muted-hint {
    font-size: 11px;
    color: var(--text-muted);
}

/* ── 弹窗全局样式 ── */
.modal-overlay {
    animation: modalFadeIn 0.25s ease-out;
}
@keyframes modalFadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.modal-card {
    animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(24px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 22px 28px;
    border-bottom: 1px solid var(--line);
    background: linear-gradient(to bottom, var(--main-surface-soft), transparent);
}
.modal-header h2 {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text);
}
.modal-header .header-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 18px;
    background: var(--accent);
    color: #fff;
    flex-shrink: 0;
}
.modal-close {
    width: 32px;
    height: 32px;
    border: none;
    background: rgba(0,0,0,.04);
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.modal-close:hover {
    background: rgba(0,0,0,.1);
    color: var(--text);
}
.modal-body {
    padding: 24px 28px;
}
.modal-footer {
    display: flex;
    gap: 12px;
    padding: 16px 28px 24px;
    border-top: 1px solid var(--line);
}

/* ── 表单分节卡片 ── */
.form-section {
    background: var(--main-surface-soft);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
}
.form-section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 16px 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.form-section-header .section-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
}

/* ── 优化输入框 ── */
.model-create-form .field span {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.model-create-form input,
.model-create-form select {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 10px 12px;
    font-size: 14px;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    background: var(--main-surface);
    color: var(--text);
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.model-create-form input:hover,
.model-create-form select:hover {
    border-color: var(--accent-soft);
    background: var(--main-surface);
}
.model-create-form input:focus,
.model-create-form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
    background: var(--main-surface);
}
.model-create-form input::placeholder {
    color: var(--text-muted);
    opacity: 0.7;
}
.model-create-form select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23666' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 5.646a.5.5 0 0 1 .708 0L8 8.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}
.model-create-form .hint {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
    line-height: 1.4;
}
.model-create-form .hint::before {
    content: 'ℹ️';
    font-size: 12px;
    flex-shrink: 0;
}

/* ── 分辨率选择 ── */
.resolution-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.resolution-chip {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s;
    user-select: none;
    background: var(--main-surface);
}
.resolution-chip:hover {
    border-color: var(--accent);
    background: var(--accent-glow);
}
.resolution-chip input[type="checkbox"] {
    display: none;
}
.resolution-chip .chip-check {
    width: 18px;
    height: 18px;
    border-radius: 5px;
    border: 2px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}
.resolution-chip input:checked + .chip-check {
    background: var(--accent);
    border-color: var(--accent);
}
.resolution-chip input:checked + .chip-check::after {
    content: '✓';
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}
.resolution-chip:has(input:checked) {
    border-color: var(--accent);
    background: var(--accent-glow);
    color: var(--accent);
}
.resolution-chip .chip-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 400;
}

/* ── 优惠色点数区域 ── */
.pricing-group {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}
.pricing-card {
    text-align: center;
    background: var(--main-surface);
    border: 1.5px solid var(--line);
    border-radius: 10px;
    padding: 14px 12px;
    transition: border-color 0.2s;
}
.pricing-card:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.pricing-card .pricing-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-soft);
    margin-bottom: 6px;
}
.pricing-card input {
    text-align: center;
    font-size: 16px;
    font-weight: 700;
    border: none !important;
    padding: 4px 8px !important;
    background: transparent !important;
    outline: none;
    color: var(--accent);
    width: 100%;
    box-shadow: none !important;
}

/* ── 按钮增强 ── */
.modal-footer .button {
    padding: 11px 24px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 10px;
    transition: all 0.2s;
}
.modal-footer .button.primary {
    flex: 1;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.modal-footer .button.primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0,0,0,.12);
}
.modal-footer .button.secondary {
    background: var(--main-surface-soft);
    border: 1.5px solid var(--line);
    color: var(--text-soft);
}
.modal-footer .button.secondary:hover {
    background: var(--accent-glow);
    border-color: var(--accent);
    color: var(--accent);
}

/* ── 移动端适配 ── */
@media (max-width: 768px) {
    /* ── 表单：统一折叠为单列，输入框全宽 ── */
    .model-create-form .field-grid,
    .model-create-form .field-grid-3 {
        grid-template-columns: 1fr;
    }
    .model-create-form .field {
        min-width: 0;
    }
    .model-create-form input,
    .model-create-form select {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        font-size: 16px; /* 防止 iOS 缩放 */
        padding: 10px;
    }

    /* ── 分辨率 checkbox：允许换行 ── */
    .model-create-form .field-grid-3 .resolution-only {
        grid-column: span 1 !important;
    }
    .model-create-form .field-grid-3 .edit-res-field {
        grid-column: span 1 !important;
    }
    .checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 14px;
    }
    .checkbox-group label {
        font-size: 13px;
        white-space: nowrap;
    }

    /* ── 点数字段：移动端单列 ── */
    .field-grid-3.credits-fields,
    .field-grid-3.edit-credits-fields {
        grid-template-columns: 1fr !important;
    }

    /* ── 上传列（排序）单独处理 ── */
    .model-create-form .field-grid:only-child,
    .model-create-form .field-grid:has(.field:only-child) {
        /* 只有排序的 field-grid，输入框不要撑太宽 */
    }

    /* ── 表格水平滚动 ── */
    .model-table {
        table-layout: auto;
        min-width: 700px;
        font-size: 12px;
    }
    .model-table th,
    .model-table td {
        padding: 8px;
        white-space: nowrap;
    }
    .model-table .col-sort   { width: auto; min-width: 36px; }
    .model-table .col-name   { width: auto; min-width: 80px; }
    .model-table .col-model  { width: auto; min-width: 100px; }
    .model-table .col-url    { width: auto; min-width: 140px; }
    .model-table .col-path   { width: auto; min-width: 100px; }
    .model-table .col-site   { width: auto; min-width: 45px; }
    .model-table .col-res    { width: auto; min-width: 60px; }
    .model-table .col-price  { width: auto; min-width: 90px; }
    .model-table .col-timeout { width: auto; min-width: 50px; }
    .model-table .col-status { width: auto; min-width: 50px; }
    .model-table .col-action { width: auto; min-width: 110px; }

    /* ── 编辑弹窗 ── */
    .modal-card { max-width: 95vw !important; }
    .modal-card .field-grid,
    .modal-card .field-grid-3 {
        grid-template-columns: 1fr;
    }

    /* ── 通用 ── */
    .card-head { flex-direction: column; align-items: flex-start; }
    .code-create-card,
    .section-card {
        max-width: 100%;
        overflow: hidden;
    }
}
</style>
<main class="grid">

    <!-- ═══ 已配置模型列表 ═══ -->
    <section class="card section-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Models</p>
                <h2>已配置模型</h2>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <button class="button primary" onclick="openCreateModal()" style="font-size:13px;padding:8px 18px;">+ 新增模型</button>
                <span class="badge">共 <?= $totalCount ?> 个</span>
            </div>
        </div>

        <?php if ($totalCount === 0): ?>
            <!-- ═══ 空列表提示 ═══ -->
            <div style="text-align:center;padding:60px 20px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:16px;opacity:0.5;">🤖</div>
                <p style="font-size:16px;margin:0;">还没有模型哦~赶紧新增一个吧</p>
            </div>
        <?php else: ?>
            <!-- ═══ 图片模型 ═══ -->
            <?php if (!empty($imageModels)): ?>
            <h3 style="padding:12px 20px 0;margin:0;font-size:15px;">🖼️ 图片模型 <span style="font-size:12px;color:var(--text-muted);font-weight:400;">(<?= count($imageModels) ?> 个)</span></h3>
            <div class="table-wrap">
                <table class="model-table">
                    <thead>
                        <tr><th class="col-sort">排序</th><th class="col-name">名称</th><th class="col-model">模型ID</th><th class="col-url">BASEURL</th><th class="col-path">接口路径</th><th class="col-site">站点</th><th class="col-res">分辨率</th><th class="col-price">价格(1K/2K/4K)</th><th class="col-timeout">API/下载(s)</th><th class="col-status">状态</th><th class="col-action">操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imageModels as $m): ?>
                            <?= renderModelRow($m) ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ═══ 视频模型 ═══ -->
            <?php if (!empty($videoModels)): ?>
            <h3 style="padding:12px 20px 0;margin:0;font-size:15px;">🎬 视频模型 <span style="font-size:12px;color:var(--text-muted);font-weight:400;">(<?= count($videoModels) ?> 个)</span></h3>
            <div class="table-wrap">
                <table class="model-table compact">
                    <thead>
                        <tr><th class="col-sort">排序</th><th class="col-name">名称</th><th class="col-model">模型ID</th><th class="col-url">BASEURL</th><th class="col-path">接口路径</th><th class="col-site">站点</th><th class="col-timeout">API/下载(s)</th><th class="col-status">状态</th><th class="col-action">操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($videoModels as $m): ?>
                            <?= renderModelRow($m, 'video') ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ═══ 对话模型 ═══ -->
            <?php if (!empty($chatModels)): ?>
            <h3 style="padding:12px 20px 0;margin:0;font-size:15px;">💬 对话模型 <span style="font-size:12px;color:var(--text-muted);font-weight:400;">(<?= count($chatModels) ?> 个)</span></h3>
            <div class="table-wrap">
                <table class="model-table compact">
                    <thead>
                        <tr><th class="col-sort">排序</th><th class="col-name">名称</th><th class="col-model">模型ID</th><th class="col-url">BASEURL</th><th class="col-path">接口路径</th><th class="col-site">站点</th><th class="col-timeout">API/下载(s)</th><th class="col-status">状态</th><th class="col-action">操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chatModels as $m): ?>
                            <?= renderModelRow($m, 'chat') ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<!-- ═══ 新增模型弹窗 ═══ -->
<div id="createModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:999;align-items:center;justify-content:center;">
    <div class="modal-card" style="background:var(--main-surface);border-radius:18px;max-width:700px;width:90vw;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.18);">
        <!-- 弹窗头部 -->
        <div class="modal-header">
            <h2><span class="header-icon">+</span>新增模型</h2>
            <button class="modal-close" onclick="closeCreateModal()" title="关闭">&times;</button>
        </div>
        <!-- 弹窗表单 -->
        <form method="post" id="createForm" class="model-create-form" style="display:flex;flex-direction:column;gap:0;">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <!-- ═══ 基本信息 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>基本信息</div>
                    <div class="field-grid">
                        <label class="field">
                            <span>显示名称</span>
                            <input name="name" placeholder="例如：GPT Image 2" required>
                        </label>
                        <label class="field">
                            <span>模型 ID</span>
                            <input name="model_id" placeholder="例如：gpt-image-2" required>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>排序</span>
                            <input name="sort_order" type="number" min="0" value="0" required>
                        </label>
                        <label class="field">
                            <span>模型类型</span>
                            <select name="model_type" id="modelTypeSelect" onchange="toggleResolutionFields()">
                                <option value="image" selected>🎨 图片生成</option>
                                <option value="video">🎬 视频生成</option>
                                <option value="chat">💬 AI对话</option>
                            </select>
                        </label>
                    </div>
                    <div class="field-grid site-type-row" style="display:none;">
                        <label class="field">
                            <span>视频站点类型</span>
                            <select name="site_type" id="siteTypeSelect" onchange="toggleSiteTypeConfig()">
                                <option value="standard">🌐 标准 OpenAI / Sora</option>
                                <option value="agnes">🎬 Agnes</option>
                                <option value="grok">🤖 Grok 新版视频</option>
                                <option value="seedance">💃 Seedance</option>
                            </select>
                            <small class="hint">Grok 异步轮询，480p/720p。Seedance 支持480p/720p/1080p+固定时长档位。</small>
                        </label>
                    </div>
                </div>

                <!-- ═══ Agnes 分辨率配置 ═══ -->
                <div class="form-section agnes-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Agnes 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <?php foreach (['480p', '720p', '1080p'] as $res): ?>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="agnes_<?= $res ?>_enabled" value="1" data-agnes-res-check="<?= $res ?>" <?= $res === '480p' ? 'checked' : '' ?>>
                                <span><?= $res ?></span>
                            </div>
                            <input type="number" name="agnes_<?= $res ?>_credits" min="1" value="<?= ['480p'=>5,'720p'=>10,'1080p'=>20][$res] ?>" placeholder="积分" class="agnes-credits-input">
                            <small>积分/次</small>
                            <small class="hint" style="font-size:10px;">最大 <?= ['480p'=>'40s','720p'=>'17s','1080p'=>'7s'][$res] ?></small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="hint" data-agnes-hint style="margin-top:8px;">至少启用一个分辨率</small>

                    <!-- Agnes 时长配置 -->
                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长</div>
                        <div class="field-grid" style="margin-bottom:8px;">
                            <label class="field">
                                <span>时长模式</span>
                                <select name="agnes_duration_mode" id="agnesDurationMode" onchange="toggleAgnesDurationFields()">
                                    <option value="custom" selected>🔢 用户自定义</option>
                                    <option value="fixed">📋 固定档位</option>
                                </select>
                            </label>
                        </div>
                        <!-- 自定义模式 -->
                        <div class="agnes-duration-custom">
                            <div class="field-grid">
                                <label class="field">
                                    <span>最长秒数</span>
                                    <input name="agnes_max_seconds" type="number" min="1" max="60" value="15">
                                    <small class="hint">用户可输入 1~此值的时长</small>
                                </label>
                                <label class="field">
                                    <span>每秒单价（积分）</span>
                                    <input name="agnes_price_per_second" type="number" min="1" value="1">
                                    <small class="hint">总消耗 = 分辨率积分 + 时长×此值</small>
                                </label>
                            </div>
                        </div>
                        <!-- 固定档位模式 -->
                        <div class="agnes-duration-fixed" style="display:none;">
                            <input type="hidden" name="agnes_tier_count" id="agnesTierCount" value="3">
                            <div id="agnesTierList">
                                <div class="field-grid agnes-tier-row">
                                    <label class="field"><span>档位1 秒数</span><input name="agnes_tier_1_sec" type="number" min="1" max="60" value="3" placeholder="秒"></label>
                                    <label class="field"><span>档位1 积分</span><input name="agnes_tier_1_credits" type="number" min="1" value="15" placeholder="积分"></label>
                                </div>
                                <div class="field-grid agnes-tier-row">
                                    <label class="field"><span>档位2 秒数</span><input name="agnes_tier_2_sec" type="number" min="1" max="60" value="5" placeholder="秒"></label>
                                    <label class="field"><span>档位2 积分</span><input name="agnes_tier_2_credits" type="number" min="1" value="25" placeholder="积分"></label>
                                </div>
                                <div class="field-grid agnes-tier-row">
                                    <label class="field"><span>档位3 秒数</span><input name="agnes_tier_3_sec" type="number" min="1" max="60" value="10" placeholder="秒"></label>
                                    <label class="field"><span>档位3 积分</span><input name="agnes_tier_3_credits" type="number" min="1" value="45" placeholder="积分"></label>
                                </div>
                            </div>
                            <button type="button" class="button secondary small" onclick="addAgnesTier()" style="margin-top:8px;">+ 添加档位</button>
                        </div>
                    </div>

                    <!-- Agnes 高级参数 -->
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:4px;"><span class="section-dot"></span>高级参数（用户可调）</div>
                        <div class="field-grid" style="grid-template-columns:1fr 1fr 1fr 1fr;">
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_fps" value="1">
                                <span>🎞 视频FPS</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_steps" value="1">
                                <span>⚙️ 推理步数</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_seed" value="1">
                                <span>🎲 随机种子</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_neg" value="1">
                                <span>🚫 负向提示词</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ═══ Grok 分辨率+时长配置 ═══ -->
                <div class="form-section grok-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Grok 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="grok_480p_enabled" value="1" checked>
                                <span>480p</span>
                            </div>
                            <input type="number" name="grok_480p_credits" min="1" value="5" placeholder="积分" class="agnes-credits-input">
                            <small>积分/次</small>
                        </label>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="grok_720p_enabled" value="1" checked>
                                <span>720p</span>
                            </div>
                            <input type="number" name="grok_720p_credits" min="1" value="10" placeholder="积分" class="agnes-credits-input">
                            <small>积分/次</small>
                        </label>
                    </div>
                    <small class="hint" style="margin-top:8px;">至少启用一个分辨率</small>

                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长定价</div>
                        <div class="field-grid">
                            <label class="field">
                                <span>每秒积分</span>
                                <input name="grok_price_per_second" type="number" min="1" value="2" placeholder="每秒消耗积分">
                                <small class="hint">总消耗 = 分辨率积分 + (此值 × 时长)</small>
                            </label>
                            <label class="field">
                                <span>最大时长（秒）</span>
                                <input name="grok_max_seconds" type="number" min="1" max="60" value="15" placeholder="最长秒数">
                                <small class="hint">用户可选择 1~此值的时长（最大 60）</small>
                            </label>
                        </div>
                        <div class="field-grid">
                            <label class="field">
                                <span>下载代理</span>
                                <select name="download_proxy_type" onchange="toggleProxyUrl(this, 'create')">
                                    <option value="none">不使用</option>
                                    <option value="free">免费代理池 (SOCKS5)</option>
                                    <option value="custom">自定义 SOCKS5</option>
                                </select>
                                <small class="hint">用于下载境外视频（如 x.ai）。免费代理池自动获取，每次重试获取新代理。</small>
                            </label>
                            <label class="field grok-proxy-custom-field" style="display:none;">
                                <span>代理地址</span>
                                <input name="download_proxy_url" placeholder="socks5://user:pass@1.2.3.4:1080">
                                <small class="hint">SOCKS5 代理地址，用于穿越网络限制</small>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ═══ Seedance 分辨率+时长配置 ═══ -->
                <div class="form-section seedance-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Seedance 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <?php foreach (['480p', '720p', '1080p'] as $res): ?>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="seedance_<?= $res ?>_enabled" value="1" <?= in_array($res, ['480p', '720p']) ? 'checked' : '' ?>>
                                <span><?= $res ?></span>
                            </div>
                            <input type="number" name="seedance_<?= $res ?>_credits" min="1" value="<?= ['480p'=>5,'720p'=>10,'1080p'=>15][$res] ?>" placeholder="积分" class="agnes-credits-input">
                            <small>积分/次</small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="hint" style="margin-top:8px;">至少启用一个分辨率</small>

                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长定价</div>
                        <div class="field-grid" style="margin-bottom:8px;">
                            <label class="field">
                                <span>时长模式</span>
                                <select name="seedance_duration_mode" id="seedanceDurationMode" onchange="toggleSeedanceDurationFields()">
                                    <option value="fixed" selected>📋 固定档位</option>
                                    <option value="custom">🔢 按秒计费</option>
                                </select>
                                <small class="hint">固定档位：管理员预设时长档位（5s/10s/15s），每档固定积分。按秒计费：总消耗 = 分辨率积分 + 秒数×单价</small>
                            </label>
                        </div>
                        <div class="seedance-duration-fixed">
                            <input type="hidden" name="seedance_tier_count" id="seedanceTierCount" value="3">
                            <div id="seedanceTierList">
                                <div class="field-grid seedance-tier-row">
                                    <label class="field"><span>档位1 秒数</span><input name="seedance_tier_1_sec" type="number" min="1" max="60" value="5" placeholder="秒"></label>
                                    <label class="field"><span>档位1 积分</span><input name="seedance_tier_1_credits" type="number" min="1" value="15" placeholder="积分"></label>
                                </div>
                                <div class="field-grid seedance-tier-row">
                                    <label class="field"><span>档位2 秒数</span><input name="seedance_tier_2_sec" type="number" min="1" max="60" value="10" placeholder="秒"></label>
                                    <label class="field"><span>档位2 积分</span><input name="seedance_tier_2_credits" type="number" min="1" value="25" placeholder="积分"></label>
                                </div>
                                <div class="field-grid seedance-tier-row">
                                    <label class="field"><span>档位3 秒数</span><input name="seedance_tier_3_sec" type="number" min="1" max="60" value="15" placeholder="秒"></label>
                                    <label class="field"><span>档位3 积分</span><input name="seedance_tier_3_credits" type="number" min="1" value="35" placeholder="积分"></label>
                                </div>
                            </div>
                            <button type="button" class="button secondary small" onclick="addSeedanceTier()" style="margin-top:8px;">+ 添加档位</button>
                        </div>
                        <div class="seedance-duration-custom" style="display:none;">
                            <div class="field-grid">
                                <label class="field">
                                    <span>最大时长（秒）</span>
                                    <input name="seedance_max_seconds" type="number" min="1" max="60" value="15" placeholder="最长秒数">
                                </label>
                                <label class="field">
                                    <span>每秒积分</span>
                                    <input name="seedance_price_per_second" type="number" min="1" value="2" placeholder="每秒消耗积分">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ 接口配置 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>接口配置</div>
                    <div class="field-grid">
                        <label class="field">
                            <span>BASEURL</span>
                            <input name="base_url" placeholder="https://api.kbl6.cn" required>
                        </label>
                        <label class="field">
                            <span>API Key</span>
                            <input name="api_key" type="password" placeholder="sk-..." required>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>接口路径</span>
                            <input name="api_path" id="apiPath" placeholder="v1/images/generations" oninput="this.dataset.userEdited='true'">
                            <small class="hint">留空则使用系统全局设置</small>
                        </label>
                        <label class="field edit-api-path-field">
                            <span>编辑接口路径</span>
                            <input name="edit_api_path" placeholder="v1/images/edits（留空使用接口路径）">
                            <small class="hint">仅编辑模式使用；留空则使用上方接口路径。Grok 视频无编辑接口，无需填写。</small>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>API 超时（秒）</span>
                            <input name="timeout" type="number" min="0" value="0" placeholder="0">
                            <small class="hint">0 = 系统默认；控制生成请求的超时</small>
                        </label>
                        <label class="field">
                            <span>下载超时（秒）</span>
                            <input name="download_timeout" type="number" min="0" value="0" placeholder="0">
                            <small class="hint">0 = 系统默认（300s）；控制视频内容下载的超时</small>
                        </label>
                    </div>
                </div>

                <!-- ═══ 价格与分辨率 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>价格与分辨率</div>
                    <label class="field resolution-only" style="margin-bottom:12px;">
                        <span>支持分辨率（可多选）</span>
                        <div class="resolution-group" style="padding-top:6px;">
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" value="1K" checked>
                                <span class="chip-check"></span>
                                1K
                                <span class="chip-label">≤1280px</span>
                            </label>
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" value="2K">
                                <span class="chip-check"></span>
                                2K
                                <span class="chip-label">1280-2048px</span>
                            </label>
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" value="4K">
                                <span class="chip-check"></span>
                                4K
                                <span class="chip-label">2048-3840px</span>
                            </label>
                        </div>
                    </label>
                    <div class="credits-fields">
                        <span style="font-size:11px;font-weight:600;color:var(--text-soft);text-transform:uppercase;letter-spacing:0.03em;display:block;margin-bottom:8px;">各分辨率消耗点数</span>
                        <div class="pricing-group">
                            <label class="pricing-card">
                                <div class="pricing-label">1K</div>
                                <input name="credits_1k" type="number" min="1" value="1" placeholder="1">
                            </label>
                            <label class="pricing-card">
                                <div class="pricing-label">2K</div>
                                <input name="credits_2k" type="number" min="1" value="3" placeholder="3">
                            </label>
                            <label class="pricing-card">
                                <div class="pricing-label">4K</div>
                                <input name="credits_4k" type="number" min="1" value="5" placeholder="5">
                            </label>
                        </div>
                    </div>
                    <div class="non-image-credits" style="display:none;">
                        <label class="field">
                            <span>消耗点数</span>
                            <input name="credits" type="number" min="1" placeholder="每次生成消耗的积分数">
                        </label>
                    </div>
                </div>

                <!-- ═══ 高级选项 ═══ -->
                <div class="form-section" style="margin-bottom:0;">
                    <div class="form-section-header"><span class="section-dot"></span>高级选项</div>
                    <label class="field">
                        <span>水印点消耗</span>
                        <input name="watermark_point_cost" type="number" min="0" value="0" placeholder="0 = 免水印点">
                        <small class="hint">用户开启"去水印"时每次生成消耗的水印点数，设为0表示免费去水印</small>
                    </label>
                </div>
            </div>

            <!-- 底部按钮 -->
            <div class="modal-footer">
                <button class="button secondary" type="button" onclick="closeCreateModal()">取消</button>
                <button class="button primary" type="submit">✨ 添加模型</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ 编辑模型弹窗 ═══ -->
<div id="editModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:999;align-items:center;justify-content:center;">
    <div class="modal-card" style="background:var(--main-surface);border-radius:18px;max-width:700px;width:90vw;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.18);">
        <!-- 弹窗头部 -->
        <div class="modal-header">
            <h2><span class="header-icon">✎</span>编辑模型</h2>
            <button class="modal-close" onclick="closeEditModal()" title="关闭">&times;</button>
        </div>
        <!-- 弹窗表单 -->
        <form method="post" id="editForm" class="model-create-form" style="display:flex;flex-direction:column;gap:0;">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">

                <!-- ═══ 基本信息 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>基本信息</div>
                    <div class="field-grid">
                        <label class="field">
                            <span>显示名称</span>
                            <input name="name" id="editName" placeholder="例如：GPT Image 2" required>
                        </label>
                        <label class="field">
                            <span>模型 ID</span>
                            <input name="model_id" id="editModelId" placeholder="例如：gpt-image-2" required>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>排序</span>
                            <input name="sort_order" id="editSort" type="number" min="0" value="0" required>
                        </label>
                        <label class="field">
                            <span>模型类型</span>
                            <select name="model_type" id="editType" onchange="toggleEditResFields()">
                                <option value="image">🎨 图片生成</option>
                                <option value="video">🎬 视频生成</option>
                                <option value="chat">💬 AI对话</option>
                            </select>
                        </label>
                    </div>
                    <div class="field-grid edit-site-type-row" style="display:none;">
                        <label class="field">
                            <span>视频站点类型</span>
                            <select name="site_type" id="editSiteType" onchange="toggleEditSiteTypeConfig()">
                                <option value="standard">🌐 标准 OpenAI / Sora</option>
                                <option value="agnes">🎬 Agnes</option>
                                <option value="grok">🤖 Grok 新版视频</option>
                                <option value="seedance">💃 Seedance</option>
                            </select>
                            <small class="hint">Grok 异步轮询，480p/720p。文生/单图最长15s，多图(2~7张)最长10s。<br>⚠️ grok-video-1.5：仅单图生视频，必须 1 张图。</small>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>状态</span>
                            <select name="is_active" id="editActive">
                                <option value="1">🟢 开启</option>
                                <option value="0">🔴 关闭</option>
                            </select>
                        </label>
                    </div>
                </div>

                <!-- ═══ Agnes 分辨率配置（编辑） ═══ -->
                <div class="form-section edit-agnes-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Agnes 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <?php foreach (['480p', '720p', '1080p'] as $res): ?>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="agnes_<?= $res ?>_enabled" value="1" class="edit-agnes-res-check" data-edit-agnes-res="<?= $res ?>">
                                <span><?= $res ?></span>
                            </div>
                            <input type="number" name="agnes_<?= $res ?>_credits" min="1" value="5" placeholder="积分" class="agnes-credits-input edit-agnes-credits" data-edit-agnes-credits="<?= $res ?>">
                            <small>积分/次</small>
                            <small class="hint" style="font-size:10px;">最大 <?= ['480p'=>'40s','720p'=>'17s','1080p'=>'7s'][$res] ?></small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="hint" data-edit-agnes-hint style="margin-top:8px;">至少启用一个分辨率</small>

                    <!-- Agnes 时长配置 -->
                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长</div>
                        <div class="field-grid" style="margin-bottom:8px;">
                            <label class="field">
                                <span>时长模式</span>
                                <select name="agnes_duration_mode" id="editAgnesDurationMode" onchange="toggleEditAgnesDurationFields()">
                                    <option value="custom">🔢 用户自定义</option>
                                    <option value="fixed">📋 固定档位</option>
                                </select>
                            </label>
                        </div>
                        <div class="edit-agnes-duration-custom">
                            <div class="field-grid">
                                <label class="field">
                                    <span>最长秒数</span>
                                    <input name="agnes_max_seconds" id="editAgnesMaxSec" type="number" min="1" max="60" value="15">
                                </label>
                                <label class="field">
                                    <span>每秒单价（积分）</span>
                                    <input name="agnes_price_per_second" id="editAgnesPricePerSec" type="number" min="1" value="1">
                                </label>
                            </div>
                        </div>
                        <div class="edit-agnes-duration-fixed" style="display:none;">
                            <input type="hidden" name="agnes_tier_count" id="editAgnesTierCount" value="3">
                            <div id="editAgnesTierList">
                            </div>
                            <button type="button" class="button secondary small" onclick="addEditAgnesTier()" style="margin-top:8px;">+ 添加档位</button>
                        </div>
                    </div>

                    <!-- Agnes 高级参数（编辑） -->
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:4px;"><span class="section-dot"></span>高级参数（用户可调）</div>
                        <div class="field-grid" style="grid-template-columns:1fr 1fr 1fr 1fr;">
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_fps" value="1" class="edit-agnes-adv" id="editAgnesAdvFps">
                                <span>🎞 视频FPS</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_steps" value="1" class="edit-agnes-adv" id="editAgnesAdvSteps">
                                <span>⚙️ 推理步数</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_seed" value="1" class="edit-agnes-adv" id="editAgnesAdvSeed">
                                <span>🎲 随机种子</span>
                            </label>
                            <label class="field agnes-adv-toggle">
                                <input type="checkbox" name="agnes_adv_neg" value="1" class="edit-agnes-adv" id="editAgnesAdvNeg">
                                <span>🚫 负向提示词</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ═══ Grok 分辨率+时长配置（编辑） ═══ -->
                <div class="form-section grok-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Grok 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="grok_480p_enabled" value="1" class="edit-grok-res" data-res="480p" checked>
                                <span>480p</span>
                            </div>
                            <input type="number" name="grok_480p_credits" min="1" value="5" placeholder="积分" class="agnes-credits-input edit-grok-credits" data-res="480p">
                            <small>积分/次</small>
                        </label>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="grok_720p_enabled" value="1" class="edit-grok-res" data-res="720p" checked>
                                <span>720p</span>
                            </div>
                            <input type="number" name="grok_720p_credits" min="1" value="10" placeholder="积分" class="agnes-credits-input edit-grok-credits" data-res="720p">
                            <small>积分/次</small>
                        </label>
                    </div>
                    <small class="hint" style="margin-top:8px;">至少启用一个分辨率</small>

                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长定价</div>
                        <div class="field-grid">
                            <label class="field">
                                <span>每秒积分</span>
                                <input name="grok_price_per_second" type="number" min="1" value="2" id="editGrokPricePerSec" placeholder="每秒消耗积分">
                            </label>
                            <label class="field">
                                <span>最大时长（秒）</span>
                                <input name="grok_max_seconds" type="number" min="1" max="60" value="15" id="editGrokMaxSec" placeholder="最长秒数">
                            </label>
                        </div>
                        <div class="field-grid">
                            <label class="field">
                                <span>下载代理</span>
                                <select name="download_proxy_type" id="editProxyType" onchange="toggleProxyUrl(this, 'edit')">
                                    <option value="none">不使用</option>
                                    <option value="free">免费代理池 (SOCKS5)</option>
                                    <option value="custom">自定义 SOCKS5</option>
                                </select>
                            </label>
                            <label class="field grok-proxy-custom-field" style="display:none;">
                                <span>代理地址</span>
                                <input name="download_proxy_url" id="editProxyUrl" placeholder="socks5://user:pass@1.2.3.4:1080">
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ═══ Seedance 分辨率+时长配置（编辑） ═══ -->
                <div class="form-section seedance-config-section" style="display:none;">
                    <div class="form-section-header"><span class="section-dot"></span>Seedance 分辨率定价</div>
                    <div class="agnes-res-grid">
                        <?php foreach (['480p', '720p', '1080p'] as $res): ?>
                        <label class="agnes-res-card">
                            <div class="agnes-res-head">
                                <input type="checkbox" name="seedance_<?= $res ?>_enabled" value="1" class="edit-seedance-res" data-res="<?= $res ?>">
                                <span><?= $res ?></span>
                            </div>
                            <input type="number" name="seedance_<?= $res ?>_credits" min="1" value="5" placeholder="积分" class="agnes-credits-input edit-seedance-credits" data-res="<?= $res ?>">
                            <small>积分/次</small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="hint" style="margin-top:8px;">至少启用一个分辨率</small>

                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                        <div class="form-section-header" style="margin-bottom:10px;"><span class="section-dot"></span>视频时长定价</div>
                        <div class="field-grid" style="margin-bottom:8px;">
                            <label class="field">
                                <span>时长模式</span>
                                <select name="seedance_duration_mode" id="editSeedanceDurationMode" onchange="toggleEditSeedanceDurationFields()">
                                    <option value="fixed">📋 固定档位</option>
                                    <option value="custom">🔢 按秒计费</option>
                                </select>
                            </label>
                        </div>
                        <div class="seedance-duration-fixed edit-seedance-duration-fixed">
                            <input type="hidden" name="seedance_tier_count" id="editSeedanceTierCount" value="3">
                            <div id="editSeedanceTierList">
                            </div>
                            <button type="button" class="button secondary small" onclick="addEditSeedanceTier()" style="margin-top:8px;">+ 添加档位</button>
                        </div>
                        <div class="seedance-duration-custom edit-seedance-duration-custom" style="display:none;">
                            <div class="field-grid">
                                <label class="field">
                                    <span>最大时长（秒）</span>
                                    <input name="seedance_max_seconds" id="editSeedanceMaxSec" type="number" min="1" max="60" value="15">
                                </label>
                                <label class="field">
                                    <span>每秒积分</span>
                                    <input name="seedance_price_per_second" id="editSeedancePricePerSec" type="number" min="1" value="2">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ 接口配置 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>接口配置</div>
                    <div class="field-grid">
                        <label class="field">
                            <span>BASEURL</span>
                            <input name="base_url" id="editUrl" placeholder="https://api.kbl6.cn" required>
                        </label>
                        <label class="field">
                            <span>API Key（留空不修改）</span>
                            <input name="api_key" id="editKey" type="password" placeholder="留空不修改" autocomplete="off">
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>接口路径</span>
                            <input name="api_path" id="editApiPath" placeholder="v1/images/generations" oninput="this.dataset.userEdited='true'">
                            <small class="hint">留空则使用系统全局设置</small>
                        </label>
                        <label class="field edit-api-path-field">
                            <span>编辑接口路径</span>
                            <input name="edit_api_path" id="editEditApiPath" placeholder="v1/images/edits（留空使用接口路径）">
                            <small class="hint">仅编辑模式使用；留空则使用上方接口路径。Grok 视频无编辑接口，无需填写。</small>
                        </label>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span>API 超时（秒）</span>
                            <input name="timeout" id="editTimeout" type="number" min="0" value="0" placeholder="0">
                            <small class="hint">0 = 系统默认；控制生成请求的超时</small>
                        </label>
                        <label class="field">
                            <span>下载超时（秒）</span>
                            <input name="download_timeout" id="editDownloadTimeout" type="number" min="0" value="0" placeholder="0">
                            <small class="hint">0 = 系统默认（300s）；控制视频内容下载的超时</small>
                        </label>
                    </div>
                </div>

                <!-- ═══ 价格与分辨率 ═══ -->
                <div class="form-section">
                    <div class="form-section-header"><span class="section-dot"></span>价格与分辨率</div>
                    <label class="field edit-res-field" style="margin-bottom:12px;">
                        <span>支持分辨率（可多选）</span>
                        <div class="resolution-group" style="padding-top:6px;">
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" class="edit-res-check" value="1K">
                                <span class="chip-check"></span>
                                1K
                                <span class="chip-label">≤1280px</span>
                            </label>
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" class="edit-res-check" value="2K">
                                <span class="chip-check"></span>
                                2K
                                <span class="chip-label">1280-2048px</span>
                            </label>
                            <label class="resolution-chip">
                                <input type="checkbox" name="res_check[]" class="edit-res-check" value="4K">
                                <span class="chip-check"></span>
                                4K
                                <span class="chip-label">2048-3840px</span>
                            </label>
                        </div>
                    </label>
                    <div class="edit-credits-fields">
                        <span style="font-size:11px;font-weight:600;color:var(--text-soft);text-transform:uppercase;letter-spacing:0.03em;display:block;margin-bottom:8px;">各分辨率消耗点数</span>
                        <div class="pricing-group">
                            <label class="pricing-card">
                                <div class="pricing-label">1K</div>
                                <input name="credits_1k" id="editPrice1K" type="number" min="1" placeholder="价格">
                            </label>
                            <label class="pricing-card">
                                <div class="pricing-label">2K</div>
                                <input name="credits_2k" id="editPrice2K" type="number" min="1" placeholder="价格">
                            </label>
                            <label class="pricing-card">
                                <div class="pricing-label">4K</div>
                                <input name="credits_4k" id="editPrice4K" type="number" min="1" placeholder="价格">
                            </label>
                        </div>
                    </div>
                    <div class="edit-non-image-credits" style="display:none;">
                        <label class="field">
                            <span>消耗点数</span>
                            <input name="credits" id="editCredits" type="number" min="1" placeholder="每次生成消耗的积分数">
                        </label>
                    </div>
                </div>

                <!-- ═══ 高级选项 ═══ -->
                <div class="form-section" style="margin-bottom:0;">
                    <div class="form-section-header"><span class="section-dot"></span>高级选项</div>
                    <label class="field">
                        <span>水印点消耗</span>
                        <input name="watermark_point_cost" id="editWpCost" type="number" min="0" value="0" placeholder="0 = 免水印点">
                        <small class="hint">用户开启"去水印"时每次生成消耗的水印点数，设为0表示免费去水印</small>
                    </label>
                </div>
            </div>

            <!-- 底部按钮 -->
            <div class="modal-footer">
                <button class="button secondary" type="button" onclick="closeEditModal()">取消</button>
                <button class="button primary" type="submit">💾 保存修改</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ 模型数据（textarea 隔离，浏览器视为纯文本） ═══ -->
<textarea id="modelData" style="display:none;"><?= e($modelDataJson) ?></textarea>

<!-- ═══ JS 从外部文件加载，零 PHP 代码 ═══ -->
<script src="/admin/assets/ai_models_v2.js"></script>
<?php render_footer(); ?>
