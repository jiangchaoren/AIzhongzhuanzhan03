<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image/generation.php';

require_admin();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
cleanup_stale_running_generation_records();

// ============================================================
// POST 永久删除
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $modeFilter = (string) ($_GET['mode'] ?? '');

    // ── 批量删除所选（或单条永久删除）──
    if (isset($_POST['batch_delete']) || isset($_POST['record_id'])) {
        // 收集要删除的 ID 列表：优先读 selected[] 数组，兼容单条 record_id
        $ids = [];
        $selected = $_POST['selected'] ?? null;
        if (is_array($selected) && !empty($selected)) {
            foreach ($selected as $s) $ids[] = (int) $s;
        } elseif (($rid = (int) ($_POST['record_id'] ?? 0)) > 0) {
            $ids[] = $rid;
        }
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
        if ($ids) {
            // 批量查询文件路径
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, image_url, input_images_json, video_url FROM generation_records WHERE id IN ($placeholders) AND deleted_at IS NOT NULL");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();

            // 批量物理删除文件
            foreach ($rows as $rec) {
                if (!empty($rec['image_url'])) {
                    $fp = local_public_file_from_url((string) $rec['image_url']);
                    if ($fp !== null && is_file($fp)) @unlink($fp);
                }
                if (!empty($rec['video_url'])) {
                    $fp = local_public_file_from_url((string) $rec['video_url']);
                    if ($fp !== null && is_file($fp)) @unlink($fp);
                }
                if (!empty($rec['input_images_json'])) {
                    $images = json_decode((string) $rec['input_images_json'], true);
                    if (is_array($images)) {
                        foreach ($images as $img) {
                            if (!empty($img['url'])) {
                                $fp = local_public_file_from_url((string) $img['url']);
                                if ($fp !== null && is_file($fp)) @unlink($fp);
                            }
                        }
                    }
                }
            }

            // 批量删除数据库记录
            db()->prepare("DELETE FROM generation_records WHERE id IN ($placeholders)")->execute($ids);

            $count = count($ids);
            flash('success', "已永久删除 {$count} 条记录。");
        }
    }

    // ── 清空垃圾箱 ──
    if (isset($_POST['empty_all'])) {
        // 清空垃圾箱：直接查所有已删除记录的 ID
        $stmt = db()->query('SELECT id, image_url, input_images_json, video_url FROM generation_records WHERE deleted_at IS NOT NULL');
        $rows = $stmt->fetchAll();

        if ($rows) {
            foreach ($rows as $rec) {
                if (!empty($rec['image_url'])) {
                    $fp = local_public_file_from_url((string) $rec['image_url']);
                    if ($fp !== null && is_file($fp)) @unlink($fp);
                }
                if (!empty($rec['video_url'])) {
                    $fp = local_public_file_from_url((string) $rec['video_url']);
                    if ($fp !== null && is_file($fp)) @unlink($fp);
                }
                if (!empty($rec['input_images_json'])) {
                    $images = json_decode((string) $rec['input_images_json'], true);
                    if (is_array($images)) {
                        foreach ($images as $img) {
                            if (!empty($img['url'])) {
                                $fp = local_public_file_from_url((string) $img['url']);
                                if ($fp !== null && is_file($fp)) @unlink($fp);
                            }
                        }
                    }
                }
            }
            db()->exec('DELETE FROM generation_records WHERE deleted_at IS NOT NULL');
            $count = count($rows);
            flash('success', "垃圾箱已清空，永久删除 {$count} 条记录。");
        } else {
            flash('warning', '垃圾箱已是空的。');
        }
    }

    redirect('/admin/index?trash=1&page=' . $page . ($modeFilter ? '&mode=' . $modeFilter : ''));
}

// ============================================================
// 垃圾箱模式
// ============================================================
$showTrash = ($_GET['trash'] ?? '') === '1';

// 统计
$stats = [
    'users'   => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'records' => (int) db()->query('SELECT COUNT(*) FROM generation_records')->fetchColumn(),
    'success' => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "succeeded" AND deleted_at IS NULL')->fetchColumn(),
    'failed'  => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "failed" AND deleted_at IS NULL')->fetchColumn(),
    'queued'  => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "queued" AND deleted_at IS NULL')->fetchColumn(),
    'deleted' => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE deleted_at IS NOT NULL')->fetchColumn(),
    'credits' => (int) db()->query('SELECT COALESCE(SUM(credits_charged), 0) FROM generation_records WHERE status = "succeeded" AND deleted_at IS NULL')->fetchColumn(),
];

$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$modeFilter = (string) ($_GET['mode'] ?? '');
if (!in_array($modeFilter, ['', 'draw', 'edit', 'video'], true)) $modeFilter = '';

