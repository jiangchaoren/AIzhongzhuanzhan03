<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();
ensure_credit_tables();

// ── 删除操作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    verify_csrf();
    $action = (string) $_POST['action'];
    $pdo = db();

    if ($action === 'delete_single' && !empty($_POST['id'])) {
        $stmt = $pdo->prepare('DELETE FROM credit_codes WHERE id = ?');
        $stmt->execute([(int) $_POST['id']]);
        flash('success', '已删除 1 个兑换码。');
    } elseif ($action === 'delete_batch' && !empty($_POST['ids'])) {
        $ids = array_map('intval', explode(',', (string) $_POST['ids']));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('DELETE FROM credit_codes WHERE id IN (' . $in . ')');
            $stmt->execute($ids);
            flash('success', '已删除 ' . count($ids) . ' 个兑换码。');
        }
    } elseif ($action === 'delete_filtered') {
        $filterStatus = (string) ($_POST['filter_status'] ?? 'all');
        $filterKeyword = trim((string) ($_POST['filter_keyword'] ?? ''));
        $allowed = ['all', 'available', 'unused', 'used', 'used_up', 'expired', 'disabled'];
        if (!in_array($filterStatus, $allowed, true)) { $filterStatus = 'all'; }
        $delWhere = [];
        $delParams = [];
        if ($filterKeyword !== '') {
            $delWhere[] = '(c.code LIKE ? OR u.username LIKE ?)';
            $like = '%' . $filterKeyword . '%';
            $delParams[] = $like; $delParams[] = $like;
        }
        if ($filterStatus === 'available') {
            $delWhere[] = 'c.is_active = 1 AND (c.expires_at IS NULL OR c.expires_at >= NOW()) AND c.used_count < c.max_uses';
        } elseif ($filterStatus === 'unused') { $delWhere[] = 'c.used_count = 0'; }
        elseif ($filterStatus === 'used') { $delWhere[] = 'c.used_count > 0'; }
        elseif ($filterStatus === 'used_up') { $delWhere[] = 'c.used_count >= c.max_uses'; }
        elseif ($filterStatus === 'expired') { $delWhere[] = 'c.expires_at IS NOT NULL AND c.expires_at < NOW()'; }
        elseif ($filterStatus === 'disabled') { $delWhere[] = 'c.is_active = 0'; }
        $delSql = $delWhere ? (' WHERE ' . implode(' AND ', $delWhere)) : '';
        $stmt = $pdo->prepare('DELETE c FROM credit_codes c LEFT JOIN users u ON u.id = c.created_by' . $delSql);
        $stmt->execute($delParams);
        flash('success', '已删除 ' . $stmt->rowCount() . ' 个兑换码。');
    } elseif ($action === 'delete_all') {
        $pdo->exec('DELETE FROM credit_codes');
        flash('success', '已清空全部兑换码。');
    }

    redirect('/admin/codes');
}

// ── 导出操作 ──
if (!empty($_GET['export']) && $_GET['export'] === 'txt') {
    $expStatus = (string) ($_GET['export_status'] ?? 'all');
    $expKeyword = trim((string) ($_GET['export_keyword'] ?? ''));
    $allowed = ['all', 'available', 'unused', 'used', 'used_up', 'expired', 'disabled'];
    if (!in_array($expStatus, $allowed, true)) { $expStatus = 'all'; }
    $expWhere = []; $expParams = [];
    if ($expKeyword !== '') {
        $expWhere[] = '(c.code LIKE ? OR u.username LIKE ?)';
        $like = '%' . $expKeyword . '%';
        $expParams[] = $like; $expParams[] = $like;
    }
    if ($expStatus === 'available') {
        $expWhere[] = 'c.is_active = 1 AND (c.expires_at IS NULL OR c.expires_at >= NOW()) AND c.used_count < c.max_uses';
    } elseif ($expStatus === 'unused') { $expWhere[] = 'c.used_count = 0'; }
    elseif ($expStatus === 'used') { $expWhere[] = 'c.used_count > 0'; }
    elseif ($expStatus === 'used_up') { $expWhere[] = 'c.used_count >= c.max_uses'; }
    elseif ($expStatus === 'expired') { $expWhere[] = 'c.expires_at IS NOT NULL AND c.expires_at < NOW()'; }
    elseif ($expStatus === 'disabled') { $expWhere[] = 'c.is_active = 0'; }
    $expSql = $expWhere ? (' WHERE ' . implode(' AND ', $expWhere)) : '';
    $stmt = db()->prepare('SELECT c.code FROM credit_codes c LEFT JOIN users u ON u.id = c.created_by' . $expSql . ' ORDER BY c.created_at DESC');
    $stmt->execute($expParams);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="codes_' . date('Ymd_His') . '.txt"');
    echo implode("\n", $rows);
    exit;
}

