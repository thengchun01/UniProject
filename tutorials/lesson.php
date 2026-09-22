<?php 
require_once __DIR__ . '/../includes/config.php';

$topicId = (int)($_GET['id'] ?? 0);
$sectionId = (int)($_GET['section'] ?? 0);

if ($topicId <= 0 || !isset($tutorialManager)) {
    redirect_to('../tutorial.php');
}

$currentUserObj = null;
if (is_logged_in() && isset($userManager)) {
    $currentUserObj = $userManager->getUser($_SESSION['user_id']);
}

// Fetch topic and all sections
$stmt = db()->prepare("SELECT * FROM tutorial_topic WHERE tutorial_id = :id");
$stmt->execute(['id' => $topicId]);
$topic = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$topic) {
    redirect_to('../tutorial.php');
}

// Check teacher toggles
if ($currentUserObj && isset($classroomManager)) {
    if ($classroomManager->isTutorialDisabledForStudent($currentUserObj, $topicId)) {
        flash_set('error', 'This tutorial has been disabled by your teacher.');
        redirect_to('../tutorial.php');
    }
}

$sections = $tutorialManager->getSectionsByTopic($topicId);
if (empty($sections)) {
    flash_set('error', 'This tutorial has no content.');
    redirect_to('../tutorial.php');
}

// Determine active section
$activeSectionIndex = 0;
foreach ($sections as $index => $sec) {
    if ($sec['section_id'] == $sectionId) {
        $activeSectionIndex = $index;
        break;
    }
}
$activeSection = $sections[$activeSectionIndex];

