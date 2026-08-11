<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/api_token.php';

$user = require_login();
ensure_api_tokens_table();

$balanceLabel = balance_label();
$tokens = get_user_api_tokens((int) $user['id']);
$allPerms = api_available_permissions();
$activeTokens = count(array_filter($tokens, fn($t) => !$t['is_revoked']));

// 动态获取站点 URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$siteUrl = $scheme . '://' . $host;

// 处理 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['api_token_action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['token_name'] ?? ''));
        if ($name === '') {
            flash('error', '请填写令牌名称。');
            redirect('/user/api_tokens');
        }

        $permissions = array_intersect(
            (array) ($_POST['permissions'] ?? []),
            array_keys($allPerms)
        );

        if (empty($permissions)) {
            flash('error', '请至少选择一个权限。');
            redirect('/user/api_tokens');
        }

        $expireDays = (int) ($_POST['expire_days'] ?? 0);
        $result = create_api_token((int) $user['id'], $name, $permissions, $expireDays > 0 ? $expireDays : null);

        $_SESSION['api_token_created'] = true;
        $_SESSION['api_token_raw'] = $result['token_raw'];
        flash('success', 'API 令牌已创建，请立即复制保存！');
        redirect('/user/api_tokens');
    }

    if ($action === 'revoke') {
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        if ($tokenId > 0) {
            revoke_api_token((int) $user['id'], $tokenId);
            flash('success', 'API 令牌已撤销。');
        }
        redirect('/user/api_tokens');
    }

    flash('error', '未知操作。');
    redirect('/user/api_tokens');
}

render_header('API 令牌', 'api-tokens');
?>

<style>
.api-page { max-width: 800px; margin: 0 auto; }

/* 权限选择 chips */
.perm-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.perm-chip { display: flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.15s; user-select: none; }
.perm-chip:hover { border-color: var(--primary-soft); background: var(--primary-soft); }
.perm-chip input { display: none; }
.perm-chip:has(input:checked) { border-color: var(--primary); background: var(--primary-soft); color: var(--primary-dark); box-shadow: 0 0 0 1px var(--primary); }
.perm-chip .dot { width: 8px; height: 8px; border-radius: 50%; }
.perm-chip:has(input:checked) .dot { background: var(--primary); }

/* 令牌列表项 */
.token-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--line); gap: 12px; }
.token-item:last-child { border-bottom: none; }
.token-item:hover { background: var(--main-surface-soft); }
.token-name { font-size: 14px; font-weight: 700; margin-bottom: 2px; display: flex; align-items: center; gap: 8px; }
.token-meta { display: flex; flex-wrap: wrap; gap: 3px 14px; font-size: 12px; color: var(--text-muted); }

