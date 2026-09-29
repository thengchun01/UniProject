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
    4. When I load preset 2 and cancel, it load the preset into the custom setting, even the user click cancel

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

14. The arrow button at the piano page, when the user sroll the music sheet to the left, it a bit hard to read, as the arrow and the background color are both white

Status: SOLVED (22/09/2026)
- Fix: the jump button is now dark (near-black background, white arrow) with a purple hover state, so it reads clearly against the white staff.
- Verified: git diff --check clean.

15. The highlight zone is not following the latest key pressed when not practicing any song in the piano page.
    2. make the key lights always on, so the option can remove
    3. not sure why, but the sheet labels is not display under the note

Status: SOLVED (22/09/2026) — follow-ups
- Key lights locked on: removed the Key lights checkbox (piano.php), its state field, toggle handler, and all guards; press/practice/play highlights now always render.
- Sheet labels missing: root cause was that renderSimpleStaff() (free-play view) never drew labels — only the loaded-song renderer did. Moved addSvgText() to module scope, passed showLabels through renderStaff(), and drew each note's label under the staff with the existing sheet-note-label style; the Sheet labels toggle now works in free play too.
- Verified: piano.js passes node --check; no keyLightsOn references remain; single addSvgText definition serves both renderers; git diff --check clean.
    1. the highlight color is still not following the latest key pressed when directly play

Status: REOPENED (22/09/2026) — code path re-verified correct (triggerNoteOn → snapKeyboardToKey, guards only fold mode / missing element / song sessions); suspected stale deployed JS or fold mode. Awaiting user check.
- Clarified + fixed (22/09/2026): free play needed a persistent latest-note marker like song mode's moving hint. New markLastNote() rings the latest pressed display key (accent outline, fold-aware) on every live press with no track loaded; cleared on reset and track load. Snap-to-view kept for off-screen keys.
- Verified: piano.js passes node --check; git diff --check clean.
Status: SOLVED (22/09/2026)
- Root cause: only song-bound practice/play hints existed; free play (no track loaded) never scrolled the keyboard, so pressed keys lit up off-screen.
- Fix: new snapKeyboardToKey() in piano.js scrolls an off-screen pressed key into centered view (smooth, reduced-motion aware, same offset math as the existing centering), called from triggerNoteOn only for live sources with no track loaded. Fold mode and song sessions untouched.
- Verified: piano.js passes node --check; git diff --check clean.

16. There should be a piano volume controller at the bottom with mute or unmute button as well, maybe under the piano key, use your best effort to design the layout

Status: SOLVED (22/09/2026)
- Fix: volume group first in the under-keys control bar — speaker mute/unmute toggle (🔊/🔇 with aria-pressed), 0–100 slider, and live %/Muted label. Maps to synth dB volume; default 100% equals today's loudness (no behavior change), dragging up from 0 unmutes, applied on every audio init. Session-only.
- Mute root cause (22/09/2026): applyVolume() was defined at module scope but read closure state, so every mute/slider click threw and silently did nothing (sound kept playing because the synth already existed). Moved it inside the page closure and wired it via a psm-audio-ready event from initAudio; muted slider now shows 0 like YouTube.
- Verified: piano.js passes node --check; CSS braces balanced; git diff --check clean.

17. In the piano page, I need you to add a discard button to the currently selected song

Status: SOLVED (22/09/2026)
- Fix: added a × discard button on the song card header that unloads the track via the existing resetPiano() (hides all song panels, returns to free play). Reuses the same path as the Reset button, so no new teardown logic.
- Verified: piano.js passes node --check; git diff --check clean.

18. when reach the end of a song in piano page, the sound awkwardly stop playing immediately, maybe it is better to let the last note last naturally.
    1. The current version, the last note still awkwardly cut

Status: SOLVED (22/09/2026) — follow-up
- Root cause: two completion paths still hard-cut voices — practice-complete called releaseAllSoundingNotes() (killing the just-played final note), and finishPlaySession() (only reachable from natural song end) called the immediate stopPlayback().
- Fix: practice-complete now uses only the natural per-note release; finishPlaySession() uses stopPlayback(true) so tails ring out. Manual stop/pause/reset/cancel paths untouched.
- Verified: piano.js passes node --check; finishPlaySession has no other callers; git diff --check clean.
Status: SOLVED (22/09/2026)
- Root cause: the natural song end called stopPlayback(), which cleared all note-off timers and releaseAll()'d the synth, cutting the ringing tail.
- Fix: stopPlayback() takes a natural flag — the end-of-song path passes true so pending note-off timers ring out naturally (per-note cleanup is unchanged); manual stop/pause/reset keep the immediate cut. Restarting during the tail clears it first for a clean start.
- Verified: piano.js passes node --check; git diff --check clean.

