<?php
require_once __DIR__ . '/includes/config.php';
$requestedPieceId = (int)($_GET['piece_id'] ?? 0);
?>
<?php include 'includes/header.php'; ?>

<section class="piano-page" id="piano-app" data-piece-id="<?= $requestedPieceId ?>">
    <span id="disp-mode" hidden>Practice</span>
    <span id="disp-note" hidden>--</span>

    <header class="piano-hero">
        <div class="piano-title-block">
            <p class="eyebrow">Piano</p>
            <h1 class="section-title">Piano Practice Studio</h1>
        </div>

        <nav class="tabs" id="main-tabs" aria-label="Piano mode">
            <button class="tab active" type="button" data-mode="practice">Practice</button>
            <button class="tab" type="button" data-mode="play">Play</button>
            <button class="tab" type="button" data-mode="analysis">Analysis</button>
            <button class="tab" type="button" data-mode="history">History</button>
        </nav>

        <div class="piano-actions">
            <label for="global-midi-upload" class="btn secondary">Import MIDI</label>
            <input type="file" id="global-midi-upload" accept=".mid,.midi" hidden>
            <button class="btn secondary danger" id="btn-reset" type="button">Reset</button>
            <button class="btn primary" id="btn-midi" type="button">
                <span class="status-dot"></span>
                <span id="midi-status-text">Connect MIDI</span>
            </button>
        </div>
    </header>

    <!-- Song loading banner (shown when piece_id is provided) -->
    <div id="song-load-banner" style="display:none; background:linear-gradient(90deg,#6c63ff22,#7c73ff11); border-left:4px solid #6c63ff; padding:10px 18px; font-size:0.9rem; align-items:center; gap:10px; flex-wrap:wrap;">
        <span id="song-load-banner-text">⏳ Loading song piece…</span>
        <a href="<?= BASE_URL ?>songs.php" style="margin-left:auto; font-size:0.82rem; color:#6c63ff; text-decoration:underline;">← Back to Songs</a>
    </div>

    <div class="piano-workspace">
        <main class="piano-main">
            <section id="view-active" class="view-panel active-view">
                <div class="score-board">
                    <div class="stat-card song-info-card" id="song-info-panel" hidden>
                        <div class="stat-label">Song</div>
                        <strong id="song-title">No MIDI loaded</strong>
                        <div class="song-info-grid">
                            <span>Tracks</span><b id="song-track-count">0</b>
                            <span>Notes</span><b id="song-note-count">0</b>
                            <span>Length</span><b id="song-duration">0:00</b>
                        </div>
                    </div>

                    <div class="stat-card track-card" id="midi-player-controls" hidden>
                        <div class="track-card-head">
                            <div class="stat-label">MIDI Player</div>
                            <div id="track-batch-controls" class="mini-actions" hidden>
                                <button id="btn-tracks-enable-all" class="mini-btn" type="button">All</button>
                                <button id="btn-tracks-disable-all" class="mini-btn" type="button">None</button>
                            </div>
                        </div>

                        <div id="track-list-container" class="track-list" hidden></div>

                        <div class="track-options">
                            <div id="focus-track-section" hidden>
                                <div class="mini-label">Focus</div>
                                <div id="focus-track-list" class="focus-track-list"></div>
                            </div>

                            <label id="label-mute-others" class="check-row" hidden>
                                <input type="checkbox" id="mute-other-tracks">
                                Mute other tracks
                            </label>
                        </div>

                        <div class="transport">
                            <button id="btn-play-pause" class="round-btn" type="button" aria-label="Play or pause">Play</button>
                            <div class="transport-range">
                                <input type="range" id="midi-progress" min="0" max="100" value="0" step="0.1">
                                <span id="midi-time">0:00 / 0:00</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card settings-card" id="train-controls" hidden>
                        <div class="stat-label">Practice</div>
                        <button class="mini-btn" id="btn-train-retry" type="button">Retry Practice</button>
                    </div>

                    <div class="stat-card play-live-card" id="play-live-panel" hidden>
                        <div class="stat-label">Play Score</div>
                        <div class="live-score-main"><span id="live-score">0</span><small>/100</small></div>
                        <div class="song-info-grid">
                            <span>Accuracy</span><b id="live-accuracy">0%</b>
                            <span>Notes</span><b id="live-notes">0 / 0</b>
                            <span>Time</span><b id="live-time">0:00</b>
                        </div>
                    </div>
                </div>

                <div class="visualizer" id="visualizer">
                    <div class="sheet-zoom-controls">
                        <button class="sheet-zoom-btn" id="btn-zoom-out" type="button">-</button>
                        <span class="sheet-zoom-label" id="sheet-zoom-level">100%</span>
                        <button class="sheet-zoom-btn" id="btn-zoom-in" type="button">+</button>
                    </div>
                    <div class="sheet-scroll-area" id="sheet-scroll-area">
                        <div id="playhead" class="playhead" hidden></div>
                        <div id="staff-container"></div>
                    </div>
                    <button id="sheet-follow-btn" class="sheet-follow-btn" type="button" aria-label="Jump to latest note" title="Jump to latest note" hidden>→</button>
                </div>
            </section>

            <section id="view-analysis" class="view-panel">
                <div class="analysis-header">
                    <h2>Performance Analysis</h2>
                    <p id="analysis-meta">Complete a play session to see accuracy and timing here.</p>
                    <div class="analysis-actions">
                        <button class="btn secondary" id="btn-analysis-csv" type="button" hidden>Export CSV</button>
                        <button class="btn secondary" id="btn-analysis-print" type="button" hidden>Print Report</button>
                    </div>
                </div>

                <div class="analysis-grid" id="analysis-grid" hidden>
                    <div class="analysis-stat-row">
                        <div class="analysis-stat-card"><span id="an-total-notes">0</span><small>Notes Played</small></div>
                        <div class="analysis-stat-card"><span id="an-score">0/100</span><small>Total Score</small></div>
                        <div class="analysis-stat-card"><span id="an-accuracy">0%</span><small>Accuracy</small></div>
                        <div class="analysis-stat-card"><span id="an-avg-timing">0ms</span><small>Avg Timing</small><em id="an-avg-tip" class="analysis-tip">100% timing at 1000ms or faster</em></div>
                        <div class="analysis-stat-card"><span id="an-best-streak">0</span><small>Best Streak</small></div>
                    </div>
                    <div class="analysis-chart-row">
                        <div class="analysis-chart-card">
                            <h3>Pitch Accuracy</h3>
                            <div class="chart-scroll-wrap"><svg id="chart-pitch" class="analysis-svg" height="160"></svg></div>
                        </div>
                        <div class="analysis-chart-card">
                            <h3>Timing Spread</h3>
                            <div class="chart-scroll-wrap"><svg id="chart-timing" class="analysis-svg" width="380" height="160"></svg></div>
                        </div>
                    </div>
                    <div class="analysis-chart-card">
                        <h3>Streak Timeline</h3>
                        <div id="streak-heatmap" class="streak-heatmap"></div>
                    </div>
                </div>

                <div id="analysis-empty" class="empty-state-block">
                    <p>No analysis data yet.</p>
                </div>
            </section>

            <section id="view-history" class="view-panel">
                <h2>Session History</h2>
                <div class="history-replay-panel" id="history-replay-panel" hidden>
                    <div class="history-replay-head">
                        <div>
                            <div class="stat-label">Replay</div>
                            <strong id="history-replay-title">Session replay</strong>
                        </div>
                        <button class="mini-btn" id="btn-history-replay-back" type="button">Return</button>
                    </div>
                    <div class="transport">
                        <button id="btn-history-replay-play" class="round-btn" type="button">Play</button>
                        <div class="transport-range history-replay-range">
                            <div class="history-replay-mistakes" id="history-replay-mistakes"></div>
                            <input type="range" id="history-replay-progress" min="0" max="100" value="0" step="0.1">
                            <span id="history-replay-time">0:00 / 0:00</span>
                        </div>
                    </div>
                    <div class="history-replay-sheet" id="history-replay-sheet" hidden>
                        <div class="history-replay-sheet-scroll" id="history-replay-sheet-scroll">
                            <div id="history-replay-playhead" class="playhead history-replay-playhead" hidden></div>
                            <div id="history-replay-staff"></div>
                        </div>
                    </div>
                </div>
                <div class="history-list" id="history-list">
                    <p class="empty-state">No sessions saved yet.</p>
                </div>
            </section>
        </main>

        <footer class="piano-wrapper">
            <div class="piano-keys-area" id="piano-keys-area">
                <button class="scroll-zone scroll-zone-left" id="scroll-zone-left" type="button" aria-label="Scroll piano left">&lt;</button>
                <div class="piano-container" id="piano-container"></div>
                <button class="scroll-zone scroll-zone-right" id="scroll-zone-right" type="button" aria-label="Scroll piano right">&gt;</button>
            </div>

            <div class="piano-controls" id="piano-controls">
                <button class="btn secondary" id="btn-keybinds" type="button">Key Binds</button>
                <label class="check-row"><input type="checkbox" id="show-labels" checked> Show labels</label>
                <label class="check-row"><input type="checkbox" id="toggle-key-highlight" checked> Key lights</label>
                <label class="check-row"><input type="checkbox" id="toggle-staff-labels" checked> Sheet labels</label>

                <div class="mode-toggle-group" id="piano-mode-toggle">
                    <button class="mode-toggle-btn active" id="btn-mode-full" type="button" data-pmode="full">Full 88-Key</button>
                    <button class="mode-toggle-btn" id="btn-mode-fold" type="button" data-pmode="fold">Octave Fold</button>
                </div>

                <div class="octave-controls">
                    <button class="btn secondary" id="btn-octave-down" type="button">Oct -</button>
                    <span class="octave-indicator" id="disp-octave">4</span>
                    <button class="btn secondary" id="btn-octave-up" type="button">Oct +</button>
                </div>
            </div>
        </footer>
    </div>
