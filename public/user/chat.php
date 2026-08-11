<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login_optional();
$isGuest = !$user;
if ($isGuest) {
    $user = ["id" => 0, "username" => "访客", "role" => "user", "credits" => 0, "watermark_points" => 0, "is_active" => 1];
}
ensure_chat_conversations_table();
ensure_chat_records_conversation_column();

$chatModels = active_chat_ai_models();
$chatCost = 1;
$balanceLabel = balance_label();

$stmt = db()->prepare(
    'SELECT c.id, c.title, c.message_count, c.updated_at
     FROM chat_conversations c
     WHERE c.user_id = ?
     ORDER BY c.updated_at DESC LIMIT 50'
);
$stmt->execute([(int) $user['id']]);
$conversations = $stmt->fetchAll();

render_header('AI 对话', 'chat');
?>
<style>
/* ── Chat polish — extends app.css without breaking existing rules ── */

/* sidebar empty state */
.chat-sidebar-empty { display:flex;flex-direction:column;align-items:center;gap:6px;padding:40px 16px;color:var(--sidebar-text);text-align:center; }
.chat-sidebar-empty svg { opacity:.3;margin-bottom:4px; }
.chat-sidebar-empty p { margin:0;font-weight:700;font-size:14px; }
.chat-sidebar-empty span { font-size:12px;opacity:.7; }

/* conversation item icon */
.chat-conv-icon { display:flex;align-items:flex-start;padding-top:2px;flex-shrink:0;color:var(--sidebar-text);opacity:.5;transition:opacity .15s; }
.chat-conv-item:hover .chat-conv-icon,
.chat-conv-item.active .chat-conv-icon { opacity:1;color:var(--sidebar-accent); }

/* welcome screen */
.chat-welcome { position:relative;padding:clamp(40px,6vw,80px) 20px;text-align:center; }
.chat-welcome-glow { position:absolute;top:30%;left:50%;transform:translate(-50%,-50%);width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(59,130,246,.07) 0%,transparent 70%);pointer-events:none; }
.chat-welcome-suggestions { display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:28px;max-width:500px;margin-left:auto;margin-right:auto; }
.chat-suggestion { padding:9px 18px;font-size:13px;font-weight:600;border:1px solid var(--line);border-radius:24px;background:var(--main-surface);color:var(--text-soft);cursor:pointer;transition:all .18s;white-space:nowrap; }
.chat-suggestion:hover { border-color:var(--primary);color:var(--primary);background:var(--primary-glow);transform:translateY(-1px);box-shadow:0 2px 8px rgba(59,130,246,.1); }

/* model pills in topbar */
.chat-model-pills { display:flex;gap:4px;flex-wrap:wrap; }
.chat-model-pill { padding:5px 14px;font-size:12px;font-weight:700;border:1px solid var(--line);border-radius:20px;background:var(--main-surface);color:var(--text-muted);cursor:pointer;transition:all .15s; }
.chat-model-pill:hover { border-color:var(--primary); }
.chat-model-pill.active { background:var(--primary);color:#fff;border-color:var(--primary); }

/* message text line-height */
.chat-msg-text { line-height:1.72; }

/* typing dot spacing fix */
.chat-typing { display:flex;gap:5px;align-items:center;padding:6px 0; }
.chat-typing span { width:7px;height:7px;border-radius:50%;background:var(--primary);animation:typeBounce 1.4s ease-in-out infinite both; }
.chat-typing span:nth-child(1) { animation-delay:-.32s; }
.chat-typing span:nth-child(2) { animation-delay:-.16s; }
@keyframes typeBounce { 0%,80%,100%{transform:scale(.6);opacity:.4} 40%{transform:scale(1);opacity:1} }

/* cost tag */
.chat-cost-tag { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:14px;background:var(--primary-soft);color:var(--primary-dark);font-size:12px;font-weight:700;white-space:nowrap; }

/* topbar dot indicator */
.chat-topbar-dot { width:7px;height:7px;border-radius:50%;background:#10b981;flex-shrink:0;box-shadow:0 0 6px rgba(16,185,129,.4); }

/* sidebar footer — balance card */
.chat-sidebar-footer { padding:12px;border-top:1px solid var(--sidebar-border); }
.chat-sidebar-footer a { display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.15);text-decoration:none;transition:background .15s; }
.chat-sidebar-footer a:hover { background:rgba(59,130,246,.14); }
.chat-sidebar-footer strong { font-size:15px;font-weight:800;color:var(--sidebar-text-hover); }
.chat-sidebar-footer span { font-size:11px;color:var(--sidebar-text); }
.chat-sidebar-footer svg { color:var(--sidebar-text);opacity:.5;flex-shrink:0; }

/* send button pulse when sending */
.chat-send-btn.sending { animation:sendPulse 1.5s infinite; }
@keyframes sendPulse { 0%,100%{opacity:1} 50%{opacity:.55} }

/* enhanced input — extend app.css, don't override */
.chat-input-wrap { border-radius:18px; border-color:var(--line); }
.chat-input-wrap:focus-within { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-glow),0 2px 16px rgba(59,130,246,.06); }
.chat-input { font-size:15px; }
.chat-send-btn { border-radius:12px; transition:all .2s; }
.chat-send-btn:hover:not(:disabled) { background:var(--primary-dark); transform:scale(1.06); }

