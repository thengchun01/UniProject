<?php
require_once __DIR__ . '/includes/config.php';

// Flatten all songs+pieces into a single list for easy search/sort
$songList = [];
$dbError = null;

if (is_database_connected()) {
    try {
        $db = db();
        $stmt = $db->query("
            SELECT m.midi_id, m.file_name AS piece_name, m.difficulty, m.notes, m.file_path,
                   s.song_id, s.song_name, u.username AS uploader
            FROM midi_file m
            LEFT JOIN song s ON s.song_id = m.song_id
            LEFT JOIN users u ON u.user_id = m.user_id
            WHERE s.song_id IS NOT NULL
            ORDER BY s.song_id ASC, m.midi_id ASC
        ");
        $songList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$user = current_user();
$role = normalize_role($user['role'] ?? 'GUEST');
$canUpload = in_array($role, ['ADMIN', 'TEACHER'], true);
$songOptions = [];
if ($canUpload && is_database_connected()) {
    try {
        $songOptions = db()->query('SELECT song_id, song_name FROM song ORDER BY song_name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $songOptions = [];
    }
}

// Encode for JS
$songListJson = json_encode(array_map(function($row) {
    return [
        'midi_id'    => (int)$row['midi_id'],
        'song_name'  => $row['song_name'],
        'piece_name' => $row['piece_name'],
        'difficulty' => $row['difficulty'] ?? 'Beginner',
        'uploader'   => $row['uploader'] ?? null,
        'has_data'   => !empty($row['notes']) || !empty($row['file_path']),
    ];
}, $songList), JSON_UNESCAPED_UNICODE);

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/songs.css">

<section class="songs-page" id="songs-page">

    <!-- ── Hero ──────────────────────────────────────── -->
    <header class="songs-hero">
        <div>
            <p class="eyebrow">Practice Library</p>
            <h1 class="section-title">Song Collection</h1>
        </div>
        <a href="<?= BASE_URL ?>piano.php" class="btn primary-btn">Open Piano Studio</a>
    </header>

    <?php if ($dbError): ?>
        <div class="songs-alert songs-alert--error">
            <strong>Database error:</strong> <?= e($dbError) ?><br>
            <small>Make sure <code>psm_schema.sql</code> is imported and the database is connected.</small>
        </div>
    <?php elseif (empty($songList)): ?>
        <div class="songs-empty">
            <div class="songs-empty-icon">🎵</div>
            <h2>No songs yet</h2>
            <p>Run the setup script to seed the built-in song library.</p>
            <a href="<?= BASE_URL ?>setup_songs.php" class="btn primary-btn">Run Setup Songs</a>
        </div>
    <?php else: ?>

        <!-- ── Toolbar ────────────────────────────────── -->
        <div class="songs-toolbar">
            <div class="songs-search-wrap">
                <span class="songs-search-icon">🔍</span>
                <input type="text" id="songs-search" class="songs-search" placeholder="Search songs or pieces…" autocomplete="off">
                <button class="songs-search-clear" id="songs-search-clear" title="Clear" hidden>✕</button>
            </div>

            <div class="songs-filters">
                <select id="songs-sort" class="songs-select">
                    <option value="default">Sort: Default</option>
                    <option value="name-asc">Name A → Z</option>
                    <option value="name-desc">Name Z → A</option>
                    <option value="diff-asc">Difficulty ↑</option>
                    <option value="diff-desc">Difficulty ↓</option>
                </select>

                <select id="songs-filter-diff" class="songs-select">
                    <option value="">All Difficulties</option>
                    <option value="Beginner">Beginner</option>
                    <option value="Intermediate">Intermediate</option>
                    <option value="Advanced">Advanced</option>
                </select>
            </div>

            <span class="songs-count" id="songs-count"><?= count($songList) ?> pieces</span>
        </div>

        <!-- ── List ───────────────────────────────────── -->
        <?php if ($canUpload): ?>
        <!-- ── Teacher upload: pieces appear here for every student ── -->
        <section class="songs-upload" aria-label="Upload a library piece">
            <h2>Share a piece with all students</h2>
            <p class="songs-upload-hint">Upload a MIDI file — it appears in this library for every student instantly.</p>
            <form id="midi-upload-form" enctype="multipart/form-data">
                <label>Title
                    <input type="text" name="title" maxlength="200" required placeholder="e.g. Für Elise">
                </label>
                <label>Difficulty
                    <select name="difficulty" required>
                        <option value="">Choose…</option>
                        <option>Beginner</option>
                        <option>Intermediate</option>
                        <option>Advanced</option>
                    </select>
                </label>
                <label>Song
                    <select name="song_id">
                        <option value="">Choose…</option>
                        <?php foreach ($songOptions as $s): ?>
                            <option value="<?= (int) $s['song_id'] ?>"><?= e($s['song_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Or new song
                    <input type="text" name="new_song" maxlength="200" placeholder="New song name (optional)">
                </label>
                <label class="btn secondary-btn songs-file-btn">Choose MIDI file<input type="file" name="midi" accept=".mid,.midi" hidden required></label>
                <span class="songs-file-name" id="midi-file-name">No file chosen</span>
                <button type="submit" class="primary-btn">Upload piece</button>
            </form>
        </section>
        <?php endif; ?>
        <div class="songs-list" id="songs-list">
            <div class="songs-list-header">
                <span class="col-song">Song</span>
                <span class="col-piece">Piece</span>
                <span class="col-diff">Difficulty</span>
                <span class="col-action"></span>
            </div>
            <div id="songs-list-body">
                <!-- Rows injected by JS -->
            </div>
            <div class="songs-no-results" id="songs-no-results" hidden>
                <p>No songs match your search.</p>
            </div>
        </div>

    <?php endif; ?>
</section>

<script>
(function () {
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
    const DIFF_ORDER = { 'Beginner': 1, 'Intermediate': 2, 'Advanced': 3 };
    const DIFF_COLOR = { 'Beginner': '#4caf50', 'Intermediate': '#ff9800', 'Advanced': '#f44336' };
    const ALL_SONGS  = <?= $songListJson ?>;

    let filtered = [...ALL_SONGS];

    const searchEl    = document.getElementById('songs-search');
    const clearBtn    = document.getElementById('songs-search-clear');
    const sortEl      = document.getElementById('songs-sort');
    const filterDiff  = document.getElementById('songs-filter-diff');
    const listBody    = document.getElementById('songs-list-body');
    const noResults   = document.getElementById('songs-no-results');
    const countEl     = document.getElementById('songs-count');

    // ── Teacher upload ───────────────────────────────
    const upForm = document.getElementById('midi-upload-form');
    if (upForm) {
        const fileInput = upForm.querySelector('input[type="file"]');
        const fileName = document.getElementById('midi-file-name');
        if (fileInput) fileInput.addEventListener('change', () => {
            fileName.textContent = (fileInput.files && fileInput.files[0] && fileInput.files[0].name) || 'No file chosen';
        });
        upForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = upForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            const oldText = btn.textContent;
            btn.textContent = 'Uploading…';
            fetch(BASE_URL + 'api/midi_upload.php', { method: 'POST', body: new FormData(upForm) })
                .then((r) => r.json())
                .then((out) => {
                    if (!out.success) throw new Error(out.error || 'Upload failed.');
                    PSMModal.alert('Piece shared — every student can now play it from the library.').then(() => window.location.reload());
                })
                .catch((err) => PSMModal.alert(err.message || 'Upload failed.'))
                .finally(() => { btn.disabled = false; btn.textContent = oldText; });
        });
    }

    if (!listBody) return; // DB error / empty state

    function applyFilters() {
        const query   = (searchEl?.value || '').toLowerCase().trim();
        const sortVal = sortEl?.value || 'default';
        const diff    = filterDiff?.value || '';

        filtered = ALL_SONGS.filter(row => {
            const matchText = !query ||
                row.song_name.toLowerCase().includes(query) ||
                row.piece_name.toLowerCase().includes(query) ||
                (row.difficulty || '').toLowerCase().includes(query);
            const matchDiff = !diff || row.difficulty === diff;
            return matchText && matchDiff;
        });

        if (sortVal === 'name-asc')   filtered.sort((a, b) => a.song_name.localeCompare(b.song_name) || a.piece_name.localeCompare(b.piece_name));
        if (sortVal === 'name-desc')  filtered.sort((a, b) => b.song_name.localeCompare(a.song_name) || b.piece_name.localeCompare(a.piece_name));
        if (sortVal === 'diff-asc')   filtered.sort((a, b) => (DIFF_ORDER[a.difficulty] || 9) - (DIFF_ORDER[b.difficulty] || 9));
        if (sortVal === 'diff-desc')  filtered.sort((a, b) => (DIFF_ORDER[b.difficulty] || 9) - (DIFF_ORDER[a.difficulty] || 9));

        renderList();
    }

    function renderList() {
        listBody.innerHTML = '';
        if (filtered.length === 0) {
            noResults.hidden = false;
            countEl.textContent = '0 pieces';
            return;
        }
        noResults.hidden = true;
        countEl.textContent = filtered.length + ' piece' + (filtered.length !== 1 ? 's' : '');

        let lastSong = null;

        filtered.forEach((row, index) => {
            // Group separator when song changes
            const songChanged = row.song_name !== lastSong;
            lastSong = row.song_name;

            const tr = document.createElement('div');
            tr.className = 'songs-row' + (songChanged ? ' songs-row--first-piece' : '');
            tr.dataset.index = index;

            const color = DIFF_COLOR[row.difficulty] || '#888';
            const url   = BASE_URL + 'piano.php?piece_id=' + row.midi_id;

            tr.innerHTML = `
                <span class="col-song">${songChanged ? escHtml(row.song_name) : '<span class="col-song-cont">↳</span>'}</span>
                <span class="col-piece">${escHtml(row.piece_name)}${row.uploader ? `<small class="songs-uploader">by ${escHtml(row.uploader)}</small>` : ''}</span>
                <span class="col-diff">
                    <span class="diff-badge" style="color:${color};border-color:${color};">${escHtml(row.difficulty)}</span>
                </span>
                <span class="col-action">
                    ${row.has_data
                        ? `<a href="${url}" class="btn-play-row" title="Open in Piano Studio">▶ Play</a>`
                        : `<span class="btn-play-row disabled">No Data</span>`}
                </span>
            `;

            // Row click also navigates (except the button click which handles itself)
            if (row.has_data) {
                tr.addEventListener('click', e => {
                    if (e.target.closest('.btn-play-row')) return;
                    window.location.href = url;
                });
                tr.style.cursor = 'pointer';
            }

            listBody.appendChild(tr);
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    // ── Event listeners ───────────────────────────────
    searchEl?.addEventListener('input', () => {
        clearBtn.hidden = !searchEl.value;
        applyFilters();
    });
    clearBtn?.addEventListener('click', () => {
        searchEl.value = '';
        clearBtn.hidden = true;
        applyFilters();
    });
    sortEl?.addEventListener('change', applyFilters);
    filterDiff?.addEventListener('change', applyFilters);

    // Initial render
    applyFilters();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