// ── 生成操作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $credits = max(1, (int) ($_POST['credits'] ?? 1));
    $maxUses = max(1, (int) ($_POST['max_uses'] ?? 1));
    $count = max(1, min(100, (int) ($_POST['count'] ?? 1)));
    $expiresAt = trim((string) ($_POST['expires_at'] ?? '')) ?: null;
    if ($expiresAt !== null) {
        $expiresAt = str_replace('T', ' ', $expiresAt) . ':00';
    }

    $created = [];
    $stmt = db()->prepare(
        'INSERT INTO credit_codes (code, credits, max_uses, expires_at, created_by) VALUES (?, ?, ?, ?, ?)'
    );

    for ($i = 0; $i < $count; $i++) {
        do {
            $code = random_code(16);
            try {
                $stmt->execute([$code, $credits, $maxUses, $expiresAt, $admin['id']]);
                $created[] = $code;
                break;
            } catch (PDOException $e) {
                $code = null;
            }
        } while ($code === null);
    }

    flash('success', '已生成兑换码：' . implode('，', $created));
    redirect('/admin/codes');
}

$perPage = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$keyword = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'available', 'unused', 'used', 'used_up', 'expired', 'disabled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(c.code LIKE ? OR u.username LIKE ?)';
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'available') {
    $where[] = 'c.is_active = 1 AND (c.expires_at IS NULL OR c.expires_at >= NOW()) AND c.used_count < c.max_uses';
} elseif ($status === 'unused') {
    $where[] = 'c.used_count = 0';
} elseif ($status === 'used') {
    $where[] = 'c.used_count > 0';
} elseif ($status === 'used_up') {
    $where[] = 'c.used_count >= c.max_uses';
} elseif ($status === 'expired') {
    $where[] = 'c.expires_at IS NOT NULL AND c.expires_at < NOW()';
} elseif ($status === 'disabled') {
    $where[] = 'c.is_active = 0';
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
$countStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM credit_codes c
     LEFT JOIN users u ON u.id = c.created_by' . $whereSql
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $query = http_build_query(['q' => $keyword, 'status' => $status, 'page' => $totalPages]);
    redirect('/admin/codes?' . $query);
}
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare(
    'SELECT c.*, u.username AS created_by_name
     FROM credit_codes c
     LEFT JOIN users u ON u.id = c.created_by' . $whereSql . '
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT ? OFFSET ?'
);
$bindIndex = 1;
foreach ($params as $param) {
    $listStmt->bindValue($bindIndex, $param, PDO::PARAM_STR);
    $bindIndex++;
}
$listStmt->bindValue($bindIndex, $perPage, PDO::PARAM_INT);
$listStmt->bindValue($bindIndex + 1, $offset, PDO::PARAM_INT);
$listStmt->execute();
$codes = $listStmt->fetchAll();

$baseQuery = ['q' => $keyword, 'status' => $status];

function code_status_label(array $code): array
{
    if ((int) $code['is_active'] !== 1) {
        return ['已停用', 'deleted'];
    }
    if (!empty($code['expires_at']) && strtotime((string) $code['expires_at']) < time()) {
        return ['已过期', 'failed'];
    }
    if ((int) $code['used_count'] >= (int) $code['max_uses']) {
        return ['已用完', 'running'];
    }
    if ((int) $code['used_count'] === 0) {
        return ['未使用', 'succeeded'];
    }
    return ['可用', 'succeeded'];
}