/* input hint bar */
.chat-foot-hint-row { text-align:center;margin-top:8px;font-size:11px;color:var(--text-muted);opacity:.5; }

/* model pills — polished */
.chat-model-pill {
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 600;
    border: 1.5px solid var(--line);
    border-radius: 20px;
    background: var(--main-surface);
    color: var(--text-muted);
    cursor: pointer;
    transition: all .18s;
    letter-spacing: .01em;
}
.chat-model-pill:hover {
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: 0 1px 6px rgba(59,130,246,.08);
}
.chat-model-pill.active {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    border-color: transparent;
    box-shadow: 0 2px 8px rgba(59,130,246,.25);
}

/* suggestion cards — upgraded */
.chat-suggestion {
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid var(--line);
    border-radius: 24px;
    background: var(--main-surface);
    color: var(--text-soft);
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
    letter-spacing: .01em;
}
.chat-suggestion:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: rgba(59,130,246,.04);
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(59,130,246,.12);
}

/* topbar polish */
.chat-topbar { background:rgba(255,255,255,.85); }
.chat-topbar-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #10b981;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(16,185,129,.15);
    animation: dotPulse 2s ease-in-out infinite;
}
@keyframes dotPulse { 0%,100%{box-shadow:0 0 0 2px rgba(16,185,129,.15)} 50%{box-shadow:0 0 0 5px rgba(16,185,129,.06)} }

/* mobile chat sidebar toggle */
.chat-sidebar-toggle { display:none;width:36px;height:36px;align-items:center;justify-content:center;border:none;background:transparent;cursor:pointer;color:var(--text);border-radius:8px;flex-shrink:0; }
.chat-sidebar-toggle svg { width:20px;height:20px; }
.chat-sidebar-toggle:hover { background:var(--main-surface-soft); }
.chat-sidebar-overlay { display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.4); }

@media (max-width:768px) {
  .chat-sidebar-toggle { display:flex; }
  .chat-sidebar-overlay.is-visible { display:block; }
  /* 移动端使用 dvh 避免键盘弹出时视口跳动 */
  .chat-layout { height: 100dvh; }
  .chat-main { min-height: 0; }
  .chat-msgs { overscroll-behavior: contain; }
  .chat-foot { flex-shrink: 0; }
  /* 防止 textarea 高度变化引起视口抖动 */
  .chat-input-wrap { min-height: 50px; }
  .chat-input { min-height: 44px; }
  /* 移动端隐藏脚注提示 */
  .chat-foot-hint-row { display: none; }
}

