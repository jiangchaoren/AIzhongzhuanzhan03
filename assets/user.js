const form = document.querySelector("#generateForm");
const button = document.querySelector("#generateButton");
const message = document.querySelector("#generateMessage");
const historyList = document.querySelector("#historyList");
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";
const modeInputs = document.querySelectorAll('input[name="mode"]');
const editUpload = document.querySelector("[data-edit-upload]");
const editImagesInput = document.querySelector('input[name="edit_images[]"]');
const editPreview = document.querySelector("[data-edit-preview]");
const editUploadHint = document.querySelector("[data-edit-upload-hint]");

let isGenerating = false;
let editSelectedFiles = [];

const showMessage = (text, type = "success") => {
  try { window.showToast?.(text, type); } catch (_) {}
  if (message) { message.textContent = ""; message.className = "inline-message hidden"; }
};

const setGenerating = (enabled) => {
  isGenerating = enabled;
  if (button) {
    button.disabled = enabled;
    button.textContent = enabled ? "生成中..." : "生成图片";
  }
};

const escapeHtml = (value) =>
  String(value ?? "").replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c]);

const statusText = (status) =>
  ({ queued: "排队中", running: "生成中", succeeded: "已完成", failed: "失败", deleted: "已删除" }[status] || status || "-");

const updateCredits = (credits) => {
  if (credits === undefined || credits === null) return;
  document.querySelectorAll("[data-balance-display]").forEach((node) => {
    node.textContent = Number(credits).toLocaleString();
  });
};

const createRecordCard = (record) => {
  const article = document.createElement("article");
  article.className = "media-card";
  article.tabIndex = 0;
  article.dataset.recordId = record.id || "";
  article.dataset.status = record.status || "succeeded";
  article.dataset.model = record.model || "";
  article.dataset.mode = record.mode || "draw";
  article.dataset.prompt = record.prompt || "";
  article.dataset.size = record.size || "auto";
  article.dataset.resolutionLevel = record.resolution_level || "1K";
  article.dataset.quality = record.quality || "auto";
  article.dataset.format = record.format || "png";
  article.dataset.credits = record.credits_charged || 0;
  article.dataset.created = record.created_at || "";
  article.dataset.finished = record.finished_at || "-";
  article.dataset.error = record.error_message || "";
  article.dataset.inputCount = record.input_image_count || 0;
  article.style.cursor = "pointer";

  const label = record.mode === "edit" ? "编辑" : "绘画";
  const src = record.image_src || record.video_src;

  article.innerHTML = `
    ${record.video_src
      ? `<video src="${escapeHtml(record.video_src)}" controls></video>`
      : record.image_src
        ? `<img src="${escapeHtml(record.image_src)}" alt="生成图片">`
        : `<div style="width:100%;aspect-ratio:1;display:grid;place-items:center;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;font-size:13px;"><span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span></div>`
    }
    <div class="media-card-body">
      <div class="prompt">${escapeHtml(record.prompt)}</div>
      <div class="meta">
        <span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span>
        <span>${escapeHtml(label)} / ${escapeHtml(record.size)}</span>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
        <time style="font-size:10px;color:var(--text-muted);">${escapeHtml(record.created_at)}</time>
        <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="record_id" value="${record.id}">
          <input type="hidden" name="redirect_to" value="${escapeHtml(window.location.pathname + window.location.search)}">
          <button type="submit" class="record-delete"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5.3 4V2.7c0-.4.3-.7.7-.7h4c.4 0 .7.3.7.7V4M6.7 7.3v4M9.3 7.3v4M3.3 4l.8 8.6c.1.7.7 1.4 1.5 1.4h4.7c.7 0 1.4-.6 1.5-1.4l.8-8.6"/></svg>删除</button>
        </form>
      </div>
    </div>
  `;
  return article;
};

const prependRecordCard = (record) => {
  if (!historyList) return;
  const existing = historyList.querySelector(`[data-record-id="${record.id}"]`);
  if (existing) existing.remove();
  const empty = historyList.querySelector(".history-empty-inline");
  if (empty) empty.remove();
  const card = createRecordCard(record);
  historyList.prepend(card);
};

const syncEditInputFiles = () => {
  if (!editImagesInput || !editPreview) return;
  const dt = new DataTransfer();
  editSelectedFiles.forEach((f) => dt.items.add(f));
  editImagesInput.files = dt.files;
};

const updateEditPreview = () => {
  if (!editPreview) return;
  editPreview.innerHTML = "";
  const max = parseInt(editImagesInput?.dataset.maxFiles || "4", 10);
  editSelectedFiles.slice(0, max).forEach((file, i) => {
    const div = document.createElement("div");
    div.className = "edit-preview-item";
    const img = document.createElement("img");
    img.src = URL.createObjectURL(file);
    img.alt = `参考图片 ${i + 1}`;
    img.addEventListener("load", () => URL.revokeObjectURL(img.src), { once: true });
    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "edit-preview-remove";
    removeBtn.textContent = "删除";
    removeBtn.addEventListener("click", () => {
      editSelectedFiles.splice(i, 1);
      updateEditPreview();
      syncEditInputFiles();
      updateEditUploadHint();
    });
    div.appendChild(img);
    div.appendChild(removeBtn);
    editPreview.appendChild(div);
  });
  updateEditUploadHint();
};

