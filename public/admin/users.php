<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/membership.php';

$admin = require_admin();
ensure_watermark_columns();

function admin_users_redirect(int $page): void { redirect('/admin/users?page=' . max(1, $page)); }
function admin_user_by_id(int $userId): ?array {
    $stmt = db()->prepare('SELECT id, username, role, credits, watermark_points, is_active, email, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]); return $stmt->fetch() ?: null;
}
function active_admin_count(): int {
    return (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $page   = max(1, (int) ($_POST['page'] ?? 1));
    $target = $userId > 0 ? admin_user_by_id($userId) : null;
    if (!$target) { flash('error', '用户不存在。'); admin_users_redirect($page); }
    $isSelf = (int) $target['id'] === (int) $admin['id'];

    if ($action === 'edit_user') {
        // 统一编辑：余额、水印点、密码、封禁、删除
        $credits = max(0, (int) ($_POST['credits'] ?? 0));
        $watermarkPoints = max(0, (int) ($_POST['watermark_points'] ?? 0));
        $newPassword = trim((string) ($_POST['password'] ?? ''));
        $active = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
        $delete = !empty($_POST['delete_user']);
        $emailVerified = trim((string) ($_POST['email_verified'] ?? ''));
        if ($emailVerified !== '') {
            if ($emailVerified === '1') {
                db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), email_verify_token = NULL WHERE id = ?')
                    ->execute([$userId]);
                $emailVerifiedChanged = true;
            } elseif ($emailVerified === '0') {
                db()->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$userId]);
                $emailVerifiedChanged = true;
            }
        }

        // ── 会员编辑 ──
        $memAction = trim((string) ($_POST['membership_action'] ?? ''));
        $memPkgId = max(0, (int) ($_POST['membership_package_id'] ?? 0));
        $memExpires = trim((string) ($_POST['membership_expires'] ?? ''));
        if ($memAction === 'set' && $memPkgId > 0 && $memExpires !== '') {
            // 覆写会员：停掉旧会员，创建新记录
            db()->prepare("UPDATE user_memberships SET status = 'expired', updated_at = NOW() WHERE user_id = ? AND status = 'active'")
                ->execute([$userId]);
            $pkg = db()->prepare('SELECT * FROM membership_packages WHERE id = ? LIMIT 1');
            $pkg->execute([$memPkgId]);
            $pkgRow = $pkg->fetch();
            if ($pkgRow) {
                db()->prepare(
                    'INSERT INTO user_memberships (user_id, package_id, status, daily_quota, monthly_quota, yearly_quota, started_at, expires_at, created_at)
                     VALUES (?, ?, "active", ?, ?, ?, NOW(), ?, NOW())'
                )->execute([$userId, $memPkgId, (int)$pkgRow['daily_quota'], (int)$pkgRow['monthly_quota'], (int)$pkgRow['yearly_quota'], $memExpires]);
            }
        } elseif ($memAction === 'clear') {
            // 清除会员
            db()->prepare("UPDATE user_memberships SET status = 'expired', updated_at = NOW() WHERE user_id = ? AND status = 'active'")
                ->execute([$userId]);
        }

        if ($delete) {
            if ($isSelf) { flash('error', '不能删除自己。'); admin_users_redirect($page); }
            if ($target['role'] === 'admin' && active_admin_count() <= 1) { flash('error', '至少保留一个管理员。'); admin_users_redirect($page); }
            db()->prepare('DELETE FROM generation_records WHERE user_id = ?')->execute([$userId]);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            flash('success', '用户及记录已删除。');
            admin_users_redirect($page);
        }

        if ($isSelf && $active === 0) { flash('error', '不能封禁自己。'); admin_users_redirect($page); }
        if ($active === 0 && $target['role'] === 'admin' && active_admin_count() <= 1) { flash('error', '至少保留一个管理员。'); admin_users_redirect($page); }

        db()->prepare('UPDATE users SET credits = ?, watermark_points = ?, is_active = ? WHERE id = ?')
            ->execute([$credits, $watermarkPoints, $active, $userId]);

        record_credit_change($userId, 'admin_set', $credits, $credits, '管理员修改余额为 ' . $credits);
        record_credit_change($userId, 'admin_set', $watermarkPoints, $watermarkPoints, '管理员修改水印点为 ' . $watermarkPoints);

        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) { flash('error', '密码至少 6 位。'); admin_users_redirect($page); }
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        }

        flash('success', '用户「' . e($target['username']) . '」信息已更新。');
        admin_users_redirect($page);
    }

    flash('error', '未知操作。'); admin_users_redirect($page);
}

