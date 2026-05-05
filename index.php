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
          Participants with fewer posts are omitted from the header (their lines remain in the log).
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
          <pre id="summary-block" class="summary-block"></pre>
          <pre id="log-output"></pre>
        </div>

      </div>

    </main>
  </div><!-- .columns -->

</div><!-- #app -->

<script src="js/app.js"></script>
</body>
</html>
