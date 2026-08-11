/* AI Models Admin - Pure JS, no PHP, ASCII-only */

var modelDataMap = {};
try {
    var raw = document.getElementById('modelData').value;
    modelDataMap = JSON.parse(raw);
} catch(e) {
    console.error('modelDataMap parse error:', e);
}

var editModal = document.getElementById('editModal');
var createModal = document.getElementById('createModal');

/* --- Modal Control --- */

function openCreateModal() {
    if (!createModal) return;
    var form = document.getElementById('createForm');
    if (form) form.reset();
    var el;
    el = document.querySelector('#createForm input[name="sort_order"]'); if (el) el.value = '0';
    el = document.querySelector('#createForm input[name="timeout"]'); if (el) el.value = '0';
    el = document.querySelector('#createForm input[name="download_timeout"]'); if (el) el.value = '0';
    el = document.querySelector('#createForm input[name="watermark_point_cost"]'); if (el) el.value = '0';
    el = document.querySelector('#createForm select[name="model_type"]'); if (el) el.value = 'image';
    el = document.querySelector('#createForm select[name="site_type"]'); if (el) el.value = 'standard';
    document.querySelectorAll('#createForm input[data-agnes-res-check]').forEach(function(cb, i) { cb.checked = (i === 0); });
    document.querySelectorAll('#createForm input[name^="agnes_"][name$="_credits"]').forEach(function(input, i) { input.value = [5, 10, 20][i] || 5; });
    el = document.querySelector('#createForm select[name="agnes_duration_mode"]'); if (el) el.value = 'custom';
    el = document.querySelector('#createForm input[name="agnes_max_seconds"]'); if (el) el.value = '15';
    el = document.querySelector('#createForm input[name="agnes_price_per_second"]'); if (el) el.value = '1';
    el = document.querySelector('#createForm input[name="agnes_tier_count"]'); if (el) el.value = '3';
    agnesTierIdx = 3;
    if (typeof toggleAgnesDurationFields === 'function') toggleAgnesDurationFields();
    if (typeof toggleResolutionFields === 'function') toggleResolutionFields();
    createModal.style.display = 'flex';
}

function closeCreateModal() {
    if (createModal) createModal.style.display = 'none';
}

