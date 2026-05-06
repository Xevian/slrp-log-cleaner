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
let inputMode       = 'upload';  // 'upload' | 'paste'
let currentPreset   = 'medium';
let customPatterns  = [];
let ignoredSpeakers = [];        // [{key, label}, ...]
let lastResult      = null;
let droppedFile     = null;      // file from drag-and-drop

// ── DOM refs ──
const $ = id => document.getElementById(id);

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
  buildFilterCheckboxes();
  setPreset('medium');
  bindEvents();
  loadCustomPatterns();
  loadIgnoredSpeakers();
  showEmpty();
});

function bindEvents() {
  $('tab-upload').addEventListener('click', () => setMode('upload'));
  $('tab-paste').addEventListener('click',  () => setMode('paste'));

  document.querySelectorAll('.preset-tab').forEach(btn => {
    btn.addEventListener('click', () => setPreset(btn.dataset.preset));
  });

  document.getElementById('filter-grid').addEventListener('change', e => {
    if (e.target.type === 'checkbox' && e.target.dataset.filter) {
      setPreset('custom', false);
    }
  });

  const zone      = $('upload-zone');
  const fileInput = $('file-input');
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { droppedFile = file; handleFileSelect(file); }
  });
  fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) { droppedFile = null; handleFileSelect(fileInput.files[0]); }
  });

  $('add-pattern-btn').addEventListener('click', addCustomPattern);
  $('pattern-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') addCustomPattern();
  });

  $('clean-btn').addEventListener('click', submitForm);
  $('copy-btn').addEventListener('click', copyOutput);
  $('download-btn').addEventListener('click', downloadOutput);
  $('ignore-btn').addEventListener('click', ignoreSelected);
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
    item.innerHTML = `<input type="checkbox" data-filter="${key}"><span class="filter-label">${label}</span>`;
    grid.appendChild(item);
  });
  const mergeSplitsWrap = document.createElement('label');
  mergeSplitsWrap.className = 'filter-item full-width';
  mergeSplitsWrap.innerHTML = `<input type="checkbox" id="merge-splits" checked><span class="filter-label">Merge split posts (same speaker, same minute)</span>`;
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
    li.innerHTML = `<span>${escapeHtml(pat)}</span><button class="btn-remove" title="Remove">x</button>`;
    li.querySelector('.btn-remove').addEventListener('click', () => removeCustomPattern(i));
    list.appendChild(li);
  });
}

// ── Ignored speakers ──
function loadIgnoredSpeakers() {
  try {
    const saved = localStorage.getItem('slrp_ignored_speakers');
    if (saved) ignoredSpeakers = JSON.parse(saved);
  } catch {}
  renderIgnoredList();
}

function saveIgnoredSpeakers() {
  try { localStorage.setItem('slrp_ignored_speakers', JSON.stringify(ignoredSpeakers)); } catch {}
}

function removeIgnored(idx) {
  ignoredSpeakers.splice(idx, 1);
  saveIgnoredSpeakers();
  renderIgnoredList();
}

function renderIgnoredList() {
  const section = $('ignored-section');
  const list    = $('ignored-list');
  if (!section || !list) return;
  list.innerHTML = '';
  section.style.display = ignoredSpeakers.length > 0 ? '' : 'none';
  ignoredSpeakers.forEach((s, i) => {
    const li = document.createElement('li');
    li.innerHTML = `<span>${escapeHtml(s.label)}</span><button class="btn-remove" title="Remove">x</button>`;
    li.querySelector('.btn-remove').addEventListener('click', () => removeIgnored(i));
    list.appendChild(li);
  });
}

function ignoreSelected() {
  const checked = document.querySelectorAll('.participant-check:checked');
  let changed = false;
  checked.forEach(cb => {
    const key   = cb.dataset.speakerKey;
    const label = cb.dataset.label;
    if (!ignoredSpeakers.find(s => s.key === key)) {
      ignoredSpeakers.push({ key, label });
      changed = true;
    }
  });
  if (changed) {
    saveIgnoredSpeakers();
    renderIgnoredList();
  }
  submitForm();
}

function updateIgnoreButton() {
  const checked = document.querySelectorAll('.participant-check:checked');
  const bar     = $('ignore-bar');
  const countEl = $('ignore-count');
  if (!bar) return;
  bar.style.display = checked.length > 0 ? '' : 'none';
  if (countEl) countEl.textContent = checked.length + ' selected';
}