19. Can you make the musesheet focus and follow the current playing note which is usually highlighted. Also when the end of the song, it need to scroll back to the beginning of the song especially when practising.

Status: SOLVED (22/09/2026)
- Fix: new focusSheetOnTime() centers the currently highlighted practice/play chord in the sheet when it leaves the viewport (reduced-motion aware, existing 72% playhead follow untouched); new scrollSheetToStart() returns the sheet to the beginning at natural song end and on practice retry.
- Verified: piano.js passes node --check; git diff --check clean.

20. Row row row your boat: the note is incorrect. YOU MIGHT SKIP IT FOR NOW

21. When the song selected, because of the practice mode is not selected by default, so the play button is unavailable

Status: SOLVED (22/09/2026)
- Root cause: loading a song never switched tabs, so users on Play/Analysis/History were left with a disabled transport Play button (enabled only in non-play modes with a track).
- Fix: both song-load paths (MIDI upload and song-piece) now switchTab("practice") after loading, so Play is available immediately.
- Verified: piano.js passes node --check; git diff --check clean.

22. I noticed that why not just fit thE song panel into the top banner that have "loaded: name of the song". So there will be more room for midi Player.

Status: SOLVED (22/09/2026)
- Fix: song stats (tracks · notes · length) now render inline in the top banner via a new stats span; updateSongInfo() drives the banner (shown only with a track loaded) and the old song-info card stays hidden, freeing its grid slot for the MIDI player. Reset hides the banner and clears stats; loading/error banner text untouched.
- Verified: piano.js passes node --check; git diff --check clean.

23. When there is multiple note at the same time, the user can click the note one by one to bypass the simultaneous notes.

Status: SOLVED (22/09/2026)
- Root cause: practice mode accumulated correct chord presses indefinitely (trainPressed never timed out), so minutes-apart clicks passed simultaneous chords. (Play mode already bounds lates with its 5s overdue rule; scoring untouched.)
- Fix: chord presses must land within a 1000ms simultaneity window — a late arrival restarts the chord instead of completing it. Single notes and wrong-note leniency unchanged.
- Verified: piano.js passes node --check; git diff --check clean.

24. The retry practice doesn't set the song back to the start. How about a whole restart practice, just put a reset icon beside the play and prograssion bar
    1. I would like you to abandone the retry practice panel, just the reset button is enough, and the reset icon seem odd as it is connect, can you find another icon there look much common like other website use.
    2. Press on the reset button only bring the music sheet to the beginning, but not reseting the progression to 0.

Status: SOLVED (22/09/2026) — follow-ups
- 24.1: removed the Retry Practice panel markup and its dead wiring (remaining show() calls are null-safe); transport reset icon swapped ⟲ → ↺ (browser-style reload glyph).
- 24.2: root cause was retryPracticeSession() skipping live-score refresh, play-session indices, and the progress bar's zero-duration edge. It now also resets playExpectedIndex/chord presses/chord window, forces the progress bar to 0, and refreshes live stats — a true whole restart.
- 24.3: bar still stuck because retry ended with highlightNextTrainNote({snapToNote:true}), which re-set currentTime to the first chord's offset and repainted bar/sheet off zero. Switched to snapToNote:false like every other reset path. Verified: node --check clean. Follow-up diagnosis (22/09/2026): code re-verified complete in tree; still-stuck progression matches a partially deployed piano.js (item-19-era file has the sheet scroll but none of the 24.2 resets) — re-upload + hard refresh required.
- Verified: piano.js passes node --check; git diff --check clean.

Status: SOLVED (22/09/2026)
- Fix: added a ⟲ reset button beside Play in the transport bar wired to the existing whole-restart routine (stops playback, resets time/index/train position, scrolls sheet to start, returns to practice tab). Reuses round-btn styling; no new logic.
- Verified: piano.js passes node --check; git diff --check clean.

25. Remove the analysis tab from the piano page, just keep it in the history page.

Status: SOLVED (22/09/2026)
- Fix: removed the Analysis tab button (the analysis view stays reachable programmatically); each history item now has a single Review button opening a combined view — analysis charts + replay player (moved into the analysis view) + Export MIDI. finishPlaySession feeds the same view for consistency; reset clears it.
- Verified: piano.js passes node --check; piano.php passes php -l; no stale tab/action references; git diff --check clean.

