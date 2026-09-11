<section id="gm-identify" class="gm-section">
    <div class="gi-mode-tabs">
        <button class="gi-mode-tab active" id="gi-tab-key" type="button">Key Mode</button>
        <button class="gi-mode-tab" id="gi-tab-note" type="button">Note Mode</button>
    </div>

    <div id="gi-settings" class="game-settings-panel">
        <div class="game-setting-card">
            <div class="game-setting-label">Total Rounds</div>
            <div class="game-setting-control">
                <button class="game-stepper-btn" id="gi-rounds-down" type="button">-</button>
                <span class="game-stepper-val" id="gi-rounds-val">10</span>
                <button class="game-stepper-btn" id="gi-rounds-up" type="button">+</button>
            </div>
        </div>

        <button class="btn primary game-start-btn" id="gi-start" type="button">Start</button>
    </div>

    <div id="gi-active" class="game-active-area" hidden>
        <div class="game-stats-bar">
            <div class="game-stat-item"><div class="game-stat-val" id="gi-time">0:00</div><div class="game-stat-lbl">Time</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gi-score">0</div><div class="game-stat-lbl">Correct</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gi-streak">0</div><div class="game-stat-lbl">Streak</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gi-remain">--</div><div class="game-stat-lbl">Left</div></div>
        </div>

        <div class="gi-prompt" id="gi-prompt">
            <div class="gi-prompt-label" id="gi-prompt-label">Identify this note</div>
            <div id="gi-staff-el" class="gi-staff-box" hidden></div>
            <div id="gi-piano-prompt" class="gi-piano-box" hidden>
                <div class="piano-container" id="gi-piano-keys"></div>
            </div>
        </div>

        <div class="gi-roulette-wrap">
            <div class="gi-roulette" id="gi-roulette"></div>
            <div class="gi-hint" id="gi-hint"></div>
        </div>
    </div>

    <div id="gi-end" class="game-end-screen" hidden>
        <div class="game-end-card">
            <h3>Round Complete</h3>
            <div class="game-end-stats">
                <div class="game-end-stat"><span class="game-end-val" id="gie-time">--</span><span class="game-end-lbl">Time</span></div>
                <div class="game-end-stat"><span class="game-end-val" id="gie-score">--</span><span class="game-end-lbl">Correct</span></div>
                <div class="game-end-stat"><span class="game-end-val" id="gie-acc">--</span><span class="game-end-lbl">Accuracy</span></div>
            </div>
            <div class="game-end-actions">
                <button class="btn secondary" id="gi-back" type="button">Change Settings</button>
                <button class="btn primary" id="gi-replay" type="button">Play Again</button>
            </div>
        </div>
    </div>
</section>