function openEditModal(id) {
    var m = modelDataMap[id];
    if (!m) { alert('Model data not found: ' + id); return; }
    var set = function(elId, val) { var el = document.getElementById(elId); if (el) el.value = val; };
    set('editId', id);
    set('editSort', m.sort_order);
    set('editName', m.name);
    set('editModelId', m.model_id);
    set('editUrl', m.base_url);
    set('editApiPath', m.api_path || '');
    set('editEditApiPath', m.edit_api_path || '');
    var apiPathEl = document.getElementById('editApiPath');
    if (apiPathEl) { delete apiPathEl.dataset.userEdited; delete apiPathEl.dataset.originalSet; }
    set('editTimeout', m.timeout || 0);
    set('editDownloadTimeout', m.download_timeout || 0);
    set('editSiteType', m.site_type || 'standard');
    set('editProxyType', m.download_proxy_type || 'none');
    set('editProxyUrl', m.download_proxy_url || '');
    toggleProxyUrl(document.getElementById('editProxyType'), 'edit');

    /* Agnes config */
    var agnesConfig = {};
    try { if (m.agnes_config) agnesConfig = JSON.parse(m.agnes_config); } catch(e) {}
    ['480p', '720p', '1080p'].forEach(function(res) {
        var cfg = agnesConfig[res] || {enabled: (res === '480p'), credits: 5};
        var checkEl = document.querySelector('[data-edit-agnes-res="' + res + '"]');
        if (checkEl) checkEl.checked = !!cfg.enabled;
        var creditEl = document.querySelector('[data-edit-agnes-credits="' + res + '"]');
        if (creditEl) creditEl.value = cfg.credits || 5;
    });
    toggleEditSiteTypeConfig();

    var dur = (agnesConfig && agnesConfig._duration) || {mode: 'custom', max_seconds: 15, price_per_second: 1, tiers: []};
    set('editAgnesDurationMode', dur.mode || 'custom');
    set('editAgnesMaxSec', dur.max_seconds || 15);
    set('editAgnesPricePerSec', dur.price_per_second || 1);
    if (dur.mode === 'fixed' && dur.tiers && dur.tiers.length) {
        buildEditAgnesTierList(dur.tiers);
    } else {
        buildEditAgnesTierList(null);
    }
    toggleEditAgnesDurationFields();

    var adv = (agnesConfig && agnesConfig._advanced) || {};
    var advFps = document.getElementById('editAgnesAdvFps'); if (advFps) advFps.checked = !!adv.frame_rate;
    var advSteps = document.getElementById('editAgnesAdvSteps'); if (advSteps) advSteps.checked = !!adv.inference_steps;
    var advSeed = document.getElementById('editAgnesAdvSeed'); if (advSeed) advSeed.checked = !!adv.seed;
    var advNeg = document.getElementById('editAgnesAdvNeg'); if (advNeg) advNeg.checked = !!adv.negative_prompt;

    /* Grok config */
    var grokConfig = {};
    try { if (m.grok_config) grokConfig = JSON.parse(m.grok_config); } catch(e) {}
    ['480p', '720p'].forEach(function(res) {
        var cfg = grokConfig[res] || {enabled: true, credits: 5};
        var checkEl = document.querySelector('.edit-grok-res[data-res="' + res + '"]');
        if (checkEl) checkEl.checked = !!cfg.enabled;
        var creditEl = document.querySelector('.edit-grok-credits[data-res="' + res + '"]');
        if (creditEl) creditEl.value = cfg.credits || 5;
    });
    var gDur = (grokConfig && grokConfig._duration) || {max_seconds: 15, price_per_second: 2};
    set('editGrokPricePerSec', gDur.price_per_second || 2);
    set('editGrokMaxSec', gDur.max_seconds || 15);

    /* Seedance config */
    var seedanceConfig = {};
    try { if (m.seedance_config) seedanceConfig = JSON.parse(m.seedance_config); } catch(e) {}
    var seedanceDefaults = {
        '480p': {enabled: true, credits: 5},
        '720p': {enabled: true, credits: 10},
        '1080p': {enabled: false, credits: 15}
    };
    ['480p', '720p', '1080p'].forEach(function(res) {
        var cfg = seedanceConfig[res] || seedanceDefaults[res];
        var checkEl = document.querySelector('.edit-seedance-res[data-res="' + res + '"]');
        if (checkEl) checkEl.checked = !!cfg.enabled;
        var creditEl = document.querySelector('.edit-seedance-credits[data-res="' + res + '"]');
        if (creditEl) creditEl.value = cfg.credits || 5;
    });
    var sdDur = (seedanceConfig && seedanceConfig._duration) || {mode: 'fixed', max_seconds: 15, price_per_second: 2, tiers: []};
    set('editSeedanceDurationMode', sdDur.mode || 'fixed');
    set('editSeedancePricePerSec', sdDur.price_per_second || 2);
    set('editSeedanceMaxSec', sdDur.max_seconds || 15);
    var sdTierList = document.getElementById('editSeedanceTierList');
    var sdTiers = sdDur.tiers || [];
    if (sdTierList) {
        sdTierList.innerHTML = '';
        editSeedanceTierIdx = sdTiers.length;
        sdTiers.forEach(function(tier, i) {
            var row = document.createElement('div');
            row.className = 'field-grid edit-seedance-tier-row';
            row.innerHTML = '<label class="field"><span>T' + (i+1) + ' sec</span><input name="seedance_tier_' + (i+1) + '_sec" type="number" min="1" max="60" value="' + (tier.seconds||5) + '" placeholder="sec"></label>' +
                '<label class="field"><span>T' + (i+1) + ' credits</span><input name="seedance_tier_' + (i+1) + '_credits" type="number" min="1" value="' + (tier.credits||15) + '" placeholder="credits"></label>';
            sdTierList.appendChild(row);
        });
        set('editSeedanceTierCount', sdTiers.length);
    }
    toggleEditSeedanceDurationFields();

    set('editKey', '');
    set('editType', m.model_type);
    set('editActive', m.is_active);
    toggleEditResFields();
    set('editPrice1K', m.credits_1k || '');
    set('editPrice2K', m.credits_2k || '');
    set('editPrice4K', m.credits_4k || '');
    set('editCredits', m.credits || '');
    set('editWpCost', m.watermark_point_cost || 0);

    var levels = (m.resolution_levels || '1K').split(',').map(function(s) { return s.trim(); });
    document.querySelectorAll('.edit-res-check').forEach(function(cb) {
        cb.checked = levels.indexOf(cb.value) >= 0;
    });

    toggleEditResFields();
    editModal.style.display = 'flex';
}

