<?php
require_once __DIR__ . '/includes/config.php';
$currentUserObj = null;
if (is_logged_in() && isset($userManager)) {
    $currentUserObj = $userManager->getUser($_SESSION['user_id']);
}
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/tutorial.css">
<link rel="stylesheet" href="assets/css/piano.css">
<link rel="stylesheet" href="assets/css/car-race.css">

<div class="race-page">

    <!-- ── HUD ────────────────────────────────────────────────── -->
    <div class="race-hud">
        <div class="race-hud-title">🏎️ Piano Race</div>
        <div class="race-hud-stats">
            <div class="hud-stat">
                <span class="hud-label">Speed</span>
                <span class="hud-value speed" id="hud-speed">0</span>
                <span class="hud-unit">km/h</span>
            </div>
            <div class="hud-stat">
                <span class="hud-label">Distance</span>
                <span class="hud-value dist" id="hud-dist">0</span>
                <span class="hud-unit">m</span>
            </div>
            <div class="hud-stat">
                <span class="hud-label">Time</span>
                <span class="hud-value timer" id="hud-timer">60</span>
                <span class="hud-unit">s</span>
            </div>
        </div>
        <a href="game.php" class="hud-back">← Games</a>
    </div>

    <!-- ── Race Canvas ────────────────────────────────────────── -->
    <div class="canvas-wrap">
        <canvas id="race-canvas"></canvas>
    </div>

    <!-- ── Sheet music ────────────────────────────────────────── -->
    <div id="race-visualizer">
        <div id="staff-container"></div>
    </div>

    <!-- ── Feedback bar ───────────────────────────────────────── -->
    <div class="race-feedback-bar">
        <div class="fb-stats">✅ <span id="stat-correct">0</span> &nbsp;❌ <span id="stat-wrong">0</span></div>
        <div class="fb-msg" id="piano-feedback"></div>
        <div class="fb-progress">Note <span id="stat-step">1</span> / <span id="stat-total">—</span></div>
    </div>

    <!-- ── Piano ──────────────────────────────────────────────── -->
    <div class="piano-wrapper" style="height:150px; border:1px solid var(--studio-border); border-top:none; border-radius:0 0 8px 8px;">
        <div class="piano-keys-area">
            <button class="scroll-zone scroll-zone-left"  id="scroll-zone-left"  type="button">&lt;</button>
            <div id="piano-container" class="piano-container"></div>
            <button class="scroll-zone scroll-zone-right" id="scroll-zone-right" type="button">&gt;</button>
        </div>
    </div>

    <!-- ── Game Overlay (Start/End screens) ───────────────────── -->
    <div class="race-overlay" id="race-overlay">
        <div class="overlay-icon">🏎️</div>
        <div class="overlay-title" id="overlay-title">Piano Race</div>
        <div class="overlay-sub"  id="overlay-sub">Play the highlighted notes to speed up your car!</div>
        <div class="overlay-result" id="overlay-result" style="display:none"></div>
        <button class="btn-race-start" id="btn-start" onclick="RaceGame.start()">🚦 Start Race!</button>
        <button class="btn-race-restart" id="btn-restart" onclick="RaceGame.restart()" style="display:none">↺ Play Again</button>
    </div>

</div><!-- .race-page -->

<script src="assets/js/piano-core.js"></script>
<script>
/* ===================================================================
   PIANO RACE — Game Logic
   =================================================================== */