if ($showTrash) {
    // 垃圾桶：只看已删除的
    $whereParts = ['r.deleted_at IS NOT NULL'];
    if ($modeFilter !== '') $whereParts[] = 'r.mode = ' . db()->quote($modeFilter);
    $where = ' WHERE ' . implode(' AND ', $whereParts);
} else {
    // 正常：只看未删除的
    $whereParts = ['r.deleted_at IS NULL'];
    if ($modeFilter !== '') $whereParts[] = 'r.mode = ' . db()->quote($modeFilter);
    $where = ' WHERE ' . implode(' AND ', $whereParts);
}

$total = (int) db()->query("SELECT COUNT(*) FROM generation_records r $where")->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT r.id, r.user_id, r.status, r.mode, r.model, r.prompt, r.size, r.quality,
            r.output_format, r.image_url, r.mime_type, r.credits_charged, r.error_message,
            r.input_images_json, r.started_at, r.finished_at, r.deleted_at, r.created_at,
            r.image_base64 IS NOT NULL AS has_image_base64, u.username
     FROM generation_records r
     JOIN users u ON u.id = r.user_id
     $where
     ORDER BY " . ($showTrash ? 'r.deleted_at' : 'r.created_at') . " DESC
     LIMIT ? OFFSET ?";
$stmt = db()->prepare($sql);
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

