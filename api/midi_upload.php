<?php
// Teacher/admin MIDI upload into the shared song library.
// POST multipart: file field "midi", title, difficulty
// (Beginner|Intermediate|Advanced), song_id (existing song) or new_song.
// The piece is linked to a song, so songs.php lists it for every student.
// JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function midi_upload_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || !in_array($user['role'], ['ADMIN', 'TEACHER'], true)) {
    midi_upload_error('Only teachers can upload library pieces.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    midi_upload_error('POST request required.', 405);
}
if (!is_database_connected()) {
    midi_upload_error('Database is not connected.', 500);
}

$title = trim($_POST['title'] ?? '');
if ($title === '' || mb_strlen($title) > 200) {
    midi_upload_error('Give the piece a title (1–200 characters).');
}
$difficulty = trim($_POST['difficulty'] ?? '');
if (!in_array($difficulty, ['Beginner', 'Intermediate', 'Advanced'], true)) {
    midi_upload_error('Choose a difficulty: Beginner, Intermediate or Advanced.');
}
if (empty($_FILES['midi']) || ($_FILES['midi']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    midi_upload_error('Choose a MIDI file to upload.');
}

$file = $_FILES['midi'];
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
    midi_upload_error('MIDI file must be 2MB or smaller.');
}
$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['mid', 'midi'], true)) {
    midi_upload_error('Only .mid / .midi files are accepted.');
}
if (!is_uploaded_file($file['tmp_name'] ?? '')) {
    midi_upload_error('Invalid upload.');
}
// Standard MIDI files always start with the MThd header chunk.
$head = @file_get_contents($file['tmp_name'], false, null, 0, 4);
if ($head !== 'MThd') {
    midi_upload_error('That file is not a valid MIDI file.');
}

$songId = (int) ($_POST['song_id'] ?? 0);
$newSong = trim($_POST['new_song'] ?? '');
$db = db();
if ($songId > 0) {
    $stmt = $db->prepare('SELECT song_id FROM song WHERE song_id = :id');
    $stmt->execute(['id' => $songId]);
    if ($stmt->fetchColumn() === false) {
        midi_upload_error('Choose a valid song.');
    }
} elseif ($newSong !== '') {
    if (mb_strlen($newSong) > 200) {
        midi_upload_error('Song name must be 200 characters or fewer.');
    }
} else {
    midi_upload_error('Pick a song or name a new one.');
}

try {
    $db->beginTransaction();
    if ($songId <= 0) {
        $stmt = $db->prepare('INSERT INTO song (song_name) VALUES (:name)');
        $stmt->execute(['name' => $newSong]);
        $songId = (int) $db->lastInsertId();
    }
    $midiDir = __DIR__ . '/../uploads/midi';
    if (!is_dir($midiDir) && !@mkdir($midiDir, 0755, true)) {
        throw new RuntimeException('Upload storage is not available.');
    }
    $target = 'midi_' . (int) $user['user_id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $midiDir . '/' . $target;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Unable to store the MIDI file.');
    }
    $relPath = 'uploads/midi/' . $target;
    try {
        $stmt = $db->prepare(
            'INSERT INTO midi_file (user_id, song_id, file_name, file_size, difficulty, file_path)
             VALUES (:uid, :song, :name, :size, :diff, :path)'
        );
        $stmt->execute([
            'uid' => (int) $user['user_id'],
            'song' => $songId,
            'name' => $title,
            'size' => (int) $file['size'],
            'diff' => $difficulty,
            'path' => $relPath,
        ]);
        $midiId = (int) $db->lastInsertId();
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    $db->commit();
    echo json_encode(['success' => true, 'data' => ['midi_id' => $midiId, 'song_id' => $songId]]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    midi_upload_error($e instanceof RuntimeException ? $e->getMessage() : 'Unable to upload MIDI.', 500);
}
