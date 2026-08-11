<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/generation.php';

$user = require_login_optional();
$isGuest = !$user;
if ($isGuest) {
    $user = ["id" => 0, "username" => "访客", "role" => "user", "credits" => 0, "watermark_points" => 0, "is_active" => 1];
}
ensure_gallery_table();

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(48, (int) ($_GET['limit'] ?? 24)));
$mode  = (string) ($_GET['mode'] ?? '');

$where = '';
$params = [];
if (in_array($mode, ['draw', 'edit', 'video'], true)) {
    $where = 'WHERE mode = ?';
    $params[] = $mode;
}

$stmt = db()->prepare("SELECT COUNT(*) FROM gallery $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $limit));
$offset = ($page - 1) * $limit;

$stmt = db()->prepare(
    "SELECT g.id, g.user_id, g.record_id, g.username, g.prompt, g.image_url,
            g.mime_type, g.model, g.mode, g.size, g.likes, g.created_at
     FROM gallery g $where
     ORDER BY g.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$items = $stmt->fetchAll();

render_header('图片广场', 'gallery');
?>

<style>
/* ── 广场专属性能优化 ── */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
@media (max-width: 1024px) { .gallery-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .gallery-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px)  { .gallery-grid { grid-template-columns: 1fr; } }

/* content-visibility: auto 让浏览器跳过屏幕外卡片的渲染，大幅减少首帧耗时 */
.gallery-grid .media-card {
    content-visibility: auto;
    contain-intrinsic-size: auto 320px;
}

/* 图片容器：固定宽高比防止 CLS */
.gallery-grid .media-card img,
.gallery-grid .media-card video {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    display: block;
    background: var(--main-surface-soft, #f1f5f9);
    transition: opacity 0.3s ease;
}

/* 懒加载时的模糊占位过渡 */
.gallery-grid .media-card img.lazy-img {
    opacity: 0;
    filter: blur(10px);
    transform: scale(1.02);
}
.gallery-grid .media-card img.lazy-loaded {
    opacity: 1;
    filter: blur(0);
    transform: scale(1);
}

/* 模式筛选 tabs（继承 app.css 的 .tabs / .tab） */
.gallery-filter {
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.gallery-filter .count-hint {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
}

/* 分享按钮 */
.media-card .share-overlay {
    position: absolute;
    top: 6px;
    right: 6px;
    z-index: 2;
    opacity: 0;
    transition: opacity 0.2s;
}
.media-card:hover .share-overlay { opacity: 1; }
.media-card .share-overlay button {
    background: rgba(0,0,0,0.55);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 11px;
    cursor: pointer;
}
.media-card .share-overlay button:hover {
    background: rgba(0,0,0,0.75);
}
</style>

<main>
    <div class="page-hd">
        <div>
            <h1>图片广场</h1>
            <p>浏览社区公开作品，获取灵感</p>
        </div>
    </div>

    <!-- 模式筛选 -->
    <div class="gallery-filter">
        <nav class="tabs">
            <a href="/user/gallery"      class="tab <?= $mode === ''     ? 'active' : '' ?>">全部</a>
            <a href="/user/gallery?mode=draw"  class="tab <?= $mode === 'draw'  ? 'active' : '' ?>">绘画</a>
            <a href="/user/gallery?mode=edit"  class="tab <?= $mode === 'edit'  ? 'active' : '' ?>">编辑</a>
            <a href="/user/gallery?mode=video" class="tab <?= $mode === 'video' ? 'active' : '' ?>">视频</a>
        </nav>
        <span class="count-hint">共 <?= number_format($total) ?> 件作品</span>
    </div>

    <?php if (!$items): ?>
        <div class="empty-state">
            <div class="icon">🖼️</div>
            <h3>暂无公开作品</h3>
            <p>去生成你的第一张图片，并分享到广场吧！</p>
        </div>
    <?php else: ?>
        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($items as $idx => $item): ?>
                <?php
                $galleryImageUrl = $item['image_url'];
                // 兼容已有广场记录中的远程 URL — 自动下载到本地
                if (is_remote_url($galleryImageUrl)) {
                    $galleryImageUrl = ensure_local_image_url($galleryImageUrl, (int) ($item['record_id'] ?? 0));
                    try {
                        db()->prepare('UPDATE gallery SET image_url = ? WHERE id = ? AND image_url = ?')
                            ->execute([$galleryImageUrl, $item['id'], $item['image_url']]);
                    } catch (Throwable $e) {
                        Logger::warning('更新广场记录本地化URL失败', [
                            'gallery_id' => $item['id'],
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
                $isVideo = $item['mode'] === 'video';
                // 前 8 张以内为 above-fold，使用 eager 加载
                $isAboveFold = $idx < 8;
                ?>
                <div class="media-card" style="cursor:pointer; position:relative;"
                     data-record-id="<?= (int) $item['record_id'] ?>"
                     data-status="succeeded"
                     data-mode="<?= e($item['mode']) ?>"
                     data-prompt="<?= e($item['prompt'] ?? '') ?>"
                     data-size="<?= e($item['size'] ?? 'auto') ?>"
                     data-credits="0"
                     data-created="<?= e($item['created_at']) ?>"
                     data-finished="<?= e($item['created_at']) ?>"
                     data-input-count="0"
                     tabindex="0"
                >
                    <?php if ($isVideo): ?>
                        <video src="<?= e($galleryImageUrl) ?>" muted preload="metadata"
                               <?= $isAboveFold ? '' : 'loading="lazy"' ?>
                               poster="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Crect fill='%23f1f5f9' width='400' height='400'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='central' text-anchor='middle' fill='%2394a3b8' font-size='48'%3E▶%3C/text%3E%3C/svg%3E"
                        ></video>
                    <?php else: ?>
                        <img src="<?= e($galleryImageUrl) ?>"
                             alt="<?= e($item['prompt'] ?? '') ?>"
                             class="lazy-img<?= $isAboveFold ? ' lazy-loaded' : '' ?>"
                             decoding="<?= $isAboveFold ? 'sync' : 'async' ?>"
                             loading="<?= $isAboveFold ? 'eager' : 'lazy' ?>"
                             fetchpriority="<?= $isAboveFold ? 'high' : 'low' ?>"
                        >
                    <?php endif; ?>
                    <div class="share-overlay">
                        <button type="button" title="分享到广场"
                                onclick="event.stopPropagation();">
                            🔗
                        </button>
                    </div>
                    <div class="media-card-body">
                        <div class="prompt"><?= e($item['prompt'] ?? '') ?></div>
                        <div class="meta">
                            <span>@<?= e($item['username'] ?? '用户' . $item['user_id']) ?></span>
                            <span><?= e($item['model'] ?? '-') ?> · <?= e(['draw' => '绘画', 'edit' => '编辑', 'video' => '视频'][$item['mode']] ?? $item['mode']) ?></span>
                        </div>
                        <time><?= e($item['created_at']) ?></time>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pages" style="margin-top:24px;">
            <?php if ($page > 1): ?>
                <a class="page-btn" href="/user/gallery?page=<?= $page - 1 ?><?= $mode ? '&mode=' . $mode : '' ?>">上一页</a>
            <?php endif; ?>
            <span><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="page-btn" href="/user/gallery?page=<?= $page + 1 ?><?= $mode ? '&mode=' . $mode : '' ?>">下一页</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>

<script src="/assets/user.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/user.js') ?: time())) ?>"></script>

<script>
// ── 高性能 Intersection Observer 懒加载（渐进模糊→清晰） ──
(function() {
    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            var img = entry.target;
            // 图片已在 src 中，直接移除模糊类触发过渡
            requestAnimationFrame(function() {
                img.classList.add('lazy-loaded');
            });
            observer.unobserve(img);
        });
    }, {
        rootMargin: '200px 0px', // 提前 200px 开始加载
        threshold: 0.01
    });

    // 仅对首屏以下（非 lazy-loaded）的图片注册观察
    document.querySelectorAll('.gallery-grid img.lazy-img:not(.lazy-loaded)').forEach(function(img) {
        observer.observe(img);
    });
})();

// ── 点击卡片打开详情弹窗 ──
(function() {
    document.getElementById("galleryGrid")?.addEventListener("click", function(e) {
        // 排除分享按钮点击
        if (e.target.closest('.share-overlay')) return;
        var card = e.target.closest(".media-card");
        if (!card) return;
        if (typeof openRecordDialog === "function") {
            openRecordDialog(card);
        }
    });

    // 键盘可访问性
    document.getElementById("galleryGrid")?.addEventListener("keydown", function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            var card = e.target.closest(".media-card");
            if (!card) return;
            e.preventDefault();
            if (typeof openRecordDialog === "function") {
                openRecordDialog(card);
            }
        }
    });
})();
</script>
<?php if ($isGuest): ?>
<div id="authModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeAuthModal()"><div class="modal-card" style="background:var(--main-surface);border-radius:16px;width:90vw;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.35);"><div style="padding:24px 24px 0;display:flex;justify-content:space-between;align-items:center;"><h3 style="margin:0;">登录 / 注册</h3><button onclick="closeAuthModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);">&times;</button></div><div style="padding:20px 24px 24px;"><p style="font-size:14px;color:var(--text-muted);margin-bottom:20px;">请登录后使用完整功能。</p><a href="/login" class="btn btn-primary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;margin-bottom:10px;">🔑 登录</a><a href="/login?register=1" class="btn btn-secondary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;">📝 注册</a></div></div></div>
<script>
function showAuthModal(){document.getElementById("authModal").style.display="flex"}
function closeAuthModal(){document.getElementById("authModal").style.display="none"}
document.addEventListener("click",function(e){var btn=e.target.closest("button, [role=button], a.btn, .media-card, .gallery-item");if(!btn)return;if(btn.closest("#authModal")||btn.closest("nav")||btn.closest(".admin-nav-bar"))return;if(btn.closest("[data-close-dialog]"))return;e.preventDefault();e.stopPropagation();showAuthModal()},true)
</script>
<?php endif; ?>
<?php render_footer(); ?>
