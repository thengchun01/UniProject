22/9/2026
1. the highlighted key node on the piano is not correctly light up, but light up all the key in the sequence instead of one by one. For example, in Lesson 3 · Part 3, E4 → D4 → C4 → D4 → E4 → E4 → E4 → D4 → D4 → D4 → E4 → G4 → G4, all c,d,e,f,g are lighted up.

Status: SOLVED (22/09/2026)
- Root cause: tutorials/lesson.php painted train-hint on the full highlight_keys set once at load.
- Fix: updateKeyHints() now hints only expectedSequence[currentStep]; it advances on each correct key, resets to step 0 on a wrong key or early release, and clears on completion/retry. Lessons without a sequence keep the static highlight set.
- Verified: inline lesson script passes node --check; git diff --check clean.