26. in the history page, put the analysis, replay and export into the analysis page, while keeping one button only as Review to go to the analysis of the performed song.

Status: SOLVED (22/09/2026)
- Fix: covered by the item 25 rework — Review opens the analysis view with charts, replay player, and Export MIDI together; per-item Analysis/Replay/Export buttons removed (exportSessionMidi kept as the shared export routine).
- Verified: piano.js passes node --check; git diff --check clean.

27. User activity log is too long when display all. Name it as "Recent Activity". And use numbered paging for the table (this style may applied to other tables within the same account page as well but you can choose which style suit the data the best).

Status: SOLVED (22/09/2026)
- Fix: table renamed to Recent Activity with numbered pagination (8 per page, windowed numbers with prev/next, clamped page param, prepared LIMIT/OFFSET as ints). Recent Performance/Progress stay as short recent-lists, which suits summary data better.
- Verified: account.php passes php -l; paged query executed live against psm_2 (36 rows, page 2 correct); git diff --check clean.
- Item-count follow-up (22/09/2026): pagination bar now shows "Showing X–Y of Z items" computed from the current page slice; php -l clean.
- Per-page follow-up (22/09/2026): added a Per page selector (5/10/20, default 10, strictly validated) that resets to page 1 on change and persists across page links; php -l clean.

28. there is no gap between the header with the content of the piano page, game page, and admin panel page (might be same for teacher page as well)

Status: SOLVED (22/09/2026)
- Fix: restored top padding on the shared content wrapper (24px desktop, 16px mobile), which covers piano, game, admin, teacher, and all other pages using the shared header at once.
- Follow-up (22/09/2026): homepage excluded — its full-bleed hero pulls back up flush under the navigation via a negative margin matching the shared gap on desktop and mobile.
- Verified: git diff --check clean.

29. schedule page, start at today, while make it able scroll to other day.
    - the time table always focus on today, 
    - create lesson, date always today and time also the start at closest next slot, and end slot 1 hour after the start time, by default
    - use half hour as interval instead of listing every minute, and also let admin, can type the time directly instead of select from roll
    

Status: SOLVED (28/09/2026)
- schedule.php defaults month/week to today, adds a Today shortcut, highlights today's column and scrolls it into view; month/week selectors still scroll to other days.
- Create form defaults to the next open day (skips Sunday/closing) with start at the next :00/:30 slot and end +1 hour.
- Start/end are typable HH:MM text fields with a half-hour datalist; server-side :00/:30 validation unchanged.

30. Can you make the schedule page, to fit both time table and create lesson at once, you may need to lessen the margin
    1. make the teacher, student, time in the create lesson separate but still in a logical group

Status: SOLVED (28/09/2026)
- schedule.php now places the timetable grid and the lesson form side by side (sticky form column, stacks under 1100px) with tighter gaps/padding.
- Create/edit form grouped into Time, Teacher & commission, and Students fieldsets.

31. Can you make the schedule page can create a schedule by holding on the time table directly

Status: SOLVED (28/09/2026)
- Mouse press-and-drag across empty cells of one day fills date/start/end with the selected consecutive slots (highlighted); tap/click fills a single slot. Lesson cells and Closed cells are skipped.

32. The status of class should be scheduled by default, and when scheduled time passed, make them complete, unless cancel.

Status: SOLVED (28/09/2026)
- New Lessons::autoCompletePast() flips SCHEDULED lessons whose end time passed to COMPLETED (CANCELLED untouched); called on schedule.php and transactions.php loads. New lessons still default to SCHEDULED.

33. When admin create the created lesson, load the lesson into the create lesson table, and change the title

Status: SOLVED (28/09/2026)
- Clicking a lesson as admin now loads it into the side form as "Edit lesson #ID" (time/status/teacher/commission prefilled, students managed in Lesson details below); "＋ New lesson" switches back to create mode. Edit saves via api/lessons_save.php update.

34. I find the current schedule selection confusing, can you make it display as a calender, and selection by week clicking on the calender, make monday the start of the time table instead of tuesday. Also can you make it scrollable

Status: SOLVED (28/09/2026)
- schedule.php now shows a Monday-first month calendar above the grid; clicking (or Enter on) a week row loads that Mon–Sat week, with the selected week highlighted, today marked, lesson counts per day, and Sundays dimmed as closed. Old week=1-5 links still map to calendar weeks.
- The timetable grid is scrollable both directions (72vh cap, sticky header) and always runs Monday to Saturday.