// ── Submit ──
async function submitForm() {
  const btn = $('clean-btn');
  btn.disabled = true;
  showLoading();

  const formData = new FormData();
  formData.append('preset',           currentPreset);
  formData.append('filters',          JSON.stringify(getActiveFilters()));
  formData.append('custom_filters',   JSON.stringify(customPatterns));
  formData.append('ignored_speakers', JSON.stringify(ignoredSpeakers.map(s => s.key)));
  formData.append('merge_splits',     $('merge-splits')?.checked ? '1' : '0');
  formData.append('min_posts',        parseInt($('min-posts')?.value, 10) || 2);

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
  $('empty-state').style.display  = 'none';
  $('results-wrap').style.display = 'flex';

  const stats = data.stats;
  $('stat-duration').textContent     = formatMinutes(stats.duration_minutes);
  $('stat-participants').textContent = stats.participants.length;
  $('stat-posts').textContent        = stats.participants.reduce((a, p) => a + p.post_count, 0);

  renderSummaryBlock(data);
  renderLog(data.cleaned_log, stats.participants);
}

function renderSummaryBlock(data) {
  const stats = data.stats;
  const lines = [];
  if (stats.start) {
    const year = stats.start.slice(0, 4);
    if (year !== '2000') {
      const d = stats.start.slice(0, 10).split('-');
      lines.push('DATE: ' + d[2] + '/' + d[1] + '/' + d[0]);
    }
    lines.push('TIME: ' + stats.start.slice(11, 16) + ' - ' + (stats.end ? stats.end.slice(11, 16) : '?'));
  }
  lines.push('DURATION: ' + formatDurationLong(stats.duration_minutes));
  $('summary-header-pre').textContent = lines.join('\n');
  renderParticipantTable(stats.participants);
}

function renderParticipantTable(participants) {
  const table = $('participant-table');
  table.innerHTML = '';

  if (!participants || participants.length === 0) {
    const row = document.createElement('div');
    row.className = 'ptable-row';
    row.style.paddingLeft = '20px';
    row.style.color = 'var(--text-dim)';
    row.textContent = '(none above minimum post threshold)';
    table.appendChild(row);
    updateIgnoreButton();
    return;
  }

  const header = document.createElement('div');
  header.className = 'ptable-row ptable-header';
  header.innerHTML =
    '<span class="ptable-check"></span>' +
    '<span class="ptable-name">Name</span>' +
    '<span class="ptable-posts">Posts</span>' +
    '<span class="ptable-est">Est.</span>' +
    '<span class="ptable-arrived">Arrived</span>';
  table.appendChild(header);

  const divider = document.createElement('div');
  divider.className = 'ptable-divider';
  table.appendChild(divider);

  participants.forEach(p => {
    const label      = p.username ? p.display_name + ' (' + p.username + ')' : p.display_name;
    const speakerKey = (p.username || p.display_name).toLowerCase();
    const arrived    = p.first_post ? p.first_post.slice(11, 16) : '-';
    const est        = formatMinutes(p.duration_minutes);

    const row = document.createElement('div');
    row.className = 'ptable-row';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'participant-check';
    cb.dataset.speakerKey = speakerKey;
    cb.dataset.label = label;
    cb.addEventListener('change', updateIgnoreButton);

    const checkWrap = document.createElement('span');
    checkWrap.className = 'ptable-check';
    checkWrap.appendChild(cb);

    const nameEl = document.createElement('span');
    nameEl.className = 'ptable-name ptable-name-link';
    nameEl.textContent = label;
    nameEl.title = 'Jump to first line';
    nameEl.addEventListener('click', () => scrollToSpeaker(speakerKey));

    const postsEl = document.createElement('span');
    postsEl.className = 'ptable-posts';
    postsEl.textContent = p.post_count;

    const estEl = document.createElement('span');
    estEl.className = 'ptable-est';
    estEl.textContent = est;

    const arrivedEl = document.createElement('span');
    arrivedEl.className = 'ptable-arrived';
    arrivedEl.textContent = arrived;

    row.appendChild(checkWrap);
    row.appendChild(nameEl);
    row.appendChild(postsEl);
    row.appendChild(estEl);
    row.appendChild(arrivedEl);
    table.appendChild(row);
  });

  updateIgnoreButton();
}