$stats = [
    'total'    => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'   => (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
    'inactive' => (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn(),
    'admins'   => (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn(),
    'credits'  => (int) db()->query('SELECT COALESCE(SUM(credits), 0) FROM users')->fetchColumn(),
];

$search = trim((string) ($_GET['search'] ?? ''));
$where = ''; $params = [];
if ($search !== '') { $where = ' WHERE u.username LIKE ? OR u.email LIKE ?'; $p = '%'.$search.'%'; $params = [$p, $p]; }

$perPage = 20; $page = max(1, (int) ($_GET['page'] ?? 1));
$countStmt = db()->prepare('SELECT COUNT(*) FROM users u'.$where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) redirect('/admin/users?page='.$totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT u.*, COUNT(r.id) AS gen_count,
        COALESCE(SUM(CASE WHEN r.status="succeeded" THEN 1 ELSE 0 END),0) AS ok,
        COALESCE(SUM(CASE WHEN r.status="failed" THEN 1 ELSE 0 END),0) AS fail,
        um.id AS membership_id, um.package_id, um.status AS membership_status,
        um.expires_at AS membership_expires, mp.name AS membership_package_name
     FROM users u LEFT JOIN generation_records r ON r.user_id=u.id'.$where.
     ' LEFT JOIN user_memberships um ON um.user_id = u.id AND um.status = "active"
       LEFT JOIN membership_packages mp ON mp.id = um.package_id
     GROUP BY u.id, um.id, mp.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$i = 1; foreach ($params as $p) $stmt->bindValue($i++, $p);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT); $stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute(); $users = $stmt->fetchAll();

// 将用户数据转为 JSON 供前端弹窗使用
$usersJson = [];
foreach ($users as $u) {
    $usersJson[(int)$u['id']] = [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'email' => $u['email'] ?: '未填写',
        'email_verified' => !empty($u['email_verified_at']),
        'role' => $u['role'],
        'credits' => (int)$u['credits'],
        'watermark_points' => (int)($u['watermark_points'] ?? 0),
        'is_active' => (int)$u['is_active'],
        'created_at' => $u['created_at'],
        'gen_count' => (int)$u['gen_count'],
        'gen_ok' => (int)$u['ok'],
        'gen_fail' => (int)$u['fail'],
        'membership_id' => (int)($u['membership_id'] ?? 0),
        'membership_package_id' => (int)($u['package_id'] ?? 0),
        'membership_status' => $u['membership_status'] ?? '',
        'membership_expires' => $u['membership_expires'] ?? '',
        'membership_package_name' => $u['membership_package_name'] ?? '',
    ];
}

render_header('用户管理', 'admin');
render_admin_nav('users');
?>
<style>
.user-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
.user-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.user-stat .n { font-size: 26px; font-weight: 800; color: var(--text); }
.user-stat .l { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .5px; margin-top: 2px; }
.user-search { display: flex; gap: 8px; margin-bottom: 16px; }
.user-search input { flex: 1; max-width: 280px; padding: 8px 14px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; background: var(--main-surface); }
.user-search input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.user-list { display: flex; flex-direction: column; gap: 10px; }
.user-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; transition: all .12s; }
.user-card:hover { border-color: var(--primary-soft); }
.user-card.banned { opacity: .55; }
.user-card .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.user-card .info { flex: 1; min-width: 0; }
.user-card .info .name { font-size: 15px; font-weight: 700; color: var(--text); }
.user-card .info .name .role { font-size: 10px; background: var(--primary-soft); color: var(--primary); padding: 2px 8px; border-radius: 10px; margin-left: 8px; font-weight: 600; }
.user-card .info .email { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.user-card .stats-row { display: flex; gap: 16px; margin-top: 10px; flex-wrap: wrap; }
.user-card .stats-row .stat { font-size: 12px; color: var(--text-muted); }
.user-card .stats-row .stat b { color: var(--text); }

/* 模态弹窗 */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 999; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-card { background: var(--main-surface); border-radius: 16px; width: 90vw; max-width: 480px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.modal-head { padding: 20px 24px 0; display: flex; justify-content: space-between; align-items: center; }
.modal-head h3 { margin: 0; font-size: 18px; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); line-height: 1; padding: 0; }
.modal-body { padding: 16px 24px 24px; }
.modal-body .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.modal-body .field label { font-size: 13px; font-weight: 600; color: var(--text-soft); }
.modal-body .field input,
.modal-body .field select { padding: 8px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; background: var(--main-surface); }
.modal-body .field input:focus,
.modal-body .field select:focus { outline: none; border-color: var(--primary); }
.modal-body .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.modal-body .field-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.modal-body .danger-zone { border-top: 1px solid var(--line); margin-top: 16px; padding-top: 14px; }
.modal-body .danger-zone label { color: var(--danger); display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
.modal-foot { padding: 0 24px 20px; display: flex; gap: 10px; justify-content: flex-end; }

@media (max-width: 768px) {
    .modal-card { max-width: 96vw; }
    .modal-body .field-row { grid-template-columns: 1fr; }
}
</style>
<main>
    <div class="page-hd">
        <div><h1>用户管理</h1><p>Users</p></div>
    </div>

    <div class="user-stats">
        <div class="user-stat"><div class="n"><?= $stats['total'] ?></div><div class="l">总用户</div></div>
        <div class="user-stat"><div class="n"><?= $stats['active'] ?></div><div class="l">活跃</div></div>
        <div class="user-stat"><div class="n"><?= $stats['inactive'] ?></div><div class="l">已封禁</div></div>
        <div class="user-stat"><div class="n"><?= $stats['admins'] ?></div><div class="l">管理员</div></div>
        <div class="user-stat"><div class="n"><?= number_format($stats['credits']) ?></div><div class="l">总<?= e(balance_label()) ?></div></div>
    </div>

    <form method="get" class="user-search">
        <input name="search" value="<?= e($search) ?>" placeholder="搜索用户名或邮箱...">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <?php if ($search): ?><a href="/admin/users" class="btn btn-secondary btn-sm">清除</a><?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:var(--text-muted);align-self:center;">共 <?= $total ?> 个</span>
    </form>

    <?php if (!$users): ?>
        <div class="empty-state"><div class="icon">👥</div><h3>暂无用户</h3></div>
    <?php else: ?>
        <div class="user-list">
            <?php foreach ($users as $u):
                $uid = (int) $u['id']; $isSelf = $uid === (int) $admin['id']; $active = (int) $u['is_active'] === 1;
            ?>
            <div class="user-card <?= !$active ? 'banned' : '' ?>">
                <div class="top">
                    <div class="info">
                        <span class="name">
                            <?= e($u['username']) ?>
                            <?php if ($u['role'] === 'admin'): ?><span class="role">管理员</span><?php endif; ?>
                            <span class="status-badge <?= $active ? 'succeeded' : 'deleted' ?>" style="font-size:10px;"><?= $active ? '正常' : '已封禁' ?></span>
                        </span>
                        <div class="email"><?= e($u['email'] ?: '未填写邮箱') ?> <?php if (!empty($u['email_verified_at'])): ?><span style="color:var(--primary);font-size:10px;font-weight:700;">✓ 已验证</span><?php else: ?><span style="color:var(--text-muted);font-size:10px;">未验证</span><?php endif; ?> · 注册于 <?= e($u['created_at']) ?></div>
                        <div class="stats-row">
                            <span class="stat"><?= e(balance_label()) ?>: <b><?= number_format((int) $u['credits']) ?></b></span>
                            <span class="stat">水印点: <b><?= number_format((int) ($u['watermark_points'] ?? 0)) ?></b></span>
                            <span class="stat">生成: <b><?= (int) $u['gen_count'] ?></b></span>
                            <?php if (!empty($u['membership_package_name'])): ?>
                            <span class="stat" style="color:var(--primary);">💎 <?= e($u['membership_package_name']) ?> · 到期 <?= substr((string)$u['membership_expires'], 0, 10) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary btn-sm" onclick="openUserEdit(<?= $uid ?>)">✏️ 编辑</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? '/admin/users?page='.($page-1).($search?'&search='.urlencode($search):'') : '#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? '/admin/users?page='.($page+1).($search?'&search='.urlencode($search):'') : '#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>

<!-- 编辑用户弹窗 -->
<div id="userEditModal" class="modal-overlay" onclick="if(event.target===this)closeUserEdit()">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="userEditTitle">编辑用户</h3>
            <button class="modal-close" onclick="closeUserEdit()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="userEditForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="hidden" name="is_active" id="editIsActive" value="1">

                <div class="field-row">
                    <div class="field">
                        <label><?= e(balance_label()) ?></label>
                        <input type="number" name="credits" id="editCredits" min="0" required>
                    </div>
                    <div class="field">
                        <label>水印点</label>
                        <input type="number" name="watermark_points" id="editWp" min="0" required>
                    </div>
                </div>
                <div class="field">
                    <label>新密码（留空不修改）</label>
                    <input type="text" name="password" placeholder="填写即重置密码，至少6位" minlength="6">
                </div>
                <div class="field">
                    <label>状态</label>
                    <select name="is_active_select" id="editStatus" onchange="document.getElementById('editIsActive').value=this.value">
                        <option value="1">正常</option>
                        <option value="0">封禁</option>
                    </select>
                </div>
                <div class="field">
                    <label>邮箱验证</label>
                    <input type="hidden" name="email_verified" id="editEmailVerified" value="">
                    <label class="switch" id="emailVerifiedSwitch" onclick="var inp=document.getElementById('editEmailVerified');inp.value=inp.value==='1'?'0':'1';this.classList.toggle('active',inp.value==='1');">
                        <span class="switch-knob"></span>
                        <small id="emailVerifiedLabel" style="margin-left:8px;">已关闭</small>
                    </label>
                </div>

                <!-- ═══ 会员管理 ═══ -->
                <div class="section" style="margin-top:8px;">
                    <h4 style="margin-bottom:6px;">💎 会员设置</h4>
                    <input type="hidden" name="membership_action" id="memAction" value="">
                    <div class="field-row">
                        <div class="field">
                            <label>会员套餐</label>
                            <select name="membership_package_id" id="memPkgId">
                                <option value="0">— 未选择 —</option>
                                <?php foreach (active_membership_packages() as $mp): ?>
                                <option value="<?= (int)$mp['id'] ?>"><?= e($mp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>到期时间</label>
                            <input type="datetime-local" name="membership_expires" id="memExpires">
                            <small class="hint">设置后自动激活会员</small>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="btn btn-primary btn-sm" style="padding:6px 14px;font-size:12px;" onclick="setMem()">💾 应用会员</button>
                        <button type="button" class="btn btn-sm" style="padding:6px 14px;font-size:12px;background:var(--danger,#ef4444);color:#fff;" onclick="clearMem()">✕ 清除会员</button>
                    </div>
                </div>

                <div class="danger-zone">
                    <label>
                        <input type="checkbox" name="delete_user" value="1" id="editDelete">
                        ⚠️ 删除此用户及其所有生成记录（不可恢复）
                    </label>
                </div>
                <div class="credit-log" id="creditLog" style="margin-top:12px;max-height:200px;overflow-y:auto;font-size:12px;border:1px solid var(--line);border-radius:10px;padding:10px 14px;display:none;"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeUserEdit()">取消</button>
            <button type="submit" class="btn btn-primary" form="userEditForm">💾 保存</button>
        </div>
    </div>
</div>

<script>
var _users = <?= json_encode($usersJson, JSON_UNESCAPED_UNICODE) ?>;
var _adminId = <?= (int)$admin['id'] ?>;

function openUserEdit(uid) {
    var u = _users[uid];
    if (!u) return;
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editCredits').value = u.credits;
    document.getElementById('editWp').value = u.watermark_points;
    document.getElementById('editIsActive').value = u.is_active;
    document.getElementById('editStatus').value = u.is_active;
    document.getElementById('editDelete').checked = false;
    // 邮箱验证状态
    var verSw = document.getElementById('emailVerifiedSwitch');
    var verLbl = document.getElementById('emailVerifiedLabel');
    var isVer = u.email_verified;
    document.getElementById('editEmailVerified').value = isVer ? '1' : '0';
    if (verSw) { verSw.classList.toggle('active', isVer); }
    if (verLbl) { verLbl.textContent = isVer ? '已验证' : '未验证'; }
    // 会员字段
    document.getElementById('memAction').value = '';
    document.getElementById('memPkgId').value = u.membership_package_id || 0;
    document.getElementById('memExpires').value = u.membership_expires ? u.membership_expires.substring(0, 16) : '';
    document.getElementById('userEditTitle').textContent = '编辑用户 — ' + u.username;
    document.getElementById('userEditModal').classList.add('show');
    loadCreditLog(uid);
}

function setMem() {
    var pkg = document.getElementById('memPkgId').value;
    var exp = document.getElementById('memExpires').value;
    if (!pkg || pkg === '0') { alert('请选择会员套餐'); return; }
    if (!exp) { alert('请选择到期时间'); return; }
    document.getElementById('memAction').value = 'set';
}

function clearMem() {
    if (!confirm('确认清除该用户的会员？')) return;
    document.getElementById('memAction').value = 'clear';
    document.getElementById('memPkgId').value = 0;
    document.getElementById('memExpires').value = '';
}

async function loadCreditLog(uid) {
    var log = document.getElementById('creditLog');
    log.style.display = 'none';
    log.innerHTML = '加载中...';
    try {
        var res = await fetch('/admin/credit_log?user_id=' + uid);
        var data = await res.json();
        if (data.ok && data.records && data.records.length) {
            log.innerHTML = '<div style="font-weight:700;margin-bottom:6px;">📋 余额变动记录</div>' +
                data.records.map(function(r) {
                    var icon = r.type.indexOf('add') >= 0 ? '🟢 +' : r.type.indexOf('deduct') >= 0 ? '🔴 -' : '🔵';
                    var label = r.type === 'credit_add' ? '余额' : r.type === 'credit_deduct' ? '余额' :
                               r.type === 'wp_add' ? '水印点' : r.type === 'wp_deduct' ? '水印点' : '设置';
                    return '<div style="padding:3px 0;border-bottom:1px solid var(--line);">' +
                        icon + r.amount + ' ' + label + ' <span style="color:var(--text-muted);">' + r.description + '</span>' +
                        '<span style="float:right;font-size:10px;color:var(--text-muted);">' + r.created_at + '</span></div>';
                }).join('');
            log.style.display = 'block';
        }
    } catch(e) { log.style.display = 'none'; }
}

function closeUserEdit() {
    document.getElementById('userEditModal').classList.remove('show');
}
</script>

<?php render_footer(); ?>
