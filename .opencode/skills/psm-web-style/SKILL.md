---
name: psm-web-style
description: Build Piano Course UI matching the black/white/purple design system, shared layout, reveal animations, and piano key states.
---

## What I do

Guide all frontend work on this PHP app so new pages match the existing
clean, high-contrast piano-learning interface. Use me when adding or
editing any PHP page, CSS in `assets/css/`, or JS in `assets/js/`.

## When to use me

Use this skill when you:

- Add a new page, card, form, table, dialog, or toast.
- Touch piano / tutorial / game UI.
- Add colors, buttons, animations, or responsive rules.

If the request conflicts with actual CSS values, the CSS files win —
never invent a new palette.

## Design tokens (source of truth)

Global tokens — `assets/css/style.css` `:root`:

```css
--primary-color: #7c3aed; --primary-hover: #6d28d9;
--bg-color: #fcfcfc; --surface-color: #ffffff;
--text-color: #111111; --text-muted: #666666;
--border-color: #eaeaea;
--shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
--shadow-md: 0 8px 24px rgba(0,0,0,0.08);
--radius-md: 12px; --radius-lg: 16px;
--transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
```

Piano/studio tokens — `assets/css/piano.css` `:root`:

```css
--studio-bg: #fcfcfc; --studio-panel: #ffffff;
--studio-panel-2: #f4f4f5; --studio-border: #eaeaea;
--studio-text: #111111; --studio-muted: #666666;
--studio-accent: #7c3aed; --studio-accent-2: #6d28d9;
--studio-danger: #dc2626;
--key-white: #ffffff; --key-white-active: #f3e8ff;
--key-black: #111111; --key-black-active: #7c3aed;
--piano-h: 245px; --key-w-white: 40px; --key-w-black: 24px;
```

Rules:

- Font is always `Inter` (imported in `style.css`). Monospace readouts use
  `Consolas, Monaco, monospace`.
- Reuse `var(...)` tokens. Never hard-code a near-match hex.
- New reusable color? Add a semantic `--color-*` token, do not sprinkle hex.

## Shared layout (do not duplicate)

- Every page uses `includes/header.php` + `includes/footer.php`.
  Header loads `style.css`, `user-widget.css`, `piano.css` plus
  Tone.js / @tonejs/midi / VexFlow CDN scripts and `window.PSM_CONFIG`.
  Footer loads `main.js`, `midi-manager.js`, `piano-core.js`, `piano.js`,
  `user-widget.js` in that order.
- Page body lives in `<div class="container site-content">`.
- Homepage hero is the one exception: full-viewport width via
  `width: 100vw; margin-left: calc(50% - 50vw);` (`assets/css/homepage.css`),
  content inside stays in the container.
- Footer markup lives in `includes/footer.php` (`.site-footer*` classes).
  Logout dialog lives in `header.php` (`.logout-confirm-dialog`) and is
  wired by `main.js`. Keep direct `logout.php` links as no-JS fallback.

## Components — reuse these classes

| Need | Class / file |
|---|---|
| Buttons | `button, .btn, .text-button` — pill `30px`, black bg → purple hover + lift. Secondary: transparent + border. See `style.css`. Piano scope overrides to 8px radius in `piano.css` — do not copy piano buttons to content pages. |
| Eyebrow/kicker | `.eyebrow`, `.section-kicker` — purple, uppercase, spaced. |
| Cards | `.card-grid` + `.card` (content), `.tutorial-card` (tutorials), `.feature-card` (homepage), `.stat-card` (piano stats). White, 1px border, `radius-lg`, hover lift + purple border. Info-only cards: `.info-card` — quiet, no hover lift. |
| Forms | `.form-panel` — 480px max, inputs with purple focus ring. Guest vs logged-in account splits into `account.css` / `account_log.css`. |
| Tables | `.data-table` — separate borders, uppercase muted `th`. |
| Alerts | `.alert.success` (green tint), `.alert.error` (red tint). |
| MIDI toast | `.midi-toast*` in `style.css` — fixed bottom-right, max 340px, Connect (purple) + Not now (ghost). Created by `midi-manager.js`, not by PHP. |
| Piano keys | `.key-white` / `.key-black`, `.active-key` (pressed), `.train-hint` (tutorial target: `#fff0c2` white / gold gradient black). Functional states — keep distinguishable. |

New styles: page/component-prefixed names (`.tutorial-card`, `.piano-actions`,
`.account-form`). Never bare `button {}`, `.card {}`, `.title {}` selectors.

## Reveal animations (mandatory pattern)

Defined in `assets/js/main.js` + `style.css` (`.reveal-pending`):

1. Auto-targets `.site-content h1-h3, p, .btn, button, .card, .stat-card,
   .form-panel, .data-table tr, .piano-hero, .view-panel, .eyebrow` —
   just use these classes and animation is free.
2. Excludes `.modal*, .piano-workspace, .game-menu, .piano-actions` to
   avoid layout jumping in app-like pages. Keep this exclusion.
3. `data-reveal="rise|jump"` + `data-reveal-delay` are optional overrides.
4. Always keep the `prefers-reduced-motion` fallback (CSS forces visible,
   JS skips animation). Homepage hero entrance uses `.hero-entrance`
   with its own `hero-enter` keyframe + reduced-motion `none`.

## Piano / tutorial / game specifics

- Piano engine lives in `assets/js/piano-core.js` (`window.PianoCore`:
  `createPianoKeys`, `renderStaff`, `midiName`, `isBlackKey`).
  Reuse note representation and key lookup — never duplicate
  note-to-frequency logic.
- MIDI input arrives only via `window` event `globalMidiMessage`
  (`{ type: noteon|noteoff, note, velocity }`) from `midi-manager.js`.
  `piano-core.js` and `piano.js` already consume it. New widgets listen
  to the event; never call `requestMIDIAccess` directly.
- Lesson hint behavior (`tutorials/lesson.php`): hint only the current
  expected key; full `highlight_keys` set is for informational lessons.
- Lesson range is full 21–108 (A0–C8) with scroll-zone buttons and
  centering on first expected key — preserve when editing.
- Touch: piano supports touch + mouse + computer keyboard; keep usable
  key proportions on narrow screens.

## Responsive + motion rules

- Breakpoints in use: `1100px` (grids 4→2 col), `820px/768px` (stack hero,
  hamburger `.nav-links.show`), `520px/480px` (single column, full-width toast).
- No fixed widths that break phones, no page-level horizontal overflow,
  no hover-only controls, `touch-action: none` on piano containers.
- Every CSS transition/animation needs a
  `@media (prefers-reduced-motion: reduce)` guard.

## Do / Do not

Do: reuse tokens, shared header/footer, `e()` + `url_path()` in PHP,
prepared PDO statements, `globalMidiMessage` for MIDI input.
Do not: new accent color per page, gradient replacing black/white neutrals,
inline `<style>` dumps, duplicated header/footer, client-side role trust,
secrets in JS, library version bump without testing all consumers
(Tone.js, @tonejs/midi, VexFlow).