render_header('兑换码管理', 'admin');
render_admin_nav('codes');
?>
<main class="grid">
    <section class="card code-create-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Create</p>
                <h2>生成兑换码</h2>
            </div>
        </div>
        <form method="post" class="form code-create-form">
            <?= csrf_field() ?>
            <label class="field">
                <span>每个兑换码增加<?= e(balance_label()) ?></span>
                <input name="credits" type="number" min="1" value="10" required>
            </label>
            <label class="field">
                <span>每个兑换码可使用次数</span>
                <input name="max_uses" type="number" min="1" value="1" required>
            </label>
            <label class="field">
                <span>生成数量</span>
                <input name="count" type="number" min="1" max="100" value="1" required>
            </label>
            <label class="field">
                <span>过期时间</span>
                <input name="expires_at" type="datetime-local">
            </label>
            <button class="button primary" type="submit">生成</button>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Codes</p>
                <h2>管理兑换码</h2>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="badge">共 <?= $total ?> 个</span>
                <a class="button secondary small" href="/admin/codes?export=txt&export_status=<?= urlencode($status) ?>&export_keyword=<?= urlencode($keyword) ?>">📥 导出 TXT</a>
            </div>
        </div>
        <form method="get" class="filter-bar">
            <label class="field">
                <span>搜索</span>
                <input name="q" value="<?= e($keyword) ?>" placeholder="兑换码或创建人">
            </label>
            <label class="field">
                <span>状态</span>
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>全部</option>
                    <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>可用</option>
                    <option value="unused" <?= $status === 'unused' ? 'selected' : '' ?>>未使用</option>
                    <option value="used" <?= $status === 'used' ? 'selected' : '' ?>>已使用</option>
                    <option value="used_up" <?= $status === 'used_up' ? 'selected' : '' ?>>已用完</option>
                    <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>已过期</option>
                    <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>
                </select>
            </label>
            <div class="filter-actions">
                <button class="button primary small" type="submit">筛选</button>
                <a class="button secondary small" href="/admin/codes">重置</a>
            </div>
        </form>

        <!-- 批量操作栏 -->
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 0;">
            <button class="button danger small" onclick="batchDelete()">🗑 删除选中</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定要删除当前筛选条件下的全部(' + document.querySelectorAll('[data-ck]:checked').length + '个/全部)兑换码？此操作不可恢复！')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_filtered">
                <input type="hidden" name="filter_status" value="<?= e($status) ?>">
                <input type="hidden" name="filter_keyword" value="<?= e($keyword) ?>">
                <button class="button danger small" type="submit">🗑 删除筛选结果</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定要删除【全部】兑换码？此操作不可恢复！')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_all">
                <button class="button danger small" type="submit">⚠ 一键清空</button>
            </form>
            <span style="font-size:12px;color:var(--text-muted);margin-left:auto;">已选 <strong id="selectedCount">0</strong> 个</span>
        </div>

        <div class="table-wrap">
            <table data-admin-codes>
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleAll(this)" title="全选/取消"></th>
                        <th>兑换码</th>
                        <th><?= e(balance_label()) ?></th>
                        <th>使用</th>
                        <th>剩余</th>
                        <th>状态</th>
                        <th>过期</th>
                        <th>创建人</th>
                        <th>创建</th>
                        <th style="width:60px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($codes as $code): ?>
                        <?php $codeStatus = code_status_label($code); ?>
                        <tr>
                            <td><input type="checkbox" data-ck value="<?= (int) $code['id'] ?>" onchange="updateCount()"></td>
                            <td><code><?= e($code['code']) ?></code></td>
                            <td><?= (int) $code['credits'] ?></td>
                            <td><?= (int) $code['used_count'] ?> / <?= (int) $code['max_uses'] ?></td>
                            <td><?= max(0, (int) $code['max_uses'] - (int) $code['used_count']) ?></td>
                            <td><span class="status <?= e($codeStatus[1]) ?>"><?= e($codeStatus[0]) ?></span></td>
                            <td><?= e($code['expires_at'] ?: '-') ?></td>
                            <td><?= e($code['created_by_name'] ?: '-') ?></td>
                            <td><?= e($code['created_at']) ?></td>
                            <td>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确认删除此兑换码？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_single">
                                    <input type="hidden" name="id" value="<?= (int) $code['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:2px 8px;">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$codes): ?>
                        <tr>
                            <td colspan="10" class="muted">暂无兑换码</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="兑换码分页">
                <a class="button secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? ('/admin/codes?' . http_build_query($baseQuery + ['page' => $page - 1])) : '#' ?>">上一页</a>
                <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 个</span>
                <a class="button secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? ('/admin/codes?' . http_build_query($baseQuery + ['page' => $page + 1])) : '#' ?>">下一页</a>
            </nav>
        <?php endif; ?>
    </section>
</main>
<script>
function toggleAll(el) {
    document.querySelectorAll('[data-ck]').forEach(function(cb) { cb.checked = el.checked; });
    updateCount();
}
function updateCount() {
    var n = document.querySelectorAll('[data-ck]:checked').length;
    document.getElementById('selectedCount').textContent = n;
}
function batchDelete() {
    var ids = [];
    document.querySelectorAll('[data-ck]:checked').forEach(function(cb) { ids.push(cb.value); });
    if (!ids.length) { alert('请先勾选要删除的兑换码'); return; }
    if (!confirm('确认删除选中的 ' + ids.length + ' 个兑换码？此操作不可恢复！')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_batch"><input type="hidden" name="ids" value="' + ids.join(',') + '">';
    document.body.appendChild(f);
    f.submit();
}
updateCount();
</script>
<?php render_footer(); ?>
