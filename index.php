<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SLRP Log Cleaner</title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div id="app">

  <header>
    <h1>SLRP Log Cleaner</h1>
    <span class="subtitle">Second Life roleplay log parser &amp; formatter</span>
    <a class="repo-link" href="https://github.com/Xevian/slrp-log-cleaner" target="_blank" rel="noopener">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
        <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38
          0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13
          -.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66
          .07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15
          -.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27
          .68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12
          .51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48
          0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
      </svg>
      GitHub
    </a>
  </header>

  <div class="columns">

    <!-- ── Left: Input + Filters ── -->
    <aside class="left-panel">

      <!-- Input mode -->
      <div class="section">
        <div class="section-title">Input</div>
        <div class="mode-tabs">
          <button id="tab-upload" class="mode-tab active">Upload File</button>
          <button id="tab-paste"  class="mode-tab">Paste Text</button>
        </div>

        <!-- File upload -->
        <div id="upload-section">
          <div id="upload-zone" class="upload-zone">
            <input type="file" id="file-input" accept=".txt,.log,text/plain">
            <span class="upload-icon">📂</span>
            <span>Drop a <strong>.txt</strong> log file here, or click to browse</span>
            <div id="file-name" class="file-name"></div>
          </div>
        </div>

        <!-- Paste -->
        <div id="paste-section" style="display:none">
          <textarea id="paste-input" placeholder="Paste your Second Life chat log here…"></textarea>
        </div>
      </div>

      <!-- Preset -->
      <div class="section">
        <div class="section-title">Filter Preset</div>
        <div class="preset-tabs">
          <button class="preset-tab" data-preset="light">Light</button>
          <button class="preset-tab active" data-preset="medium">Medium</button>
          <button class="preset-tab" data-preset="full">Full</button>
          <button class="preset-tab" data-preset="custom">Custom</button>
        </div>

        <!-- Filter checkboxes (built dynamically by JS) -->
        <div id="filter-grid" class="filter-grid"></div>
      </div>

      <!-- Ignored speakers -->
      <div class="section" id="ignored-section" style="display:none">
        <div class="section-title">Ignored Speakers</div>
        <ul id="ignored-list" class="custom-pattern-list"></ul>
        <p style="font-size:11px;color:var(--text-dim);margin-top:8px;">
          These speakers are hidden from the log and stats. Click a participant's checkbox to add them.
        </p>
      </div>

      <!-- Custom patterns -->
      <div class="section">
        <div class="section-title">Custom Filters</div>
        <div class="custom-pattern-row">
          <input type="text" id="pattern-input" placeholder="Speaker name, keyword, or /regex/">
          <button id="add-pattern-btn" class="btn btn-secondary btn-small">Add</button>
        </div>
        <ul id="pattern-list" class="custom-pattern-list"></ul>
        <p style="font-size:11px;color:var(--text-dim);margin-top:8px;">
          Match against speaker&nbsp;+&nbsp;content. Use <code>/pattern/i</code> for regex.
        </p>
      </div>

      <!-- Output options -->
      <div class="section">
        <div class="section-title">Output Options</div>
        <label class="filter-item full-width" style="grid-column:unset">
          <span class="filter-label" style="margin-right:8px">Min. posts to appear in summary</span>
          <input type="number" id="min-posts" value="2" min="1" max="99"
                 style="width:52px;background:var(--panel-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:3px 6px;font-size:13px;text-align:center">
        </label>
        <p style="font-size:11px;color:var(--text-dim);margin-top:8px;">
          Participants with fewer posts are omitted from the summary and removed from the log.
        </p>
      </div>

      <!-- Submit -->
      <div class="section">
        <button id="clean-btn" class="btn btn-primary">
          ✦ Clean Log
        </button>
      </div>

    </aside>

    <!-- ── Right: Results ── -->
    <main class="right-panel">

      <div class="results-header">
        <h2>Output</h2>
        <div class="results-actions">
          <button id="copy-btn"     class="btn btn-secondary btn-small">Copy</button>
          <button id="download-btn" class="btn btn-secondary btn-small">Download .txt</button>
        </div>
      </div>

      <!-- Empty / loading state -->
      <div id="empty-state" class="empty-state">
        <div class="big-icon">📜</div>
        <p>Paste or upload a Second Life chat log,<br>then click <strong>Clean Log</strong>.</p>
      </div>

      <!-- Results (hidden until first run) -->
      <div id="results-wrap" style="display:none; flex-direction:column; flex:1; min-height:0; overflow:hidden;">

        <!-- Stats bar -->
        <div class="stats-bar">
          <div class="stat-item">
            <div class="stat-label">Duration</div>
            <div class="stat-value small" id="stat-duration">—</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Participants</div>
            <div class="stat-value" id="stat-participants">—</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Posts</div>
            <div class="stat-value" id="stat-posts">—</div>
          </div>
        </div>

        <!-- Summary + log -->
        <div class="output-body" style="flex:1; min-height:0; display:flex; flex-direction:column; overflow:hidden;">
          <div id="summary-block" class="summary-block">
            <pre id="summary-header-pre" class="summary-header-pre"></pre>
            <div id="participant-table" class="participant-table"></div>
            <div id="ignore-bar" class="ignore-bar" style="display:none">
              <span id="ignore-count" class="ignore-count"></span>
              <button id="ignore-btn" class="btn btn-secondary btn-small">Ignore &amp; re-clean</button>
            </div>
          </div>
          <pre id="log-output"></pre>
        </div>

      </div>

    </main>
  </div><!-- .columns -->

</div><!-- #app -->

<script src="js/app.js"></script>
</body>
</html>