/* conversation delete button */
.chat-conv-item { position:relative; }
.chat-conv-delete {
  position:absolute;
  right:8px; top:50%;
  transform:translateY(-50%);
  width:28px; height:28px;
  display:flex; align-items:center; justify-content:center;
  border:none; border-radius:6px;
  background:transparent;
  color:var(--sidebar-text);
  opacity:0;
  font-size:13px;
  cursor:pointer;
  transition:opacity .15s, background .15s;
}
.chat-conv-item:hover .chat-conv-delete { opacity:.6; }
.chat-conv-delete:hover { opacity:1 !important; background:rgba(239,68,68,.15); color:#ef4444; }

/* ── 流式输出闪烁光标 ── */
.stream-cursor {
    display: inline-block;
    width: 2px;
    height: 1.1em;
    margin-left: 1px;
    background: var(--primary);
    vertical-align: text-bottom;
    animation: cursorBlink .8s steps(1) infinite;
}
@keyframes cursorBlink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}
.chat-msg-stream .chat-msg-text { white-space: pre-wrap; }
</style>

<main class="chat-layout">
    <!-- ═══════════════ Sidebar ═══════════════ -->
    <aside class="chat-sidebar">
        <div class="chat-sidebar-top">
            <div class="chat-sidebar-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span class="chat-sidebar-title">对话</span>
            </div>
            <button id="newChatBtn" class="chat-new-btn" title="新建对话">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
        </div>
        <div class="chat-sidebar-list" id="conversationList">
            <?php if (empty($conversations)): ?>
                <div class="chat-sidebar-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <p>暂无对话</p>
                    <span>点击 + 开始新对话</span>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <div class="chat-conv-item" data-id="<?= (int) $conv['id'] ?>" onclick="switchConversation(<?= (int) $conv['id'] ?>)">
                        <div class="chat-conv-title"><?= e($conv['title'] ?: '新对话') ?></div>
                        <div class="chat-conv-meta">
                            <span><?= e($conv['updated_at']) ?></span>
                            <span><?= (int) $conv['message_count'] ?> 条</span>
                        </div>
                        <button class="chat-conv-delete" data-id="<?= (int) $conv['id'] ?>" title="删除对话" onclick="event.stopPropagation();deleteConversation(<?= (int) $conv['id'] ?>)">🗑</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="chat-sidebar-footer">
            <a href="/user/shop">
                <span style="font-size:18px;">💰</span>
                <span style="flex:1;"><strong data-balance-display><?= number_format((int) $user['credits']) ?></strong><br><span><?= e($balanceLabel) ?></span></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </aside>

    <!-- ═══════════════ Main Chat Area ═══════════════ -->
    <section class="chat-main">
        <div class="chat-sidebar-overlay" id="chatSidebarOverlay"></div>
        <div class="chat-topbar">
            <button class="chat-sidebar-toggle" id="chatSidebarToggle" aria-label="对话列表">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="13" y2="18"/></svg>
            </button>
            <div class="chat-topbar-left">
                <span class="chat-topbar-dot"></span>
                <span class="chat-topbar-title" id="chatTitle">新对话</span>
            </div>
            <div class="chat-topbar-right">
                <?php if ($chatModels): ?>
                <div class="chat-model-pills" id="chatModelPills">
                    <?php $first = true; foreach ($chatModels as $m): ?>
                    <button type="button" class="chat-model-pill<?= $first ? ' active' : '' ?>" data-id="<?= (int) $m['id'] ?>" data-credits="<?= (int)($m['credits'] ?? 0) ?>"><?= e($m['name']) ?></button>
                    <?php $first = false; endforeach; ?>
                </div>
                <span class="chat-cost-tag" id="chatCostTag"><?= $chatCost ?> <?= e($balanceLabel) ?>/次</span>
                <?php endif; ?>
            </div>
        </div>

        <?= csrf_field() ?>

        <!-- Messages -->
        <div class="chat-msgs" id="chatMessages">
            <div class="chat-welcome">
                <div class="chat-welcome-glow"></div>
                <div class="chat-welcome-icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <h2 class="chat-welcome-heading">开始对话</h2>
                <p class="chat-welcome-text">向 AI 提问，支持多轮上下文理解</p>
                <div class="chat-welcome-suggestions">
                    <button class="chat-suggestion" data-prompt="帮我写一份项目计划书的大纲">📋 项目计划书大纲</button>
                    <button class="chat-suggestion" data-prompt="解释一下机器学习和深度学习的区别">🤖 ML vs DL 区别</button>
                    <button class="chat-suggestion" data-prompt="用Python写一个快速排序算法">💻 快速排序算法</button>
                    <button class="chat-suggestion" data-prompt="推荐一些提升工作效率的方法">⚡ 提升工作效率</button>
                </div>
            </div>
        </div>

        <!-- Input -->
        <div class="chat-foot">
            <div class="chat-foot-inner">
                <div class="chat-input-wrap">
                    <textarea id="chatInput" class="chat-input" rows="1" placeholder="输入你的问题…" maxlength="4000"></textarea>
                    <button id="chatSendBtn" class="chat-send-btn" title="发送 (Enter)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
                <div class="chat-foot-hint-row">
                    Enter 发送 · Shift+Enter 换行 · 支持多轮上下文对话
                </div>
            </div>
        </div>
    </section>