function closeEditModal() {
    if (editModal) editModal.style.display = 'none';
}

/* --- Field Visibility --- */

function toggleResolutionFields() {
    var select = document.getElementById('modelTypeSelect');
    var siteSelect = document.getElementById('siteTypeSelect');
    var isImage = select && select.value === 'image';
    var isVideo = select && select.value === 'video';
    var isChat = select && select.value === 'chat';
    var isImageOrVideo = isImage || isVideo;
    document.querySelectorAll('.resolution-only:not(.edit-res-field)').forEach(function(el) { el.style.display = isImage ? '' : 'none'; });
    document.querySelectorAll('.credits-fields').forEach(function(el) { el.style.display = isImage ? '' : 'none'; });
    document.querySelectorAll('.non-image-credits').forEach(function(el) { el.style.display = isImage ? 'none' : ''; });
    document.querySelectorAll('.site-type-row').forEach(function(el) { el.style.display = isImageOrVideo ? '' : 'none'; });
    if (siteSelect) {
        var prevVal = siteSelect.value;
        siteSelect.querySelectorAll('option').forEach(function(opt) {
            var v = opt.value;
            if (isChat) { opt.style.display = (v === 'standard') ? '' : 'none'; }
            else if (isImage) { opt.style.display = (v === 'grok') ? 'none' : ''; }
            else { opt.style.display = ''; }
        });
        var curOpt = siteSelect.querySelector('option[value="' + prevVal + '"]');
        if (curOpt && curOpt.style.display === 'none') { siteSelect.value = 'standard'; }
    }
    toggleSiteTypeConfig();
}

function toggleProxyUrl(selectEl, form) {
    if (!selectEl) return;
    var customFields = selectEl.closest('.grok-config-section')
        ? selectEl.closest('.grok-config-section').querySelectorAll('.grok-proxy-custom-field')
        : document.querySelectorAll('.grok-proxy-custom-field');
    customFields.forEach(function(el) { el.style.display = selectEl.value === 'custom' ? '' : 'none'; });
}

function toggleSiteTypeConfig() {
    var siteSelect = document.getElementById('siteTypeSelect');
    var modelSelect = document.getElementById('modelTypeSelect');
    var isVideo = modelSelect && modelSelect.value === 'video';
    var isAgnes = siteSelect && siteSelect.value === 'agnes' && isVideo;
    var isGrok = siteSelect && siteSelect.value === 'grok' && isVideo;
    var isSeedance = siteSelect && siteSelect.value === 'seedance' && isVideo;
    document.querySelectorAll('.agnes-config-section').forEach(function(el) { el.style.display = isAgnes ? '' : 'none'; });
    document.querySelectorAll('.grok-config-section').forEach(function(el) { el.style.display = isGrok ? '' : 'none'; });
    document.querySelectorAll('.seedance-config-section').forEach(function(el) { el.style.display = isSeedance ? '' : 'none'; });
    document.querySelectorAll('.edit-api-path-field').forEach(function(el) { el.style.display = isGrok ? 'none' : ''; });
    var apiPathEl = document.getElementById('apiPath');
    if (apiPathEl) {
        if (isGrok) {
            if (!apiPathEl.dataset.userEdited || apiPathEl.value === '' || apiPathEl.value === 'v1/images/generations') {
                apiPathEl.value = 'v1/video/generations';
                apiPathEl.dataset.userEdited = 'false';
            }
        } else if (apiPathEl.dataset.userEdited === 'false') {
            apiPathEl.value = '';
            delete apiPathEl.dataset.userEdited;
        }
    }
    if (isAgnes) toggleAgnesDurationFields();
}