const updateEditUploadHint = () => {
  if (!editUploadHint) return;
  const max = parseInt(editImagesInput?.dataset.maxFiles || "4", 10);
  editUploadHint.textContent = editSelectedFiles.length > 0
    ? `已选择 ${editSelectedFiles.length} / ${max} 张，可继续添加`
    : "支持 PNG / JPG / WEBP，可多次选择";
};

const syncModeFields = () => {
  const selectedInput = document.querySelector('input[name="mode"]:checked');
  const selected = selectedInput?.value || "draw";
  const isEdit = selected === "edit";
  if (editUpload) editUpload.classList.toggle("hidden", !isEdit);

  // 仅图片生成页才有 draw/edit 分档价格；视频页也存在 data-cost-display，不能被这里覆盖成 1。
  const costDisplay = document.querySelector("[data-cost-display]");
  if (costDisplay && selectedInput && (costDisplay.dataset.drawCost !== undefined || costDisplay.dataset.editCost !== undefined)) {
    const drawCost = costDisplay.dataset.drawCost || "1";
    const editCost = costDisplay.dataset.editCost || "2";
    costDisplay.querySelector("[data-cost-value]").textContent = isEdit ? editCost : drawCost;
    const editSpan = costDisplay.querySelector("[data-cost-edit]");
    if (editSpan) editSpan.classList.toggle("hidden", !isEdit);
  }
};

// Mode toggle
modeInputs.forEach((input) => {
  input.addEventListener("change", () => {
    syncModeFields();
    if (editPreview) { editSelectedFiles = []; editPreview.innerHTML = ""; editImagesInput.value = ""; updateEditUploadHint(); }
  });
});
syncModeFields();

// Edit image upload
if (editImagesInput) {
  // 点击上传区域 → 直接程序化触发文件选择器（绕过所有 CSS 层叠问题）
  const uploadBox = document.querySelector("[data-edit-upload-box]");
  if (uploadBox) {
    uploadBox.addEventListener("click", (e) => {
      // 如果点击的是移除按钮或预览区域，不触发文件选择
      if (e.target.closest("[data-edit-remove]")) return;
      editImagesInput.click();
    });
  }

  editImagesInput.addEventListener("change", () => {
    const max = parseInt(editImagesInput.dataset.maxFiles || "4", 10);
    const newFiles = Array.from(editImagesInput.files || []);
    editSelectedFiles = [...editSelectedFiles, ...newFiles].slice(0, max);
    syncEditInputFiles();
    updateEditPreview();
    updateEditUploadHint();
  });
}

// AI模型选择 → 联动消耗显示 & 分辨率选项
const modelSelect = document.getElementById("ai_model");
if (modelSelect) {
  modelSelect.addEventListener("change", () => {
    updateResolutionOptions();
    updateCostFromResolution();
    updateWatermarkCost();
    updateSizeOptions();
    updateMembershipQuotaHint();
  });
  // 初始触发
  modelSelect.dispatchEvent(new Event("change"));
}

// 根据当前模型+分辨率更新消耗显示
function updateCostFromResolution() {
  const model = getCurrentModel();
  const resChip = document.querySelector("[data-resolution-selector] [data-res].active");
  const level = resChip ? resChip.getAttribute("data-res") : "1K";
  const costVal = document.querySelector("[data-cost-value]");
  if (!costVal) return;

  // 优先用分档价，没有则用通用 credits
  var price = 0;
  if (level === "2K") price = model.price_2k || 0;
  else if (level === "4K") price = model.price_4k || 0;
  else price = model.price_1k || 0;
  if (!price) price = model.credits || 0;
  if (!price) price = 1;

  costVal.textContent = price;
}

// 更新去水印消耗显示
function updateWatermarkCost() {
  var costDisplay = document.querySelector("[data-wp-cost-display]");
  var balanceDisplay = document.querySelector("[data-wp-balance]");
  var wpDisplay = document.querySelector("[data-wp-display]");
  var toggleLabel = document.querySelector("[data-wp-toggle-label]");
  var check = document.querySelector("[data-anti-watermark-check]");
  var field = document.querySelector("[data-anti-watermark-toggle]");
  if (!field) return;
  var model = getCurrentModel();
  var wpCost = model.watermark_point_cost || 0;

  // 更新余额文字
  var wp = window._wpBalance;
  if (wp === undefined) {
    wp = parseInt(balanceDisplay ? balanceDisplay.textContent.replace(/\D/g, '') : '0') || 0;
    window._wpBalance = wp;
  }

  // 更新 toggle 标签文字
  if (toggleLabel && costDisplay) {
    costDisplay.textContent = wpCost === 0 ? '0（免费）' : wpCost;
    if (check && check.checked) {
      toggleLabel.innerHTML = '已开启去水印：<strong data-wp-cost-display style="color:inherit;">' + costDisplay.textContent + '</strong> 水印点';
      costDisplay = toggleLabel.querySelector('[data-wp-cost-display]');
    } else {
      toggleLabel.innerHTML = '关闭去水印：节省 <strong data-wp-cost-display style="color:inherit;">' + costDisplay.textContent + '</strong> 水印点';
      costDisplay = toggleLabel.querySelector('[data-wp-cost-display]');
    }
  }

  // 余额文字和颜色
  if (balanceDisplay) {
    balanceDisplay.textContent = '当前水印点：' + wp;
    if (wpCost > 0 && check && check.checked && wp < wpCost) {
      balanceDisplay.style.color = 'var(--danger, #e74c3c)';
      if (toggleLabel) toggleLabel.style.color = 'var(--danger, #e74c3c)';
    } else {
      balanceDisplay.style.color = '';
      if (toggleLabel) toggleLabel.style.color = '';
    }
  }

  // 更新头部水印点徽章
  if (wpDisplay) {
    wpDisplay.textContent = Number(wp).toLocaleString();
  }
}