</main>

<script>
/* chat sidebar toggle (mobile) */
(function() {
    var toggle = document.getElementById('chatSidebarToggle');
    var sidebar = document.querySelector('.chat-sidebar');
    var overlay = document.getElementById('chatSidebarOverlay');
    if (!toggle || !sidebar) return;
    function open() { sidebar.classList.add('is-open'); if(overlay)overlay.classList.add('is-visible'); }
    function close() { sidebar.classList.remove('is-open'); if(overlay)overlay.classList.remove('is-visible'); }
    toggle.addEventListener('click', function(e) { e.stopPropagation(); sidebar.classList.contains('is-open') ? close() : open(); });
    if(overlay) overlay.addEventListener('click', close);
    sidebar.querySelectorAll('.chat-conv-item,a').forEach(function(el) { el.addEventListener('click', close); });
})();

(function() {
    var TOKEN = document.querySelector('input[name="csrf_token"]')?.value || '';
    var input = document.getElementById('chatInput');
    var btn = document.getElementById('chatSendBtn');
    var msgs = document.getElementById('chatMessages');
    var titleEl = document.getElementById('chatTitle');
    var listEl = document.getElementById('conversationList');
    var costTag = document.getElementById('chatCostTag');
    var busy = false, curId = 0, curModelId = 0;

    // model pills
    var pills = document.querySelectorAll('.chat-model-pill');
    if (pills.length) {
        curModelId = parseInt(pills[0]?.dataset.id || '0');
        var c = parseInt(pills[0]?.dataset.credits || '1');
        if (costTag) costTag.textContent = c + ' <?= e($balanceLabel) ?>/次';
        pills.forEach(function(p) {
            p.addEventListener('click', function() {
                pills.forEach(function(x) { x.classList.remove('active'); });
                p.classList.add('active');
                curModelId = parseInt(p.dataset.id);
                var cr = parseInt(p.dataset.credits || '1');
                if (costTag) costTag.textContent = cr + ' <?= e($balanceLabel) ?>/次';
            });
        });
    }

    // suggestions
    document.querySelectorAll('.chat-suggestion').forEach(function(s) {
        s.addEventListener('click', function() { input.value = s.dataset.prompt; send(); });
    });

    // auto-resize（移动端防抖：保留最小高度，避免键盘弹出时视口缩水）
    var inputMinHeight = 0;
    input.addEventListener('input', function() {
        if (!inputMinHeight) inputMinHeight = input.offsetHeight || 44;
        var newH = Math.min(Math.max(inputMinHeight, input.scrollHeight), 180);
        if (Math.abs(input.offsetHeight - newH) > 4 || input.style.height === '') {
            input.style.height = newH + 'px';
        }
    });

    var esc = function(v) { return String(v ?? '').replace(/[&<>]/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;'})[c]; }); };
    var escMD = function(t) { return esc(t).replace(/\n/g, '<br>'); };

    function addMsg(role, text) {
        var w = msgs.querySelector('.chat-welcome');
        if (w) w.remove();
        var d = document.createElement('div');
        d.className = 'chat-msg ' + (role === 'user' ? 'chat-msg-user' : 'chat-msg-ai');
        d.innerHTML = '<div class="chat-msg-avatar">' + (role === 'ai' ? 'AI' : '我') + '</div>' +
            '<div class="chat-msg-body"><div class="chat-msg-text">' + escMD(text) + '</div></div>';
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function addErr(text) {
        var d = document.createElement('div');
        d.className = 'chat-msg chat-msg-err';
        d.innerHTML = '<div class="chat-msg-body"><div class="chat-msg-text">' + esc(text) + '</div></div>';
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
    }

    var typing = null;
    function showTyping() {
        hideTyping();
        var w = msgs.querySelector('.chat-welcome');
        if (w) w.remove();
        typing = document.createElement('div');
        typing.className = 'chat-msg chat-msg-ai';
        typing.innerHTML = '<div class="chat-msg-avatar">AI</div><div class="chat-msg-body"><div class="chat-msg-text chat-typing"><span></span><span></span><span></span></div></div>';
        msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function hideTyping() { if (typing) { typing.remove(); typing = null; } }

    window.switchConversation = async function(id) {
        curId = id;
        msgs.innerHTML = '<div class="chat-welcome" style="padding:40px"><div class="chat-typing" style="justify-content:center"><span></span><span></span><span></span></div></div>';
        document.querySelectorAll('.chat-conv-item').forEach(function(e) { e.classList.remove('active'); });
        var item = document.querySelector('.chat-conv-item[data-id="' + id + '"]');
        if (item) item.classList.add('active');
        try {
            var r = await fetch('/api/chat_history?conversation_id=' + id, { headers: { 'X-Requested-With': 'fetch' } });
            var d = await r.json();
            msgs.innerHTML = '';
            if (d.ok && d.messages) {
                titleEl.textContent = d.title || '对话';
                d.messages.forEach(function(m) { addMsg(m.role, m.content); });
            } else {
                msgs.innerHTML = '<div class="chat-welcome"><h2 class="chat-welcome-heading">加载失败</h2></div>';
            }
        } catch(e) {
            msgs.innerHTML = '<div class="chat-welcome"><h2 class="chat-welcome-heading">网络错误</h2></div>';
        }
    };

    document.getElementById('newChatBtn').addEventListener('click', function() {
        curId = 0;
        msgs.innerHTML = '<div class="chat-welcome">' +
            '<div class="chat-welcome-glow"></div>' +
            '<div class="chat-welcome-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>' +
            '<h2 class="chat-welcome-heading">开始对话</h2>' +
            '<p class="chat-welcome-text">向 AI 提问，支持多轮上下文理解</p>' +
            '<div class="chat-welcome-suggestions">' +
            '<button class="chat-suggestion" data-prompt="帮我写一份项目计划书的大纲">📋 项目计划书大纲</button>' +
            '<button class="chat-suggestion" data-prompt="解释一下机器学习和深度学习的区别">🤖 ML vs DL 区别</button>' +
            '<button class="chat-suggestion" data-prompt="用Python写一个快速排序算法">💻 快速排序算法</button>' +
            '<button class="chat-suggestion" data-prompt="推荐一些提升工作效率的方法">⚡ 提升工作效率</button>' +
            '</div></div>';
        titleEl.textContent = '新对话';
        document.querySelectorAll('.chat-conv-item').forEach(function(e) { e.classList.remove('active'); });
        document.querySelectorAll('.chat-suggestion').forEach(function(s) {
            s.addEventListener('click', function() { input.value = s.dataset.prompt; send(); });
        });
    });

    // ── 流式对话核心 ──
    // 创建可增量追加的 AI 消息气泡
    function createStreamMsg() {
        hideTyping();
        var w = msgs.querySelector('.chat-welcome');
        if (w) w.remove();
        var d = document.createElement('div');
        d.className = 'chat-msg chat-msg-ai chat-msg-stream';
        d.innerHTML = '<div class="chat-msg-avatar">AI</div>' +
            '<div class="chat-msg-body"><div class="chat-msg-text"><span class="stream-cursor"></span></div></div>';
        msgs.appendChild(d);
        return d.querySelector('.chat-msg-text');
    }

    function appendToStream(el, text) {
        var cursor = el.querySelector('.stream-cursor');
        if (cursor) cursor.remove();
        el.appendChild(document.createTextNode(text));
        el.appendChild(cursorSpan());
        msgs.scrollTop = msgs.scrollHeight;
    }

    function cursorSpan() {
        var s = document.createElement('span');
        s.className = 'stream-cursor';
        return s;
    }

    function finalizeStream(el) {
        var cursor = el.querySelector('.stream-cursor');
        if (cursor) cursor.remove();
        el.parentElement.parentElement.classList.remove('chat-msg-stream');
    }

    async function send() {
        var text = input.value.trim();
        if (!text || busy) return;
        busy = true;
        btn.disabled = true;
        btn.classList.add('sending');
        addMsg('user', text);
        input.value = '';
        // 不重置 height 以避免移动端键盘/视口抖动；保持 CSS 默认高度
        input.style.height = '';

        // 先显示 typing dots，收到第一个 delta 时替换为流式气泡
        showTyping();
        var streamEl = null;

        try {
            var r = await fetch('/api/chat_stream', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
                body: JSON.stringify({ prompt: text, model_id: curModelId, conversation_id: curId, csrf_token: TOKEN })
            });

            if (!r.ok) {
                hideTyping();
                var errTxt = '';
                try { errTxt = await r.text(); } catch(e) {}
                addErr('请求失败 (HTTP ' + r.status + ')：' + errTxt.substring(0, 200));
                busy = false; btn.disabled = false; btn.classList.remove('sending');
                return;
            }

            var reader = r.body.getReader();
            var decoder = new TextDecoder();
            var lineBuf = '';

            while (true) {
                var part = await reader.read();
                if (part.done) break;

                lineBuf += decoder.decode(part.value, { stream: true });

                // 按行拆解 SSE
                var lines = lineBuf.split('\n');
                // 最后一行可能不完整，保留到下次
                lineBuf = lines.pop();

                var eventType = '';
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].trim();
                    if (line === '') { eventType = ''; continue; }

                    if (line.indexOf('event:') === 0) {
                        eventType = line.substring(6).trim();
                        continue;
                    }

                    if (line.indexOf('data:') !== 0) continue;
                    var jsonStr = line.substring(5).trim();
                    if (!jsonStr) continue;

                    var data;
                    try { data = JSON.parse(jsonStr); } catch(e) { continue; }

                    if (eventType === 'error' || data.ok === false) {
                        hideTyping();
                        if (streamEl) { finalizeStream(streamEl); streamEl = null; }
                        addErr(data.message || '对话失败');
                        busy = false; btn.disabled = false; btn.classList.remove('sending');
                        reader.cancel();
                        return;
                    }

                    if (eventType === 'meta') {
                        // 会话元信息
                        if (data.conversation_id && data.conversation_id !== curId) {
                            curId = data.conversation_id;
                            titleEl.textContent = data.title || '对话';
                            refreshList();
                        }
                        var bals = document.querySelectorAll('[data-balance-display]');
                        if (data.credits !== undefined) bals.forEach(function(b) { b.textContent = data.credits; });
                        continue;
                    }

                    if (eventType === 'done') {
                        if (streamEl) finalizeStream(streamEl);
                        var bals2 = document.querySelectorAll('[data-balance-display]');
                        if (data.credits !== undefined) bals2.forEach(function(b) { b.textContent = data.credits; });
                        if (data.conversation_id && data.conversation_id !== curId) {
                            curId = data.conversation_id;
                            titleEl.textContent = data.title || '对话';
                            refreshList();
                        }
                        busy = false; btn.disabled = false; btn.classList.remove('sending');
                        return;
                    }

                    // 默认：delta 事件（无 event 字段的 data 行即视为 delta）
                    if (data.delta) {
                        if (!streamEl) streamEl = createStreamMsg();
                        appendToStream(streamEl, data.delta);
                    }
                }
            }

            // 流意外结束
            if (streamEl) finalizeStream(streamEl);
        } catch(e) {
            hideTyping();
            if (streamEl) finalizeStream(streamEl);
            addErr('网络错误：' + (e.message || '请检查网络连接'));
            console.error(e);
        }
        busy = false;
        btn.disabled = false;
        btn.classList.remove('sending');
    }

    async function refreshList() {
        try {
            var r = await fetch('/api/chat_conversations', { headers: { 'X-Requested-With': 'fetch' } });
            var d = await r.json();
            if (d.ok && d.conversations) {
                listEl.innerHTML = d.conversations.map(function(c) {
                    return '<div class="chat-conv-item' + (c.id === curId ? ' active' : '') + '" data-id="' + c.id + '" onclick="switchConversation(' + c.id + ')">' +
                        '<div class="chat-conv-title">' + esc(c.title || '新对话') + '</div>' +
                        '<div class="chat-conv-meta"><span>' + c.updated_at + '</span><span>' + c.message_count + ' 条</span></div>' +
                        '<button class="chat-conv-delete" data-id="' + c.id + '" title="删除对话" onclick="event.stopPropagation();deleteConversation(' + c.id + ')">🗑</button>' +
                        '</div>';
                }).join('') || '<div class="chat-sidebar-empty"><p>暂无对话记录</p></div>';
            }
        } catch(e) {}
    }

    window.deleteConversation = async function(id) {
        if (!confirm('确认删除此对话？对话记录将一并删除且不可恢复。')) return;
        try {
            var r = await fetch('/api/chat_conversations?id=' + id + '&_method=DELETE', {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' }
            });
            var d = await r.json();
            if (d.ok) {
                // 如果删除的是当前对话，回到新对话状态
                if (curId === id) {
                    document.getElementById('newChatBtn').click();
                }
                refreshList();
            } else {
                alert(d.message || '删除失败');
            }
        } catch(e) {
            alert('网络错误，请重试');
        }
    };

    btn.addEventListener('click', send);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
})();
</script>
<?php if ($isGuest): ?>
<div id="authModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeAuthModal()"><div class="modal-card" style="background:var(--main-surface);border-radius:16px;width:90vw;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.35);"><div style="padding:24px 24px 0;display:flex;justify-content:space-between;align-items:center;"><h3 style="margin:0;">登录 / 注册</h3><button onclick="closeAuthModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);">&times;</button></div><div style="padding:20px 24px 24px;"><p style="font-size:14px;color:var(--text-muted);margin-bottom:20px;">请登录后使用完整功能。</p><a href="/login" class="btn btn-primary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;margin-bottom:10px;">🔑 登录</a><a href="/login?register=1" class="btn btn-secondary" style="display:block;text-align:center;padding:12px;border-radius:12px;text-decoration:none;">📝 注册</a></div></div></div>
<script>function showAuthModal(){document.getElementById("authModal").style.display="flex"}function closeAuthModal(){document.getElementById("authModal").style.display="none"}
document.addEventListener("click",function(e){var btn=e.target.closest("button, [role=button], a.btn, .media-card");if(!btn)return;if(btn.closest("#authModal")||btn.closest("nav")||btn.closest(".admin-nav-bar"))return;if(btn.closest("[data-close-dialog]"))return;e.preventDefault();e.stopPropagation();showAuthModal()},true)</script>
<?php endif; ?>
<?php render_footer(); ?>