function toggleAgnesDurationFields() {
    var modeSelect = document.getElementById('agnesDurationMode');
    var isFixed = modeSelect && modeSelect.value === 'fixed';
    document.querySelectorAll('.agnes-duration-custom').forEach(function(el) { el.style.display = isFixed ? 'none' : ''; });
    document.querySelectorAll('.agnes-duration-fixed').forEach(function(el) { el.style.display = isFixed ? '' : 'none'; });
}

function toggleSeedanceDurationFields() {
    var modeSelect = document.getElementById('seedanceDurationMode');
    var isFixed = modeSelect && modeSelect.value === 'fixed';
    document.querySelectorAll('.seedance-duration-fixed').forEach(function(el) { el.style.display = isFixed ? '' : 'none'; });
    document.querySelectorAll('.seedance-duration-custom').forEach(function(el) { el.style.display = isFixed ? 'none' : ''; });
}

function toggleEditResFields() {
    var typeEl = document.getElementById('editType');
    if (!typeEl) return;
    var isImage = typeEl.value === 'image';
    var isVideo = typeEl.value === 'video';
    var isChat = typeEl.value === 'chat';
    var isImageOrVideo = isImage || isVideo;
    document.querySelectorAll('.edit-res-field').forEach(function(el) { el.style.display = isImage ? '' : 'none'; });
    document.querySelectorAll('.edit-credits-fields').forEach(function(el) { el.style.display = isImage ? '' : 'none'; });
    document.querySelectorAll('.edit-non-image-credits').forEach(function(el) { el.style.display = isImage ? 'none' : ''; });
    document.querySelectorAll('.edit-site-type-row').forEach(function(el) { el.style.display = isImageOrVideo ? '' : 'none'; });
    var siteSelect = document.getElementById('editSiteType');
    if (siteSelect) {
        var prevVal = siteSelect.value;
        siteSelect.querySelectorAll('option').forEach(function(opt) {
            var v = opt.value;
            if (isChat) { opt.style.display = (v === 'standard') ? '' : 'none'; }
            else if (isImage) { opt.style.display = (v === 'grok') ? 'none' : ''; }
            else { opt.style.display = ''; }
        });
        var curOpt = siteSelect.querySelector('option[value="' + prevVal + '"]');
        if (curOpt && curOpt.style.display === 'none') { siteSelect.value = 'standard'; }
    }
    toggleEditSiteTypeConfig();
}

function toggleEditSiteTypeConfig() {
    var siteSelect = document.getElementById('editSiteType');
    var typeSelect = document.getElementById('editType');
    var isVideo = typeSelect && typeSelect.value === 'video';
    var isAgnes = siteSelect && siteSelect.value === 'agnes' && isVideo;
    var isGrok = siteSelect && siteSelect.value === 'grok' && isVideo;
    var isSeedance = siteSelect && siteSelect.value === 'seedance' && isVideo;
    document.querySelectorAll('.edit-agnes-config-section').forEach(function(el) { el.style.display = isAgnes ? '' : 'none'; });
    document.querySelectorAll('.grok-config-section').forEach(function(el) { el.style.display = isGrok ? '' : 'none'; });
    document.querySelectorAll('.seedance-config-section').forEach(function(el) { el.style.display = isSeedance ? '' : 'none'; });
    document.querySelectorAll('.edit-api-path-field').forEach(function(el) { el.style.display = isGrok ? 'none' : ''; });
    var apiPathEl = document.getElementById('editApiPath');
    if (apiPathEl) {
        if (isGrok) {
            if (!apiPathEl.dataset.originalSet) {
                apiPathEl.dataset.originalValue = apiPathEl.value;
                apiPathEl.dataset.originalSet = 'true';
            }
            if (!apiPathEl.dataset.userEdited || apiPathEl.value === '' || apiPathEl.value === apiPathEl.dataset.originalValue) {
                apiPathEl.value = 'v1/video/generations';
                apiPathEl.dataset.userEdited = 'false';
            }
        } else if (apiPathEl.dataset.userEdited === 'false') {
            apiPathEl.value = apiPathEl.dataset.originalValue || '';
            delete apiPathEl.dataset.userEdited;
        }
    }
    if (isAgnes) toggleEditAgnesDurationFields();
}

