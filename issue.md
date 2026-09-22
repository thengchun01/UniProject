## 22/9/2026
1. the highlighted key node on the piano is not correctly light up, but light up all the key in the sequence instead of one by one. For example, in Lesson 3 · Part 3, E4 → D4 → C4 → D4 → E4 → E4 → E4 → D4 → D4 → D4 → E4 → G4 → G4, all c,d,e,f,g are lighted up.

Status: SOLVED (22/09/2026)
- Root cause: tutorials/lesson.php painted train-hint on the full highlight_keys set once at load.
- Fix: updateKeyHints() now hints only expectedSequence[currentStep]; it advances on each correct key, resets to step 0 on a wrong key or early release, and clears on completion/retry. Lessons without a sequence keep the static highlight set.
- Verified: inline lesson script passes node --check; git diff --check clean.

2. the popup for the controls within the lesson, the cancel button's activate area is odd, only cover little area of the top and right

Status: SOLVED (22/09/2026)
- Root cause: the keyboardModal close button in tutorials/lesson.php was a bare × glyph with no padding, so its hit area was glyph-sized.
- Fix: gave it a 40×40px flex-centered hit area with border-radius plus aria-label/title. No behavior change.
- Verified: markup/JS ID cross-check; inline lesson script passes node --check; git diff --check clean.
- Follow-up fix (22/09/2026): enlarged to a 44×44 flex-centered box with explicit z-index, wrapped the glyph in a pointer-events:none span so the full border box receives clicks, and added visible hover/active feedback in assets/css/tutorial.css. Status: SOLVED.

3. in the lesson page, since the piano only cover c3 to c5, note that goes higher or lower than this can't be press. I need you to make the piano, and be scroll to cover all note

Status: SOLVED (22/09/2026)
- Root cause: tutorials/lesson.php hardcoded range 48–72 (C3–C5) and never wired its scroll-zone buttons, so out-of-range notes were unreachable.
- Fix: range extended to full 21–108 (A0–C8, same constants as piano.php); wired the existing scroll-zone buttons with smooth 80%-viewport steps and at-limit states (same pattern as piano.js); first expected key (C4 fallback) centered on load via rAF. Reused existing scrollable .piano-container CSS and PianoCore engine — no new abstractions.
- Verified: inline lesson script passes node --check; markup IDs match JS lookups; all seed expected_keys fall within 21–108; computer-keyboard map (C3–B4) still inside range; git diff --check clean.

4. when I go straight to the piano page, indicate "loading.. " although no song is being selected.

Status: SOLVED (22/09/2026)
- Root cause: the #song-load-banner inline style in piano.php contained both display:none and a later display:flex; the later declaration won, so the "Loading song piece…" banner showed on every direct visit.
- Fix: removed the stray display:flex from the inline style (piano.php). JS already sets display:flex only when a piece_id is actually loading.
- Verified: markup check; git diff --check clean.

5. Make the show label enable by default

Status: SOLVED (22/09/2026)
- Root cause: piano.js state.showLabels defaulted to false and the piano.php checkbox was unchecked.
- Fix: checkbox checked by default in piano.php; state.showLabels now reads the checkbox (same pattern as the neighboring keyLightsOn/staffNoteLabels toggles). No persistence layer involved, so the default applies on every load until the user toggles it.
- Verified: piano.js passes node --check; git diff --check clean.

6. when in full 88-key, I want the oct-/+ to position the Cx at the center, or at the leftmost/rightmost when it is the lowest or highest octave

Status: SOLVED (22/09/2026)
- Root cause: the Oct -/+ handlers in piano.js only updated baseOctave and rebuilt the keyboard without scrolling, so the view never followed in full 88-key mode.
- Fix: buildMainPiano() accepts an optional centerMidi (defaults to 60, so all existing callers are unchanged); the octave handlers pass C of the selected octave ((baseOctave+1)*12, i.e. C1–C7). Centering math with clamping pins the lowest/highest octaves to the edges automatically. Fold mode unaffected (single octave, no scroll).
- Verified: piano.js passes node --check; C1(24)–C7(96) all within the 21–108 range; git diff --check clean.
    1. I need you to add scroll effect when the octave changes.