render_header('管理后台', 'admin');
render_admin_nav('index');
?>
<style>
.dash-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
.dash-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.dash-stat .icon { font-size: 20px; margin-bottom: 6px; }
.dash-stat .num { font-size: 26px; font-weight: 800; color: var(--text); }
.dash-stat .lbl { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }
.dash-stat.trash .num { color: var(--danger); }
.filter-bar { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.filter-bar .tab { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--line); color: var(--text-muted); background: var(--main-surface); transition: all .15s; }
.filter-bar .tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.filter-bar .tab.trash { margin-left: auto; }
.filter-bar .tab.trash.active { background: var(--danger); border-color: var(--danger); }
.filter-bar .tab:hover:not(.active) { border-color: var(--primary-soft); }
.filter-bar .tab.trash:hover:not(.active) { border-color: #fecaca; color: var(--danger); }
.filter-bar .count { margin-left: 8px; font-size: 13px; color: var(--text-muted); }
.record-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.record-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; transition: all .15s; cursor: pointer; }
.record-card:hover { border-color: var(--primary-soft); box-shadow: 0 4px 16px rgba(0,0,0,.06); transform: translateY(-1px); }
.record-card.deleted { opacity: .5; }
.record-card .thumb { width: 100%; aspect-ratio: 1; background: var(--main-surface-soft); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.record-card .thumb img, .record-card .thumb video { width: 100%; height: 100%; object-fit: cover; }
.record-card .thumb .no-img { font-size: 32px; color: var(--text-muted); }
.record-card .body { padding: 12px 14px; }
.record-card .body .user { font-size: 12px; font-weight: 600; color: var(--primary); margin-bottom: 4px; }
.record-card .body .prompt { font-size: 12px; color: var(--text); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-all; margin-bottom: 8px; }
.record-card .body .meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; color: var(--text-muted); }
.record-card .body .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--line); }
.record-card .body .foot .id { font-size: 10px; color: var(--text-muted); }
.record-card .body .foot .actions { display: flex; gap: 6px; }
.record-card .card-check { position:absolute; top:8px; right:8px; z-index:2; width:18px; height:18px; cursor:pointer; accent-color:var(--danger); }
.record-card.selected { opacity:.8; border-color:var(--danger); box-shadow:0 0 0 2px rgba(239,68,68,.25); }
.trash-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; padding:10px 14px; border:1px solid var(--line); border-radius:var(--radius); background:var(--main-surface-soft); flex-wrap:wrap; }
.trash-toolbar .tool-left { display:flex; align-items:center; gap:8px; }
.trash-toolbar .tool-right { display:flex; align-items:center; gap:6px; }
.select-all-label { font-size:13px; color:var(--text-soft); cursor:pointer; display:flex; align-items:center; gap:6px; user-select:none; }
.select-all-label input { width:16px; height:16px; cursor:pointer; accent-color:var(--danger); }
</style>
<main>
    <div class="page-hd">
        <div><h1><?= $showTrash ? '已删除记录' : '生成记录' ?></h1><p><?= $showTrash ? 'Trash' : 'Records Dashboard' ?></p></div>
    </div>

    <!-- 统计 -->
    <div class="dash-stats">
        <div class="dash-stat"><div class="icon">👥</div><div class="num"><?= number_format($stats['users']) ?></div><div class="lbl">用户数</div></div>
        <div class="dash-stat"><div class="icon">📊</div><div class="num"><?= number_format($stats['records']) ?></div><div class="lbl">总记录</div></div>
        <div class="dash-stat"><div class="icon">✅</div><div class="num"><?= number_format($stats['success']) ?></div><div class="lbl">成功</div></div>
        <div class="dash-stat"><div class="icon">⏳</div><div class="num"><?= number_format($stats['queued']) ?></div><div class="lbl">排队中</div></div>
        <div class="dash-stat"><div class="icon">❌</div><div class="num"><?= number_format($stats['failed']) ?></div><div class="lbl">失败</div></div>
        <div class="dash-stat trash"><div class="icon">🗑️</div><div class="num"><?= number_format($stats['deleted']) ?></div><div class="lbl">已删除</div></div>
    </div>

    <!-- 筛选 -->
    <div class="filter-bar">
        <?php if ($showTrash): ?>
            <a href="/admin/index?trash=1<?= $modeFilter ? '&mode=' . $modeFilter : '' ?>" class="tab <?= $modeFilter === '' ? 'active' : '' ?>">全部</a>
            <a href="/admin/index?trash=1&mode=draw" class="tab <?= $modeFilter === 'draw' ? 'active' : '' ?>">绘画</a>
            <a href="/admin/index?trash=1&mode=edit" class="tab <?= $modeFilter === 'edit' ? 'active' : '' ?>">编辑</a>
            <a href="/admin/index?trash=1&mode=video" class="tab <?= $modeFilter === 'video' ? 'active' : '' ?>">视频</a>
            <span class="count">已删除 <?= $total ?> 条</span>
        <?php else: ?>
            <a href="/admin/index" class="tab <?= $modeFilter === '' ? 'active' : '' ?>">全部</a>
            <a href="/admin/index?mode=draw" class="tab <?= $modeFilter === 'draw' ? 'active' : '' ?>">绘画</a>
            <a href="/admin/index?mode=edit" class="tab <?= $modeFilter === 'edit' ? 'active' : '' ?>">编辑</a>
            <a href="/admin/index?mode=video" class="tab <?= $modeFilter === 'video' ? 'active' : '' ?>">视频</a>
            <span class="count">共 <?= $total ?> 条</span>
        <?php endif; ?>
        <a href="/admin/index?trash=<?= $showTrash ? '0' : '1' ?>" class="tab trash <?= $showTrash ? 'active' : '' ?>">🗑️ 垃圾箱</a>
    </div>

    <?php if (!$records): ?>
        <div class="empty-state"><div class="icon">📭</div><h3><?= $showTrash ? '暂无已删除记录' : '暂无生成记录' ?></h3></div>
    <?php else: ?>
        <?php if ($showTrash): ?>
        <!-- 垃圾箱工具栏 -->
        <form method="post" action="/admin/index?trash=1&page=<?= $page ?><?= $modeFilter ? '&mode=' . $modeFilter : '' ?>" id="trashForm">
        <?= csrf_field() ?>
        <div class="trash-toolbar">
            <div class="tool-left">
                <label class="select-all-label">
                    <input type="checkbox" id="selectAll" onchange="toggleAll(this, 'selTrash')"> 全选
                </label>
            </div>
            <div class="tool-right">
                <button type="submit" name="batch_delete" value="1" class="button danger small" onclick="return confirmDelSelected()">🗑️ 删除所选</button>
                <button type="submit" name="empty_all" value="1" class="button danger small" onclick="return confirm('确认清空垃圾箱？所有已删除记录将被永久移除，不可恢复！')">⚠️ 清空垃圾箱</button>
            </div>
        </div>
        <?php endif; ?>
        <div class="record-grid" id="adminRecordGrid">
            <?php foreach ($records as $r): ?>
                <?php
                $src = record_image_src($r);
                $isDeleted = !empty($r['deleted_at']);
                $modeIcon = ['draw' => '🎨', 'edit' => '🖌️', 'video' => '🎬'][$r['mode'] ?? 'draw'] ?? '🎨';
                $statusClass = $isDeleted ? 'deleted' : $r['status'];
                ?>
                <div class="record-card <?= $isDeleted ? 'deleted' : '' ?>" style="position:relative;"
                     data-record-id="<?= (int) $r['id'] ?>"
                     data-status="<?= e($isDeleted ? 'deleted' : $r['status']) ?>"
                     data-mode="<?= e($r['mode'] ?? 'draw') ?>"
                     data-model="<?= e($r['model'] ?? '') ?>"
                     data-prompt="<?= e($r['prompt']) ?>"
                     data-size="<?= e($r['size']) ?>"
                     data-quality="<?= e($r['quality']) ?>"
                     data-format="<?= e($r['output_format']) ?>"
                     data-credits="<?= (int) $r['credits_charged'] ?>"
                     data-created="<?= e($r['created_at']) ?>"
                     data-finished="<?= e($r['finished_at'] ?: '-') ?>"
                     data-error="<?= e($r['error_message'] ?: '') ?>"
                     data-input-count="<?= generation_input_image_count($r) ?>"
                     <?= !$isDeleted ? 'data-open-record' : '' ?>>
                    <?php if ($isDeleted): ?>
                    <input type="checkbox" name="selected[]" value="<?= (int) $r['id'] ?>" class="selTrash card-check" onchange="updateCardStyle(this)">
                    <?php endif; ?>
                    <div class="thumb">
                        <?php if ($r['mode'] === 'video' && record_image_src($r)): ?>
                            <video src="<?= e(record_image_src($r)) ?>" muted></video>
                        <?php elseif ($src): ?>
                            <img src="<?= e($src) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="no-img"><?= $modeIcon ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="body">
                        <div class="user">@<?= e($r['username']) ?></div>
                        <div class="prompt"><?= e($r['prompt'] ?: '(无提示词)') ?></div>
                        <div class="meta">
                            <span class="status-badge <?= $statusClass ?>"><?= e(generation_status_label($isDeleted ? 'deleted' : (string) $r['status'])) ?></span>
                            <span><?= e(mode_display_label((string) ($r['mode'] ?? 'draw'))) ?> · <?= e($r['size'] ?? 'auto') ?></span>
                            <?php if ($isDeleted): ?>
                                <span style="color:var(--danger)">删除于 <?= e($r['deleted_at']) ?></span>
                            <?php else: ?>
                                <span><?= e($r['created_at']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="foot">
                            <span class="id">#<?= (int) $r['id'] ?></span>
                            <span class="actions">
                                <?php if ($isDeleted): ?>
                                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;" onclick="if(confirm('确认永久删除？')){var f=document.createElement('form');f.method='post';f.action='/admin/index?trash=1&page=<?= $page ?><?= $modeFilter?'&mode='.$modeFilter:'' ?>';f.innerHTML='<?= csrf_field() ?><input type=hidden name=action value=permanent_delete><input type=hidden name=record_id value=<?= (int) $r['id'] ?>>';document.body.appendChild(f);f.submit();}">永久删除</button>
                                <?php else: ?>
                                    <form method="post" action="/delete_record" onsubmit="return confirm('确认删除记录 #<?= (int) $r['id'] ?>？'); event.stopPropagation();">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="redirect_to" value="/admin/index?page=<?= $page ?><?= $modeFilter ? '&mode=' . $modeFilter : '' ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;">删除</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($showTrash): ?></form><?php endif; ?>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <?php $baseUrl = '/admin/index?' . ($showTrash ? 'trash=1&' : '') . ($modeFilter ? 'mode=' . $modeFilter . '&' : ''); ?>
        <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? $baseUrl . 'page=' . ($page - 1) : '#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? $baseUrl . 'page=' . ($page + 1) : '#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>

<script>
function toggleAll(master, cls) {
    document.querySelectorAll('.' + cls).forEach(function(cb) {
        cb.checked = master.checked;
        updateCardStyle(cb);
    });
}
function updateCardStyle(cb) {
    var card = cb.closest('.record-card');
    if (card) card.classList.toggle('selected', cb.checked);
}
function confirmDelSelected() {
    var checked = document.querySelectorAll('.selTrash:checked');
    if (checked.length === 0) { alert('请先勾选要删除的记录'); return false; }
    return confirm('确认永久删除所选 ' + checked.length + ' 条记录？\n\n数据库记录和本地文件将彻底删除，不可恢复！');
}
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('selTrash')) {
        updateCardStyle(e.target);
        var all = document.querySelectorAll('.selTrash');
        var checked = document.querySelectorAll('.selTrash:checked');
        var master = document.getElementById('selectAll');
        if (master) master.checked = all.length > 0 && checked.length === all.length;
    }
});

// 点击卡片打开详情弹窗（垃圾箱模式不打开）
document.getElementById("adminRecordGrid")?.addEventListener("click", function(e) {
    if (e.target.closest("form") || e.target.closest("button") || e.target.closest("input[type=checkbox]")) return;
    var card = e.target.closest("[data-open-record]");
    if (!card) return;
    if (typeof openRecordDialog === "function") openRecordDialog(card);
});
</script>
<script src="/assets/user.js"></script>
<?php render_footer(); ?>
