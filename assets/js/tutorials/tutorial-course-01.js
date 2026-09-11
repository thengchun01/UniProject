const targetNotes = ['C2', 'C3', 'C4', 'C5'];
let foundNotes = [];
let progressSaved = false;

function syncTutorialProgress(sectionKey) {
    if (!window.PSM_CONFIG?.isLoggedIn || !window.PSM_CONFIG?.apiBase) return;
    fetch(window.PSM_CONFIG.apiBase + '/save_progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            section_key: sectionKey,
            is_completed: true
        })
    }).catch(() => {});
}

window.addEventListener('DOMContentLoaded', () => {

    const practicePiano = document.getElementById('practice-piano');

    if (!practicePiano) return;

    new TutorialPiano(practicePiano, {
        highlightKeys: [],
        disableUnrelated: false,
        octaves: 4
    });

    practicePiano.addEventListener('click', e => {

        const key = e.target.closest('.key');

        if (!key) return;

        const note = key.dataset.note;

        if (targetNotes.includes(note)) {

            if (!foundNotes.includes(note)) {
                foundNotes.push(note);
                key.classList.add('correct-key');
            }

            if (foundNotes.length === targetNotes.length) {
                document.getElementById('practice-feedback').innerHTML = 'Correct! You found all C keys.';
                if (!progressSaved) {
                    progressSaved = true;
                    syncTutorialProgress('course_01_practice_c_keys');
                }
            }
        }
        else {
            key.classList.add('wrong-key');

            setTimeout(() => {
                key.classList.remove('wrong-key');
            }, 500);
        }
    });
});