Status: SOLVED (22/09/2026)
- Fix: buildMainPiano() accepts an optional smoothCenter flag; the Oct -/+ handlers pass true so the jump to C of the selected octave animates via scrollTo({behavior:"smooth"}). All other callers keep the instant scroll.
- Verified: piano.js passes node --check; git diff --check clean.

7.  There is a retry practice, when direct into piano page and no song is loaded.

Status: SOLVED (22/09/2026)
- Root cause: switchTab() showed the Retry Practice panel in practice mode (the default tab) regardless of whether a song was loaded.
- Fix: visibility now requires isPracticeMode() && state.customTrackLoaded (same pattern as the live panel), refreshed in switchTab(), after MIDI file load, after song-piece load, and in resetPiano().
- Verified: piano.js passes node --check; git diff --check clean.

8. There is no cancel button for the configure keyboard binds
    1. Can you make the restore default having a prompt before restore
    2. make the cancel button before the save button
    3. add a X button at right top conner
Status: SOLVED (22/09/2026) — follow-ups
- 8.1: Reset Default now asks window.confirm() before wiping custom binds; cancelling keeps the modal open with edits intact.
- 8.2: redesigned as a split footer — Reset Default quarantined on the left with a danger tint (existing --studio-danger token), Cancel + Save grouped on the right, so Cancel still sits immediately before Save and the destructive action can't be mis-clicked.
- 8.3: added a 44×44 × button at the modal's top-right (same kb-modal-close pattern as the lesson modal, with positioning context and hover feedback in piano.css); closes without saving.
- Verified: piano.js passes node --check; git diff --check clean.
Status: SOLVED (22/09/2026)
- Fix: added a Cancel button to the keybinds modal actions in piano.php (same position/pattern as the other modals) with a piano.js handler that closes the modal without saving; the grid rebuilds on next open so unsaved edits are discarded.
- Verified: piano.js passes node --check; git diff --check clean.

9. at the homepage, it still indicate create an account and log in, even the user already logged into an account

Status: SOLVED (22/09/2026)
- Root cause: the hero and CTA sections in index.php rendered register/login buttons unconditionally.
- Fix: when $currentUser is set, the hero shows Open Piano / My Account and the CTA section becomes "Keep Practicing" with Continue Tutorials / Open Piano. Guests see the original account buttons.
- Verified: $currentUser is provided by includes/header.php; git diff --check clean.

10. make the homepage have a continue journey button, and add a scroll to top button
    1. can you make them located at right and much visible
Status: SOLVED (22/09/2026) — follow-up
- Scroll-to-top moved to bottom-right above the widget/toast zone (bottom:110px), enlarged to 48px with a white border and stronger shadow for visibility. Widget remains draggable so minor overlap in custom positions is acceptable.
- Verified: git diff --check clean.
Status: SOLVED (22/09/2026)
- Continue Journey (index.php, logged-in hero): primary button resumes the last-accessed lesson section via the same user_progress join pattern as account.php (prepared statement, try/catch with tutorial.php fallback, escaped output). Secondary hero action is Open Piano.
- Scroll-to-top: shared #scroll-top-btn in includes/footer.php (outside the reveal-animated regions), bottom-left to avoid the user widget and MIDI toast, appears after 400px, smooth scroll with prefers-reduced-motion fallback, styled in assets/css/style.css and wired in assets/js/main.js.
- Verified: main.js passes node --check; git diff --check clean.

11. redesign keybind configuration page, so that it look much intuitive, maybe a octave a row with numbering with piano image

