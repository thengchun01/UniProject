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