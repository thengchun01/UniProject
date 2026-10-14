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

<!-- ── Variance picker: choose a version, then play ── -->
<div class="songs-picker-overlay" id="songs-picker" hidden>
    <div class="songs-picker" role="dialog" aria-modal="true" aria-labelledby="songs-picker-title">
        <div class="songs-picker-head">
            <div>
                <p class="eyebrow">Choose version</p>
                <h2 id="songs-picker-title">Song</h2>
            </div>
            <button type="button" class="songs-picker-close" id="songs-picker-close" aria-label="Close">×</button>
        </div>
        <p class="songs-picker-sub" id="songs-picker-sub"></p>
        <div class="songs-piece-cards" id="songs-piece-cards"></div>
    </div>
</div>

<script>
(function () {
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
    const DIFF_ORDER = { 'Beginner': 1, 'Intermediate': 2, 'Advanced': 3 };
    const DIFF_COLOR = { 'Beginner': '#4caf50', 'Intermediate': '#ff9800', 'Advanced': '#f44336' };
    const ALL_SONGS  = <?= $songListJson ?>;

    let filtered = [...ALL_SONGS];
    let sortVal = 'default';

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
        const query = (searchEl?.value || '').toLowerCase().trim();
        sortVal = sortEl?.value || 'default';
        const diff = filterDiff?.value || '';

        filtered = ALL_SONGS.filter(row => {
            const matchText = !query ||
                (row.song_name || '').toLowerCase().includes(query) ||
                row.piece_name.toLowerCase().includes(query) ||
                (row.difficulty || '').toLowerCase().includes(query);
            const matchDiff = !diff || row.difficulty === diff;
            return matchText && matchDiff;
        });

        renderList();
    }

    function renderList() {
        listBody.innerHTML = '';
        // Collapse variances: one row per song, difficulties combined.
        const bySong = [];
        const seen = {};
        filtered.forEach(row => {
            const name = row.song_name || 'Untitled Song';
            if (!seen[name]) { seen[name] = { name: name, pieces: [], diffs: [] }; bySong.push(seen[name]); }
            seen[name].pieces.push(row);
            if (row.difficulty && seen[name].diffs.indexOf(row.difficulty) === -1) seen[name].diffs.push(row.difficulty);
        });
        const rank = d => DIFF_ORDER[d] || 9;
        if (sortVal === 'name-asc')  bySong.sort((a, b) => a.name.localeCompare(b.name));
        if (sortVal === 'name-desc') bySong.sort((a, b) => b.name.localeCompare(a.name));
        if (sortVal === 'diff-asc')  bySong.sort((a, b) => Math.min(...a.diffs.map(rank)) - Math.min(...b.diffs.map(rank)));
        if (sortVal === 'diff-desc') bySong.sort((a, b) => Math.max(...b.diffs.map(rank)) - Math.max(...a.diffs.map(rank)));
        if (bySong.length === 0) {
            noResults.hidden = false;
            countEl.textContent = '0 songs';
            return;
        }
        noResults.hidden = true;
        countEl.textContent = bySong.length + ' song' + (bySong.length !== 1 ? 's' : '');

        bySong.forEach(song => {
            const playable = song.pieces.some(p => p.has_data);
            const tr = document.createElement('div');
            tr.className = 'songs-row';
            tr.innerHTML = `
                <span class="col-song">${escHtml(song.name)}${song.pieces.length > 1 ? `<span class="songs-var-marker" title="${song.pieces.length} versions — select to choose">*</span>` : ''}</span>
                <span class="col-diff">${song.diffs.map(d => {
                    const c = DIFF_COLOR[d] || '#888';
                    return `<span class="diff-badge" style="color:${c};border-color:${c};">${escHtml(d)}</span>`;
                }).join('')}</span>
                <span class="col-action">
                    ${playable
                        ? `<button type="button" class="btn-play-row" title="Select a version to play">Select</button>`
                        : `<span class="btn-play-row disabled">No Data</span>`}
                </span>
            `;

            // Row click opens the version picker (except dead rows).
            if (playable) {
                tr.addEventListener('click', () => openPicker(song.name, tr.querySelector('.btn-play-row')));
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

    // ── Version picker popup ─────────────────────────
    const picker     = document.getElementById('songs-picker');
    const pickerTitle = document.getElementById('songs-picker-title');
    const pickerSub  = document.getElementById('songs-picker-sub');
    const pickerCards = document.getElementById('songs-piece-cards');
    const pickerClose = document.getElementById('songs-picker-close');
    let pickerOpener = null;

    function openPicker(songName, opener) {
        if (!picker) return;
        const pieces = ALL_SONGS.filter(r => r.song_name === songName && r.has_data);
        if (!pieces.length) return;
        pickerOpener = opener || null;
        pickerTitle.textContent = songName;
        pickerSub.textContent = pieces.length + ' version' + (pieces.length === 1 ? '' : 's') + ' — pick one to play';
        pickerCards.innerHTML = '';
        pieces.forEach(p => {
            const color = DIFF_COLOR[p.difficulty] || '#888';
            const card = document.createElement('div');
            card.className = 'songs-piece-card';
            card.innerHTML =
                '<h3>' + escHtml(p.piece_name) + '</h3>' +
                '<span class="diff-badge" style="color:' + color + ';border-color:' + color + ';">' + escHtml(p.difficulty || 'Beginner') + '</span>' +
                (p.uploader
                    ? '<p class="songs-piece-meta">Shared by ' + escHtml(p.uploader) + '</p>'
                    : '<p class="songs-piece-meta">Built-in piece</p>');
            const a = document.createElement('a');
            a.className = 'btn-play-row';
            a.href = BASE_URL + 'piano.php?piece_id=' + p.midi_id;
            a.textContent = '▶ Play';
            card.appendChild(a);
            pickerCards.appendChild(card);
        });
        picker.hidden = false;
        document.body.style.overflow = 'hidden';
        if (pickerClose) pickerClose.focus();
    }

    function closePicker() {
        if (!picker || picker.hidden) return;
        picker.hidden = true;
        document.body.style.overflow = '';
        if (pickerOpener && pickerOpener.focus) pickerOpener.focus();
    }

    if (pickerClose) pickerClose.addEventListener('click', closePicker);
    if (picker) picker.addEventListener('click', e => { if (e.target === picker) closePicker(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && picker && !picker.hidden) closePicker();
    });

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