Status: SOLVED (22/09/2026)
- Redesign: piano.php keybinds modal now renders one mini piano strip per octave row (9 rows: A0–B0, octaves 1–7, C8), each headed by an octave-number badge plus note range, with white/black styled key cells, note names, and the bind field on each key; added a one-line usage hint. No image assets exist, so piano look is drawn with existing CSS tokens. Save/Reset/Cancel logic untouched (same input[data-midi] contract).
- Verified: piano.js passes node --check; piano.css braces balanced with all new rules present; grouping math executed (9 rows, 88 inputs, all black-key offsets valid); git diff --check clean.
- Spacing follow-up (22/09/2026): widened the keybinds dialog to 1020px, raised rows to 112px strips with larger badges/labels/fields, widened row gaps, and grew the scroll area to 62vh so more rows breathe. CSS-only change. Status: SOLVED.
- Layout rework (22/09/2026): converted the full-width strips into octave cards, 4 per row (2 on tablet, 1 on phone), each card stacking its keys as roomy full-width rows with piano-style note badges (dark for black keys). Same input[data-midi] contract, so Save/Reset/Cancel logic untouched. Status: SOLVED.
- Horizontal restore (22/09/2026): vertical stacks were harder to read, so each octave is a horizontal piano strip again (full width, keys left-to-right like a real piano), keeping the roomy 1020px dialog, 112px strips, and larger labels/fields. Same input[data-midi] contract; dead card-list code fully removed. Verified: node --check passed, CSS braces balanced, no orphaned classes, git diff --check clean. Status: SOLVED.
- Slim-down (22/09/2026): keys looked too fat because 7 whites stretched across the full dialog width, so strips now sit two per row with white keys capped at 70px and centered (88px tall, closer to real piano proportions); black-key boundary math rechecked exact for full and partial octaves. Single column under 640px. CSS-only change. Verified: CSS braces balanced, git diff --check clean. Status: SOLVED.


12. Piano page, when the user click on piano keys, and draw the notes, can you make the display follow the latest note, unless user scroll and add a right arrow