function getCurrentModel() {
  const imgModels = getImageModelsData();
  const vModelSelect = document.getElementById("ai_model_id");
  
  // 视频模型（视频页专用）
  if (vModelSelect && !imgModels.length) {
    try {
      const vModels = JSON.parse(vModelSelect.dataset.videoModels || "[]");
      const vOpt = vModelSelect.selectedOptions[0];
      const vId = vOpt ? parseInt(vOpt.value) : 0;
      return vModels.find(function(m) { return m.id === vId; }) || {};
    } catch (e) { return {}; }
  }
  
  const opt = modelSelect?.selectedOptions[0];
  const modelId = opt ? parseInt(opt.value) : 0;
  return imgModels.find(function(m) { return m.id === modelId; }) || {};
}

// ── 会员额度提示 ──
function updateMembershipQuotaHint(decrement) {
  var hint = document.getElementById("membershipQuotaHint");
  if (!hint) return;

  var opt = modelSelect?.selectedOptions[0];
  var modelId = opt ? parseInt(opt.value) : 0;
  if (!modelId) {
    hint.style.display = "none";
    return;
  }

  // 读取会员配额数据
  var quotasData = {};
  try {
    quotasData = JSON.parse(modelSelect.dataset.membershipQuotas || "{}");
  } catch (e) {}

  var quota = quotasData[String(modelId)];
  if (!quota || !quota.periods || !quota.periods.length) {
    hint.style.display = "none";
    return;
  }

  // 生成后递减 used 计数（本地乐观更新）
  if (decrement && quota.used !== undefined) {
    quota.used += 1;
    quotasData[String(modelId)] = quota;
    modelSelect.dataset.membershipQuotas = JSON.stringify(quotasData);
  }

  var periods = quota.periods;
  var lines = [];
  var hasUnlimited = false;
  var minRemaining = Infinity;

  for (var i = 0; i < periods.length; i++) {
    var p = periods[i];
    if (decrement && p.remaining > 0) {
      p.remaining = Math.max(0, p.remaining - 1);
    }
    if (p.type === 'unlimited') {
      hasUnlimited = true;
    } else {
      var resetLabel = formatResetDate(p.reset_at);
      var remaining = p.remaining === -1 ? '不限' : p.remaining;
      lines.push(p.label + '会员剩余额度：' + remaining + '（' + resetLabel + '刷新）');
      if (p.remaining !== -1 && p.remaining < minRemaining) {
        minRemaining = p.remaining;
      }
    }
  }

  if (hasUnlimited && lines.length === 0) {
    // 纯无限额
    hint.innerHTML = '<span class="quota-icon">🎖️</span> 当前会员剩余额度：不限';
    hint.style.color = "#7c3aed";
    hint.style.borderColor = "rgba(139,92,246,.15)";
    hint.style.background = "linear-gradient(135deg,rgba(139,92,246,.08),rgba(59,130,246,.06))";
    hint.style.display = "";
    return;
  }

  // 颜色判定：取最紧张的周期
  if (minRemaining === 0) {
    hint.style.color = "#ef4444";
    hint.style.borderColor = "rgba(239,68,68,.3)";
    hint.style.background = "linear-gradient(135deg,rgba(239,68,68,.08),rgba(239,68,68,.04))";
  } else if (minRemaining <= 5) {
    hint.style.color = "#f59e0b";
    hint.style.borderColor = "rgba(245,158,11,.3)";
    hint.style.background = "linear-gradient(135deg,rgba(245,158,11,.08),rgba(245,158,11,.04))";
  } else {
    hint.style.color = "#7c3aed";
    hint.style.borderColor = "rgba(139,92,246,.15)";
    hint.style.background = "linear-gradient(135deg,rgba(139,92,246,.08),rgba(59,130,246,.06))";
  }

  hint.innerHTML = '<span class="quota-icon">🎖️</span> ' + lines.join('<br>');
  hint.style.display = "";
}

function formatResetDate(dateStr) {
  if (!dateStr) return '';
  var parts = dateStr.split('-');
  if (parts.length !== 3) return dateStr;
  return parts[1] + '月' + parts[2] + '日';
}

// Resolution selector — update options based on model support list
function updateResolutionOptions() {
  const container = document.querySelector("[data-resolution-selector]");
  if (!container) return;
  const model = getCurrentModel();
  const levelsStr = model.resolution_levels || "1K";
  const supported = levelsStr.split(",").map(function(s) { return s.trim(); });
  const input = document.querySelector("[data-resolution-input]");

  container.querySelectorAll("[data-res]").forEach(function(chip) {
    var res = chip.getAttribute("data-res");
    if (supported.indexOf(res) >= 0) {
      chip.classList.remove("disabled");
      chip.disabled = false;
    } else {
      chip.classList.add("disabled");
      chip.classList.remove("active");
      chip.disabled = true;
    }
  });

  // 确保只有一个 active
  var actives = container.querySelectorAll("[data-res].active");
  if (actives.length !== 1 || actives[0].classList.contains("disabled")) {
    actives.forEach(function(c) { c.classList.remove("active"); });
    var fallback = container.querySelector("[data-res=\"" + (supported[0] || "1K") + "\"]");
    if (fallback) {
      fallback.classList.add("active");
      fallback.classList.remove("disabled");
      fallback.disabled = false;
      if (input) input.value = supported[0] || "1K";
    }
  }
}

