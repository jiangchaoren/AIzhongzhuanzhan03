<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/generation.php';

require_admin();
ensure_gallery_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);

    if ($action === 'remove' && $galleryId > 0) {
        db()->prepare('DELETE FROM gallery WHERE id = ?')->execute([$galleryId]);
        flash('success', '已移除该分享。');
    }
    redirect('/admin/gallery');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;

$stmt = db()->query('SELECT COUNT(*) FROM gallery');
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $limit));
$offset = ($page - 1) * $limit;

$stmt = db()->prepare(
    'SELECT g.*, u.username
     FROM gallery g
     LEFT JOIN users u ON g.user_id = u.id
     ORDER BY g.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

// 预处理图片 URL — 兼容已有广场记录中的远程地址
$localizedUrls = [];
foreach ($items as $item) {
    $url = $item['image_url'];
    if (is_remote_url($url)) {
        $recordId = (int) ($item['record_id'] ?? 0);
        $url = ensure_local_image_url($url, $recordId);
        // 同步更新 gallery 表
        try {
            db()->prepare('UPDATE gallery SET image_url = ? WHERE id = ? AND image_url = ?')
                ->execute([$url, $item['id'], $item['image_url']]);
        } catch (Throwable $e) {
            Logger::warning('管理后台-更新广场URL失败', ['gallery_id' => $item['id']]);
        }
    }
    $localizedUrls[$item['id']] = $url;
}

render_header('图片广场管理', 'admin');
render_admin_nav('gallery');
?>
<style>
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; }
.admin-table th { font-weight: 600; color: var(--text-muted); font-size: 11px; text-transform: uppercase; }
.admin-table img { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; }
.prompt-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* mobile gallery cards */
.gallery-mobile { display: none; flex-direction: column; gap: 10px; padding: 10px; }
.gallery-mobile-card { display: flex; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--main-surface); align-items: center; }
.gallery-mobile-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
.gallery-mobile-thumb-video { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
.gallery-mobile-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.gallery-mobile-info .user { font-weight: 700; font-size: 13px; color: var(--text); }
.gallery-mobile-info .prompt { font-size: 11px; color: var(--text-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gallery-mobile-info .meta { display: flex; gap: 8px; font-size: 10px; color: var(--text-muted); flex-wrap: wrap; }
.gallery-mobile-info .meta span { white-space: nowrap; }
.gallery-mobile-actions { display: flex; align-items: center; flex-shrink: 0; }
.gallery-mobile-actions .btn { font-size: 11px; padding: 4px 10px; white-space: nowrap; }

@media (max-width: 640px) {
    .admin-table-wrap { display: none; }
    .gallery-mobile { display: flex; }
    .page-hd { flex-direction: column; align-items: flex-start; gap: 8px; }
    .page-hd h1 { font-size: 18px; }
}
</style>
<main>
    <div class="page-hd">
        <div>
            <h1>图片广场管理</h1>
            <p>共 <?= $total ?> 条分享</p>
        </div>
    </div>

    <section class="card-v3">
        <div class="card-v3-body" style="padding:0;">
            <?php if (!$items): ?>
                <div class="empty-state"><div class="icon">🖼️</div><h3>暂无分享</h3></div>
            <?php else: ?>
            <div class="admin-table-wrap" style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>图片</th>
                            <th>用户</th>
                            <th>提示词</th>
                            <th>模型</th>
                            <th>模式</th>
                            <th>时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php $gUrl = $localizedUrls[$item['id']]; ?>
                                <?php if ($item['mode'] === 'video'): ?>
                                    <video src="<?= e($gUrl) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:6px;" muted></video>
                                <?php else: ?>
                                    <img src="<?= e($gUrl) ?>" alt="">
                                <?php endif; ?>
                            </td>
                            <td><?= e($item['username'] ?? 'ID:' . $item['user_id']) ?></td>
                            <td class="prompt-cell" title="<?= e($item['prompt'] ?? '') ?>"><?= e($item['prompt'] ?? '') ?></td>
                            <td><?= e($item['model'] ?? '-') ?></td>
                            <td><?= e(['draw' => '绘画', 'edit' => '编辑', 'video' => '视频'][$item['mode']] ?? $item['mode']) ?></td>
                            <td style="white-space:nowrap;"><?= e($item['created_at']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('确认移除该分享？')" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="gallery_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger);">移除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- mobile card view -->
            <div class="gallery-mobile">
                <?php foreach ($items as $item): ?>
                <?php $gUrl = $localizedUrls[$item['id']]; ?>
                <div class="gallery-mobile-card">
                    <?php if ($item['mode'] === 'video'): ?>
                        <video src="<?= e($gUrl) ?>" class="gallery-mobile-thumb-video" muted></video>
                    <?php else: ?>
                        <img src="<?= e($gUrl) ?>" class="gallery-mobile-thumb" alt="">
                    <?php endif; ?>
                    <div class="gallery-mobile-info">
                        <span class="user"><?= e($item['username'] ?? 'ID:' . $item['user_id']) ?></span>
                        <span class="prompt" title="<?= e($item['prompt'] ?? '') ?>"><?= e($item['prompt'] ?: '无描述') ?></span>
                        <div class="meta">
                            <span><?= e($item['model'] ?? '-') ?></span>
                            <span><?= e(['draw' => '绘画', 'edit' => '编辑', 'video' => '视频'][$item['mode']] ?? $item['mode']) ?></span>
                            <span><?= e($item['created_at']) ?></span>
                        </div>
                    </div>
                    <div class="gallery-mobile-actions">
                        <form method="post" onsubmit="return confirm('确认移除该分享？')" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="gallery_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger);">移除</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="border-top:1px solid var(--line);padding:12px 20px;display:flex;justify-content:center;gap:8px;">
            <?php if ($page > 1): ?>
                <a class="btn btn-secondary btn-sm" href="?page=<?= $page - 1 ?>">上一页</a>
            <?php endif; ?>
            <span style="padding:6px 12px;font-size:13px;color:var(--text-muted);"><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-secondary btn-sm" href="?page=<?= $page + 1 ?>">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
<?php render_footer(); ?>