35. Schedule page, I think the repeat same time is also confusing, can you make it as something like once per month, or a lesson per week,  two week and so on

Status: SOLVED (28/09/2026)
- Replaced the week-tick checkboxes with Repeat (Just once / Weekly / Every 2 weeks / Monthly same-date) plus an Extra times count (0–12). api/lessons_save.php generates the series server-side; dates outside working hours or missing month dates are skipped and reported, not fatal.

36. schedule page, can you put student over the teacher pane, also can you put sorting into these student and teacher table as well.

Status: SOLVED (28/09/2026)
- Create form now orders Students above Teacher & commission. Both pickers are tables with click-to-sort headers (student name; teacher name/status with Available/Not available/Clash pills and clash row shading). The Lesson details enrolment table is sortable by student/fee/payment too.

37. I need you to only mark the lesson as complete when the current over its time

Status: SOLVED (28/09/2026)
- Auto-complete was already end-time based; added the missing guard on the manual path: api/lessons_save.php update now rejects COMPLETED while the end time is still in the future (CANCELLED stays always allowed). Status prompt notes the rule.

38. Schedule page, Can you add filter to find all lessons for certain student as well.

Status: SOLVED (28/09/2026)
- Added a student filter dropdown (admin: all students; teacher: own students; student role stays locked to self) backed by the existing enrollment-scoped lesson query and preserved across lesson links.

39. Schedule page, the month selection on the top bar is unnecessary, we can just use the calender to adjust the date and month, so we might want to remove it.

Status: SOLVED (28/09/2026)
- Removed the month picker from the filter bar (kept as a hidden field); the calendar head now has Prev/Next month navigation and week rows for date selection.

40. Schedule page, I want you to make the time table, parallel with the create lesson, maybe we need to put the calender else where?
    1. I think it would be better to put the calender into the top bar together with the status, teacher and student filter, so that the time table will be parallel with the create lesson pane

Status: SUPERSEDED by 40.1 (28/09/2026)
- First pass put the calendar in the right sidebar; per 40.1 it now lives in the top bar (see below).

    1. I think it would be better to put the calender into the top bar together with the status, teacher and student filter, so that the time table will be parallel with the create lesson pane

    2. Instead of right of the top bar, just put it inside the left of the top bar, and the button beside the month is a bit fat.

Status: SOLVED (28/09/2026)
- Calendar moved into a top-bar grid beside the status/teacher/student filters (compact sizing, stacks under 1200px); the timetable grid below stays parallel with the create/edit form.
- 40.1.2: calendar now renders on the left of the top bar (filters right), Prev/Next month buttons slimmed down, and both grid rows stretch so items in a row share the same height.

    3. filter pane together with top of timetable under calendar; lesson details same row as calendar right side with greater portion; create lesson right of filter/timetable

Status: SOLVED (28/09/2026)
- schedule.php is now a 12-column grid: row 1 calendar (5/12) | lesson details (7/12); row 2 filters + timetable (8/12) | create/edit form (4/12, spanning rows 2-3); single column under 1200px.

    4. remove the week label; filter bar in a single row; layout as calendar|details, filter bar full width, timetable|create lesson

Status: SOLVED (28/09/2026)
- Week-of label removed; filter bar spans the full row in one line (scrolls horizontally on narrow screens); grid is now row 1 calendar|details, row 2 filter bar, row 3 timetable|form.

    5. timetable fits all days without right-scroll; timetable and form show all content without inner scroll at equal height; filter bar under calendar sharing its row with lesson details

Status: SOLVED (28/09/2026)
- Table uses fixed layout with no min-width floors so all days fit; removed inner-scroll caps (grid, sidebar, pick lists, series list) so the page scrolls as one; rows stretch for equal heights; grid is now row 1 calendar|details, row 2 filter bar (under calendar, details spanning rows 1-2), row 3 timetable|form.

    6. filter bar fits all items in one row instead of scrolling

Status: SOLVED (28/09/2026)
- Filter controls share the row with flexible shrinking widths and compact padding; no horizontal scroll.

41. in the timetable, can you try give priority as following, small time duration, big student names, middle teacher, small status ?

Status: SOLVED (28/09/2026)
- Chips restyled in that priority (small time + duration + title, big student names up to 3 + more-count, middle teacher, small status pill) with one extra enrolment-names query per week view.

42. I found out that completed lesson can't edit how many time repeat, can you re-adjust it

Status: SOLVED (28/09/2026)
- Completed lessons now show a "Repeat from this lesson" section (mode + extra times) that clones the lesson with its students/fees/commission into new scheduled lessons via the create endpoint, then jumps to the first new lesson.