function getImageModelsData() {
  var el = document.getElementById("ai_model");
  if (!el) return [];
  try { return JSON.parse(el.dataset.imageModels || "[]"); } catch (e) { return []; }
}

(function() {
  var container = document.querySelector("[data-resolution-selector]");
  if (!container) return;
  var input = document.querySelector("[data-resolution-input]");
  container.addEventListener("click", function(e) {
    var chip = e.target.closest("[data-res]");
    if (!chip || chip.classList.contains("disabled")) return;
    var res = chip.getAttribute("data-res");
    container.querySelectorAll("[data-res]").forEach(function(c) { c.classList.remove("active"); });
    chip.classList.add("active");
    if (input) input.value = res;
    updateCostFromResolution();
    updateWatermarkCost();
    updateSizeOptions();
  });
  // 初始执行
  updateSizeOptions();
})();

// 根据分辨率级限制可选画面比例（1K 仅支持正方形）
function updateSizeOptions() {
  // gpt-image-2 原生支持任意比例（16px倍数 + 比例≤3:1 + 像素≥655K）
  // 无论选什么分辨率级，画面比例全开放
  var container = document.querySelector("[data-size-selector]");
  if (!container) return;
  container.querySelectorAll("[data-size]").forEach(function(chip) {
    chip.classList.remove("disabled");
    chip.style.pointerEvents = "";
    chip.style.opacity = "";
  });
}

// Form submit — optimistic UI：先插卡再提交，消除等待延迟
form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (isGenerating) return;

  // 提取提交参数，用于构建占位卡片
  var pendingPrompt = (form.querySelector('[name="prompt"]')?.value || "").trim();
  var pendingMode = form.querySelector('input[name="mode"]:checked')?.value || "draw";
  var pendingSize = form.querySelector('[name="size"]')?.value || "auto";
  var pendingQuality = form.querySelector('[name="quality"]')?.value || "auto";
  var pendingFormat = form.querySelector('[name="output_format"]')?.value || "png";
  var pendingModel = form.querySelector('#ai_model')?.selectedOptions?.[0]?.text || "";

  // 立即插入占位卡片，任务"瞬间"出现在图库
  var pendingCard = null;
  if (historyList) {
    var emptyHint = historyList.querySelector(".history-empty-inline");
    if (emptyHint) emptyHint.remove();
    pendingCard = document.createElement("article");
    pendingCard.className = "media-card";
    pendingCard.tabIndex = 0;
    pendingCard.dataset.status = "queued";
    pendingCard.dataset.mode = pendingMode;
    pendingCard.dataset.prompt = pendingPrompt;
    pendingCard.dataset.size = pendingSize;
    pendingCard.dataset.quality = pendingQuality;
    pendingCard.dataset.format = pendingFormat;
    pendingCard.dataset.model = pendingModel;
    pendingCard.dataset.credits = "0";
    pendingCard.dataset.created = "";
    pendingCard.style.cursor = "pointer";
    pendingCard.innerHTML = '<div style="width:100%;aspect-ratio:1;display:grid;place-items:center;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;font-size:13px;"><span class="status-badge queued">提交中…</span></div>'
      + '<div class="media-card-body"><div class="prompt">' + escapeHtml(pendingPrompt) + '</div>'
      + '<div class="meta"><span class="status-badge queued">提交中…</span><span>' + escapeHtml(pendingMode === "edit" ? "编辑" : "绘画") + ' / ' + escapeHtml(pendingSize) + '</span></div></div>';
    historyList.prepend(pendingCard);
  }

  // 先同步 editSelectedFiles 到 input.files，再构建 FormData
  if (typeof syncEditInputFiles === 'function') syncEditInputFiles();
  const hasEditFiles = editSelectedFiles.length > 0 || (editImagesInput.files && editImagesInput.files.length > 0);
  const formData = new FormData(form);
  editImagesInput.value = "";
  editSelectedFiles = [];

  setGenerating(true);
  showMessage("正在提交...", "info");

  // 去水印参数
  const antiWmCheck = document.querySelector("[data-anti-watermark-check]");
  if (antiWmCheck && antiWmCheck.checked) {
    formData.append("anti_watermark", "1");
  }

  try {
    // 使用 XHR 替代 fetch 以支持上传进度
    let responseText;
    if (hasEditFiles) {
      responseText = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/generate.php');

        // 上传进度
        xhr.upload.addEventListener('progress', (e) => {
          if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const progressEl = document.querySelector('[data-edit-progress]');
            const pctEl = document.querySelector('[data-edit-progress-pct]');
            const barEl = document.querySelector('[data-edit-progress-bar]');
            const textEl = document.querySelector('[data-edit-progress-text]');
            if (progressEl) progressEl.style.display = '';
            if (pctEl) pctEl.textContent = pct + '%';
            if (barEl) barEl.style.width = pct + '%';
            if (textEl) textEl.textContent = pct >= 100 ? '处理中...' : '上传中 ' + e.loaded + ' / ' + e.total + ' 字节';
          }
        });

        xhr.addEventListener('load', () => {
          const progressEl = document.querySelector('[data-edit-progress]');
          if (progressEl) progressEl.style.display = 'none';
          resolve(xhr.responseText);
        });
        xhr.addEventListener('error', () => reject(new Error('上传失败，请检查网络连接。')));
        xhr.addEventListener('abort', () => reject(new Error('上传已取消。')));
        xhr.send(formData);
      });
    } else {
      const response = await fetch("/generate.php", { method: "POST", body: formData });
      responseText = await response.text();
    }

    const text = responseText;
    let data;
    try { data = JSON.parse(text); } catch (e) { data = null; }

    if (data?.ok && data.record_id) {
      if (data.credits !== undefined) updateCredits(data.credits);
      if (data.watermark_points !== undefined) updateWatermarkPoints(data.watermark_points);
      // 清空提示词输入，防止误点重复扣费
      var promptInput = form.querySelector('[name="prompt"]');
      if (promptInput) promptInput.value = '';
      // 移除占位卡片，替换为真实数据卡片
      if (pendingCard) pendingCard.remove();
      if (data.record) {
        prependRecordCard(data.record);
        startPollingRecord(data.record_id, data.record);
      } else {
        showMessage(data.message || "已提交生成！");
      }
    } else {
      // 移除占位卡片
      if (pendingCard) pendingCard.remove();
      const errMsg = data?.message || "提交失败，请重试。";
      showErrorDialog(errMsg);
    }
  } catch (err) {
    if (pendingCard) pendingCard.remove();
    showErrorDialog(err.message || "网络请求失败，请检查连接后重试。");
  } finally {
    setGenerating(false);
  }
});

