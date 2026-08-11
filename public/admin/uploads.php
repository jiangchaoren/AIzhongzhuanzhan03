<?php

/**
 * 图片管理后台
 *
 * 两个 Tab：参考图片 / 生成图片
 * 每个 Tab 提供两个删除按钮：
 *   1. 删除失效引用（清理无法正常显示的文件）
 *   2. 删除所选（勾选的图片，数据库引用 + 物理文件一同删除）
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/generation.php';

require_admin();
ensure_generation_records_soft_delete();

$activeTab = (string) ($_GET['tab'] ?? 'input');
$message = '';
$messageType = 'success';

// ============================================================
// 辅助函数
// ============================================================

function collect_referenced_upload_paths(): array
{
    $paths = [];
    $pdo = db();

    $stmt = $pdo->query("SELECT image_url FROM generation_records WHERE image_url IS NOT NULL AND image_url != '' AND deleted_at IS NULL");
    while ($row = $stmt->fetchColumn()) {
        $fp = local_public_file_from_url((string) $row);
        if ($fp !== null) $paths[] = $fp;
    }

    $stmt = $pdo->query("SELECT input_images_json FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != ''");
    while ($row = $stmt->fetchColumn()) {
        $images = json_decode((string) $row, true);
        if (is_array($images)) {
            foreach ($images as $img) {
                if (!empty($img['url'])) {
                    $fp = local_public_file_from_url((string) $img['url']);
                    if ($fp !== null) $paths[] = $fp;
                }
            }
        }
    }

    return array_unique($paths);
}

function scan_upload_files(): array
{
    $files = [];
    $uploadsDir = dirname(__DIR__, 2) . '/public/uploads';

    foreach (['generations', 'input-images'] as $bucket) {
        $bucketDir = $uploadsDir . '/' . $bucket;
        if (!is_dir($bucketDir)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            $relativePath = str_replace('\\', '/', substr($realPath, strlen(realpath($uploadsDir))));
            $files[] = [
                'path'     => $realPath,
                'relative' => '/uploads' . $relativePath,
                'size'     => $file->getSize(),
                'mtime'    => $file->getMTime(),
                'bucket'   => $bucket,
            ];
        }
    }

    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $files;
}

// ============================================================
// POST 处理
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    // ── 删除失效引用（参考图片 tab）──
    if ($action === 'clean_broken_inputs') {
        // 1. 清理 DB 中引用已不存在文件的 input_images 记录
        $stmt = db()->query("SELECT id, input_images_json FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != ''");
        $phantomCount = 0;
        while ($row = $stmt->fetch()) {
            $images = json_decode((string) $row['input_images_json'], true);
            if (!is_array($images)) continue;
            $changed = false;
            $filtered = array_values(array_filter($images, function ($img) use (&$changed) {
                if (empty($img['url'])) return true;
                $fp = local_public_file_from_url((string) $img['url']);
                if ($fp === null || !is_file($fp)) { $changed = true; return false; }
                return true;
            }));
            if ($changed) {
                db()->prepare('UPDATE generation_records SET input_images_json = ? WHERE id = ?')->execute([
                    !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    (int) $row['id'],
                ]);
                $phantomCount++;
            }
        }

        // 2. 删除磁盘上无 DB 引用的孤立文件
        $referenced = collect_referenced_upload_paths();
        $referencedLower = array_map('strtolower', $referenced);
        $allFiles = scan_upload_files();
        $orphanCount = 0;
        foreach ($allFiles as $f) {
            if (!in_array(strtolower($f['path']), $referencedLower, true)) {
                if (unlink($f['path'])) $orphanCount++;
            }
        }

        $parts = [];
        if ($phantomCount > 0) $parts[] = "清理 {$phantomCount} 条失效 DB 引用";
        if ($orphanCount > 0) $parts[] = "删除 {$orphanCount} 个孤立文件";
        $message = implode('，', $parts) ?: '没有发现失效引用，一切干净。';
    }

    // ── 删除失效引用（生成图片 tab）──
    elseif ($action === 'clean_broken_generated') {
        // 1. 删除磁盘上无有效 DB 引用的孤立生成文件
        $referenced = collect_referenced_upload_paths();
        $referencedLower = array_map('strtolower', $referenced);
        $orphanCount = 0;

        $uploadsDir = dirname(__DIR__, 2) . '/public/uploads/generations';
        if (is_dir($uploadsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $absPath = $file->getRealPath();
                if (!in_array(strtolower($absPath), $referencedLower, true)) {
                    if (unlink($absPath)) $orphanCount++;
                }
            }
        }

        // 2. 清理已软删除记录仍然残留的物理文件
        $stmt = db()->query("SELECT id, image_url FROM generation_records WHERE deleted_at IS NOT NULL AND image_url IS NOT NULL AND image_url != ''");
        $deletedFileCount = 0;
        while ($row = $stmt->fetch()) {
            $fp = local_public_file_from_url((string) $row['image_url']);
            if ($fp !== null && is_file($fp)) {
                if (unlink($fp)) $deletedFileCount++;
            }
        }

        $parts = [];
        if ($orphanCount > 0) $parts[] = "删除 {$orphanCount} 个孤立文件";
        if ($deletedFileCount > 0) $parts[] = "清理 {$deletedFileCount} 个已删除记录的残留文件";
        $message = implode('，', $parts) ?: '没有发现失效引用，一切干净。';
    }

    // ── 删除所选参考图片 ──
    elseif ($action === 'delete_selected_inputs') {
        $selected = $_POST['selected'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            $message = '未选择任何图片。';
            $messageType = 'warning';
        } else {
            $deletedCount = 0;
            foreach ($selected as $entry) {
                // entry 格式: "recordId|imageUrl"
                $parts = explode('|', (string) $entry, 2);
                if (count($parts) !== 2) continue;
                $recordId = (int) $parts[0];
                $imageUrl = $parts[1];

                // 物理删除文件
                $fp = local_public_file_from_url($imageUrl);
                if ($fp !== null && is_file($fp)) @unlink($fp);

                // 从 input_images_json 中移除
                $stmt = db()->prepare('SELECT input_images_json FROM generation_records WHERE id = ?');
                $stmt->execute([$recordId]);
                $json = $stmt->fetchColumn();
                $images = json_decode((string) $json, true);
                if (is_array($images)) {
                    $filtered = array_values(array_filter($images, fn($img) =>
                        !(is_array($img) && ($img['url'] ?? '') === $imageUrl)
                    ));
                    db()->prepare('UPDATE generation_records SET input_images_json = ? WHERE id = ?')->execute([
                        !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        $recordId,
                    ]);
                    $deletedCount++;
                }
            }
            $message = "已删除 {$deletedCount} 张参考图片（含物理文件）。";
        }
    }

    // ── 删除所选生成图片 ──
    elseif ($action === 'delete_selected_generated') {
        $selected = $_POST['selected'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            $message = '未选择任何图片。';
            $messageType = 'warning';
        } else {
            $deletedCount = 0;
            foreach ($selected as $recordId) {
                $recordId = (int) $recordId;
                if ($recordId < 1) continue;

                $stmt = db()->prepare('SELECT id, image_url FROM generation_records WHERE id = ?');
                $stmt->execute([$recordId]);
                $rec = $stmt->fetch();
                if (!$rec) continue;

                // 物理删除文件
                if (!empty($rec['image_url'])) {
                    $fp = local_public_file_from_url((string) $rec['image_url']);
                    if ($fp !== null && is_file($fp)) @unlink($fp);
                }

                // 软删除记录
                db()->prepare('UPDATE generation_records SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
                    ->execute([$recordId]);
                $deletedCount++;
            }
            $message = "已删除 {$deletedCount} 条生成记录（含物理文件）。";
        }
    }

    if ($message !== '') {
        flash($messageType, $message);
    }
    redirect('/admin/uploads?tab=' . $activeTab);
}

// ============================================================
// 页面渲染
// ============================================================
render_header('图片管理', 'admin');
render_admin_nav('uploads');
?>

<style>
.admin-tabs { display:flex; gap:6px; margin-bottom:20px; padding:8px; background:var(--main-surface); border:1px solid var(--line); border-radius:var(--radius); }
.admin-tabs .tab { min-height:34px; display:inline-flex; align-items:center; padding:0 14px; border-radius:var(--radius-sm); color:var(--text-soft); font-size:13px; font-weight:700; text-decoration:none; transition:all var(--duration) var(--ease-out); }
.admin-tabs .tab:hover { background:var(--sidebar-accent-soft); color:var(--sidebar-text-hover); }
.admin-tabs .tab.active { background:var(--sidebar-accent-soft); color:var(--sidebar-text-active); }

.upload-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px,1fr)); gap:14px; }
.upload-card { border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; background:var(--main-surface); box-shadow:var(--shadow-sm); position:relative; }
.upload-card.selected { border-color:var(--danger); box-shadow:0 0 0 2px rgba(239,68,68,.25); }
.upload-card .card-check { position:absolute; top:8px; right:8px; z-index:2; width:20px; height:20px; cursor:pointer; accent-color:var(--danger); }
.upload-card .thumb { width:100%; height:160px; display:grid; place-items:center; overflow:hidden; background:linear-gradient(135deg,#eff6ff,#f8fafc); }
.upload-card .thumb img { width:100%; height:100%; object-fit:cover; }
.upload-card .thumb .no-img { font-size:12px; color:var(--text-muted); font-weight:700; }
.upload-card .info { padding:10px 12px; display:grid; gap:4px; font-size:12px; }
.upload-card .info .label { color:var(--text-muted); font-weight:700; }
.upload-card .info .val { color:var(--text); font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.upload-card .actions { padding:8px 12px 12px; display:flex; gap:6px; }
.upload-card .actions form { margin:0; }

.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; padding:12px 16px; border:1px solid var(--line); border-radius:var(--radius); background:var(--main-surface-soft); flex-wrap:wrap; }
.toolbar .toolbar-left { display:flex; align-items:center; gap:8px; }
.toolbar .toolbar-right { display:flex; align-items:center; gap:8px; }
.toolbar .stat { font-size:13px; font-weight:600; color:var(--text); }
.toolbar .stat strong { font-size:18px; color:var(--danger); }
.select-all-label { font-size:13px; color:var(--text-soft); cursor:pointer; display:flex; align-items:center; gap:6px; user-select:none; }
.select-all-label input { width:16px; height:16px; cursor:pointer; accent-color:var(--danger); }
</style>

<div class="admin-tabs">
    <a class="tab <?= $activeTab === 'input' ? 'active' : '' ?>" href="?tab=input">📷 参考图片</a>
    <a class="tab <?= $activeTab === 'generated' ? 'active' : '' ?>" href="?tab=generated">🎨 生成图片</a>
</div>

<?php
// ============================================================
// Tab 1: 参考图片
// ============================================================
if ($activeTab === 'input'):
    $perPage = 20;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare('SELECT COUNT(*) FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != \'\'');
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT r.id, r.user_id, r.input_images_json, r.created_at, u.username
         FROM generation_records r
         JOIN users u ON u.id = r.user_id
         WHERE r.input_images_json IS NOT NULL AND r.input_images_json != \'\'
         ORDER BY r.created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
    $totalPages = max(1, (int) ceil($total / $perPage));

    // 失效统计
    $referenced = collect_referenced_upload_paths();
    $referencedLower = array_map('strtolower', $referenced);
    $allFiles = scan_upload_files();
    $orphans = array_values(array_filter($allFiles, fn($f) => !in_array(strtolower($f['path']), $referencedLower, true)));
    $orphanCount = count($orphans);
?>

<section class="card section-card" style="margin-top:0">
    <div class="card-head">
        <div>
            <p class="eyebrow">Input Images</p>
            <h2>参考图片</h2>
        </div>
        <span class="badge">共 <?= $total ?> 条记录</span>
    </div>

    <!-- 工具栏：全选 + 两个删除按钮 -->
    <form method="post" action="/admin/uploads?tab=input&page=<?= $page ?>" id="inputForm">
    <?= csrf_field() ?>
    <div class="toolbar">
        <div class="toolbar-left">
            <label class="select-all-label">
                <input type="checkbox" id="selectAllInput" onchange="toggleAll(this, 'inputForm', 'selInput')"> 全选
            </label>
            <?php if ($orphanCount > 0): ?>
            <span class="stat">发现 <strong><?= $orphanCount ?></strong> 个失效引用</span>
            <?php endif; ?>
        </div>
        <div class="toolbar-right">
            <button type="submit" name="action" value="clean_broken_inputs" class="button warning small"
                    onclick="return confirm('确认清理所有失效引用？（清理DB中指向不存在文件的引用 + 删除磁盘上的孤立文件）')">
                🧹 删除失效引用
            </button>
            <button type="submit" name="action" value="delete_selected_inputs" class="button danger small"
                    onclick="return confirmDelSelected('inputForm', 'selInput', '参考图片')">
                🗑️ 删除所选
            </button>
        </div>
    </div>

    <?php if (!$records): ?>
        <div style="display:grid;place-items:center;min-height:120px;border:1px dashed var(--line-strong);border-radius:var(--radius);color:var(--text-muted);font-weight:700;">
            暂无参考图片记录
        </div>
    <?php else: ?>
        <div class="upload-grid">
            <?php foreach ($records as $record): ?>
                <?php
                $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
                if (!is_array($images)) continue;
                ?>
                <?php foreach ($images as $index => $image): ?>
                    <?php if (empty($image['url'])) continue;
                    $imgPath = local_public_file_from_url((string) $image['url']);
                    $fileExists = $imgPath !== null && is_file($imgPath);
                    $entryValue = (int) $record['id'] . '|' . $image['url'];
                    ?>
                    <div class="upload-card" data-file-exists="<?= $fileExists ? '1' : '0' ?>">
                        <input type="checkbox" name="selected[]" value="<?= e($entryValue) ?>" class="selInput card-check">
                        <div class="thumb">
                            <?php if ($fileExists): ?>
                                <img src="<?= e($image['url']) ?>" alt="参考图片">
                            <?php else: ?>
                                <span class="no-img" style="color:var(--danger)">⚠️ 文件丢失</span>
                            <?php endif; ?>
                        </div>
                        <div class="info">
                            <span><span class="label">用户：</span><span class="val"><?= e($record['username']) ?></span></span>
                            <span><span class="label">记录 #<?= (int) $record['id'] ?></span></span>
                            <span><span class="label">上传：</span><span class="val"><?= e($record['created_at']) ?></span></span>
                            <span style="font-size:11px;color:var(--text-muted);word-break:break-all"><?= e(basename($image['url'])) ?></span>
                        </div>
                        <div class="actions">
                            <?php if ($fileExists): ?>
                                <a class="button secondary small" href="<?= e($image['url']) ?>" target="_blank" rel="noopener">查看原图</a>
                            <?php else: ?>
                                <span style="font-size:11px;color:var(--danger)">文件不存在</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </form>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" style="margin-top:20px">
                <a class="button secondary small <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= $page > 1 ? ('/admin/uploads?tab=input&page=' . ($page - 1)) : '#' ?>">上一页</a>
                <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 条记录</span>
                <a class="button secondary small <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= $page < $totalPages ? ('/admin/uploads?tab=input&page=' . ($page + 1)) : '#' ?>">下一页</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
// ============================================================
// Tab 2: 生成图片
// ============================================================
elseif ($activeTab === 'generated'):
    $perPage = 24;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare("SELECT COUNT(*) FROM generation_records WHERE image_url IS NOT NULL AND image_url != '' AND deleted_at IS NULL");
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT r.id, r.user_id, r.image_url, r.status, r.mode, r.prompt, r.finished_at, r.created_at, u.username
         FROM generation_records r
         JOIN users u ON u.id = r.user_id
         WHERE r.image_url IS NOT NULL AND r.image_url != '' AND r.deleted_at IS NULL
         ORDER BY r.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
    $totalPages = max(1, (int) ceil($total / $perPage));

    // 失效统计：孤立文件 + 已删除记录的残留文件
    $referenced = collect_referenced_upload_paths();
    $referencedLower = array_map('strtolower', $referenced);
    $orphanGenCount = 0;
    $uploadsDir = dirname(__DIR__, 2) . '/public/uploads/generations';
    if (is_dir($uploadsDir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && !in_array(strtolower($f->getRealPath()), $referencedLower, true)) {
                $orphanGenCount++;
            }
        }
    }
    $stmt = db()->query("SELECT COUNT(*) FROM generation_records WHERE deleted_at IS NOT NULL AND image_url IS NOT NULL AND image_url != ''");
    $deletedWithFiles = (int) $stmt->fetchColumn();
    $brokenTotal = $orphanGenCount + $deletedWithFiles;
?>

<section class="card section-card" style="margin-top:0">
    <div class="card-head">
        <div>
            <p class="eyebrow">Generated Images</p>
            <h2>生成图片</h2>
        </div>
        <span class="badge">共 <?= $total ?> 张</span>
    </div>

    <form method="post" action="/admin/uploads?tab=generated&page=<?= $page ?>" id="generatedForm">
    <?= csrf_field() ?>
    <div class="toolbar">
        <div class="toolbar-left">
            <label class="select-all-label">
                <input type="checkbox" id="selectAllGen" onchange="toggleAll(this, 'generatedForm', 'selGen')"> 全选
            </label>
            <?php if ($brokenTotal > 0): ?>
            <span class="stat">发现 <strong><?= $brokenTotal ?></strong> 处失效引用</span>
            <?php endif; ?>
        </div>
        <div class="toolbar-right">
            <button type="submit" name="action" value="clean_broken_generated" class="button warning small"
                    onclick="return confirm('确认清理所有失效引用？（删除磁盘上的孤立文件 + 清理已删除记录的残留文件）')">
                🧹 删除失效引用
            </button>
            <button type="submit" name="action" value="delete_selected_generated" class="button danger small"
                    onclick="return confirmDelSelected('generatedForm', 'selGen', '生成图片')">
                🗑️ 删除所选
            </button>
        </div>
    </div>

    <?php if (!$records): ?>
        <div style="display:grid;place-items:center;min-height:120px;border:1px dashed var(--line-strong);border-radius:var(--radius);color:var(--text-muted);font-weight:700;">
            暂无生成图片
        </div>
    <?php else: ?>
        <div class="upload-grid">
            <?php foreach ($records as $record): ?>
                <?php
                $imgPath = local_public_file_from_url((string) $record['image_url']);
                $fileExists = $imgPath !== null && is_file($imgPath);
                ?>
                <div class="upload-card" data-file-exists="<?= $fileExists ? '1' : '0' ?>">
                    <input type="checkbox" name="selected[]" value="<?= (int) $record['id'] ?>" class="selGen card-check">
                    <div class="thumb">
                        <?php if ($fileExists): ?>
                            <img src="<?= e($record['image_url']) ?>" alt="生成图片">
                        <?php else: ?>
                            <span class="no-img" style="color:var(--danger)">⚠️ 文件丢失</span>
                        <?php endif; ?>
                    </div>
                    <div class="info">
                        <span><span class="label">用户：</span><span class="val"><?= e($record['username']) ?></span></span>
                        <span><span class="label">记录 #<?= (int) $record['id'] ?></span></span>
                        <span class="val" title="<?= e($record['prompt']) ?>"><?= e(mb_substr((string) $record['prompt'], 0, 30)) ?><?= mb_strlen((string) $record['prompt']) > 30 ? '…' : '' ?></span>
                        <span style="font-size:11px;color:var(--text-muted)"><?= e($record['finished_at'] ?: $record['created_at']) ?></span>
                    </div>
                    <div class="actions">
                        <?php if ($fileExists): ?>
                            <a class="button secondary small" href="<?= e($record['image_url']) ?>" target="_blank" rel="noopener">查看</a>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--danger)">文件不存在</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" style="margin-top:20px">
                <a class="button secondary small <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= $page > 1 ? ('/admin/uploads?tab=generated&page=' . ($page - 1)) : '#' ?>">上一页</a>
                <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 张</span>
                <a class="button secondary small <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= $page < $totalPages ? ('/admin/uploads?tab=generated&page=' . ($page + 1)) : '#' ?>">下一页</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php endif; ?>

<script>
// 全选/取消全选
function toggleAll(masterCheckbox, formId, checkboxClass) {
    var form = document.getElementById(formId);
    if (!form) return;
    var checks = form.querySelectorAll('.' + checkboxClass);
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = masterCheckbox.checked;
        updateCardStyle(checks[i]);
    }
}

// 单个checkbox变化时更新卡片样式
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('selInput') || e.target.classList.contains('selGen')) {
        updateCardStyle(e.target);
        updateSelectAll(e.target);
    }
});

function updateCardStyle(cb) {
    var card = cb.closest('.upload-card');
    if (card) {
        card.classList.toggle('selected', cb.checked);
    }
}

function updateSelectAll(cb) {
    var form = cb.closest('form');
    if (!form) return;
    var className = cb.classList.contains('selInput') ? 'selInput' : 'selGen';
    var selectAllId = className === 'selInput' ? 'selectAllInput' : 'selectAllGen';
    var all = form.querySelectorAll('.' + className);
    var checked = form.querySelectorAll('.' + className + ':checked');
    var master = document.getElementById(selectAllId);
    if (master) {
        master.checked = all.length > 0 && checked.length === all.length;
    }
}

function confirmDelSelected(formId, checkboxClass, label) {
    var form = document.getElementById(formId);
    if (!form) return false;
    var checked = form.querySelectorAll('.' + checkboxClass + ':checked');
    if (checked.length === 0) {
        alert('请先勾选要删除的' + label);
        return false;
    }
    return confirm('确认删除所选 ' + checked.length + ' 张' + label + '？\n\n数据库记录和物理文件将一同删除，此操作不可恢复！');
}
</script>
<?php render_footer(); ?>