/* 令牌创建成功提示 */
.token-reveal { margin-top: 20px; padding: 20px; border-radius: 16px; background: linear-gradient(135deg, #ecfdf5, #f0fdf4); border: 1px solid #a7f3d0; }
.token-reveal strong { color: #059669; font-size: 14px; display: block; margin-bottom: 10px; }
.token-reveal .raw { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 13px; word-break: break-all; background: #fff; padding: 14px; border: 1px solid #a7f3d0; border-radius: 10px; color: #065f46; margin-bottom: 10px; line-height: 1.6; }
.token-reveal .copy-btn { padding: 8px 18px; background: #059669; color: #fff; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
.token-reveal .copy-btn:hover { opacity: 0.85; }
.token-reveal .copy-btn.copied { background: #10b981; }

/* 使用示例 pre */
.example-block { padding: 16px 20px; }
.example-block details summary { cursor: pointer; font-size: 13px; font-weight: 700; color: var(--primary); margin-bottom: 8px; user-select: none; }
.example-block pre { margin: 0; padding: 14px; background: var(--main-surface-soft); border-radius: 12px; overflow-x: auto; font-size: 12px; line-height: 1.7; white-space: pre-wrap; word-break: break-all; }
</style>

<main class="api-page">
    <div class="page-hd">
        <div>
            <h1>API 令牌</h1>
            <p>用于第三方站点或脚本调用本程序接口 · 令牌仅创建时显示一次</p>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="badge-balance">
                <span class="num" data-balance-display><?= number_format((int) $user['credits']) ?></span>
                <span class="label"><?= e($balanceLabel) ?></span>
            </a>
        </div>
    </div>

    <!-- 使用示例 -->
    <div class="card-v3" style="margin-bottom:18px;">
        <div class="example-block">
            <details>
                <summary>📖 查看使用示例</summary>
                <pre><code># 生成图片
curl -X POST <?= e($siteUrl) ?>/api/generate \
  -H "Authorization: Bearer 你的令牌" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"一只猫","size":"1024x1024"}'

# 查询生成结果
curl <?= e($siteUrl) ?>/api/check?id=123 \
  -H "Authorization: Bearer 你的令牌"

# 查询积分
curl <?= e($siteUrl) ?>/api/credits \
  -H "Authorization: Bearer 你的令牌"</code></pre>
            </details>
        </div>
    </div>

    <!-- 创建令牌 -->
    <section class="card-v3" style="margin-bottom:18px;">
        <div class="card-v3-head">
            <div>
                <h3>创建新令牌</h3>
                <p class="sub">New Token</p>
            </div>
        </div>
        <div class="card-v3-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="api_token_action" value="create">

                <div class="field-v3">
                    <label>令牌名称</label>
                    <input name="token_name" type="text" placeholder="例如：我的博客对接、自动化脚本" maxlength="50" required>
                </div>

                <div class="field-v3">
                    <label>权限选择</label>
                    <div class="perm-chips">
                        <?php foreach ($allPerms as $key => $label): ?>
                        <label class="perm-chip">
                            <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" checked>
                            <span class="dot"></span>
                            <span><?= e($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field-v3">
                    <label>过期时间</label>
                    <select name="expire_days" style="max-width:200px;">
                        <option value="0">永不过期</option>
                        <option value="30">30 天</option>
                        <option value="90" selected>90 天</option>
                        <option value="180">180 天</option>
                        <option value="365">1 年</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">🔑 生成令牌</button>
            </form>
        </div>
    </section>

    <!-- 令牌列表 -->
    <section class="card-v3">
        <div class="card-v3-head">
            <div>
                <h3>已有令牌</h3>
                <p class="sub">共 <?= $activeTokens ?> 个启用</p>
            </div>
        </div>
        <div class="card-v3-body" style="padding:0;">
            <?php if (empty($tokens)): ?>
                <div style="text-align:center;padding:48px 20px;">
                    <div style="font-size:40px;margin-bottom:12px;">🔑</div>
                    <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:4px;">暂无 API 令牌</div>
                    <div style="font-size:13px;color:var(--text-muted);">在上方创建你的第一个令牌，开始 API 对接</div>
                </div>
            <?php else: ?>
                <?php foreach ($tokens as $t):
                    $perms = json_decode((string) ($t['permissions'] ?? '[]'), true) ?: [];
                    $permLabels = $perms ? array_map(fn($p) => $allPerms[$p] ?? $p, $perms) : ['无权限'];
                ?>
                <div class="token-item">
                    <div style="min-width:0;flex:1;">
                        <div class="token-name">
                            <?= e($t['name']) ?>
                            <?php if ($t['is_revoked']): ?>
                                <span class="status-badge failed">已撤销</span>
                            <?php else: ?>
                                <span class="status-badge succeeded">启用</span>
                            <?php endif; ?>
                        </div>
                        <div class="token-meta">
                            <span>权限：<?= implode(' · ', $permLabels) ?></span>
                            <span>最后使用：<?= $t['last_used_at'] ? e(substr($t['last_used_at'], 0, 16)) : '从未' ?></span>
                            <span>创建：<?= e(substr($t['created_at'], 0, 16)) ?></span>
                            <?php if ($t['expires_at']): ?>
                                <span style="<?= strtotime($t['expires_at']) < time() ? 'color:#ef4444;' : '' ?>">过期：<?= e(substr($t['expires_at'], 0, 16)) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$t['is_revoked']): ?>
                        <form method="post" style="margin:0;flex-shrink:0;" onsubmit="return confirm('确认撤销「<?= e($t['name']) ?>」？撤销后使用该令牌的请求将立即失效。')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="api_token_action" value="revoke">
                            <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">撤销</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- 令牌创建成功提示 -->
    <?php if (!empty($_SESSION['api_token_created'])): ?>
        <div class="token-reveal">
            <strong>✅ 令牌已创建！请立即复制并妥善保存，关闭页面后将无法再次查看。</strong>
            <div class="raw" id="tokenRaw"><?= e((string) ($_SESSION['api_token_raw'] ?? '')) ?></div>
            <button type="button" class="copy-btn" id="copyTokenBtn" onclick="copyToken()">📋 复制令牌</button>
            <span id="copyStatus" style="margin-left:10px;font-size:12px;color:#059669;font-weight:600;display:none;">已复制！</span>
        </div>
        <?php unset($_SESSION['api_token_created'], $_SESSION['api_token_raw']); ?>
    <?php endif; ?>
</main>

<script>
function copyToken() {
    var raw = document.getElementById('tokenRaw').textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(raw).then(function() {
            var btn = document.getElementById('copyTokenBtn');
            var status = document.getElementById('copyStatus');
            btn.textContent = '✅ 已复制';
            btn.classList.add('copied');
            status.style.display = 'inline';
        });
    } else {
        // fallback
        var ta = document.createElement('textarea');
        ta.value = raw;
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var btn = document.getElementById('copyTokenBtn');
        btn.textContent = '✅ 已复制';
        btn.classList.add('copied');
    }
}
</script>
<?php render_footer(); ?>