// 更新水印点显示
const updateWatermarkPoints = (points) => {
  if (points === undefined || points === null) return;
  window._wpBalance = points;
  // 更新 anti-watermark toggle 区域
  var balanceEl = document.querySelector("[data-wp-balance]");
  if (balanceEl) {
    balanceEl.textContent = '当前水印点：' + points;
  }
  // 更新头部全局水印点徽章
  var displayEl = document.querySelector("[data-wp-display]");
  if (displayEl) {
    displayEl.textContent = Number(points).toLocaleString();
  }
};

// 轮询记录状态 — 递归 setTimeout 模式，每次请求完成后才排下一次
let _pollTimers = {};
let _pollAttempts = {};
const startPollingRecord = (recordId, record) => {
  if (_pollTimers[recordId]) clearTimeout(_pollTimers[recordId]);
  _pollAttempts[recordId] = 0;

  const poll = function() {
    var attempt = (_pollAttempts[recordId] || 0) + 1;
    _pollAttempts[recordId] = attempt;
    if (attempt > 100) {
      delete _pollTimers[recordId];
      delete _pollAttempts[recordId];
      return;
    }

    // 延迟首次轮询（给 Worker 启动时间），后续每次 2 秒
    var delay = attempt === 1 ? 800 : 2000;
    _pollTimers[recordId] = setTimeout(async () => {
      try {
        var card = historyList ? historyList.querySelector('[data-record-id="' + recordId + '"]') : null;
        if (!card) { /* card removed, stop */ return; }

        var res = await fetch('/check_record?id=' + recordId);
        var data = await res.json();
        if (!data?.ok) { poll(); return; }

        // 始终更新状态和数据
        var newStatus = data.status || '';
        card.dataset.status = newStatus;
        if (data.record) {
          if (data.record.model) card.dataset.model = data.record.model;
          if (data.record.credits_charged !== undefined) card.dataset.credits = data.record.credits_charged;
          if (data.record.finished_at) card.dataset.finished = data.record.finished_at;
          if (data.record.error_message) card.dataset.error = data.record.error_message;
        }
        if (data.credits !== undefined) updateCredits(data.credits);

        // 更新所有 status badge
        card.querySelectorAll('.status-badge').forEach(function(b) {
          b.textContent = statusText(newStatus);
          b.className = 'status-badge ' + newStatus;
        });

        // 图片就绪时注入 <img>
        if (data.image_src) {
          var img = card.querySelector('img');
          if (!img) {
            img = document.createElement('img');
            img.alt = '生成图片';
            var ph = card.querySelector('[style*="aspect-ratio"]');
            if (ph) ph.replaceWith(img); else card.prepend(img);
          }
          if (img.src !== data.image_src) img.src = data.image_src;
        }

        // 终态停止
        if (newStatus === 'succeeded' || newStatus === 'failed') {
          delete _pollTimers[recordId];
          delete _pollAttempts[recordId];
          // 会员额度实时刷新：生成成功后递减本地计数
          if (newStatus === 'succeeded') {
            updateMembershipQuotaHint(true);
          }
          return;
        }

        poll(); // 继续下一轮
      } catch (e) {
        poll(); // 出错也继续
      }
    }, delay);
  };

  poll();
};

// Anti-watermark checkbox: update cost display on toggle
document.addEventListener("change", function(e) {
  if (e.target.matches("[data-anti-watermark-check]")) {
    updateWatermarkCost();
  }
});

// Beforeunload warning
let pendingSubmit = false;
form?.addEventListener("submit", () => { pendingSubmit = true; });
form?.addEventListener("input", () => { pendingSubmit = false; });
window.addEventListener("beforeunload", (event) => {
  if (isGenerating || pendingSubmit) event.returnValue = "请求仍在处理中，关闭页面可能导致当前提交中断。";
});