function toggleEditAgnesDurationFields() {
    var modeSelect = document.getElementById('editAgnesDurationMode');
    var isFixed = modeSelect && modeSelect.value === 'fixed';
    document.querySelectorAll('.edit-agnes-duration-custom').forEach(function(el) { el.style.display = isFixed ? 'none' : ''; });
    document.querySelectorAll('.edit-agnes-duration-fixed').forEach(function(el) { el.style.display = isFixed ? '' : 'none'; });
}

function toggleEditSeedanceDurationFields() {
    var modeSelect = document.getElementById('editSeedanceDurationMode');
    var isFixed = modeSelect && modeSelect.value === 'fixed';
    document.querySelectorAll('.edit-seedance-duration-fixed').forEach(function(el) { el.style.display = isFixed ? '' : 'none'; });
    document.querySelectorAll('.edit-seedance-duration-custom').forEach(function(el) { el.style.display = isFixed ? 'none' : ''; });
}

/* --- Tier Management --- */

var agnesTierIdx = 3;
function addAgnesTier() {
    agnesTierIdx++;
    var list = document.getElementById('agnesTierList');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'field-grid agnes-tier-row';
    row.innerHTML = '<label class="field"><span>T' + agnesTierIdx + ' sec</span><input name="agnes_tier_' + agnesTierIdx + '_sec" type="number" min="1" max="60" value="10" placeholder="sec"></label><label class="field"><span>T' + agnesTierIdx + ' credits</span><input name="agnes_tier_' + agnesTierIdx + '_credits" type="number" min="1" value="50" placeholder="credits"></label>';
    list.appendChild(row);
    var countEl = document.getElementById('agnesTierCount'); if (countEl) countEl.value = agnesTierIdx;
}

var editAgnesTierIdx = 3;
function addEditAgnesTier() {
    editAgnesTierIdx++;
    var list = document.getElementById('editAgnesTierList');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'field-grid agnes-tier-row';
    row.innerHTML = '<label class="field"><span>T' + editAgnesTierIdx + ' sec</span><input name="agnes_tier_' + editAgnesTierIdx + '_sec" type="number" min="1" max="60" value="10" placeholder="sec"></label><label class="field"><span>T' + editAgnesTierIdx + ' credits</span><input name="agnes_tier_' + editAgnesTierIdx + '_credits" type="number" min="1" value="50" placeholder="credits"></label>';
    list.appendChild(row);
    var countEl = document.getElementById('editAgnesTierCount'); if (countEl) countEl.value = editAgnesTierIdx;
}

function buildEditAgnesTierList(tiers) {
    var list = document.getElementById('editAgnesTierList');
    if (!list) return;
    list.innerHTML = '';
    if (!tiers || !tiers.length) {
        tiers = [{seconds: 3, credits: 15}, {seconds: 5, credits: 25}, {seconds: 10, credits: 45}];
    }
    editAgnesTierIdx = tiers.length;
    tiers.forEach(function(tier, i) {
        var idx = i + 1;
        var row = document.createElement('div');
        row.className = 'field-grid agnes-tier-row';
        row.innerHTML = '<label class="field"><span>T' + idx + ' sec</span><input name="agnes_tier_' + idx + '_sec" type="number" min="1" max="60" value="' + (tier.seconds || 5) + '" placeholder="sec"></label><label class="field"><span>T' + idx + ' credits</span><input name="agnes_tier_' + idx + '_credits" type="number" min="1" value="' + (tier.credits || 25) + '" placeholder="credits"></label>';
        list.appendChild(row);
    });
    var countEl = document.getElementById('editAgnesTierCount'); if (countEl) countEl.value = editAgnesTierIdx;
}