43. In transaction, can you add button to delete the transaction

Status: SOLVED (28/09/2026)
- transactions.php rows gained an admin-only Delete button (confirm-guarded) that removes the enrolment, its fee and proof file via api/enrollments_save.php remove.

44. in schedule page, can you give a bit space at the right of the scheduled slot, so othat I can overlay some more class.

Status: SOLVED (28/09/2026)
- Timetable cells widened (140px min, extra right padding, 40px min-height) and grid floor raised to 880px so slots have room to stack more class chips and stay easy drag targets.

45. Can you add a delete button beside the edit lesson, and instead of using the id as title, it would be better to use the title name as title, and the id as sub-heading.

Status: SOLVED (28/09/2026)
- Edit panel now heads with the lesson title plus a "Lesson #ID · date · time" sub-heading, with Delete beside the New-lesson link sharing the guarded delete flow.

46. When in edit lesson, the student info is not display

Status: SOLVED (28/09/2026)
- Edit form gained a Students section listing enrolled name/fee/payment/proof; fee edits and removals stay centralised in Lesson details below.

47. there is a weird, unused gap between the time table with the lesson details

Status: SOLVED (28/09/2026)
- Root cause: the details panel carried its own 16px top margin on top of the wrap's 14px flex gap (30px total). Removed the inline margin so spacing matches the rest of the page.

48. the filter bar can you group it together on top of the time table

Status: SOLVED (28/09/2026)
- Top bar is now one grouped panel (filters + calendar flat inside it) sitting on top of the timetable.

49. after you have put the filter bar together with the time table, you can put the lesson details at that location.

Status: SOLVED (28/09/2026)
- Lesson details moved from below the grid into the right sidebar under the form, so the timetable keeps full width.

50. I found the lesson details and schedule still confusing. I want to see the all the slots that is created with a lesson the repeats using repeat function, and also can be deleted by all related slot. You may include this in the lesson details. Like when is the first course, and the next course based on the repeat function.

Status: SOLVED (28/09/2026)
- Lesson details gained a Repeat series section (series tag, first + next upcoming dates, every slot linked, this-lesson marked) via Lessons::getGroupLessons(); admin gets a confirm-guarded Delete-entire-series button backed by api/lessons_save.php delete_group (proof files cleaned up).

51. Currently the repeat function is confusing, the extra time meaning is unknown, how about change it to until which month? Then the repeat can have option something like just once, every week, or every two week. When it creates, it should creates lesson on same day different week, but the lesson details will list down all the lesson creates by this subscription (a temporary name) 

Status: SOLVED (28/09/2026)
- Repeat is now mode (Just once / Every week / Every two weeks) plus an until-month picker (empty means once; same weekday/time each occurrence, server errors clearly if month precedes the lesson). Unusable dates are skipped and reported; the series list in details acts as the subscription record with a short series tag.

52. the lesson details is a bit too fat, try to use the space at the right top

Status: SOLVED (28/09/2026)
- Details now live in the narrower right sidebar (same column as the form) instead of a full-width panel, with the enrolment table scrolling horizontally inside it.

53. When selecting date using the create lesson, can you make the related slot highlighted, instead of only highlight when selecting using mouse. Also, can you make the header always on top of the timetable when scroll down 

Status: SOLVED (28/09/2026)
- Typing or picking date/start/end in create/edit forms now paints the matching timetable cells (same highlight as drag-select; drag keeps priority mid-drag; defaults highlighted on load).
- Header cells gained z-index over the sticky position so the day header stays pinned on top while scrolling down.
- Rework: reverted pre-painted defaults — one shared selection synced both ways (drag fills the form, typing paints the grid) with highlight only after explicit selection; fixed drop-ordering so the highlight survives pointer-up.
- Header now docks below the sticky blurred site nav (measured offset, refreshed on resize) instead of sliding underneath it.

54. the date at form, can you include the day, and the day at the header cell can you include date, in the timetable the date should be larger than day

Status: SOLVED (28/09/2026)
- Create/edit date fields show a live weekday badge (e.g. Mon, 29 Sep 2026); timetable headers stack a small weekday over a big date (29 Sep).

55. when the date is choose in the form, it should change the timetable to include the date
    1. make sure the page position back to the time table instead of top of the page when refresh

Status: SOLVED (28/09/2026)
- Picking a form date outside the shown Mon–Sat week reloads the timetable on that date's week (Sunday resolves to its Monday week); the half-typed form is stashed to sessionStorage first and restored after reload so nothing is lost.