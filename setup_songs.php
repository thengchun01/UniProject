<?php
/**
 * setup_songs.php - Seeds the song and midi_file tables with built-in songs.
 *
 * Run this script ONCE in your browser after first setup, or whenever the
 * built-in song list (SongData.php) changes.
 *
 * ⚠ This script will:
 *   - Add a `file_path` column to midi_file (if missing).
 *   - DELETE all seeded songs (rows where user_id IS NULL).
 *   - Re-insert all songs from SongData::getAll().
 *   - It does NOT touch user-uploaded MIDI files (user_id IS NOT NULL).
 *   - It does NOT touch the users table or any tutorial/progress tables.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/data/SongData.php';

$log = [];

function log_msg(string $msg, string $type = 'info'): void {
    global $log;
    $log[] = ['type' => $type, 'msg' => $msg];
}

try {
    $db = db();

    // ── Step 1: Add file_path column to midi_file if missing ─────────────
    $cols = $db->query("SHOW COLUMNS FROM `midi_file`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('file_path', $cols)) {
        $db->exec("ALTER TABLE `midi_file` ADD COLUMN `file_path` VARCHAR(512) DEFAULT NULL AFTER `notes`");
        log_msg("Added <code>file_path</code> column to <code>midi_file</code> table.", 'success');
    } else {
        log_msg("<code>file_path</code> column already exists — skipping.", 'info');
    }

    // ── Step 2: Delete existing seeded rows (user_id IS NULL) ────────────
    $deletedMidi = $db->exec("DELETE FROM `midi_file` WHERE `user_id` IS NULL");
    $deletedSong = $db->exec("DELETE FROM `song`");
    log_msg("Cleared {$deletedSong} song(s) and {$deletedMidi} piece(s) from previous seed.", 'info');

    // ── Step 3: Insert all songs from SongData ───────────────────────────
    $songStmt = $db->prepare("INSERT INTO `song` (`song_name`) VALUES (:song_name)");
    $pieceStmt = $db->prepare("
        INSERT INTO `midi_file`
            (`user_id`, `song_id`, `file_name`, `difficulty`, `notes`)
        VALUES
            (NULL, :song_id, :file_name, :difficulty, :notes)
    ");

    $songs = SongData::getAll();
    $totalSongs = 0;
    $totalPieces = 0;

    foreach ($songs as $song) {
        $songStmt->execute([':song_name' => $song['name']]);
        $songId = (int) $db->lastInsertId();
        $totalSongs++;

        foreach ($song['pieces'] as $piece) {
            // Store the notes array as a JSON object with 'notes' key,
            // matching the hybrid format: { "notes": [ {...}, ... ] }
            $notesJson = json_encode(['notes' => $piece['notes']], JSON_UNESCAPED_UNICODE);

            $pieceStmt->execute([
                ':song_id'    => $songId,
                ':file_name'  => $piece['file_name'],
                ':difficulty' => $piece['difficulty'],
                ':notes'      => $notesJson,
            ]);
            $totalPieces++;
        }

        log_msg("Inserted song: <strong>" . htmlspecialchars($song['name']) . "</strong> (" . count($song['pieces']) . " piece(s)).", 'success');
    }

    log_msg("✅ Done! Inserted {$totalSongs} song(s) and {$totalPieces} piece(s) total.", 'success');

} catch (Throwable $e) {
    log_msg("❌ Error: " . htmlspecialchars($e->getMessage()), 'error');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Songs</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; color: #222; }
        h1 { font-size: 1.6rem; border-bottom: 2px solid #333; padding-bottom: 12px; }
        .log-entry { padding: 10px 14px; margin: 8px 0; border-radius: 8px; font-size: 0.95rem; }
        .log-entry.info    { background: #e8f4fd; border-left: 4px solid #2196f3; }
        .log-entry.success { background: #e8f5e9; border-left: 4px solid #4caf50; }
        .log-entry.error   { background: #ffebee; border-left: 4px solid #f44336; }
        a.back { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #333; color: #fff; border-radius: 8px; text-decoration: none; }
        a.back:hover { background: #555; }
        code { background: rgba(0,0,0,0.07); padding: 1px 4px; border-radius: 3px; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>🎵 Song Database Setup</h1>
    <?php foreach ($log as $entry): ?>
        <div class="log-entry <?= htmlspecialchars($entry['type']) ?>"><?= $entry['msg'] ?></div>
    <?php endforeach; ?>
    <a class="back" href="songs.php">← Go to Songs Page</a>
</body>
</html>
