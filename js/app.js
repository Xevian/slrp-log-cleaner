'use strict';

// ── Preset definitions (must match LogFilter::PRESETS in PHP) ──
const PRESETS = {
  light:  ['status', 'music'],
  medium: ['status', 'system', 'music', 'deliveries', 'combat', 'rptool', 'objects'],
  full:   ['status', 'system', 'music', 'deliveries', 'combat', 'rptool', 'ooc_full', 'ooc_inline', 'objects'],
};

const FILTER_LABELS = {
  status:     'Online/Offline',
  system:     'System Messages',
  music:      'Now Playing',
  deliveries: 'Item Deliveries',
  combat:     'Combat (CCS/MTR)',
  rptool:     'RP Tool',
  ooc_full:   'OOC Comments (( ))',
  ooc_inline: 'Strip Inline OOC',
  objects:    'Object Notices',
};

// ── State ──
let inputMode     = 'upload';   // 'upload' | 'paste'
let currentPreset = 'medium';
let customPatterns = [];
let lastResult    = null;
let droppedFile   = null;       // file from drag-and-drop (not in input.files)

// ── DOM refs ──
const $ = id => document.getElementById(id);

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
  buildFilterCheckboxes();
  setPreset('medium');
  bindEvents();
  loadCustomPatterns();
  showEmpty();
});

function bindEvents() {
  // Mode tabs
  $('tab-upload').addEventListener('click', () => setMode('upload'));
  $('tab-paste').addEventListener('click',  () => setMode('paste'));

  // Preset tabs
  document.querySelectorAll('.preset-tab').forEach(btn => {
    btn.addEventListener('click', () => setPreset(btn.dataset.preset));
  });

  // Individual checkbox changes → switch to custom
  document.getElementById('filter-grid').addEventListener('change', e => {
    if (e.target.type === 'checkbox' && e.target.dataset.filter) {
      setPreset('custom', false); // false = don't re-check boxes
    }
  });

  // File upload zone
  const zone = $('upload-zone');
  const fileInput = $('file-input');

  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop',      e => {
    e.preventDefault();
    zone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { droppedFile = file; handleFileSelect(file); }
  });
  fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) { droppedFile = null; handleFileSelect(fileInput.files[0]); }
  });

  // Custom patterns
  $('add-pattern-btn').addEventListener('click', addCustomPattern);
  $('pattern-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') addCustomPattern();
  });

  // Submit
  $('clean-btn').addEventListener('click', submitForm);

  // Copy & Download
  $('copy-btn').addEventListener('click', copyOutput);
  $('download-btn').addEventListener('click', downloadOutput);
}

// ── Input mode ──
function setMode(mode) {
  inputMode = mode;
  $('tab-upload').classList.toggle('active', mode === 'upload');
  $('tab-paste').classList.toggle('active',  mode === 'paste');
  $('upload-section').style.display = mode === 'upload' ? '' : 'none';
  $('paste-section').style.display  = mode === 'paste'  ? '' : 'none';
}

function handleFileSelect(file) {
  $('file-name').textContent = file.name + ' (' + formatBytes(file.size) + ')';
}

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

// ── Preset ──
function setPreset(preset, applyChecks = true) {
  currentPreset = preset;

  document.querySelectorAll('.preset-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.preset === preset);
  });

  if (applyChecks && preset !== 'custom') {
    const active = PRESETS[preset] || [];
    document.querySelectorAll('#filter-grid input[type=checkbox][data-filter]').forEach(cb => {
      cb.checked = active.includes(cb.dataset.filter);
    });
  }
}

// ── Filter checkboxes ──
function buildFilterCheckboxes() {
  const grid = $('filter-grid');
  grid.innerHTML = '';

  Object.entries(FILTER_LABELS).forEach(([key, label]) => {
    const item = document.createElement('label');
    item.className = 'filter-item';
    item.innerHTML = `
      <input type="checkbox" data-filter="${key}">
      <span class="filter-label">${label}</span>
    `;
    grid.appendChild(item);
  });

  // Merge splits toggle (full width, below grid)
  const mergeSplitsWrap = document.createElement('label');
  mergeSplitsWrap.className = 'filter-item full-width';
  mergeSplitsWrap.innerHTML = `
    <input type="checkbox" id="merge-splits" checked>
    <span class="filter-label">Merge split posts (same speaker, same minute)</span>
  `;
  grid.appendChild(mergeSplitsWrap);
}

function getActiveFilters() {
  const filters = [];
  document.querySelectorAll('#filter-grid input[type=checkbox][data-filter]').forEach(cb => {
    if (cb.checked) filters.push(cb.dataset.filter);
  });
  return filters;
}

// ── Custom patterns ──
function loadCustomPatterns() {
  try {
    const saved = localStorage.getItem('slrp_custom_filters');
    if (saved) customPatterns = JSON.parse(saved);
  } catch {}
  renderCustomPatterns();
}

function saveCustomPatterns() {
  try { localStorage.setItem('slrp_custom_filters', JSON.stringify(customPatterns)); } catch {}
}

function addCustomPattern() {
  const input = $('pattern-input');
  const val   = input.value.trim();
  if (!val || customPatterns.includes(val)) return;
  customPatterns.push(val);
  renderCustomPatterns();
  saveCustomPatterns();
  input.value = '';
}

