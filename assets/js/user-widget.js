/**
 * Floating User Widget — v4 (Snooker Physics)
 * ─────────────────────────────────────────────────────────────
 *
 * Physics model based on 2D billiard / snooker ball:
 *
 *   Each frame:
 *     vx *= FRICTION   ← rolling resistance (felt surface)
 *     vy *= FRICTION
 *     x  += vx
 *     y  += vy
 *
 *   On wall hit:
 *     vx = -vx * RESTITUTION   ← cushion absorbs some energy
 *
 *   Stop when speed < STOP_SPEED
 *
 * Throw velocity:
 *   Sampled from mouse movement over the last 80ms using timestamps.
 *   This gives the *real* speed at release (px/ms → px/frame).
 *   A gentle place-down has near-zero velocity → ball stays.
 *   A fast flick has high velocity → energetic bounce.
 *
 * References:
 *   MDN Canvas bouncing ball tutorial
 *   Stack Overflow: billiard friction / restitution coefficients
 */
(function () {
    'use strict';

    /* ── DOM ─────────────────────────────────────────────────────────────── */
    const widget    = document.getElementById('user-float-widget');
    if (!widget) return;

    const bubble    = widget.querySelector('.ufw-bubble');
    const xpBarFill = widget.querySelector('.ufw-xp-bar-fill');
    const ringFill  = widget.querySelector('.ufw-ring-fill');

    const xpPct   = parseFloat(widget.dataset.xpPct || '0');
    const CIRCUMF = 201;

    /* ── Physics constants ───────────────────────────────────────────────── */
    //  Snooker felt:  friction ≈ 0.988–0.992 per frame @ 60fps
    //  Cushion rubber: restitution ≈ 0.78–0.88
    const FRICTION      = 0.990;   // speed multiplier each frame (rolling deceleration)
    const RESTITUTION   = 0.82;    // fraction of speed kept after wall bounce
    const STOP_SPEED    = 0.08;    // px/frame — stop the loop below this threshold
    const THROW_SCALE   = 0.55;    // scale drag px/ms → px/frame  (tune feel)
    const MAX_SPEED     = 28;      // cap so an insane fast drag doesn't teleport
    const MIN_THROW     = 0.4;     // px/frame minimum to start bouncing after release
    const SIZE          = 62;      // bounding box of the bubble

    /* ── State ───────────────────────────────────────────────────────────── */
    let x = window.innerWidth  - SIZE - 28;
    let y = window.innerHeight - SIZE - 28;
    let vx = 0, vy = 0;

    let bouncing   = false;
    let isHovered  = false;
    let isDragging = false;
    let dragDist   = 0;
    let expanded   = false;
    let xpAnimated = false;
    let animFrame  = null;
    let hoverTimer = null;

    /* ── Position helpers ────────────────────────────────────────────────── */
    const STORAGE_KEY = 'ufw_pos_v2';

    function maxX() { return Math.max(0, window.innerWidth  - SIZE); }
    function maxY() { return Math.max(0, window.innerHeight - SIZE); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function applyPos() {
        widget.style.left   = x + 'px';
        widget.style.top    = y + 'px';
        widget.style.right  = 'auto';
        widget.style.bottom = 'auto';
    }

    function loadPos() {
        try {
            const s = JSON.parse(localStorage.getItem(STORAGE_KEY));
            if (s && typeof s.x === 'number' && typeof s.y === 'number') {
                x = clamp(s.x, 0, maxX());
                y = clamp(s.y, 0, maxY());
            }
        } catch (_) {}
    }

    function savePos() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ x, y })); } catch (_) {}
    }

    /* ── Physics tick ────────────────────────────────────────────────────── */
    function tick() {
        if (!bouncing || isDragging) { animFrame = null; return; }

        // Apply friction (rolling resistance — decelerates naturally each frame)
        vx *= FRICTION;
        vy *= FRICTION;

        // Move
        x += vx;
        y += vy;

        const mx = maxX(), my = maxY();

        // Wall collision with energy loss (cushion restitution)
        if (x <= 0) {
            x  = 0;
            vx = Math.abs(vx) * RESTITUTION;   // reverse + lose some energy
            spawnRipple();
        } else if (x >= mx) {
            x  = mx;
            vx = -Math.abs(vx) * RESTITUTION;
            spawnRipple();
        }

        if (y <= 0) {
            y  = 0;
            vy = Math.abs(vy) * RESTITUTION;
            spawnRipple();
        } else if (y >= my) {
            y  = my;
            vy = -Math.abs(vy) * RESTITUTION;
            spawnRipple();
        }

        applyPos();

        // Stop the loop when ball is slow enough (feels like ball coming to rest)
        const speed = Math.hypot(vx, vy);
        if (speed < STOP_SPEED) {
            vx = 0; vy = 0;
            bouncing = false;
            widget.classList.remove('bouncing');
            animFrame = null;
            savePos();
            return;
        }

        animFrame = requestAnimationFrame(tick);
    }

    function startBounce() {
        if (animFrame) return;
        bouncing = true;
        widget.classList.add('bouncing');
        animFrame = requestAnimationFrame(tick);
    }

    function stopBounce() {
        bouncing = false;
        widget.classList.remove('bouncing');
        if (animFrame) { cancelAnimationFrame(animFrame); animFrame = null; }
    }

    /* ── Wall-hit ripple (snooker cushion flash) ─────────────────────────── */
    function spawnRipple() {
        const speed  = Math.hypot(vx, vy);
        const alpha  = clamp(speed / 12, 0.1, 0.5); // harder hit = more visible
        const r = document.createElement('div');
        r.style.cssText = `
            position:fixed; border-radius:50%; pointer-events:none; z-index:8999;
            width:${SIZE}px; height:${SIZE}px;
            left:${x}px; top:${y}px;
            background: rgba(124,58,237,${alpha.toFixed(2)});
            animation: ufw-ripple 0.45s ease-out forwards;
        `;
        document.body.appendChild(r);
        setTimeout(() => r.remove(), 500);
    }

    if (!document.getElementById('ufw-ripple-style')) {
        const s = document.createElement('style');
        s.id  = 'ufw-ripple-style';
        s.textContent = `@keyframes ufw-ripple {
            0%   { transform:scale(1);   opacity:1;  }
            100% { transform:scale(2.6); opacity:0;  }
        }`;
        document.head.appendChild(s);
    }

    /* ── Drag with timestamped velocity sampling ─────────────────────────── */
    //  We keep a rolling buffer of (x, y, timestamp) samples during drag.
    //  On release, we look back ~80ms and compute average velocity in px/ms.
    //  This accurately captures the throw speed regardless of frame rate.
    const SAMPLE_WINDOW = 80; // ms
    let posBuffer = []; // { x, y, t }

    let dragStartX, dragStartY;
    let dragWidgetX, dragWidgetY;

    function clientXY(e) {
        const t = e.touches ? e.touches[0] : e;
        return [t.clientX, t.clientY];
    }

    function onDown(e) {
        if (!e.target.closest('.ufw-bubble')) return;
        if (e.button !== undefined && e.button !== 0) return;

        stopBounce();
        vx = 0; vy = 0;

        [dragStartX, dragStartY] = clientXY(e);
        dragWidgetX = x;
        dragWidgetY = y;
        dragDist    = 0;
        isDragging  = false;
        posBuffer   = [{ x: dragStartX, y: dragStartY, t: performance.now() }];

        document.addEventListener('mousemove', onMove, { passive: true });
        document.addEventListener('touchmove', onMove, { passive: true });
        document.addEventListener('mouseup',   onUp);
        document.addEventListener('touchend',  onUp);
        e.preventDefault();
    }

    function onMove(e) {
        const [cx, cy] = clientXY(e);
        const dx = cx - dragStartX;
        const dy = cy - dragStartY;
        dragDist = Math.hypot(dx, dy);

        if (!isDragging && dragDist < 5) return;
        if (!isDragging) {
            isDragging = true;
            widget.classList.add('dragging');
            collapsePanel();
        }

        // 1:1 follow
        x = clamp(dragWidgetX + dx, 0, maxX());
        y = clamp(dragWidgetY + dy, 0, maxY());
        applyPos();

        // Record timestamped position for velocity sampling
        const now = performance.now();
        posBuffer.push({ x: cx, y: cy, t: now });
        // Trim samples older than SAMPLE_WINDOW
        while (posBuffer.length > 1 && now - posBuffer[0].t > SAMPLE_WINDOW) {
            posBuffer.shift();
        }
    }

    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('mouseup',   onUp);
        document.removeEventListener('touchend',  onUp);

        if (isDragging) {
            // ── Compute throw velocity from sample buffer ──
            const now = performance.now();
            const old = posBuffer.find(s => now - s.t <= SAMPLE_WINDOW) || posBuffer[0];
            const newest = posBuffer[posBuffer.length - 1];
            const dt = newest.t - old.t;

            if (dt > 5 && posBuffer.length >= 2) {
                const rawVx = ((newest.x - old.x) / dt) * 16.67 * THROW_SCALE;
                const rawVy = ((newest.y - old.y) / dt) * 16.67 * THROW_SCALE;
                const speed = Math.hypot(rawVx, rawVy);

                if (speed >= MIN_THROW) {
                    const cappedSpeed = Math.min(speed, MAX_SPEED);
                    vx = (rawVx / speed) * cappedSpeed;
                    vy = (rawVy / speed) * cappedSpeed;
                }
            }

            // Clear drag state IMMEDIATELY — tick() checks isDragging so it
            // must be false before startBounce() queues the first frame
            isDragging = false;
            widget.classList.remove('dragging');
            savePos();
            posBuffer = [];

            // Start bounce right away if we have enough velocity
            if (Math.hypot(vx, vy) >= MIN_THROW) {
                startBounce(); // no setTimeout — fires on next rAF (~16ms)
            }
        } else {
            isDragging = false;
            posBuffer  = [];
        }
    }


    /* ── XP animation ─────────────────────────────────────────────────────── */
    function animateXp() {
        if (xpAnimated) return;
        xpAnimated = true;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            if (xpBarFill) xpBarFill.style.width = xpPct + '%';
            if (ringFill)  ringFill.style.strokeDashoffset = CIRCUMF - (CIRCUMF * xpPct / 100);
        }));
    }

    /* ── Panel expand / collapse ─────────────────────────────────────────── */
    function expandPanel() {
        if (expanded) return;
        expanded = true;
        widget.classList.add('expanded');
        animateXp();
    }

    function collapsePanel() {
        if (!expanded) return;
        expanded = false;
        widget.classList.remove('expanded');
    }

    /* ── Hover ────────────────────────────────────────────────────────────── */
    widget.addEventListener('mouseenter', () => {
        isHovered = true;
        stopBounce();
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(expandPanel, 120);
    });

    widget.addEventListener('mouseleave', () => {
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => {
            if (!widget.matches(':hover')) {
                isHovered = false;
                collapsePanel();
                // Resume bounce only if it still has velocity
                if (Math.hypot(vx, vy) > STOP_SPEED) startBounce();
            }
        }, 220);
    });

    /* ── Click / tap ─────────────────────────────────────────────────────── */
    bubble.addEventListener('click', () => {
        if (dragDist > 5) return;
        if (expanded) collapsePanel();
        else          expandPanel();
    });

    document.addEventListener('click', (e) => {
        if (!widget.contains(e.target)) collapsePanel();
    });

    /* ── Drag listeners ──────────────────────────────────────────────────── */
    bubble.addEventListener('mousedown',  onDown);
    bubble.addEventListener('touchstart', onDown, { passive: false });

    /* ── Resize ──────────────────────────────────────────────────────────── */
    window.addEventListener('resize', () => {
        x = clamp(x, 0, maxX());
        y = clamp(y, 0, maxY());
        applyPos();
    });

    /* ── Init ─────────────────────────────────────────────────────────────── */
    loadPos();
    applyPos();
    requestAnimationFrame(() => requestAnimationFrame(() => widget.classList.add('ready')));

})();
