<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$pieceId = (int) ($_GET['piece_id'] ?? 0);
if ($pieceId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid piece_id.']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("
        SELECT m.midi_id, m.file_name, m.difficulty, m.notes, m.file_path,
               s.song_id, s.song_name
        FROM midi_file m
        LEFT JOIN song s ON s.song_id = m.song_id
        WHERE m.midi_id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $pieceId]);
    $piece = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$piece) {
        echo json_encode(['success' => false, 'message' => 'Piece not found.']);
        exit;
    }

    // Determine the type: file_path => MIDI file reference, notes (string) => JSON note sequence
    $notesRaw = $piece['notes'];
    $decoded  = $notesRaw ? json_decode($notesRaw, true) : null;

    if ($piece['file_path']) {
        // Physical MIDI file – return the URL so JS can fetch/load it
        echo json_encode([
            'success'   => true,
            'type'      => 'midi_file',
            'song_name' => $piece['song_name'],
            'piece_name'=> $piece['file_name'],
            'difficulty'=> $piece['difficulty'],
            'file_url'  => BASE_URL . ltrim($piece['file_path'], '/'),
        ]);
    } elseif ($decoded && isset($decoded['notes']) && is_array($decoded['notes'])) {
        // JSON note sequence
        echo json_encode([
            'success'   => true,
            'type'      => 'json_notes',
            'song_name' => $piece['song_name'],
            'piece_name'=> $piece['file_name'],
            'difficulty'=> $piece['difficulty'],
            'notes'     => $decoded['notes'],
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Piece has no playable data.']);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