const RaceGame = (() => {
'use strict';

/* ── Constants ──────────────────────────────────────────────────── */
const GAME_DURATION   = 60;       // seconds
const SEQ_LENGTH      = 80;       // total notes for 1 minute
const MIDI_START      = 48;       // C3
const MIDI_END        = 71;       // B4

// Physics
const DECEL           = 0.994;    // natural friction per frame
const BOOST_CORRECT   = 22;       // speed added on correct note
const PENALTY_WRONG   = 28;       // speed lost on wrong note
const MAX_SPEED       = 180;      // km/h cap
const OBS_SPEED_BASE  = 3;        // obstacle base scroll speed

/* ── State ──────────────────────────────────────────────────────── */
let sequence = [], currentStep = 0;
let carSpeed = 0, distance = 0;
let timeLeft = GAME_DURATION;
let correctCount = 0, wrongCount = 0;
let combo = 0;
let gameRunning = false;
let shakeTimer  = 0;      // frames remaining for car shake
let flashTimer  = 0;      // frames remaining for colour flash
let flashColor  = '';
let obstacles   = [];     // {x, type, lane}
let particles   = [];     // speed-boost particles
let floatingTexts = [];   // combo/speed popups
let trees       = [];     // background trees
let roadOffset  = 0;
let lastTs      = 0;
let animId      = null;

/* ── Staff Scroll State ── */
let staffNoteMap = [];
let targetStaffX = 0;
let currentStaffX = 0;

/* ── Canvas ─────────────────────────────────────────────────────── */
const canvas = document.getElementById('race-canvas');
const ctx    = canvas.getContext('2d');

function resizeCanvas() {
    const dpr = window.devicePixelRatio || 1;
    const W   = canvas.parentElement.clientWidth;
    const H   = 160;
    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';
    ctx.scale(dpr, dpr);
    return [W, H];
}

/* ── Note helpers ────────────────────────────────────────────────── */
function buildSequence() {
    const pool = [];
    for (let m = MIDI_START; m <= MIDI_END; m++) pool.push(m);
    const arr = [];
    for (let i = 0; i < SEQ_LENGTH; i++) arr.push(pool[Math.floor(Math.random() * pool.length)]);
    return arr;
}

function nameToMidi(name) {
    const notes = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    const m = name.match(/^([A-G]#?)(\d)$/i);
    if (m) { const idx = notes.indexOf(m[1].toUpperCase()); if (idx >= 0) return (parseInt(m[2])+1)*12+idx; }
    return 60;
}

function midiToName(midi) {
    const notes = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    return notes[midi % 12] + (Math.floor(midi/12)-1);
}

/* ── Piano init ─────────────────────────────────────────────────── */
const pianoContainer = document.getElementById('piano-container');
PianoCore.syncKeySizing();
PianoCore.createPianoKeys(pianoContainer, {
    start: MIDI_START,
    end:   MIDI_END,
    showLabels: true,
    onDown: async (midi) => {
        await PianoCore.initAudio();
        const synth = PianoCore.getSynth();
        if (synth) synth.triggerAttack(midiToName(midi), Tone.now(), 0.7);
        pianoContainer.querySelector('.key[data-midi="'+midi+'"]')?.classList.add('active-key');
        if (gameRunning) handleNote(midi);
    },
    onUp: (midi) => {
        const synth = PianoCore.getSynth();
        if (synth) synth.triggerRelease(midiToName(midi), Tone.now());
        pianoContainer.querySelector('.key[data-midi="'+midi+'"]')?.classList.remove('active-key');
    }
});

/* ── Staff rendering ─────────────────────────────────────────────── */
function renderStaff() {
    const staffEl = document.getElementById('staff-container');
    const win = [];
    for (let i = 0; i < sequence.length; i++) {
        win.push({ midi: sequence[i], time: i });
    }
    // Render all notes at once. VexFlow is fast enough for 80 notes.
    staffNoteMap = PianoCore.renderStaff(staffEl, win, { activeIndex: currentStep });
    
    document.getElementById('stat-step').textContent  = currentStep + 1;
    document.getElementById('stat-total').textContent = SEQ_LENGTH;
}

/* ── Piano key highlight ─────────────────────────────────────────── */
function highlightKey(midi) {
    pianoContainer.querySelectorAll('.train-hint').forEach(k => k.classList.remove('train-hint'));
    pianoContainer.querySelector('.key[data-midi="'+midi+'"]')?.classList.add('train-hint');
}

/* ── Note input handler ─────────────────────────────────────────── */
const feedbackEl = document.getElementById('piano-feedback');

function handleNote(midi) {
    const expected = sequence[currentStep];
    if (currentStep >= sequence.length) return; // game finished array
    
    const [W, H] = [canvas.width/(window.devicePixelRatio||1), canvas.height/(window.devicePixelRatio||1)];

    if (midi === expected) {
        correctCount++;
        combo++;
        carSpeed = Math.min(MAX_SPEED, carSpeed + BOOST_CORRECT);
        spawnBoostParticles();
        
        // Add floating text
        floatingTexts.push({
            x: 130 + (Math.random()-0.5)*20, 
            y: H * 0.70 - 40,
            vx: (Math.random()-0.5)*1, vy: -1.5, life: 1.0,
            text: combo >= 3 ? 'Combo x' + combo + '!' : '+22 km/h',
            color: combo >= 3 ? '#fbbf24' : '#6ee7b7',
            scale: combo >= 3 ? 1.2 : 1.0
        });

        feedbackEl.style.color = '#16a34a';
        feedbackEl.textContent = '✓ ' + midiToName(midi);
        document.getElementById('stat-correct').textContent = correctCount;
        currentStep++;
        renderStaff(); // re-render to update focus color
        if (currentStep < sequence.length) highlightKey(sequence[currentStep]);
    } else {
        wrongCount++;
        combo = 0;
        carSpeed = Math.max(0, carSpeed - PENALTY_WRONG);
        shakeTimer = 18; flashColor = '#ef4444'; flashTimer = 8;
        spawnObstacle();
        
        floatingTexts.push({
            x: 130 + (Math.random()-0.5)*20, 
            y: H * 0.70 - 40,
            vx: (Math.random()-0.5)*1, vy: -1.5, life: 1.0,
            text: 'Miss!', color: '#ef4444', scale: 1.0
        });

        feedbackEl.style.color = '#ef4444';
        feedbackEl.textContent = '✗ Expected: ' + midiToName(expected);
        document.getElementById('stat-wrong').textContent = wrongCount;
        setTimeout(() => { if (gameRunning) feedbackEl.textContent = ''; }, 1500);
    }
}

/* ── Obstacles ───────────────────────────────────────────────────── */
const OBS_TYPES = ['cone','barrier','car'];
const LANES = [0.58, 0.72, 0.86]; // y as fraction of canvas height

function spawnObstacle() {
    const [W] = [canvas.width / (window.devicePixelRatio||1)];
    obstacles.push({
        x:    W + 40,
        type: OBS_TYPES[Math.floor(Math.random() * OBS_TYPES.length)],
        lane: LANES[Math.floor(Math.random() * LANES.length)]
    });
}

function spawnBoostParticles() {
    const [W, H] = [canvas.width/(window.devicePixelRatio||1), canvas.height/(window.devicePixelRatio||1)];
    const carX = 130, carY = H * 0.70;
    for (let i = 0; i < 8; i++) {
        particles.push({
            x: carX, y: carY,
            vx: -(Math.random() * 3 + 1),
            vy: (Math.random() - 0.5) * 2,
            life: 1.0, size: 4 + Math.random() * 4,
            color: ['#a855f7','#fde68a','#6ee7b7'][Math.floor(Math.random()*3)]
        });
    }
}

/* ── Canvas drawing ──────────────────────────────────────────────── */
function rRect(cx, cy, w, h, r, fill) {
    ctx.beginPath();
    ctx.roundRect(cx, cy, w, h, r);
    ctx.fillStyle = fill;
    ctx.fill();
}

function drawRoad(W, H) {
    // Sky gradient
    const sky = ctx.createLinearGradient(0, 0, 0, H * 0.48);
    sky.addColorStop(0, '#0f172a');
    sky.addColorStop(1, '#1e3a5f');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, W, H * 0.48);

    // Stars
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    [[40,20],[100,10],[180,35],[260,8],[340,28],[440,15],[520,30],[620,5],[700,22],[780,18]].forEach(([sx,sy]) => {
        ctx.beginPath(); ctx.arc(sx % W, sy, 1, 0, Math.PI*2); ctx.fill();
    });

    // Mountains
    ctx.fillStyle = '#0f172a';
    const mOffset = (distance * 0.05) % W;
    for(let i = -1; i <= Math.ceil(W/W)+1; i++) {
        const bx = i*W - mOffset;
        ctx.beginPath();
        ctx.moveTo(bx, H*0.48);
        ctx.lineTo(bx + W*0.3, H*0.25);
        ctx.lineTo(bx + W*0.6, H*0.48);
        ctx.fill();
        
        ctx.fillStyle = '#1e293b';
        ctx.beginPath();
        ctx.moveTo(bx + W*0.4, H*0.48);
        ctx.lineTo(bx + W*0.7, H*0.20);
        ctx.lineTo(bx + W*1.0, H*0.48);
        ctx.fill();
        ctx.fillStyle = '#0f172a';
    }

    // Grass top
    ctx.fillStyle = '#14532d';
    ctx.fillRect(0, H * 0.47, W, H * 0.06);

    // Road
    const roadGrad = ctx.createLinearGradient(0, H * 0.52, 0, H);
    roadGrad.addColorStop(0, '#374151');
    roadGrad.addColorStop(1, '#1f2937');
    ctx.fillStyle = roadGrad;
    ctx.fillRect(0, H * 0.52, W, H * 0.48);

    // Road edge lines
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, H * 0.52, W, 2);
    ctx.fillRect(0, H - 3, W, 3);

    // Dashed centre lines (2 lanes)
    const dashW = 36, dashGap = 28, totalDash = dashW + dashGap;
    [0.63, 0.76].forEach(ly => {
        const y = H * ly;
        ctx.fillStyle = 'rgba(255,255,255,0.45)';
        for (let dx = -(roadOffset % totalDash); dx < W; dx += totalDash) {
            ctx.fillRect(dx, y - 1, dashW, 2);
        }
    });

    // Grass bottom
    ctx.fillStyle = '#14532d';
    ctx.fillRect(0, H * 0.96, W, H * 0.04);
}

function drawCar(W, H, shaking) {
    const cx = 130, cy = H * 0.70;
    ctx.save();
    if (shaking) ctx.translate((Math.random()-0.5)*5, (Math.random()-0.5)*3);

    // Shadow
    ctx.fillStyle = 'rgba(0,0,0,0.3)';
    ctx.beginPath(); ctx.ellipse(cx+4, cy+14, 36, 6, 0, 0, Math.PI*2); ctx.fill();

    // Body
    const bg = ctx.createLinearGradient(cx-38, cy-14, cx-38, cy+14);
    bg.addColorStop(0, '#a855f7'); bg.addColorStop(1, '#6d28d9');
    rRect(cx-38, cy-13, 76, 26, 5, 'transparent');
    ctx.fillStyle = bg; ctx.fill();

    // Cabin
    rRect(cx-10, cy-27, 38, 18, 4, '#5b21b6');

    // Windows
    ctx.fillStyle = 'rgba(186,230,253,0.85)';
    rRect(cx-6, cy-24, 14, 12, 2, 'rgba(186,230,253,0.85)');
    rRect(cx+12, cy-24, 13, 12, 2, 'rgba(186,230,253,0.7)');

    // Headlight glow
    ctx.shadowColor = '#fef08a'; ctx.shadowBlur = 10;
    ctx.fillStyle = '#fef08a';
    ctx.beginPath(); ctx.ellipse(cx+38, cy-4, 5, 3.5, 0, 0, Math.PI*2); ctx.fill();
    ctx.shadowBlur = 0;

    // Tail light
    ctx.fillStyle = '#ef4444';
    ctx.beginPath(); ctx.ellipse(cx-38, cy-4, 4, 3, 0, 0, Math.PI*2); ctx.fill();

    // Wheels
    const wheelY = cy + 14;
    [-20, 24].forEach(dx => {
        ctx.fillStyle = '#111';
        ctx.beginPath(); ctx.ellipse(cx+dx, wheelY, 10, 8, 0, 0, Math.PI*2); ctx.fill();
        ctx.fillStyle = '#9ca3af';
        ctx.beginPath(); ctx.ellipse(cx+dx, wheelY, 5, 4, 0, 0, Math.PI*2); ctx.fill();
    });

    // Exhaust smoke
    if (carSpeed > 3) {
        const puffs = Math.min(4, Math.ceil(carSpeed / 45));
        for (let i = 0; i < puffs; i++) {
            const a = 0.25 - i * 0.06;
            ctx.fillStyle = `rgba(180,180,180,${a.toFixed(2)})`;
            ctx.beginPath();
            ctx.arc(cx - 46 - i*14, cy + 4 + (Math.random()-0.5)*4, 5+i*2, 0, Math.PI*2);
            ctx.fill();
        }
    }
    ctx.restore();
}

function drawObstacle(ob, H) {
    const y = H * ob.lane;
    ctx.save();
    switch(ob.type) {
        case 'cone':
            ctx.fillStyle = '#f97316';
            ctx.beginPath();
            ctx.moveTo(ob.x, y - 22);
            ctx.lineTo(ob.x - 12, y + 6);
            ctx.lineTo(ob.x + 12, y + 6);
            ctx.closePath(); ctx.fill();
            ctx.fillStyle = 'white';
            ctx.fillRect(ob.x - 10, y - 4, 20, 4);
            break;
        case 'barrier':
            // Red/white striped box
            ctx.fillStyle = '#ef4444';
            rRect(ob.x - 20, y - 12, 40, 20, 3, '#ef4444');
            ctx.fillStyle = 'white';
            [0,1,2].forEach(i => ctx.fillRect(ob.x - 20 + i*14, y - 12, 7, 20));
            ctx.strokeStyle = '#b91c1c'; ctx.lineWidth = 1;
            ctx.strokeRect(ob.x - 20, y - 12, 40, 20);
            break;
        case 'car':
            // Rival car (red)
            const rbg = ctx.createLinearGradient(ob.x-30, y-12, ob.x-30, y+12);
            rbg.addColorStop(0, '#f87171'); rbg.addColorStop(1, '#dc2626');
            rRect(ob.x-30, y-11, 60, 22, 5, 'transparent');
            ctx.fillStyle = rbg; ctx.fill();
            rRect(ob.x-14, y-22, 30, 15, 3, '#b91c1c');
            ctx.fillStyle = 'rgba(186,230,253,0.7)';
            rRect(ob.x-10, y-20, 12, 11, 2, 'rgba(186,230,253,0.7)');
            rRect(ob.x+4, y-20, 8, 11, 2, 'rgba(186,230,253,0.5)');
            [-14, 18].forEach(dx => {
                ctx.fillStyle = '#111';
                ctx.beginPath(); ctx.ellipse(ob.x+dx, y+12, 8, 6, 0, 0, Math.PI*2); ctx.fill();
            });
            break;
    }
    ctx.restore();
}

function drawTree(t, H) {
    const y = t.side === 1 ? H * 0.96 + 10 : H * 0.47 + 5;
    ctx.fillStyle = '#064e3b';
    ctx.beginPath();
    ctx.moveTo(t.x, y);
    ctx.lineTo(t.x-12, y-24);
    ctx.lineTo(t.x+12, y-24);
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(t.x, y-12);
    ctx.lineTo(t.x-10, y-34);
    ctx.lineTo(t.x+10, y-34);
    ctx.fill();
}

function drawParticles() {
    particles.forEach(p => {
        ctx.globalAlpha = p.life;
        ctx.fillStyle = p.color;
        ctx.beginPath(); ctx.arc(p.x, p.y, p.size * p.life, 0, Math.PI*2); ctx.fill();
    });
    ctx.globalAlpha = 1;
}

function drawFloatingTexts() {
    ctx.textAlign = 'center';
    floatingTexts.forEach(ft => {
        ctx.globalAlpha = ft.life;
        ctx.fillStyle = ft.color;
        ctx.font = `bold ${14 * ft.scale}px Inter, sans-serif`;
        ctx.shadowColor = 'rgba(0,0,0,0.5)';
        ctx.shadowBlur = 4;
        ctx.fillText(ft.text, ft.x, ft.y);
    });
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;
}

function drawSpeedBar(W, H) {
    const barW = 90, barH = 10, bx = W - barW - 14, by = 14;
    const pct  = carSpeed / MAX_SPEED;
    ctx.fillStyle = 'rgba(0,0,0,0.4)';
    ctx.beginPath(); ctx.roundRect(bx, by, barW, barH, 4); ctx.fill();
    const sg = ctx.createLinearGradient(bx, 0, bx + barW, 0);
    sg.addColorStop(0, '#6ee7b7'); sg.addColorStop(0.6, '#fde68a'); sg.addColorStop(1, '#f87171');
    ctx.fillStyle = sg;
    ctx.beginPath(); ctx.roundRect(bx, by, barW * pct, barH, 4); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = '10px Inter, sans-serif';
    ctx.fillText('SPEED', bx, by + 24);
}

/* ── Game loop ───────────────────────────────────────────────────── */
function frame(ts) {
    const dt = Math.min((ts - lastTs) / 1000, 0.05); // cap delta at 50ms
    lastTs = ts;

    if (!gameRunning) return;

    // Physics
    carSpeed  = Math.max(0, carSpeed * DECEL);
    distance += carSpeed * dt * 0.5;
    roadOffset += carSpeed * 0.28;

    // Update obstacles & trees
    const W = canvas.width  / (window.devicePixelRatio||1);
    const H = canvas.height / (window.devicePixelRatio||1);
    
    const baseScroll = carSpeed * 0.28;
    
    obstacles.forEach(ob => {
        // Only rival cars move on their own. Cones and barriers are static.
        const obSpeed = ob.type === 'car' ? OBS_SPEED_BASE : 0;
        ob.x -= (baseScroll + obSpeed);
    });
    obstacles = obstacles.filter(ob => ob.x > -60);
    
    // Only spawn trees if the car is actually moving
    if (carSpeed > 5 && Math.random() < 0.08) {
        trees.push({ x: W + 40, side: Math.random() > 0.5 ? 1 : -1 });
    }
    trees.forEach(t => t.x -= baseScroll);
    trees = trees.filter(t => t.x > -60);

    // Update particles & texts
    particles.forEach(p => { p.x += p.vx; p.y += p.vy; p.life -= 0.07; });
    particles = particles.filter(p => p.life > 0);
    
    floatingTexts.forEach(ft => { ft.x += ft.vx; ft.y += ft.vy; ft.life -= 0.02; });
    floatingTexts = floatingTexts.filter(ft => ft.life > 0);

    if (shakeTimer > 0) shakeTimer--;
    if (flashTimer > 0) flashTimer--;

    // Timer
    timeLeft -= dt;
    if (timeLeft <= 0 || currentStep >= sequence.length) { 
        timeLeft = Math.max(0, timeLeft);
        endGame(); 
        return; 
    }

    // HUD update
    document.getElementById('hud-speed').textContent = Math.round(carSpeed);
    document.getElementById('hud-dist').textContent  = Math.round(distance);
    document.getElementById('hud-timer').textContent = Math.ceil(timeLeft);
    
    // Smooth scroll the staff
    const staffEl = document.getElementById('staff-container');
    const currentNoteObj = staffNoteMap.find(n => n.time === currentStep);
    if (currentNoteObj) {
        // The SVG is scaled 1.35x via CSS, so visual X is multiplied.
        // We position the active note 150px from the left edge, 
        // leaving the rest of the screen (~750px) to show the upcoming 3 bars.
        const scale = 1.35;
        targetStaffX = -(currentNoteObj.x * scale - 150); 
    }
    currentStaffX += (targetStaffX - currentStaffX) * 0.12;
    // Don't scroll past the left edge
    currentStaffX = Math.min(0, currentStaffX);
    staffEl.style.transform = `translateX(${currentStaffX}px)`;

    // ── Draw ──
    ctx.clearRect(0, 0, W, H);
    drawRoad(W, H);
    trees.forEach(t => drawTree(t, H));
    drawParticles();
    obstacles.forEach(ob => drawObstacle(ob, H));
    drawCar(W, H, shakeTimer > 0);
    drawFloatingTexts();
    drawSpeedBar(W, H);

    // Flash overlay (only for errors now)
    if (flashTimer > 0 && flashColor) {
        ctx.globalAlpha = flashTimer / 14;
        ctx.fillStyle = flashColor;
        ctx.fillRect(0, 0, W, H);
        ctx.globalAlpha = 1;
    }

    animId = requestAnimationFrame(frame);
}

/* ── Game control ────────────────────────────────────────────────── */
function start() {
    sequence     = buildSequence();
    currentStep  = 0;
    carSpeed     = 0;
    distance     = 0;
    timeLeft     = GAME_DURATION;
    correctCount = 0;
    combo        = 0;
    obstacles    = [];
    particles    = [];
    floatingTexts= [];
    trees        = [];
    shakeTimer   = 0;
    flashTimer   = 0;
    roadOffset   = 0;
    
    currentStaffX = 0;
    targetStaffX  = 0;

    document.getElementById('stat-correct').textContent = 0;
    document.getElementById('stat-wrong').textContent   = 0;
    document.getElementById('piano-feedback').textContent = '';

    document.getElementById('race-overlay').style.display = 'none';
    document.getElementById('btn-restart').style.display  = 'none';

    resizeCanvas();
    renderStaff();
    highlightKey(sequence[0]);

    gameRunning = true;
    lastTs = performance.now();
    animId = requestAnimationFrame(frame);
}

function endGame() {
    gameRunning = false;
    if (animId) { cancelAnimationFrame(animId); animId = null; }

    // Draw final frame static
    const W = canvas.width/(window.devicePixelRatio||1);
    const H = canvas.height/(window.devicePixelRatio||1);
    drawRoad(W, H);
    drawCar(W, H, false);

    const overlay = document.getElementById('race-overlay');
    document.getElementById('overlay-title').textContent = '🏁 Race Over!';
    document.getElementById('overlay-sub').style.display  = 'none';
    const res = document.getElementById('overlay-result');
    res.style.display = 'block';
    res.innerHTML = `
        <div class="result-row">🏎️ Distance: <strong>${Math.round(distance)} m</strong></div>
        <div class="result-row">✅ Correct: <strong>${correctCount}</strong></div>
        <div class="result-row">❌ Wrong: <strong>${wrongCount}</strong></div>
        <div class="result-row">🎯 Accuracy: <strong>${correctCount+wrongCount > 0 ? Math.round(correctCount/(correctCount+wrongCount)*100) : 0}%</strong></div>
    `;
    document.getElementById('btn-start').style.display   = 'none';
    document.getElementById('btn-restart').style.display = 'inline-block';
    overlay.style.display = 'flex';

    pianoContainer.querySelectorAll('.train-hint').forEach(k => k.classList.remove('train-hint'));
}

function restart() {
    document.getElementById('race-overlay').style.display = 'none';
    document.getElementById('overlay-title').textContent  = 'Piano Race';
    document.getElementById('overlay-sub').style.display  = '';
    document.getElementById('overlay-result').style.display = 'none';
    document.getElementById('btn-start').style.display    = 'inline-block';
    document.getElementById('btn-restart').style.display  = 'none';
    start();
}

/* ── Initial staff render ────────────────────────────────────────── */
window.addEventListener('DOMContentLoaded', () => {
    resizeCanvas();
    // Draw idle road
    const W = canvas.width/(window.devicePixelRatio||1);
    const H = canvas.height/(window.devicePixelRatio||1);
    drawRoad(W, H);
    drawCar(W, H, false);

    // Show an example staff
    sequence = buildSequence();
    renderStaff();
    document.getElementById('stat-total').textContent = SEQ_LENGTH;
});

window.addEventListener('resize', () => {
    resizeCanvas();
    if (!gameRunning) {
        const W = canvas.width/(window.devicePixelRatio||1);
        const H = canvas.height/(window.devicePixelRatio||1);
        drawRoad(W, H);
        drawCar(W, H, false);
    }
});

return { start, restart, endGame };

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