// Record dialog (from media card click)
const ensureRecordDialog = () => {
  let dialog = document.querySelector("#recordDialog");
  if (dialog) return dialog;
  dialog = document.createElement("div");
  dialog.id = "recordDialog";
  dialog.className = "record-dialog hidden";
  dialog.innerHTML = `
    <div class="record-dialog-panel" role="dialog" aria-modal="true">
      <div class="record-dialog-head">
        <h2>生成记录详情</h2>
        <button type="button" data-close-dialog class="record-dialog-close" aria-label="关闭">关闭</button>
      </div>
      <div class="record-dialog-body">
        <div class="record-full-prompt"><span>完整提示词</span><p data-dialog-prompt></p></div>
        <div class="record-dialog-image" data-dialog-images></div>
        <div class="record-detail-grid">
          <div class="record-detail-status"><span>状态</span><strong class="status" data-dialog-status>-</strong></div>
          <div><span>模型</span><strong data-dialog-model>-</strong></div>
          <div><span>参数</span><strong data-dialog-params>-</strong></div>
          <div><span>消耗</span><strong data-dialog-credits>-</strong></div>
          <div><span>时间</span><strong data-dialog-time>-</strong></div>
        </div>
        <div class="record-error hidden" data-dialog-error></div>
      </div>
      <div class="record-dialog-foot">
        <a id="recordDownloadBtn" href="#" download class="btn btn-primary" style="font-size:12px;padding:6px 14px;text-decoration:none;">⬇ 下载视频</a>
        <form method="post" action="/delete_record" onsubmit="return confirm('确认删除？')">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="record_id" value="">
          <button type="submit" class="record-delete"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5.3 4V2.7c0-.4.3-.7.7-.7h4c.4 0 .7.3.7.7V4M6.7 7.3v4M9.3 7.3v4M3.3 4l.8 8.6c.1.7.7 1.4 1.5 1.4h4.7c.7 0 1.4-.6 1.5-1.4l.8-8.6"/></svg>删除</button>
        </form>
        <a data-download-video class="btn btn-secondary btn-sm" style="display:none;" download>⬇ 下载视频</a>
        <button type="button" data-download-image class="btn btn-secondary btn-sm" style="display:none;">⬇ 下载图片</button>
        <button type="button" data-share-gallery class="btn btn-secondary btn-sm" style="display:none;">📤 分享到广场</button>
        <button type="button" data-close-dialog class="btn btn-primary btn-sm">关闭</button>
      </div>
    </div>`;
  document.body.appendChild(dialog);
  dialog.addEventListener("click", (e) => {
    // 仅关闭按钮可关闭弹窗，点击遮罩不关闭
    if (e.target.closest("[data-close-dialog]")) {
      dialog.classList.add("hidden");
      document.body.classList.remove("has-dialog");
      const shouldRefresh = dialog.dataset.refreshOnClose === "1";
      delete dialog.dataset.refreshOnClose;
      if (shouldRefresh) { location.reload(); }
    }
  });
  // 移除 Escape 按键关闭（仅关闭按钮控制）
  // document.addEventListener keydown handler removed for recordDialog
  return dialog;
};

