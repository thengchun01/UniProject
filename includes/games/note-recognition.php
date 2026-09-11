<section id="gm-recognition" class="gm-section">
    <div id="gr-settings" class="game-settings-panel">
        <div class="game-setting-card">
            <div class="game-setting-label">Difficulty</div>
            <div class="game-setting-desc">Easy uses one octave; hard uses three octaves.</div>
            <div class="diff-toggle">
                <button class="diff-btn active" id="gr-diff-easy" type="button">Easy</button>
                <button class="diff-btn" id="gr-diff-hard" type="button">Hard</button>
            </div>
        </div>

        <div class="game-setting-card">
            <div class="game-setting-label">Total Cards</div>
            <div class="game-setting-control">
                <button class="game-stepper-btn" id="gr-cards-down" type="button">-</button>
                <span class="game-stepper-val" id="gr-cards-val">10</span>
                <button class="game-stepper-btn" id="gr-cards-up" type="button">+</button>
            </div>
        </div>

        <div class="game-setting-card">
            <div class="game-setting-label">Notes Per Card</div>
            <div class="game-setting-control">
                <button class="game-stepper-btn" id="gr-conc-down" type="button">-</button>
                <span class="game-stepper-val" id="gr-conc-val">1</span>
                <button class="game-stepper-btn" id="gr-conc-up" type="button">+</button>
            </div>
        </div>

        <button class="btn primary game-start-btn" id="gr-start" type="button">Start Game</button>
    </div>

    <div id="gr-active" class="game-active-area" hidden>
        <div class="game-stats-bar">
            <div class="game-stat-item"><div class="game-stat-val" id="gr-time">0:00</div><div class="game-stat-lbl">Time</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gr-avg">--</div><div class="game-stat-lbl">Avg/Card</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gr-solved">0</div><div class="game-stat-lbl">Solved</div></div>
            <div class="game-stat-item"><div class="game-stat-val" id="gr-remain">--</div><div class="game-stat-lbl">Remaining</div></div>
        </div>

        <div class="gr-sheet-wrap" id="gr-sheet-wrap">
            <div id="gr-focus-zone" class="gr-focus-zone" hidden></div>
            <div id="gr-staff-el"></div>
        </div>

        <div class="gr-piano-wrap">
            <div class="piano-container" id="gr-piano"></div>
        </div>
    </div>

    <div id="gr-end" class="game-end-screen" hidden>
        <div class="game-end-card">
            <h3>Session Complete</h3>
            <div class="game-end-stats">
                <div class="game-end-stat"><span class="game-end-val" id="gre-time">--</span><span class="game-end-lbl">Total Time</span></div>
                <div class="game-end-stat"><span class="game-end-val" id="gre-avg">--</span><span class="game-end-lbl">Avg/Card</span></div>
                <div class="game-end-stat"><span class="game-end-val" id="gre-solved">--</span><span class="game-end-lbl">Cards Solved</span></div>
            </div>
            <div class="game-end-actions">
                <button class="btn secondary" id="gr-back" type="button">Change Settings</button>
                <button class="btn primary" id="gr-replay" type="button">Play Again</button>
            </div>
        </div>
    </div>
</section>
