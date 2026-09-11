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
                   s.song_id, s.song_name
            FROM midi_file m
            LEFT JOIN song s ON s.song_id = m.song_id
            WHERE s.song_id IS NOT NULL
            ORDER BY s.song_id ASC, m.midi_id ASC
        ");
        $songList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Encode for JS
$songListJson = json_encode(array_map(function($row) {
    return [
        'midi_id'    => (int)$row['midi_id'],
        'song_name'  => $row['song_name'],
        'piece_name' => $row['piece_name'],
        'difficulty' => $row['difficulty'] ?? 'Beginner',
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
                <span class="col-piece">${escHtml(row.piece_name)}</span>
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
