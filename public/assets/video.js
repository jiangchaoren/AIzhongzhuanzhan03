(() => {
const form = document.querySelector("#videoGenerateForm");
const button = document.querySelector("#generateButton");
const message = document.querySelector("#generateMessage");
const historyList = document.querySelector("#videoHistoryList");
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";

let isGenerating = false;

const showMessage = (text, type = "success") => {
  try { window.showToast?.(text, type); } catch (_) {}
  if (message) { message.textContent = ""; message.className = "inline-message hidden"; }
};

const setGenerating = (enabled) => {
  isGenerating = enabled;
  if (button) {
    button.disabled = enabled;
    button.textContent = enabled ? "生成中..." : "生成视频";
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
  article.dataset.mode = "video";
  article.dataset.prompt = record.prompt || "";
  article.dataset.size = record.size || "auto";
  article.dataset.quality = record.quality || "";
  article.dataset.format = record.format || "mp4";
  article.dataset.credits = record.credits_charged || 0;
  article.dataset.created = record.created_at || "";
  article.dataset.finished = record.finished_at || "-";
  article.dataset.error = record.error_message || "";
  article.style.cursor = "pointer";
  article.dataset.inputCount = "0";

  const src = record.video_src;

  article.innerHTML = `
    ${src
      ? `<video src="${escapeHtml(src)}" controls></video>`
      : `<div style="width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:var(--main-surface-soft);color:var(--text-muted);font-size:13px;font-weight:700;"><span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span></div>`
    }
    <div class="media-card-body">
      <div class="prompt">${escapeHtml(record.prompt)}</div>
      <div class="meta">
        <span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span>
        <span>视频生成</span>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
        <time style="font-size:10px;color:var(--text-muted);">${escapeHtml(record.created_at)}</time>
        <div style="display:flex;gap:4px;">
          ${src ? `<a href="${escapeHtml(src)}" download class="btn btn-primary btn-sm" style="font-size:10px;padding:2px 8px;">⬇ 下载</a>` : ''}
          <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')" style="display:inline;">
            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
            <input type="hidden" name="record_id" value="${record.id}">
            <input type="hidden" name="redirect_to" value="/user/video">
            <button type="submit" class="btn btn-ghost btn-sm">删除</button>
          </form>
        </div>
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

  // 如果状态是排队中/生成中，开始轮询
  if (record.status === "queued" || record.status === "running") {
    watchRecord(record.id);
  }
};

// ── 轮询：实时更新视频记录状态 ──
const watchedRecords = {};

const watchRecord = (recordId) => {
  if (watchedRecords[recordId]) return; // 已在轮询中
  watchedRecords[recordId] = true;

  const poll = async () => {
    if (!watchedRecords[recordId]) return; // 已被取消

    try {
      const res = await fetch(`/check_record?id=${recordId}`);
      const data = await res.json();
      if (!data?.ok) return;

      if (data.credits !== undefined) updateCredits(data.credits);

      const card = historyList?.querySelector(`[data-record-id="${recordId}"]`);
      if (!card) { delete watchedRecords[recordId]; return; } // card 已被移除

      const newStatus = data.status || "";
      const oldStatus = card.dataset.status || "";

      // 更新 data-status
      card.dataset.status = newStatus;
      card.dataset.error = data.record?.error_message || "";
      card.dataset.finished = data.record?.finished_at || "-";

      // 状态变为成功 → 注入 video 元素
      if (newStatus === "succeeded" && data.video_src && oldStatus !== "succeeded") {
        // 移除占位 div，插入 video
        const placeholder = card.querySelector(":scope > div[style]");
        if (placeholder) {
          const video = document.createElement("video");
          video.src = data.video_src;
          video.controls = true;
          placeholder.replaceWith(video);
        }

        // 更新 meta 里的状态徽章
        const statusBadge = card.querySelector(".status-badge");
        if (statusBadge) {
          statusBadge.className = `status-badge succeeded`;
          statusBadge.textContent = "已完成";
        }
        updateCredits(data.credits);

        // 停止轮询
        delete watchedRecords[recordId];
        return;
      }

      // 状态变为失败
      if (newStatus === "failed" && oldStatus !== "failed") {
        const statusBadge = card.querySelector(".status-badge");
        if (statusBadge) {
          statusBadge.className = `status-badge failed`;
          statusBadge.textContent = "失败";
        }
        updateCredits(data.credits);
        delete watchedRecords[recordId];
        return;
      }

      // 更新状态徽章文字（包括 placeholder 内的和 meta 内的）
      if (newStatus !== oldStatus) {
        card.querySelectorAll(".status-badge").forEach((badge) => {
          badge.className = `status-badge ${newStatus}`;
          badge.textContent = statusText(newStatus);
        });
      }

      // 继续轮询（queued / running）
      setTimeout(poll, 3000);
    } catch (e) {
      // 网络错误，5秒后重试
      setTimeout(poll, 5000);
    }
  };

  // 启动首次轮询
  setTimeout(poll, 2000);
};

// 页面加载时，为所有排队/生成中的视频记录启动轮询
const initWatchAll = () => {
  if (!historyList) return;
  const cards = historyList.querySelectorAll(".media-card");
  cards.forEach((card) => {
    const rid = parseInt(card.dataset.recordId, 10);
    const status = card.dataset.status;
    if (rid && (status === "queued" || status === "running")) {
      watchRecord(rid);
    }
  });
};

// 页面初始化
initWatchAll();

// 模型选择 → 消耗联动 + Agnes 分辨率/宽高比
const videoModelSelect = document.getElementById("ai_model_id");
if (videoModelSelect) {
  const costHint = document.querySelector("[data-cost-display]");
  const costVal = document.querySelector("[data-cost-value]");
  const defaultCost = parseInt(costHint?.dataset.defaultCost || costVal?.textContent || "1", 10) || 1;

  const normalizeCredits = (value) => {
    const credits = parseInt(String(value ?? "").trim(), 10);
    return credits > 0 ? credits : 0;
  };

  // 完整视频模型数据（含 site_type, agnes_config）
  let videoModelsData = [];
  try {
    videoModelsData = JSON.parse(videoModelSelect.dataset.videoModels || "[]");
  } catch (e) {}

  const modelCreditsMap = {};
  videoModelsData.forEach((model) => {
    const id = String(model?.id ?? "");
    if (id && normalizeCredits(model?.credits) > 0) modelCreditsMap[id] = normalizeCredits(model?.credits);
  });
  // 兜底 option[data-credits]
  Array.from(videoModelSelect.options || []).forEach((option) => {
    const id = String(option.value || "");
    const credits = normalizeCredits(option.dataset.credits);
    if (id && credits > 0) modelCreditsMap[id] = credits;
  });

  const getCurrentVideoModel = () => {
    const selectedOption = videoModelSelect.selectedOptions?.[0];
    if (!selectedOption) return {};

    // 优先从 option data 属性直接读取（避免 JSON 解析失败）
    var siteType = selectedOption.dataset.siteType || "";
    var agnesConfigRaw = selectedOption.dataset.agnesConfig || "";
    var grokConfigRaw = selectedOption.dataset.grokConfig || "";
    var seedanceConfigRaw = selectedOption.dataset.seedanceConfig || "";
    var agnesConfig = null;
    var grokConfig = null;
    var seedanceConfig = null;
    if (agnesConfigRaw) {
      try { agnesConfig = JSON.parse(agnesConfigRaw.replace(/&quot;/g, '"')); } catch(e) {}
    }
    if (grokConfigRaw) {
      try { grokConfig = JSON.parse(grokConfigRaw.replace(/&quot;/g, '"')); } catch(e) {}
    }
    if (seedanceConfigRaw) {
      try { seedanceConfig = JSON.parse(seedanceConfigRaw.replace(/&quot;/g, '"')); } catch(e) {}
    }

    // 回退到 videoModelsData JSON
    const selectedId = String(videoModelSelect.value || "");
    var modelFromJson = videoModelsData.find(function(m) { return String(m.id) === selectedId; }) || {};
    return {
      site_type: siteType || modelFromJson.site_type || "standard",
      agnes_config: agnesConfig || modelFromJson.agnes_config || null,
      grok_config: grokConfig || modelFromJson.grok_config || null,
      seedance_config: seedanceConfig || modelFromJson.seedance_config || null,
      id: modelFromJson.id || selectedId,
      credits: modelFromJson.credits || 0,
    };
  };

  // ── Agnes 分辨率/宽高比逻辑 ──
  const agnesResSelector = document.querySelector("[data-agnes-res-selector]");
  const agnesArSelector = document.querySelector("[data-agnes-ar-selector]");
  const agnesResInput = document.querySelector("[data-agnes-resolution-input]");
  const agnesArInput = document.querySelector("[data-agnes-ar-input]");
  const agnesResChips = document.querySelectorAll("[data-agnes-res]");
  const agnesArChips = document.querySelectorAll("[data-agnes-ar]");

  // 分辨率 chip 点击
  agnesResChips.forEach(function(chip) {
    chip.addEventListener("click", function() {
      if (chip.hasAttribute("data-agnes-res-active")) return;
      agnesResChips.forEach(function(c) { c.removeAttribute("data-agnes-res-active"); });
      chip.setAttribute("data-agnes-res-active", "");
      if (agnesResInput) agnesResInput.value = chip.getAttribute("data-agnes-res");
      updateCost();
      if (typeof updateWatermarkCost === "function") updateWatermarkCost();
    });
  });

  // 宽高比 chip 点击
  agnesArChips.forEach(function(chip) {
    chip.addEventListener("click", function() {
      agnesArChips.forEach(function(c) { c.removeAttribute("data-agnes-ar-active"); });
      chip.setAttribute("data-agnes-ar-active", "");
      if (agnesArInput) agnesArInput.value = chip.getAttribute("data-agnes-ar");
    });
  });

  const updateAgnesUI = () => {
    const model = getCurrentVideoModel();
    const isAgnes = (model.site_type || "standard") === "agnes";
    if (agnesResSelector) agnesResSelector.style.display = isAgnes ? "" : "none";
    if (agnesArSelector) agnesArSelector.style.display = isAgnes ? "" : "none";

    // 更新分辨率 chips 的可用状态
    if (isAgnes && model.agnes_config) {
      try {
        var agnesConfig = typeof model.agnes_config === "string" ? JSON.parse(model.agnes_config) : model.agnes_config;
        agnesResChips.forEach(function(chip) {
          var res = chip.getAttribute("data-agnes-res");
          var cfg = agnesConfig && agnesConfig[res];
          if (cfg && cfg.enabled) {
            chip.classList.remove("disabled");
            chip.style.opacity = "";
            chip.style.pointerEvents = "";
          } else {
            chip.classList.add("disabled");
            chip.style.opacity = "0.4";
            chip.style.pointerEvents = "none";
            if (chip.hasAttribute("data-agnes-res-active")) {
              chip.removeAttribute("data-agnes-res-active");
            }
          }
        });
        // 如果当前 active 的 resolution 不可用，切换到第一个可用的
        var activeRes = document.querySelector("[data-agnes-res-active]");
        if (!activeRes || activeRes.classList.contains("disabled")) {
          var firstAvail = document.querySelector("[data-agnes-res]:not(.disabled)");
          if (firstAvail) {
            firstAvail.setAttribute("data-agnes-res-active", "");
            if (agnesResInput) agnesResInput.value = firstAvail.getAttribute("data-agnes-res");
          }
        }
      } catch (e) {}
    }
  };

  // ── Agnes 时长逻辑 ──
  const agnesDurSelector = document.querySelector("[data-agnes-duration-selector]");
  const agnesDurCustom = document.querySelector("[data-agnes-duration-custom]");
  const agnesDurFixed = document.querySelector("[data-agnes-duration-fixed]");
  const agnesDurInput = document.querySelector("[data-agnes-duration-input]");
  const agnesDurRange = document.querySelector("[data-agnes-duration-range]");
  const agnesDurChipsContainer = document.querySelector("[data-agnes-duration-chips]");
  const agnesDurFixedInput = document.querySelector("[data-agnes-duration-fixed-input]");

  // 写入时长到两个隐藏 input（兼容自定义和固定模式）
  const setAgnesDurationValue = (val) => {
    if (agnesDurInput) agnesDurInput.value = val;
    if (agnesDurFixedInput) agnesDurFixedInput.value = val;
  };

  const getAgnesCost = () => {
    var model = getCurrentVideoModel();
    if ((model.site_type || "standard") !== "agnes") return null;
    try {
      var agnesConfig = typeof model.agnes_config === "string" ? JSON.parse(model.agnes_config) : model.agnes_config;
      var activeRes = document.querySelector("[data-agnes-res-active]");
      var res = activeRes ? activeRes.getAttribute("data-agnes-res") : "480p";
      var resCfg = agnesConfig && agnesConfig[res];
      var resCost = resCfg ? (resCfg.credits || 5) : 5;

      var durCfg = (agnesConfig && agnesConfig._duration) || {mode: 'custom', max_seconds: 15, price_per_second: 1, tiers: []};
      var durCost = 0;
      if (durCfg.mode === 'custom') {
        var secs = parseInt((agnesDurInput && agnesDurInput.value) || 5, 10) || 5;
        durCost = secs * (durCfg.price_per_second || 1);
      } else if (durCfg.mode === 'fixed') {
        var activeDur = document.querySelector("[data-agnes-duration-active]");
        if (activeDur) {
          durCost = parseInt(activeDur.getAttribute("data-agnes-dur-credits") || 0, 10) || 0;
        } else {
          var tiers = durCfg.tiers || [];
          durCost = tiers.length ? (tiers[0].credits || 0) : 0;
        }
      }
      return resCost + durCost;
    } catch (e) { return null; }
  };

  const getGrokCost = () => {
    var model = getCurrentVideoModel();
    if ((model.site_type || "standard") !== "grok") return null;
    try {
      var grokConfig = typeof model.grok_config === "string" ? JSON.parse(model.grok_config) : model.grok_config;
      // Grok 复用 Agnes 的 data-agnes-res-active 属性做选中态
      var activeResBtn = document.querySelector(".grok-res-chip[data-agnes-res-active]");
      var res = activeResBtn ? activeResBtn.getAttribute("data-grok-res") : "480p";
      var resCfg = grokConfig && grokConfig[res];
      var resCost = resCfg ? (resCfg.credits || 5) : 5;

      var durCfg = (grokConfig && grokConfig._duration) || {max_seconds: 15, price_per_second: 2};
      var durInput = document.querySelector("[name=grok_duration]");
      var secs = parseInt((durInput && durInput.value) || 4, 10) || 4;
      var durCost = secs * (durCfg.price_per_second || 2);
      return resCost + durCost;
    } catch (e) { return null; }
  };

  const getSeedanceCost = () => {
    var model = getCurrentVideoModel();
    if ((model.site_type || "standard") !== "seedance") return null;
    try {
      var seedanceConfig = typeof model.seedance_config === "string" ? JSON.parse(model.seedance_config) : model.seedance_config;
      var activeResBtn = document.querySelector(".seedance-res-chip[data-agnes-res-active]");
      var res = activeResBtn ? activeResBtn.getAttribute("data-seedance-res") : "720p";
      var resCfg = seedanceConfig && seedanceConfig[res];
      var resCost = resCfg ? (resCfg.credits || 5) : 5;

      var durCfg = (seedanceConfig && seedanceConfig._duration) || {mode: 'fixed', tiers: [], max_seconds: 15, price_per_second: 2};
      var durCost = 0;
      if ((durCfg.mode || 'fixed') === 'fixed') {
        var activeDur = document.querySelector("[data-seedance-duration-active]");
        if (activeDur) {
          durCost = parseInt(activeDur.getAttribute("data-seedance-dur-credits") || 0, 10) || 0;
        } else {
          var tiers = durCfg.tiers || [];
          durCost = tiers.length ? (tiers[0].credits || 0) : 0;
        }
      } else {
        var durInput = document.querySelector("[name=seedance_duration]");
        var secs = parseInt((durInput && durInput.value) || 5, 10) || 5;
        durCost = secs * (durCfg.price_per_second || 2);
      }
      return resCost + durCost;
    } catch (e) { return null; }
  };

  const updateAgnesDurationUI = () => {
    var model = getCurrentVideoModel();
    var isAgnes = (model.site_type || "standard") === "agnes";
    if (agnesDurSelector) agnesDurSelector.style.display = isAgnes ? "" : "none";
    if (!isAgnes) return;

    try {
      var agnesConfig = typeof model.agnes_config === "string" ? JSON.parse(model.agnes_config) : model.agnes_config;
      var durCfg = (agnesConfig && agnesConfig._duration) || {mode: 'custom', max_seconds: 15, price_per_second: 1, tiers: []};

      if (durCfg.mode === 'custom') {
        if (agnesDurCustom) agnesDurCustom.style.display = "";
        if (agnesDurFixed) agnesDurFixed.style.display = "none";
        var maxSec = durCfg.max_seconds || 15;
        if (agnesDurInput) {
          agnesDurInput.max = maxSec;
          agnesDurInput.min = 1;
          var curVal = parseInt(agnesDurInput.value, 10) || 5;
          if (curVal > maxSec) agnesDurInput.value = maxSec;
          if (curVal < 1) agnesDurInput.value = 1;
        }
        if (agnesDurRange) agnesDurRange.textContent = "(1~" + maxSec + ")";
      } else {
        if (agnesDurCustom) agnesDurCustom.style.display = "none";
        if (agnesDurFixed) agnesDurFixed.style.display = "";
        var tiers = durCfg.tiers || [];
        if (agnesDurChipsContainer) {
          agnesDurChipsContainer.innerHTML = '';
          tiers.forEach(function(tier, i) {
            var chip = document.createElement("button");
            chip.type = "button";
            chip.className = "chip agnes-dur-chip";
            chip.setAttribute("data-agnes-dur", tier.seconds);
            chip.setAttribute("data-agnes-dur-credits", tier.credits);
            chip.textContent = tier.seconds + "s";
            if (i === 0) {
              chip.setAttribute("data-agnes-duration-active", "");
              setAgnesDurationValue(tier.seconds);
            }
            chip.addEventListener("click", function() {
              document.querySelectorAll("[data-agnes-duration-active]").forEach(function(c) { c.removeAttribute("data-agnes-duration-active"); });
              this.setAttribute("data-agnes-duration-active", "");
              setAgnesDurationValue(this.getAttribute("data-agnes-dur"));
              updateCost();
            });
            agnesDurChipsContainer.appendChild(chip);
          });
        }
      }
    } catch (e) {}
  };

  // 时长输入变化时更新消耗
  if (agnesDurInput) {
    agnesDurInput.addEventListener("input", updateCost);
  }

  const updateCost = () => {
    var agnesCost = getAgnesCost();
    var grokCost = getGrokCost();
    var seedanceCost = getSeedanceCost();
    if (agnesCost !== null) {
      if (costVal) costVal.textContent = String(agnesCost);
      if (costHint) costHint.dataset.defaultCost = String(agnesCost);
    } else if (grokCost !== null) {
      if (costVal) costVal.textContent = String(grokCost);
      if (costHint) costHint.dataset.defaultCost = String(grokCost);
    } else if (seedanceCost !== null) {
      if (costVal) costVal.textContent = String(seedanceCost);
      if (costHint) costHint.dataset.defaultCost = String(seedanceCost);
    } else {
      const selectedOption = videoModelSelect.selectedOptions?.[0];
      const selectedId = String(videoModelSelect.value || "");
      const selectedCredits = normalizeCredits(selectedOption?.dataset.credits);
      const mappedCredits = normalizeCredits(modelCreditsMap[selectedId]);
      const credits = selectedCredits || mappedCredits || defaultCost;
      if (costVal) costVal.textContent = String(credits);
      if (costHint) costHint.dataset.defaultCost = String(credits);
    }
  };

  videoModelSelect.addEventListener("change", function() {
    updateAgnesUI();
    updateAgnesDurationUI();
    updateCost();
    if (typeof updateWatermarkCost === "function") updateWatermarkCost();
  });
  // 初始化 - 确保 DOM 就绪后执行
  var initAgnes = function() {
    try { updateAgnesUI(); } catch(e) { console.warn('Agnes UI init error:', e); }
    try { updateAgnesDurationUI(); } catch(e) { console.warn('Agnes dur init error:', e); }
    updateCost();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAgnes);
  } else {
    initAgnes();
  }
  // 联动更新去水印消耗
  if (typeof updateWatermarkCost === "function") {
    try { updateWatermarkCost(); } catch (e) {}
  }
}

// Form submit — result dialog mode
form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (isGenerating) return;

  const formData = new FormData(form);
  // 去水印参数
  const antiWmCheck = document.querySelector("[data-anti-watermark-check]");
  if (antiWmCheck && antiWmCheck.checked) {
    formData.append("anti_watermark", "1");
  }
  setGenerating(true);
  showMessage("正在提交...", "info");

  try {
    const response = await fetch("/video_generate.php", { method: "POST", body: formData });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (e) { data = null; }

    if (data?.ok && data.record_id) {
      if (data.credits !== undefined) updateCredits(data.credits);
      if (data.watermark_points !== undefined && typeof updateWatermarkPoints === "function") {
        updateWatermarkPoints(data.watermark_points);
      }
      if (data.record) {
        prependRecordCard(data.record);
        // 视频与图片一致：卡片直接显示在图库，不弹详情窗
      } else {
        showMessage(data.message || "已提交生成！");
      }
    } else {
      const errMsg = data?.message || "提交失败，请重试。";
      showErrorDialog(errMsg);
    }
  } catch (err) {
    showErrorDialog(err.message || "网络请求失败，请检查连接后重试。");
  } finally {
    setGenerating(false);
  }
});

// Beforeunload warning
let pendingSubmit = false;
form?.addEventListener("submit", () => { pendingSubmit = true; });
form?.addEventListener("input", () => { pendingSubmit = false; });
window.addEventListener("beforeunload", (event) => {
  if (isGenerating || pendingSubmit) event.returnValue = "请求仍在处理中，关闭页面可能导致当前提交中断。";
});

// ── Prompt Optimize ──
const optimizeBtn = document.querySelector("#optimizePromptBtn");
const promptTextarea = document.querySelector('textarea[name="prompt"]');
const optimizeStatus = document.querySelector("#optimizePromptStatus");
if (optimizeBtn && promptTextarea) {
  optimizeBtn.addEventListener("click", async () => {
    const raw = promptTextarea.value.trim();
    if (!raw) {
      if (optimizeStatus) { optimizeStatus.textContent = "请先输入提示词"; optimizeStatus.className = "field-hint is-error"; }
      promptTextarea.focus();
      return;
    }
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

// ── Result dialog helpers (shared) ──

const showResultDialog = (record) => {
  const proxyCard = {
    dataset: {
      recordId: record.id || "",
      status: record.status || "succeeded",
      mode: "video",
      model: record.model || "",
      prompt: record.prompt || "",
      size: record.size || "auto",
      quality: record.quality || "",
      format: record.format || "mp4",
      credits: record.credits_charged || 0,
      created: record.created_at || "",
      finished: record.finished_at || "-",
      error: record.error_message || "",
      inputCount: "0",
      videoSrc: record.video_src || "",
    },
    querySelector: (sel) => {
      // 支持 querySelector 以便 openRecordDialog 读取 video/image 元素
      if (sel === "video" && record.video_src) {
        const v = document.createElement("video");
        v.src = record.video_src;
        v.controls = true;
        return v;
      }
      return null;
    }
  };

  // Use the shared openRecordDialog from user.js if available
  if (typeof window.openRecordDialog === "function") {
    window.openRecordDialog(proxyCard);
  } else {
    // Fallback: just show the card
    showMessage("生成完成！", "success");
  }

  const dialog = document.querySelector("#recordDialog");
  if (dialog) dialog.dataset.refreshOnClose = "1";
};

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

// 暴露给 inline onclick/oninput 调用
window.updateCost = updateCost;
})();
