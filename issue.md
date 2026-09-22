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

3. in the lesson page, since the piano only cover c3 to c5, note that goes higher or lower than this can't be press. I need you to make the piano, and be scroll to cover all note

Status: SOLVED (22/09/2026)
- Root cause: tutorials/lesson.php hardcoded range 48–72 (C3–C5) and never wired its scroll-zone buttons, so out-of-range notes were unreachable.
- Fix: range extended to full 21–108 (A0–C8, same constants as piano.php); wired the existing scroll-zone buttons with smooth 80%-viewport steps and at-limit states (same pattern as piano.js); first expected key (C4 fallback) centered on load via rAF. Reused existing scrollable .piano-container CSS and PianoCore engine — no new abstractions.
- Verified: inline lesson script passes node --check; markup IDs match JS lookups; all seed expected_keys fall within 21–108; computer-keyboard map (C3–B4) still inside range; git diff --check clean.