// ── Log rendering with per-speaker anchors ──
function renderLog(text, participants) {
  const el = $('log-output');
  if (!text) { el.innerHTML = ''; return; }

  // Map exact display_name -> speakerKey.
  // Both the participant JSON and the log lines use the same PHP displayName field,
  // so they are byte-identical — no toLowerCase() needed (which breaks Unicode names).
  const nameToKey = {};
  (participants || []).forEach(p => {
    nameToKey[p.display_name] = (p.username || p.display_name).toLowerCase();
  });
  // Sort longest-first so multi-word names don't get shadowed by a shorter prefix.
  const knownNames = Object.keys(nameToKey).sort((a, b) => b.length - a.length);

  // Extract the speaker key for a single log line using exact string matching.
  function extractKey(line) {
    // Speech: [HH:MM] ExactDisplayName: content
    const sm = line.match(/^\[\d{2}:\d{2}\] (.+?): /);
    if (sm && nameToKey[sm[1]] !== undefined) return nameToKey[sm[1]];

    // Action: [HH:MM] *ExactDisplayName rest
    const am = line.match(/^\[\d{2}:\d{2}\] \*(.+)$/);
    if (am) {
      const rest = am[1];
      for (const name of knownNames) {
        if (rest === name || rest.startsWith(name + ' ')) return nameToKey[name];
      }
    }
    return null;
  }

  const seen = new Set();
  const html = text.split('\n').map(line => {
    let anchor = '';
    const key = extractKey(line);
    if (key !== null && !seen.has(key)) {
      seen.add(key);
      anchor = '<span id="log-' + speakerCssId(key) + '"></span>';
    }

    const actionMatch = line.match(/^(\[\d{2}:\d{2}\]) (\*.+)$/);
    const speechMatch = line.match(/^(\[\d{2}:\d{2}\]) (.+?)(: )(.*)$/);

    if (actionMatch) {
      const [, time, rest] = actionMatch;
      return anchor +
        '<span class="time-tag">' + escapeHtml(time) + '</span> ' +
        '<span class="action">' + escapeHtml(rest) + '</span>';
    }
    if (speechMatch) {
      const [, time, name, colon, content] = speechMatch;
      return anchor +
        '<span class="time-tag">' + escapeHtml(time) + '</span> ' +
        '<span class="speaker">' + escapeHtml(name) + colon + '</span>' +
        escapeHtml(content);
    }
    return anchor + escapeHtml(line);
  }).join('\n');

  el.innerHTML = html;
}

function scrollToSpeaker(key) {
  const anchor = document.getElementById('log-' + speakerCssId(key));
  if (!anchor) return;
  const pre = $('log-output');
  const aRect = anchor.getBoundingClientRect();
  const pRect = pre.getBoundingClientRect();
  pre.scrollTo({ top: pre.scrollTop + aRect.top - pRect.top - 12, behavior: 'smooth' });
}

function speakerCssId(key) {
  return key.replace(/[^a-z0-9]/g, '_');
}

// ── Formatters ──
function formatMinutes(mins) {
  if (!mins) return '0m';
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h === 0) return m + 'm';
  if (m === 0) return h + 'h';
  return h + 'h ' + m + 'm';
}

function formatDurationLong(mins) {
  if (!mins) return '0 minutes';
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  const hLabel = h === 1 ? 'hour' : 'hours';
  const mLabel = m === 1 ? 'minute' : 'minutes';
  if (h === 0) return m + ' ' + mLabel;
  if (m === 0) return h + ' ' + hLabel;
  return h + ' ' + hLabel + ' ' + m + ' ' + mLabel;
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
  }).catch(() => showToast('Copy failed - try selecting and copying manually.'));
}

function downloadOutput() {
  if (!lastResult) return;
  const text = lastResult.summary + lastResult.cleaned_log;
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = 'cleaned-log.txt';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ── UI states ──
function showLoading() {
  $('empty-state').style.display  = '';
  $('results-wrap').style.display = 'none';
  $('empty-state').innerHTML = '<div class="spinner"></div><p>Processing log...</p>';
}

function showEmpty() {
  $('empty-state').style.display  = '';
  $('results-wrap').style.display = 'none';
  $('empty-state').innerHTML =
    '<div class="big-icon">' + String.fromCodePoint(0x1F4DC) + '</div>' +
    '<p>Paste or upload a Second Life chat log,<br>then click <strong>Clean Log</strong>.</p>';
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