var seedanceTierIdx = 3;
function addSeedanceTier() {
    seedanceTierIdx++;
    var list = document.getElementById('seedanceTierList');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'field-grid seedance-tier-row';
    row.innerHTML = '<label class="field"><span>T' + seedanceTierIdx + ' sec</span><input name="seedance_tier_' + seedanceTierIdx + '_sec" type="number" min="1" max="60" value="10" placeholder="sec"></label><label class="field"><span>T' + seedanceTierIdx + ' credits</span><input name="seedance_tier_' + seedanceTierIdx + '_credits" type="number" min="1" value="30" placeholder="credits"></label>';
    list.appendChild(row);
    var countEl = document.getElementById('seedanceTierCount'); if (countEl) countEl.value = seedanceTierIdx;
}

var editSeedanceTierIdx = 3;
function addEditSeedanceTier() {
    editSeedanceTierIdx++;
    var list = document.getElementById('editSeedanceTierList');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'field-grid edit-seedance-tier-row';
    row.innerHTML = '<label class="field"><span>T' + editSeedanceTierIdx + ' sec</span><input name="seedance_tier_' + editSeedanceTierIdx + '_sec" type="number" min="1" max="60" value="10" placeholder="sec"></label><label class="field"><span>T' + editSeedanceTierIdx + ' credits</span><input name="seedance_tier_' + editSeedanceTierIdx + '_credits" type="number" min="1" value="30" placeholder="credits"></label>';
    list.appendChild(row);
    var countEl = document.getElementById('editSeedanceTierCount'); if (countEl) countEl.value = editSeedanceTierIdx;
}

/* --- Validation --- */

function validateResolution(form) {
    var checks = form.querySelectorAll('input[name="res_check[]"]:checked');
    if (checks.length === 0) {
        alert('Please select at least one resolution (1K / 2K / 4K).');
        return false;
    }
    return true;
}

function validateAgnesResolutions(form, type) {
    var siteSelect = form.querySelector('select[name="site_type"]');
    if (!siteSelect || siteSelect.value !== 'agnes') return true;
    var prefix = type === 'edit' ? '.edit-agnes-res-check' : '[data-agnes-res-check]';
    var checks = form.querySelectorAll(prefix);
    var anyChecked = false;
    checks.forEach(function(cb) { if (cb.checked) anyChecked = true; });
    if (!anyChecked) {
        alert('Agnes: at least one resolution required.');
        return false;
    }
    return true;
}

/* --- Event Bindings --- */

if (editModal) editModal.addEventListener('click', function(e) { if (e.target === editModal) closeEditModal(); });
if (createModal) createModal.addEventListener('click', function(e) { if (e.target === createModal) closeCreateModal(); });

document.addEventListener('DOMContentLoaded', function() {
    if (typeof toggleResolutionFields === 'function') toggleResolutionFields();
});

var cf = document.getElementById('createForm');
if (cf) cf.addEventListener('submit', function(e) {
    if (!validateResolution(this)) { e.preventDefault(); return false; }
    if (!validateAgnesResolutions(this, 'create')) { e.preventDefault(); return false; }
});

var ef = document.getElementById('editForm');
if (ef) ef.addEventListener('submit', function(e) {
    if (!validateResolution(this)) { e.preventDefault(); return false; }
    if (!validateAgnesResolutions(this, 'edit')) { e.preventDefault(); return false; }
});

document.addEventListener('change', function(e) {
    if (!e.target.matches('input[name="res_check[]"]')) return;
    var group = e.target.closest('#createForm') || e.target.closest('#editForm');
    if (!group) return;
    var checks = group.querySelectorAll('input[name="res_check[]"]:checked');
    if (checks.length === 0) {
        e.target.checked = true;
        alert('At least one resolution required.');
    }
});

console.log('[ai_models] JS loaded. modelDataMap keys:', Object.keys(modelDataMap).length);