// Check progress if logged in
$isCompleted = false;
$progressMap = [];
if ($currentUserObj) {
    $stmt = db()->prepare("SELECT section_id, is_completed FROM user_progress WHERE user_id = :uid");
    $stmt->execute(['uid' => $currentUserObj['user_id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $progressMap[$row['section_id']] = (int)$row['is_completed'];
    }
    $isCompleted = !empty($progressMap[$activeSection['section_id']]);
}

include __DIR__ . '/../includes/header.php'; 
?>

<link rel="stylesheet" href="../assets/css/tutorial.css">
<link rel="stylesheet" href="../assets/css/piano.css">

<div class="lesson-container">

    <div class="lesson-sidebar">
        <h2><?= e($topic['title']) ?></h2>
        <div class="sidebar-desc"><?= e($topic['content']) ?></div>

        <ul>
            <?php foreach ($sections as $index => $sec): ?>
                <?php 
                    $secCompleted = !empty($progressMap[$sec['section_id']]);
                    $isActive = ($index === $activeSectionIndex);
                ?>
                <li>
                    <a href="?id=<?= $topicId ?>&section=<?= $sec['section_id'] ?>" class="<?= $isActive ? 'active' : '' ?>">
                        <div class="section-status <?= $secCompleted ? 'completed' : '' ?>">
                            <?= $secCompleted ? '✓' : ($index + 1) ?>
                        </div>
                        Part <?= $index + 1 ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        
        <div style="margin-top: 30px;">
            <a href="../tutorial.php" style="color: #666; font-size: 13px; text-decoration: underline;">&larr; Back to all lessons</a>
        </div>
    </div>

    <div class="lesson-content">
        <section class="lesson-section">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div>
                    <h1 style="font-size:24px; color:var(--primary-color); margin-bottom:5px;">Lesson <?= $topic['order_index'] ?> &middot; Part <?= $activeSectionIndex + 1 ?></h1>
                </div>
                <a href="../tutorial.php" class="btn secondary" style="text-decoration: none;">Return to Tutorials</a>
            </div>
            
            <?= $activeSection['content'] ?>
            
            <!-- Hidden data for JS (always present; logged_in flag controls save behaviour) -->
            <div id="tutorialStateData" 
                 data-section-id="<?= $activeSection['section_id'] ?>" 
                 data-logged-in="<?= $currentUserObj ? 'true' : 'false' ?>"
                 data-completed="<?= $isCompleted ? 'true' : 'false' ?>"
                 data-expected-keys="<?= e($activeSection['expected_keys'] ?? '') ?>"
                 data-highlight-keys="<?= e($activeSection['highlight_keys'] ?? '') ?>"
                 data-sheet-notes="<?= e($activeSection['sheet_notes'] ?? '') ?>"
                 hidden></div>

            <?php if (!$currentUserObj): ?>
                <div style="margin-top: 20px; font-size: 14px; color: #666;">
                    <a href="../account.php?mode=login" style="color: var(--primary-color);">Log in</a> to save your progress and earn XP.
                </div>
            <?php endif; ?>
            
            <!-- Music Sheet Visualizer (only shown when there are sheet notes) -->
            <?php if (!empty($activeSection['sheet_notes'])): ?>
            <div id="visualizer" style="margin-top: 30px; background: #fff; border: 1px solid var(--studio-border); border-radius: 8px 8px 0 0; overflow-x: auto; padding: 12px 16px; display: block;">
                <div id="staff-container" style="display: inline-block; min-width: 100%;"></div>
            </div>
            <?php else: ?>
            <div id="staff-container" style="display:none;"></div>
            <?php endif; ?>

            <!-- Instruction / Feedback & Controls Button -->
            <div style="display:flex; justify-content:space-between; align-items:center; min-height: 28px; padding: 6px 0;">
                <div style="width:100px;"></div> <!-- spacer -->
                <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <div id="piano-feedback" style="font-weight: bold; font-size: 15px; text-align:center;"></div>
                    <button id="btn-retry-lesson" style="display:none; background:var(--studio-panel); border:1px solid var(--studio-border); border-radius:12px; padding:4px 12px; font-size:12px; cursor:pointer; color:var(--studio-accent);">↻ Retry Exercise</button>
                </div>
                <div style="width:100px; text-align:right;">
                    <button onclick="document.getElementById('keyboardModal').style.display='flex'" style="background:none; border:none; cursor:pointer; color:var(--studio-muted); font-size:13px; text-decoration:underline;">
                        ⌨️ Controls
                    </button>
                </div>
            </div>

            <!-- Keyboard Controls Modal -->
            <div id="keyboardModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--studio-panel); padding:24px; border-radius:12px; max-width:500px; width:90%; position:relative; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <button onclick="document.getElementById('keyboardModal').style.display='none'" style="position:absolute; top:12px; right:16px; background:none; border:none; font-size:24px; cursor:pointer; color:var(--studio-muted);">&times;</button>
                    <h2 style="margin-top:0; font-size:20px; display:flex; align-items:center; gap:8px;">⌨️ Keyboard Controls</h2>
                    <p style="color:var(--studio-muted); font-size:14px; margin-bottom:20px;">Use your computer keyboard to play the piano.</p>
                    
                    <div style="background:var(--studio-bg); padding:16px; border-radius:8px; border:1px solid var(--studio-border); margin-bottom:16px;">
                        <strong style="color:var(--studio-accent); display:block; margin-bottom:8px;">Left Hand (C3 - B3)</strong>
                        <code style="background:transparent; padding:0; font-size:15px; font-family:monospace; color:#333; letter-spacing:1px;">Tab 1 q 2 w e 4 r 5 t 6 y</code>
                    </div>
                    
                    <div style="background:var(--studio-bg); padding:16px; border-radius:8px; border:1px solid var(--studio-border);">
                        <strong style="color:var(--studio-accent); display:block; margin-bottom:8px;">Right Hand (C4 - B4)</strong>
                        <code style="background:transparent; padding:0; font-size:15px; font-family:monospace; color:#333; letter-spacing:1px;">u 8 i 9 o p - [ = ] Backspace \</code>
                    </div>
                </div>
            </div>

            <!-- Embedded Visual Piano using correct CSS class structure -->
            <div class="piano-wrapper" style="position:relative; height: 200px; border: 1px solid var(--studio-border); border-top: none; border-radius: 0 0 8px 8px; margin-top: 0; overflow:hidden;">
                <div class="piano-keys-area">
                    <button class="scroll-zone scroll-zone-left" id="scroll-zone-left" type="button">&lt;</button>
                    <div id="piano-container" class="piano-container"></div>
                    <button class="scroll-zone scroll-zone-right" id="scroll-zone-right" type="button">&gt;</button>
                </div>
                <!-- Login overlay shown only to guests when the section has interactive keys -->
                <div id="piano-login-overlay" style="display:none; position:absolute; inset:0; background:rgba(255,255,255,0.88); backdrop-filter:blur(4px); z-index:10; flex-direction:column; justify-content:center; align-items:center; gap:10px; text-align:center; padding:20px;">
                    <span style="font-size:32px;">🔒</span>
                    <p style="margin:0; font-weight:600; font-size:15px; color:#333;">Sign in to play this exercise</p>
                    <p style="margin:0; font-size:13px; color:#666;">Track your progress and earn XP by logging into your account.</p>
                    <a href="../account.php?mode=login" style="margin-top:8px; background:var(--primary-color); color:#fff; padding:9px 22px; border-radius:6px; text-decoration:none; font-size:14px; font-weight:600;">Log In</a>
                    <a href="../account.php?mode=register" style="font-size:12px; color:var(--primary-color); text-decoration:underline;">Don't have an account? Register</a>
                </div>
            </div>
        </section>

        <div class="lesson-nav">
            <?php if ($activeSectionIndex > 0): ?>
                <?php $prevSec = $sections[$activeSectionIndex - 1]; ?>
                <a href="?id=<?= $topicId ?>&section=<?= $prevSec['section_id'] ?>">&larr; Previous Part</a>
            <?php else: ?>
                <span class="disabled">&larr; Previous Part</span>
            <?php endif; ?>

            <?php if ($activeSectionIndex < count($sections) - 1): ?>
                <?php $nextSec = $sections[$activeSectionIndex + 1]; ?>
                <a href="?id=<?= $topicId ?>&section=<?= $nextSec['section_id'] ?>" class="btn-next-part secondary">Next Part &rarr;</a>
            <?php else: ?>
                <a href="../tutorial.php" class="btn-next-part secondary">Finish Lesson &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- XP Toast -->
<div id="xpToast" class="xp-toast">
    <div id="xpToastContent"></div>
</div>

<script src="../assets/js/piano-core.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stateData = document.getElementById('tutorialStateData');
    // stateData is always present now; isLoggedIn flag controls save behaviour
    const isLoggedIn = stateData ? stateData.dataset.loggedIn === 'true' : false;
    
    const sectionId = stateData.dataset.sectionId;
    let isCompleted = stateData.dataset.completed === 'true';
    const feedbackEl = document.getElementById('piano-feedback');
    
    // Parse tutorial data (empty string = no requirement)
    const expectedKeysRaw = stateData.dataset.expectedKeys || '';
    const highlightKeysRaw = stateData.dataset.highlightKeys || '';
    const sheetNotesRaw = stateData.dataset.sheetNotes || '';
    
    // Check if this is a required Practice Exercise
    const lessonContentText = document.querySelector('.lesson-section').innerText;
    const isPracticeExercise = lessonContentText.includes('Practice Exercise');
    
    // Convert comma-separated note names to array, stripping whitespace and parsing duration
    const expectedSequence = expectedKeysRaw.split(',').map(s => {
        const parts = s.trim().split('/');
        return parts[0] ? { name: parts[0], duration: parts[1] || null } : null;
    }).filter(Boolean);
    
    const highlightKeys = highlightKeysRaw.split(',').map(s => s.trim()).filter(Boolean);
    const sheetNotesArray = sheetNotesRaw.split(',').map(s => s.trim()).filter(Boolean);
    
    let currentStep = 0;
    let pianoEngine = null;
    let holdTimer = null;
    let activeHoldMidi = null;
    const DURATION_BEATS = { "w": 4, "h": 2, "q": 1, "8": 0.5, "16": 0.25 };

    // Hint ONLY the current step's key so the highlight advances one by one
    // as the user plays. Informational lessons without a sequence keep the
    // static highlight set. Clears all hints once the section is completed.
    // (pianoContainer is assigned during init; every call happens after that.)
    function updateKeyHints() {
        if (!pianoContainer) return;
        pianoContainer.querySelectorAll('.key.train-hint').forEach(keyEl => keyEl.classList.remove('train-hint'));
        if (isCompleted) return;
        if (expectedSequence.length > 0) {
            const current = expectedSequence[currentStep];
            if (current) {
                const midi = nameToMidi(current.name);
                pianoContainer.querySelector('.key[data-midi="' + midi + '"]')?.classList.add('train-hint');
            }
        } else if (highlightKeys.length > 0) {
            highlightKeys.forEach(noteName => {
                const midi = nameToMidi(noteName);
                const keyEl = pianoContainer.querySelector('.key[data-midi="' + midi + '"]');
                if (keyEl) keyEl.classList.add('train-hint');
            });
        }
    }

    // Show login overlay when there are interactive keys but user is a guest
    const loginOverlay = document.getElementById('piano-login-overlay');
    if (!isLoggedIn && expectedSequence.length > 0 && loginOverlay) {
        loginOverlay.style.display = 'flex';
    }

    // Helper: Note Name to MIDI (Fallback if Tone is not ready)
    function nameToMidi(name) {
        if (window.Tone && Tone.Frequency) {
            try { return Tone.Frequency(name).toMidi(); } catch(e){}
        }
        // Basic fallback for standard notes like C4, F#3
        const notes = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"];
        const match = name.match(/^([A-G]#?)([0-9])$/i);
        if (match) {
            const pitch = match[1].toUpperCase();
            const oct = parseInt(match[2], 10);
            const idx = notes.indexOf(pitch);
            if (idx >= 0) return (oct + 1) * 12 + idx;
        }
        return 60; // Default C4
    }

    async function markAsComplete() {
        if (isCompleted || !isLoggedIn) return;
        isCompleted = true; // Prevent multiple calls
        
        if (feedbackEl) {
            feedbackEl.style.color = 'var(--studio-accent)';
            feedbackEl.innerText = 'Correct! Section completed.';
            document.getElementById('btn-retry-lesson').style.display = 'block';
        }
        updateKeyHints();
        
        try {
            const response = await fetch('../api/complete_section.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ section_id: sectionId })
            });
            const data = await response.json();
            
            if (data.success) {
                const sidebarLink = document.querySelector('.lesson-sidebar a.active .section-status');
                if (sidebarLink) {
                    sidebarLink.classList.add('completed');
                    sidebarLink.innerHTML = '✓';
                }
                
                if (data.xp_gained > 0) {
                    showXpToast(data.xp_gained, data.level_up, data.level);
                }
                
                // If there's a next button, highlight it
                const nextBtn = document.querySelector('.btn-next-part');
                if (nextBtn) {
                    nextBtn.classList.add('primary');
                    nextBtn.classList.remove('secondary');
                }
            }
        } catch (err) {
            console.error('Failed to auto-complete section:', err);
            isCompleted = false;
        }
    }

    // 1. Initialize Piano Core
    const pianoContainer = document.getElementById('piano-container');
    const staffContainer = document.getElementById('staff-container');

    if (window.PianoCore && pianoContainer) {
        // Directly use createPianoKeys for the tutorial
        const TUTORIAL_START = 48; // C3
        const TUTORIAL_END   = 72; // C5 (3 octave boundaries: C3, C4, C5)
        PianoCore.syncKeySizing();
        PianoCore.createPianoKeys(pianoContainer, {
            start: TUTORIAL_START,
            end: TUTORIAL_END,
            showLabels: true,
            onDown: async function(midi, source) {
                await PianoCore.initAudio();
                const synth = PianoCore.getSynth();
                if (synth) synth.triggerAttack(PianoCore.midiName(midi), Tone.now(), 0.72);
                pianoContainer.querySelector('.key[data-midi="' + midi + '"]')?.classList.add('active-key');

                // Validate sequence
                if (isCompleted || expectedSequence.length === 0) return;
                const expectedNote = expectedSequence[currentStep];
                const expectedMidi = nameToMidi(expectedNote.name);

                if (midi === expectedMidi) {
                    if (expectedNote.duration) {
                        // Hold required!
                        const beats = DURATION_BEATS[expectedNote.duration] || 1;
                        const requiredMs = beats * 500; // 500ms per beat (120bpm)
                        
                        if (feedbackEl) {
                            feedbackEl.style.color = '#eab308';
                            feedbackEl.innerText = 'Hold...';
                        }
                        activeHoldMidi = midi;
                        holdTimer = setTimeout(() => {
                            currentStep++;
                            if (feedbackEl) {
                                feedbackEl.style.color = '#16a34a';
                                feedbackEl.innerText = currentStep + ' / ' + expectedSequence.length + ' ✓';
                            }
                            if (typeof updateStaff === 'function') updateStaff();
                            updateKeyHints();
                            if (currentStep >= expectedSequence.length) markAsComplete();
                            holdTimer = null;
                            activeHoldMidi = null;
                        }, requiredMs);
                    } else {
                        // Instant success
                        currentStep++;
                        if (feedbackEl) {
                            feedbackEl.style.color = '#16a34a';
                            feedbackEl.innerText = currentStep + ' / ' + expectedSequence.length + ' ✓';
                        }
                        if (typeof updateStaff === 'function') updateStaff();
                        updateKeyHints();
                        if (currentStep >= expectedSequence.length) {
                            markAsComplete();
                        }
                    }
                } else {
                    currentStep = 0;
                    if (holdTimer) clearTimeout(holdTimer);
                    holdTimer = null;
                    activeHoldMidi = null;
                    
                    if (feedbackEl) {
                        feedbackEl.style.color = 'var(--studio-danger)';
                        feedbackEl.innerText = '✗ Try again! Play: ' + expectedSequence.map(n=>n.name).join(' → ');
                        setTimeout(() => { if (!isCompleted) feedbackEl.innerText = 'Play: ' + expectedSequence.map(n=>n.name).join(' → '); }, 2500);
                    }
                    if (typeof updateStaff === 'function') updateStaff();
                    updateKeyHints();
                }
            },
            onUp: function(midi, source) {
                const synth = PianoCore.getSynth();
                if (synth) synth.triggerRelease(PianoCore.midiName(midi), Tone.now());
                pianoContainer.querySelector('.key[data-midi="' + midi + '"]')?.classList.remove('active-key');
                
                // If they released the key they were supposed to hold
                if (activeHoldMidi === midi && holdTimer) {
                    clearTimeout(holdTimer);
                    holdTimer = null;
                    activeHoldMidi = null;
                    currentStep = 0;
                    
                    if (feedbackEl) {
                        feedbackEl.style.color = 'var(--studio-danger)';
                        feedbackEl.innerText = '✗ Released too early! Try again.';
                        setTimeout(() => { if (!isCompleted) feedbackEl.innerText = 'Play: ' + expectedSequence.map(n=>n.name).join(' → '); }, 2500);
                    }
                    if (typeof updateStaff === 'function') updateStaff();
                    updateKeyHints();
                }
            }
        });

        // 2. Hint the current step's key after piano renders
        updateKeyHints();

        // 3. Render sheet music function
        window.updateStaff = function() {
            if (sheetNotesArray.length > 0 && staffContainer) {
                let noteStep = 0;
                const noteObjects = sheetNotesArray.map((noteStr) => {
                    const parts = noteStr.split('/');
                    const name = parts[0];
                    const duration = parts[1] || 'q';
                    const isRest = name.toUpperCase() === 'R';
                    
                    const obj = {
                        name: isRest ? 'Rest' : name,
                        midi: isRest ? 0 : nameToMidi(name),
                        time: isRest ? -1 : noteStep, // Align time to expectedSequence index
                        duration: duration,
                        isRest: isRest
                    };
                    if (!isRest) noteStep++;
                    return obj;
                });
                PianoCore.renderStaff(staffContainer, noteObjects, { activeIndex: isCompleted ? -1 : currentStep });
            }
        };
        updateStaff();

        // 4. Scroll so C3 is visible at the left (all keys start from C3)
        // Use manual scrollLeft = 0 since we always start from C3 now
        pianoContainer.scrollLeft = 0;

        // 5. Show instruction prompt if there's a sequence to play
        if (expectedSequence.length > 0) {
            if (isCompleted) {
                document.getElementById('btn-retry-lesson').style.display = 'block';
            } else if (feedbackEl) {
                feedbackEl.style.color = '#555';
                feedbackEl.innerText = 'Play: ' + expectedSequence.map(n=>n.name).join(' → ');
            }
        }
        
        // 6. Retry Button Logic
        document.getElementById('btn-retry-lesson').addEventListener('click', () => {
            isCompleted = false;
            currentStep = 0;
            if (holdTimer) clearTimeout(holdTimer);
            holdTimer = null;
            activeHoldMidi = null;
            
            document.getElementById('btn-retry-lesson').style.display = 'none';
            if (feedbackEl) {
                feedbackEl.style.color = '#555';
                feedbackEl.innerText = 'Play: ' + expectedSequence.map(n=>n.name).join(' → ');
            }
            updateStaff();
            updateKeyHints();
        });

    } else {
        console.error("PianoCore is not loaded or piano container missing.");
    }

    // Trigger completion when clicking Next
    const nextBtns = document.querySelectorAll('.btn-next-part');
    nextBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            // Only require piano interaction if it's a Practice Exercise
            if (!isCompleted && expectedSequence.length > 0 && isPracticeExercise) {
                e.preventDefault();
                alert("This is a Practice Exercise! Please complete it on the piano first.");
            } else if (!isCompleted) {
                e.preventDefault();
                const href = this.href;
                // If it's not a practice exercise, they can skip it. We mark as complete and go.
                markAsComplete().then(() => { window.location.href = href; });
            }
        });
    });
});

function showXpToast(xpGained, levelUp, newLevel) {
    const toast = document.getElementById('xpToast');
    const content = document.getElementById('xpToastContent');
    
    let html = `+${xpGained} XP Earned!`;
    if (levelUp) {
        toast.classList.add('level-up');
        html += `<br><span style="font-size:20px;">🎉 Level Up! You are now Level ${newLevel}</span>`;
    } else {
        toast.classList.remove('level-up');
    }
    
    content.innerHTML = html;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 4000);
}
</script>

<?php include '../includes/footer.php'; ?>