Status: SOLVED (22/09/2026)
- Fix: the sheet auto-follows the latest drawn note (smooth, reduced-motion aware); a debounced scroll listener pauses following on manual scroll and reveals a → jump button (#sheet-follow-btn in piano.php) that restores it. Programmatic scrolls are timestamp-guarded so the button never flickers. Follow resets on piano reset.
- Verified: piano.js passes node --check; git diff --check clean.

13. When the logged in user enter the keybind, and logout, the user's key bind remain, and log in back into his account, I want to keep the key-bind setting from the server instead of the offline.
    1. Can you make that there is a different between unregistered key and registered key, so it is more intuitive, and maybe a red color indicator when there is two overlapping keybind

Status: SOLVED (22/09/2026)
- Fix: loadKeyMap() now prefers the server copy whenever the user is logged in and a valid non-empty server doc exists (validated + persisted locally); guests and empty server docs keep local/defaults. This also fixes cross-device and account-switch staleness.
- 13.1: refreshBindStates() marks bound fields with an accent border, empty fields stay neutral, and duplicate binds get a red border/glow, red note label, and tooltip — refreshed live on every keystroke via the shared keydown handler and a grid-level input listener.
- Verified: 9 assertions against the real functions passed (server-wins, local fallback, guest, dup flag/tooltip/clearing); piano.js passes node --check; git diff --check clean.

14. store user's personal configuration such as piano keybind preset — cache or database?

Status: SOLVED (22/09/2026)
- Decision: hybrid. localStorage stays the fast working copy (guests/offline included); new user_settings table (user_id PK, settings_json, updated_at, FK cascade) persists {keybinds:{...}} per account for cross-device sync.
- Implemented: user_settings added to database/psm_schema.sql and live psm_2; api/save_settings.php (auth + shape/range validation + prepared upsert); header.php exposes the doc via PSM_CONFIG.userSettings (null-safe when logged out or migration missing); piano.js seeds empty browsers from server and pushes on keybind Save/Reset.
- Verified: upsert round-trip executed live against psm_2 (rolled back, 0 rows left); 6 pull-logic assertions against the real loadKeyMap() passed; piano.js passes node --check; git diff --check clean.

13. keybind, can you make the keybind synchronize across everypage including the tutorial, piano and so on, and also make three preset between preset 1 (single hand) for the "cfv..." as for the current piano page, preset 2 for the "tab, 1, q, ..." and preset 3 custom following the user settings
    1. When I enter the key for the keybind, but it doesn't registered the key into it
    2. preset 1, 2 doesn't effect the keybind settings and they are both the same as the "cfv...". And the preset 1, 2 should always be the same. And can you blur out the other option for the default preset.
    3. When in the custom keybind, can you add the reset back to dafault option for whether for reset to preset 1 or 2 

Status: SOLVED (22/09/2026)
- Fix: presets centralized in piano-core.js (KEYBIND_PRESETS single/double + getKeybindMap()); tutorial/game/car-race keyboards resolve through it, and the piano page delegates to it. Page defaults preserved (single on piano, double in tutorials) until the user picks a preset, which then wins on every page via localStorage. Piano keybind modal gained a preset radio (grid always edits Custom; Save/Reset switch to and use Custom); preset id syncs through user_settings.keybindPreset with server enum validation.
- Verified: 11 assertions against the real engine passed (defaults, cross-page override, server adoption, custom fallback, invalid rejection); piano-core.js and piano.js pass node --check; git diff --check clean.
- Follow-ups (22/09/2026):
  - Unassigned fields are now darkened (dark inset field + dimmed note) in every preset view via a new is-empty state in refreshBindStates(); bound keeps the accent border, duplicates keep red.
  - Reset collapsed back to one Reset to Default button opening a styled popup (native dialog reusing the logout-confirm styles, with backdrop-click and Cancel) offering Preset 1 · Single-hand / Preset 2 · Two-hand with full names; choice restores Custom and switches to it.
  - Shadow-veil rework (22/09/2026): replaced the dark input fields with a pointer-events:none shadow veil over each entire unassigned key (matching key corner radii), so fields stay clickable beneath and the veil lifts on focus-within; bound and duplicate treatments unchanged and never co-occur with it.
  - Load Preset no-op fix (22/09/2026): root cause was ordering — Save/Reset wrote locally then re-read from the server, so the stale server copy instantly clobbered the fresh change for logged-in users (Save was affected too). Removed the post-write re-reads; Reset now awaits the push before rebuilding the grid. Also added ?v=filemtime cache-busters to all shared CSS/JS includes (same stale-asset hazard as before) and documented the convention in AGENTS.md.
  - Stale modal after save fix (22/09/2026): playing worked but the reopened modal showed old binds until hard refresh, because PSM_CONFIG.userSettings is a page-load snapshot. pushSettingsToServer() now refreshes that in-page snapshot with the saved payload, so later server-first reads see the fresh copy. NOTE: the deployed host also needs the user_settings table (only local psm_2 has it) or pushes fail silently there.
  - Lost binds after navigation fix (22/09/2026): saving then quickly navigating aborted the push, and the next page load let the older server copy clobber the fresh local map. Saves now mark a pending flag (cleared only by a confirmed push) that makes the unconfirmed local map win, and pushes use keepalive so navigation can't abort them. Login-time server-wins behavior is unchanged.
  - Push success detection fix (22/09/2026): the push treated any HTTP response (even 404/500 pages) as success, hiding real failures and wrongly clearing the pending flag. It now requires response.ok plus data.success; the pending flag survives genuine failures so binds persist across pages regardless.
- Verified: 4 pending-flag assertions against the real engine passed plus the earlier 11 preset assertions re-passed; both JS files pass node --check; git diff --check clean.
  - Rephrase (22/09/2026): Reset to Default is now Load Preset, since the popup loads factory binds from Preset 1/2 into Custom rather than restoring a single default.
- Verified: piano.js passes node --check; CSS braces balanced; no stale reset-button references; git diff --check clean.
- Follow-ups (22/09/2026):
  - Key entry: unbindable keys (Shift, Esc, F-keys, arrows) now flash the field red instead of silently doing nothing; hint text documents Save requirement and unbindable keys. (Global piano handlers already ignore INPUT focus, so typing never sounds notes.)
  - Grid reflects the active preset: built-in presets render blurred read-only (immutable, always identical) with Save disabled; only Custom is editable. Switching presets rebuilds the grid, with a confirm guard if Custom has unsaved edits.
  - Reset choice: split into Reset to P1 / Reset to P2 (danger zone left), each with its own confirm; both restore Custom and switch to it.
- Verified: piano.js passes node --check; no stale reset-button references; git diff --check clean.