const openRecordDialog = (card) => {
  const d = ensureRecordDialog();
  if (!d) return;
  d.classList.remove("hidden");
  document.body.classList.add("has-dialog");
  const st = (sel, val) => { const el = d.querySelector(sel); if (el) el.textContent = val; };
  st("[data-dialog-prompt]", card.dataset.prompt || "");
  st("[data-dialog-status]", statusText(card.dataset.status) || "-");
  // 模型（独立展示）
  st("[data-dialog-model]", card.dataset.model || "-");
  st("[data-dialog-params]", ((mode) => {
    var res = card.dataset.resolutionLevel || "";
    var resStr = res ? (" " + res) : "";
    if (mode === "video") return `视频 / ${card.dataset.format || "mp4"} / ${card.dataset.size || "auto"}`;
    if (mode === "edit") return `编辑${resStr} / ${card.dataset.size || "-"}`;
    return `绘画${resStr} / ${card.dataset.size || "-"}`;
  })(card.dataset.mode));
  st("[data-dialog-credits]", card.dataset.credits || "0");
  st("[data-dialog-time]", `创建 ${card.dataset.created}`);
  const errEl = d.querySelector("[data-dialog-error]");
  if (errEl) {
    const msg = card.dataset.error || "";
    if (msg) { errEl.textContent = msg; errEl.classList.remove("hidden"); }
    else { errEl.classList.add("hidden"); }
  }
  const imgSec = d.querySelector("[data-dialog-images]");
  if (imgSec) {
    const img = card.querySelector("img");
    const vid = card.querySelector("video");
    imgSec.innerHTML = "";
    if (vid) {
      const c = vid.cloneNode(true);
      c.removeAttribute("style");
      c.style.cssText = "max-width:100%;max-height:300px;";
      imgSec.appendChild(c);
    } else if (img) {
      const c = img.cloneNode();
      c.style.cssText = "max-width:100%;max-height:300px;cursor:pointer;";
      imgSec.appendChild(c);
    } else {
      // 卡片内无 media 元素 → 尝试 data 属性回退
      const videoSrc = card.dataset.videoSrc || "";
      const imageSrc = card.dataset.imageSrc || "";
      if (videoSrc) {
        const v = document.createElement("video");
        v.src = videoSrc;
        v.controls = true;
        v.style.cssText = "max-width:100%;max-height:300px;";
        imgSec.appendChild(v);
      } else if (imageSrc) {
        const i = document.createElement("img");
        i.src = imageSrc;
        i.alt = "生成结果";
        i.style.cssText = "max-width:100%;max-height:300px;cursor:pointer;";
        imgSec.appendChild(i);
      }
    }
  }

  // ── 图片 lightbox：点击放大查看原图 ──
  (function() {
    var lb = document.querySelector("#recordLightbox");
    if (!lb) {
      lb = document.createElement("div");
      lb.id = "recordLightbox";
      lb.className = "record-lightbox hidden";
      lb.innerHTML = '<button class="record-lightbox-close" aria-label="关闭">&times;</button><img alt="原图预览">';
      document.body.appendChild(lb);
      lb.addEventListener("click", function(e) {
        if (e.target === lb || e.target.closest(".record-lightbox-close")) {
          lb.classList.add("hidden");
        }
      });
      document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && !lb.classList.contains("hidden")) lb.classList.add("hidden");
      });
    }
    var dlgImg = d.querySelector(".record-dialog-image img");
    if (dlgImg) {
      dlgImg.style.cursor = "zoom-in";
      dlgImg.addEventListener("click", function() {
        var full = lb.querySelector("img");
        if (full && dlgImg.src) { full.src = dlgImg.src; full.alt = dlgImg.alt; }
        lb.classList.remove("hidden");
      });
    }
  })();

  // ── 视频下载按钮 ──
  var vidDlBtn = d.querySelector("[data-download-video]");
  if (vidDlBtn) {
    var isVideo = card.dataset.mode === "video";
    var videoSrc = "";
    var vidEl = d.querySelector(".record-dialog-image video");
    if (vidEl) videoSrc = vidEl.src || vidEl.querySelector("source")?.src || "";
    if (!videoSrc) videoSrc = card.dataset.videoSrc || "";
    if (isVideo && videoSrc) {
      vidDlBtn.href = videoSrc;
      vidDlBtn.style.display = "";
    } else {
      vidDlBtn.style.display = "none";
      vidDlBtn.removeAttribute("href");
    }
  }

  const delForm = d.querySelector(".record-dialog-foot form");
  if (delForm) { delForm.querySelector('[name="record_id"]').value = card.dataset.recordId; }

  // 下载按钮
  const dlBtn = d.querySelector("#recordDownloadBtn");
  if (dlBtn) {
    const dlSrc = card.querySelector("video")?.getAttribute("src") || card.dataset.videoSrc || "";
    if (dlSrc) { dlBtn.href = dlSrc; dlBtn.style.display = ""; }
    else { dlBtn.style.display = "none"; }
  }

  // 分享到广场按钮（切换模式）
  const shareBtn = d.querySelector("[data-share-gallery]");
  if (shareBtn) {
    const isSucceeded = card.dataset.status === "succeeded";
    shareBtn.style.display = isSucceeded ? "" : "none";
    shareBtn.disabled = false;
    const recordId = parseInt(card.dataset.recordId);

    // 检查是否已分享
    const checkShare = async () => {
      try {
        const r = await fetch("/api/gallery", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "check_share", record_id: recordId })
        });
        const d = await r.json();
        shareBtn.textContent = d.data?.shared ? "✅ 已分享（点击取消）" : "📤 分享到广场";
        shareBtn.dataset.shared = d.data?.shared ? "1" : "";
      } catch(e) {}
    };
    checkShare();

    shareBtn.onclick = async () => {
      shareBtn.disabled = true;
      const isShared = shareBtn.dataset.shared === "1";
      const action = isShared ? "unshare" : "share";
      shareBtn.textContent = isShared ? "取消中..." : "分享中...";
      try {
        const res = await fetch("/api/gallery", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action, record_id: recordId })
        });
        const data = await res.json();
        if (data.ok) {
          shareBtn.dataset.shared = isShared ? "" : "1";
          shareBtn.textContent = isShared ? "📤 分享到广场" : "✅ 已分享（点击取消）";
        } else {
          shareBtn.textContent = "❌ " + (data.message || "失败");
        }
      } catch(e) { shareBtn.textContent = "❌ 网络错误"; }
      shareBtn.disabled = false;
    };
  }

  // 下载图片按钮
  const imgDlBtn = d.querySelector("[data-download-image]");
  if (imgDlBtn) {
    const isImage = card.dataset.mode !== "video";
    const isSucceeded = card.dataset.status === "succeeded";
    imgDlBtn.style.display = (isImage && isSucceeded) ? "" : "none";
    imgDlBtn.onclick = () => {
      const imgEl = d.querySelector("[data-dialog-images] img");
      const videoEl = d.querySelector("[data-dialog-images] video");
      let src = "";
      if (imgEl) src = imgEl.src;
      else if (!videoEl && card.dataset.imageSrc) src = card.dataset.imageSrc;

      if (!src) return;

      const filename = (card.dataset.prompt || "image").substring(0, 40).replace(/[\\/:*?"<>|]/g, "_") + ".png";

      fetch(src)
        .then(r => r.blob())
        .then(blob => {
          const url = URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.href = url;
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          a.remove();
          URL.revokeObjectURL(url);
        })
        .catch(() => {
          // fetch 跨域回退：直接打开图片
          const a = document.createElement("a");
          a.href = src;
          a.download = filename;
          a.target = "_blank";
          document.body.appendChild(a);
          a.click();
          a.remove();
        });
    };
  }
};
window.openRecordDialog = openRecordDialog;

// Show result dialog from a generated record
const showResultDialog = (record) => {
  // Create a temporary card-like object to reuse openRecordDialog
  const proxyCard = {
    dataset: {
      recordId: record.id || "",
      status: record.status || "succeeded",
      mode: record.mode || "draw",
      model: record.model || "",
      prompt: record.prompt || "",
      size: record.size || "auto",
      quality: record.quality || "auto",
      format: record.format || "png",
      credits: record.credits_charged || 0,
      created: record.created_at || "",
      finished: record.finished_at || "-",
      error: record.error_message || "",
      inputCount: record.input_image_count || 0
    },
    querySelector: () => null // no image/video element in the proxy
  };

  openRecordDialog(proxyCard);

  // Set refresh-on-close flag
  const dialog = document.querySelector("#recordDialog");
  if (dialog) {
    dialog.dataset.refreshOnClose = "1";
  }
};

// Show error dialog (manual close, no refresh)
const showErrorDialog = (message) => {
  let dialog = document.querySelector("#errorDialog");
  if (!dialog) {
    dialog = document.createElement("div");
    dialog.id = "errorDialog";
    dialog.className = "record-dialog hidden";
    dialog.innerHTML = `
      <div class="error-dialog-panel" role="dialog" aria-modal="true">
        <div class="error-dialog-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <h2>生成失败</h2>
        </div>
        <div class="error-dialog-body" id="errorDialogBody">${escapeHtml(message)}</div>
        <div class="error-dialog-foot">
          <button type="button" data-close-dialog class="btn btn-primary">知道了</button>
        </div>
      </div>`;
    document.body.appendChild(dialog);
    dialog.addEventListener("click", (e) => {
      if (e.target.closest("[data-close-dialog]") || e.target === dialog) {
        dialog.classList.add("hidden");
        document.body.classList.remove("has-dialog");
      }
    });
  } else {
    const body = dialog.querySelector("#errorDialogBody");
    if (body) body.textContent = message;
  }
  dialog.classList.remove("hidden");
  document.body.classList.add("has-dialog");
};

// Click on media card opens detail
document.addEventListener("click", (event) => {
  const card = event.target.closest(".media-card");
  if (!card || event.target.closest(".record-delete-form, button")) return;
  event.preventDefault();
  openRecordDialog(card);
});

// Close helpers
const closeImageViewer = () => { document.querySelector("#imageViewer")?.classList.add("hidden"); };
const closeRecordDialog = () => { document.querySelector("#recordDialog")?.classList.add("hidden"); document.body.classList.remove("has-dialog"); };
window.addEventListener("keydown", (e) => { if (e.key === "Escape") { closeImageViewer(); closeRecordDialog(); } });

// Prompt Optimize
const optimizeBtn = document.querySelector("#optimizePromptBtn");
const promptTextarea = document.querySelector('textarea[name="prompt"]');
const optimizeStatus = document.querySelector("#optimizePromptStatus");
        if (optimizeBtn && promptTextarea) {
  optimizeBtn.addEventListener("click", async () => {
    const raw = promptTextarea.value.trim();
    if (!raw) { if (optimizeStatus) { optimizeStatus.textContent = "请先输入提示词"; optimizeStatus.className = "field-hint is-error"; } promptTextarea.focus(); return; }
    optimizeBtn.classList.add("is-loading"); optimizeBtn.textContent = "优化中...";
    if (optimizeStatus) { optimizeStatus.textContent = ""; optimizeStatus.className = "field-hint hidden"; }
    try {
      const fd = new FormData(); fd.append("prompt", raw); fd.append("csrf_token", csrfToken);
      const res = await fetch("/prompt_optimize.php", { method: "POST", body: fd });
      const data = await res.json();
      if (data.ok && data.prompt) {
        promptTextarea.value = data.prompt;
        if (optimizeStatus) { optimizeStatus.textContent = "优化完成"; optimizeStatus.className = "field-hint"; }
        promptTextarea.style.height = "auto"; promptTextarea.style.height = promptTextarea.scrollHeight + "px";
      } else throw new Error(data.message || "优化失败");
    } catch (err) {
      if (optimizeStatus) { optimizeStatus.textContent = err.message || "优化请求失败"; optimizeStatus.className = "field-hint is-error"; }
    } finally { optimizeBtn.classList.remove("is-loading"); optimizeBtn.textContent = "✨ 优化提示词"; }
  });
}

/* ── Mode Toggle (fallback for :has() selector) ── */
document.querySelectorAll('.mode-toggle').forEach(function(group) {
    var radios = group.querySelectorAll('input[type="radio"]');
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            radios.forEach(function(r) {
                var label = r.closest('label');
                if (label) label.classList.toggle('active', r.checked);
            });
        });
        if (radio.checked) {
            var label = radio.closest('label');
            if (label) label.classList.add('active');
        }
    });
});

/* ── Size Selector ── */
(function() {
    var container = document.querySelector("[data-size-selector]");
    if (!container) return;
    var input = container.querySelector("[data-size-input]");
    container.addEventListener("click", function(e) {
        var chip = e.target.closest("[data-size]");
        if (!chip) return;
        var size = chip.getAttribute("data-size");
        container.querySelectorAll("[data-size]").forEach(function(c) { c.classList.remove("active"); });
        chip.classList.add("active");
        if (input) input.value = size;
    });
})();

// ── 页面加载时恢复轮询：扫描图库中排队/生成中的卡片并启动实时状态更新 ──
(function() {
  if (!historyList) return;
  historyList.querySelectorAll('.media-card').forEach(function(card) {
    var rid = card.dataset.recordId;
    var st = card.dataset.status;
    if (rid && (st === 'queued' || st === 'running')) {
      startPollingRecord(parseInt(rid, 10), { status: st });
    }
  });
})();