</section>

<div class="modal-overlay" id="modal-session">
    <div class="modal">
        <h2 id="modal-title">Session Saved</h2>
        <div class="session-stats">
            <p>Total Notes: <strong id="stat-total">0</strong></p>
            <p>Duration: <strong id="stat-duration">0s</strong></p>
        </div>
        <div class="modal-actions">
            <button class="btn secondary" id="btn-export-midi" type="button">Export MIDI</button>
            <button class="btn primary" id="btn-modal-close" type="button">Close</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-keybinds">
    <div class="modal modal-wide">
        <button type="button" class="kb-modal-close" aria-label="Close keyboard binds" title="Close" style="position:absolute; top:8px; right:8px; z-index:1; min-width:44px; min-height:44px; padding:0; display:flex; align-items:center; justify-content:center; background:none; border:none; border-radius:8px; font-size:24px; line-height:1; cursor:pointer; color:var(--studio-muted);"><span aria-hidden="true" style="pointer-events:none;">&times;</span></button>
        <h2>Configure Keyboard Binds</h2>
        <div class="kb-preset-row" role="radiogroup" aria-label="Keybind preset">
            <label class="kb-preset"><input type="radio" name="kb-preset" value="single"> Preset 1 · Single-hand</label>
            <label class="kb-preset"><input type="radio" name="kb-preset" value="double"> Preset 2 · Two-hand</label>
            <label class="kb-preset"><input type="radio" name="kb-preset" value="custom"> Preset 3 · Custom</label>
        </div>
        <p class="kb-hint">Preset 1 covers C4–B4 on the home row; Preset 2 spans C3–B4 across two hands. Built-in presets are fixed — switch to Custom to make your own. Click a field, press a key (Backspace clears; Tab, Shift, Esc and F-keys can't be bound), then press Save to keep changes.</p>
        <div id="keybinds-grid" class="keybinds-grid"></div>
        <div class="modal-actions">
            <button class="btn secondary modal-reset modal-reset-left" id="btn-keybinds-reset" type="button" title="Load factory binds from a preset into Custom">Load Preset</button>
            <button class="btn secondary" id="btn-keybinds-cancel" type="button">Cancel</button>
            <button class="btn primary" id="btn-keybinds-save" type="button">Save</button>
        </div>
    </div>
</div>

<dialog class="logout-confirm-dialog" id="keybindsResetDialog" aria-labelledby="keybindsResetTitle">
    <div class="logout-confirm-content">
        <p class="logout-confirm-eyebrow">KEYBINDS</p>
        <h2 id="keybindsResetTitle">Load preset into Custom?</h2>
        <p>Load factory binds from which preset? Your custom binds will be replaced.</p>
        <div class="logout-confirm-actions">
            <button class="logout-confirm-submit" type="button" data-reset-preset="single">Preset 1 · Single-hand</button>
            <button class="logout-confirm-submit" type="button" data-reset-preset="double">Preset 2 · Two-hand</button>
            <button class="logout-confirm-cancel" type="button" data-reset-cancel>Cancel</button>
        </div>
    </div>
</dialog>

<div class="modal-overlay" id="modal-multitrack">
    <div class="modal">
        <h2>Multi-track MIDI Detected</h2>
        <p>Select which tracks to display after loading.</p>
        <div class="modal-actions">
            <button class="btn secondary" id="btn-multitrack-cancel" type="button">Cancel</button>
            <button class="btn primary" id="btn-multitrack-continue" type="button">Continue</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-play-session">
    <div class="modal">
        <h2>Start Play Session?</h2>
        <p>The song will stop at each note for your input. Each key press is recorded for analysis, and right or wrong answers move to the next note.</p>
        <div class="modal-actions">
            <button class="btn secondary" id="btn-play-session-cancel" type="button">Not Now</button>
            <button class="btn primary" id="btn-play-session-start" type="button">Start Session</button>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