function removeCustomPattern(idx) {
  customPatterns.splice(idx, 1);
  renderCustomPatterns();
  saveCustomPatterns();
}

function renderCustomPatterns() {
  const list = $('pattern-list');
  list.innerHTML = '';
  customPatterns.forEach((pat, i) => {
    const li = document.createElement('li');
    li.innerHTML = `<span>${escapeHtml(pat)}</span>
      <button class="btn-remove" title="Remove">×</button>`;
    li.querySelector('.btn-remove').addEventListener('click', () => removeCustomPattern(i));
    list.appendChild(li);
  });
}

// ── Submit ──
async function submitForm() {
  const btn = $('clean-btn');
  btn.disabled = true;

  showLoading();

  const formData = new FormData();
  formData.append('preset', currentPreset);
  formData.append('filters', JSON.stringify(getActiveFilters()));
  formData.append('custom_filters', JSON.stringify(customPatterns));
  formData.append('merge_splits', $('merge-splits')?.checked ? '1' : '0');
  formData.append('min_posts', parseInt($('min-posts')?.value, 10) || 2);

  if (inputMode === 'upload') {
    const file = $('file-input').files[0] || droppedFile;
    if (!file) {
      showToast('Please select or drop a file to upload.');
      btn.disabled = false;
      showEmpty();
      return;
    }
    formData.append('logfile', file);
  } else {
    const text = $('paste-input').value.trim();
    if (!text) {
      showToast('Please paste log text first.');
      btn.disabled = false;
      showEmpty();
      return;
    }
    formData.append('text', text);
  }

  try {
    const resp = await fetch('process.php', { method: 'POST', body: formData });
    const data = await resp.json();

    if (!data.success) {
      showToast(data.error || 'Processing failed.');
      showEmpty();
    } else {
      lastResult = data;
      renderResults(data);
    }
  } catch (err) {
    showToast('Network error: ' + err.message);
    showEmpty();
  } finally {
    btn.disabled = false;
  }
}

// ── Render results ──
function renderResults(data) {
  const resultsWrap = $('results-wrap');
  const emptyState  = $('empty-state');
  emptyState.style.display  = 'none';
  resultsWrap.style.display = 'flex';

  // Stats bar
  const stats = data.stats;
  $('stat-duration').textContent    = formatMinutes(stats.duration_minutes);
  $('stat-participants').textContent = stats.participants.length;
  $('stat-posts').textContent       = stats.participants.reduce((a, p) => a + p.post_count, 0);

  // Summary block
  const summaryEl = $('summary-block');
  summaryEl.textContent = data.summary;

  // Log output with syntax highlights
  const logEl = $('log-output');
  logEl.innerHTML = highlightLog(data.cleaned_log);
}

function highlightLog(text) {
  if (!text) return '';
  const lines = text.split('\n');
  return lines.map(line => {
    // [HH:MM] *Name action  or  [HH:MM] Name: content
    const actionMatch = line.match(/^(\[\d{2}:\d{2}\]) (\*.+?)( .+)?$/);
    const speechMatch = line.match(/^(\[\d{2}:\d{2}\]) (.+?)(: )(.*)$/);

    if (actionMatch) {
      const [, time, speaker, rest = ''] = actionMatch;
      return `<span class="time-tag">${escapeHtml(time)}</span> <span class="action">${escapeHtml(speaker + rest)}</span>`;
    }
    if (speechMatch) {
      const [, time, speaker, colon, content] = speechMatch;
      return `<span class="time-tag">${escapeHtml(time)}</span> <span class="speaker">${escapeHtml(speaker)}${colon}</span>${escapeHtml(content)}`;
    }
    return escapeHtml(line);
  }).join('\n');
}

function formatMinutes(mins) {
  if (!mins) return '0 min';
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h === 0) return m + 'm';
  if (m === 0) return h + 'h';
  return h + 'h ' + m + 'm';
}

// ── Copy / Download ──
function copyOutput() {
  if (!lastResult) return;
  const text = lastResult.summary + lastResult.cleaned_log;
  navigator.clipboard.writeText(text).then(() => {
    const btn = $('copy-btn');
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => { btn.textContent = orig; }, 1800);
  }).catch(() => showToast('Copy failed — try selecting and copying manually.'));
}

function downloadOutput() {
  if (!lastResult) return;
  const text     = lastResult.summary + lastResult.cleaned_log;
  const blob     = new Blob([text], { type: 'text/plain;charset=utf-8' });
  const url      = URL.createObjectURL(blob);
  const a        = document.createElement('a');
  a.href         = url;
  a.download     = 'cleaned-log.txt';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ── UI states ──
function showLoading() {
  $('empty-state').style.display  = '';
  $('results-wrap').style.display = 'none';
  $('empty-state').innerHTML = `
    <div class="spinner"></div>
    <p>Processing log…</p>
  `;
}

function showEmpty() {
  $('empty-state').style.display  = '';
  $('results-wrap').style.display = 'none';
  $('empty-state').innerHTML = `
    <div class="big-icon">📜</div>
    <p>Paste or upload a Second Life chat log,<br>then click <strong>Clean Log</strong>.</p>
  `;
}

function showToast(msg) {
  const existing = document.querySelector('.toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = msg;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

// ── Utils ──
function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
