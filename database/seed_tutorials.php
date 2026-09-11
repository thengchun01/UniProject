<?php
require_once __DIR__ . '/includes/config.php';

try {
    $db = db();

    // 1. Ensure interactive columns exist (safe to re-run)
    foreach (['expected_keys VARCHAR(255) DEFAULT NULL',
              'highlight_keys VARCHAR(255) DEFAULT NULL',
              'sheet_notes TEXT DEFAULT NULL'] as $col) {
        try { $db->exec("ALTER TABLE tutorial_section ADD COLUMN $col"); } catch (Exception $e) {}
    }

    // 2. Clear existing data
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    $db->exec("TRUNCATE TABLE user_progress");
    $db->exec("TRUNCATE TABLE tutorial_section");
    $db->exec("TRUNCATE TABLE tutorial_topic");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 3. All 13 lessons with interactive metadata
    $lessons = [
        // Lesson 1: The Piano Keyboard
        [
            'title'      => 'The Piano Keyboard',
            'content'    => 'Learn the layout of the piano keyboard and how the keys are organized into repeating groups.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>The 88 Keys</h3><p>A standard piano has 88 keys — 52 white and 36 black. The keys repeat in groups of 12 (7 white + 5 black). This group is called an <strong>octave</strong>.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>White Keys & Black Keys</h3><p>White keys are named <strong>A, B, C, D, E, F, G</strong>. Black keys are sharps (♯) or flats (♭) of their neighbours.</p>',
                 'highlight_keys' => 'C#4,D#4,F#4,G#4,A#4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Finding Middle C</h3><p><strong>Middle C (C4)</strong> is immediately to the <strong>left</strong> of the group of 2 black keys nearest the centre. Try pressing it!</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => 'C4', 'sheet_notes' => ''],
                ['content' => '<h3>Practice: Play Middle C</h3><p>Use the piano to play Middle C. Listen to its sound — this is your reference note for everything ahead.</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => 'C4', 'sheet_notes' => 'C4'],
            ]
        ],
        // Lesson 2: Right Hand Notes C-D-E
        [
            'title'      => 'Right Hand Notes (C-D-E)',
            'content'    => 'Begin playing your first three notes with the right hand using fingers 1, 2, and 3.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>Finger Numbers</h3><p>Thumb = 1, Index = 2, Middle = 3. Place <strong>thumb (1)</strong> on C4, <strong>index (2)</strong> on D4, <strong>middle (3)</strong> on E4.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Playing C, D, E</h3><p>Press each key gently. Pitch rises as you move C → D → E. These three notes start the C major scale.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,D4,E4', 'sheet_notes' => 'C4,D4,E4'],
                ['content' => '<h3>Your First Melody</h3><p>Play <strong>C - D - E - D - C</strong>. This simple phrase goes up and back down.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,D4,E4,D4,C4', 'sheet_notes' => 'C4,D4,E4,D4,C4'],
                ['content' => '<h3>Practice Exercise</h3><p>Play <strong>C C D D E E D D C</strong> slowly and steadily. Keep your wrist relaxed and fingers curved.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,C4,D4,D4,E4,E4,D4,D4,C4', 'sheet_notes' => 'C4,C4,D4,D4,E4,E4,D4,D4,C4'],
            ]
        ],
        // Lesson 3: Right Hand C-D-E-F-G
        [
            'title'      => 'Right Hand Notes (C-D-E-F-G)',
            'content'    => 'Expand to five notes using all five fingers of the right hand.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>Adding F and G</h3><p>Add <strong>ring finger (4)</strong> on F4 and <strong>pinky (5)</strong> on G4. This is the <strong>five-finger position</strong>.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>The Five-Note Scale</h3><p>Play ascending: <strong>C-D-E-F-G</strong>, then descending: <strong>G-F-E-D-C</strong>.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'C4,D4,E4,F4,G4,G4,F4,E4,D4,C4', 'sheet_notes' => 'C4,D4,E4,F4,G4,G4,F4,E4,D4,C4'],
                ['content' => '<h3>"Mary Had a Little Lamb"</h3><p>Using only C, D, E, F, G try:<br><strong>E D C D | E E E | D D D | E G G</strong></p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'E4,D4,C4,D4,E4,E4,E4,D4,D4,D4,E4,G4,G4', 'sheet_notes' => 'E4,D4,C4,D4,E4,E4,E4,D4,D4,D4,E4,G4,G4'],
                ['content' => '<h3>Practice Exercise</h3><p>Play the five-finger scale up and down 5 times. Focus on curved fingers, relaxed wrist, even volume.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'C4,D4,E4,F4,G4', 'sheet_notes' => 'C4,D4,E4,F4,G4'],
            ]
        ],
        // Lesson 4: Introduction to the Music Staff
        [
            'title'      => 'Introduction to the Music Staff',
            'content'    => 'Learn how music is written on the staff with the treble clef.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>The Staff</h3><p>Music is written on <strong>5 horizontal lines</strong> called the <strong>staff</strong>. Higher-pitched notes appear higher on the staff.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>The Treble Clef</h3><p>The <strong>treble clef (𝄞)</strong> marks the higher range, typically the right hand. It curls around the G line, so it\'s also called the <strong>G clef</strong>.</p>',
                 'highlight_keys' => 'G4', 'expected_keys' => 'G4', 'sheet_notes' => 'G4'],
                ['content' => '<h3>Lines and Spaces</h3><p>Lines: <strong>E, G, B, D, F</strong> ("Every Good Boy Does Fine"). Spaces: <strong>F, A, C, E</strong> ("FACE").</p>',
                 'highlight_keys' => 'E4,G4,F4,A4,C5', 'expected_keys' => '', 'sheet_notes' => 'E4,G4,B4,D5,F5'],
                ['content' => '<h3>Middle C on the Staff</h3><p>Middle C sits on a small <strong>ledger line</strong> below the staff. Play it!</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => 'C4', 'sheet_notes' => 'C4'],
            ]
        ],
        // Lesson 5: Reading Notes on the Staff
        [
            'title'      => 'Reading Notes on the Staff',
            'content'    => 'Practice identifying and playing notes directly from the treble clef staff.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>Notes C Through G</h3><p>C = ledger line below, D = below first line, E = first line, F = first space, G = second line.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => '', 'sheet_notes' => 'C4,D4,E4,F4,G4'],
                ['content' => '<h3>Reading Practice</h3><p>See a note → say its name → play it. This three-step process builds sight-reading ability.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'E4,G4,F4', 'sheet_notes' => 'E4,G4,F4'],
                ['content' => '<h3>Note Recognition</h3><p>Cover the labels and identify notes by position. Lines: <strong>EGBDF</strong>. Spaces: <strong>FACE</strong>.</p>',
                 'highlight_keys' => 'E4,G4,F4,A4', 'expected_keys' => '', 'sheet_notes' => 'E4,F4,G4'],
                ['content' => '<h3>Practice Exercise</h3><p>Play: <strong>E, G, F, D, C, E, G</strong>. Then: <strong>C, E, G, F, D, C</strong>.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'E4,G4,F4,D4,C4,E4,G4,C4,E4,G4,F4,D4,C4', 'sheet_notes' => 'E4,G4,F4,D4,C4,E4,G4,C4,E4,G4,F4,D4,C4'],
            ]
        ],
        // Lesson 6: Note Duration & Rhythm
        [
            'title'      => 'Note Duration & Rhythm',
            'content'    => 'Understand how long to hold each note by learning whole, half, and quarter notes.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>What is Rhythm?</h3><p>Rhythm is the pattern of long and short sounds. Different note shapes tell you how long to hold each note.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Quarter Notes</h3><p>A <strong>quarter note (♩)</strong> gets <strong>1 beat</strong>. Count "1, 2, 3, 4" evenly — each count is one quarter note.</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => 'C4', 'sheet_notes' => 'C4'],
                ['content' => '<h3>Half & Whole Notes</h3><p>A <strong>half note</strong> = 2 beats. A <strong>whole note</strong> = 4 beats. Hold them for the full duration.</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => '', 'sheet_notes' => 'C4'],
                ['content' => '<h3>Practice Exercise</h3><p>Play C for 4 beats. Then D for 4 beats. Then E for 4 beats. Feel the steady pulse.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,D4,E4', 'sheet_notes' => 'C4,D4,E4'],
            ]
        ],
        // Lesson 7: Time Signatures
        [
            'title'      => 'Time Signatures',
            'content'    => 'Learn how music is organized into measures using time signatures.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>What is a Measure?</h3><p>Music is divided into equal sections called <strong>measures</strong>, separated by vertical bar lines. Each measure has a fixed number of beats.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>4/4 Time</h3><p>The most common time signature: <strong>4 beats per measure</strong>, quarter note = 1 beat. Count: "1-2-3-4 | 1-2-3-4".</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => 'C4', 'sheet_notes' => 'C4'],
                ['content' => '<h3>3/4 Time</h3><p><strong>3 beats per measure</strong> — the waltz feel. Count: "1-2-3 | 1-2-3".</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Practice Exercise</h3><p>Play C for 4 beats, D for 4 beats, E for 4 beats, then rest. That\'s 3 measures in 4/4!</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,D4,E4', 'sheet_notes' => 'C4,D4,E4'],
            ]
        ],
        // Lesson 8: Left Hand Notes
        [
            'title'      => 'Left Hand Notes (C-B-A-G-F)',
            'content'    => 'Introduce the left hand and the bass clef position.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>The Bass Clef</h3><p>The <strong>bass clef (𝄢)</strong> is used for lower-pitched notes, typically the left hand. Also called the <strong>F clef</strong>.</p>',
                 'highlight_keys' => 'C3', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Left Hand Position</h3><p>Pinky (5) on C3, ring (4) on D3, middle (3) on E3, index (2) on F3, thumb (1) on G3.</p>',
                 'highlight_keys' => 'C3,D3,E3,F3,G3', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Left Hand Scale</h3><p>Play ascending: <strong>C-D-E-F-G</strong> (fingers 5-4-3-2-1), then descending.</p>',
                 'highlight_keys' => 'C3,D3,E3,F3,G3', 'expected_keys' => 'C3,D3,E3,F3,G3', 'sheet_notes' => 'C3,D3,E3,F3,G3'],
                ['content' => '<h3>Practice Exercise</h3><p>Play the left-hand scale up and down 5 times. Then: <strong>C - E - G - E - C</strong>.</p>',
                 'highlight_keys' => 'C3,E3,G3', 'expected_keys' => 'C3,E3,G3,E3,C3', 'sheet_notes' => 'C3,E3,G3,E3,C3'],
            ]
        ],
        // Lesson 9: Playing Hands Together
        [
            'title'      => 'Playing Hands Together',
            'content'    => 'Coordinate both hands to play simple patterns simultaneously.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>Why Hands Together?</h3><p>Right hand plays the <strong>melody</strong>; left hand plays the <strong>harmony</strong>. Start very slowly — coordination takes time!</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Unison Playing</h3><p>Both hands play the same notes one octave apart. Right on C4-G4, left on C3-G3. Play C\'s together, then D\'s, etc.</p>',
                 'highlight_keys' => 'C3,C4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Contrary Motion</h3><p>Right moves up (C-D-E-F-G), left moves down (C-B-A-G-F) at the same time.</p>',
                 'highlight_keys' => 'C3,C4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Practice Exercise</h3><p>Both hands play C together (4 beats), then D (4 beats), then E (4 beats), then C (4 beats).</p>',
                 'highlight_keys' => 'C3,C4,D3,D4,E3,E4', 'expected_keys' => 'C3,C4,D3,D4,E3,E4,C3,C4', 'sheet_notes' => 'C3,C4,D3,D4,E3,E4,C3,C4'],
            ]
        ],
        // Lesson 10: Sharps, Flats & Naturals
        [
            'title'      => 'Sharps, Flats & Naturals',
            'content'    => 'Understand accidentals and how they modify the pitch of notes.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>What Are Accidentals?</h3><p>Sharp (♯) = raise by a half step. Flat (♭) = lower by a half step. Natural (♮) = cancel a sharp or flat.</p>',
                 'highlight_keys' => 'C#4,D#4,F#4,G#4,A#4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Half Steps</h3><p>A half step is from any key to the very next key. C to C♯ is a half step. E to F is also a half step.</p>',
                 'highlight_keys' => 'C4,C#4', 'expected_keys' => 'C4,C#4', 'sheet_notes' => 'C4,C#4'],
                ['content' => '<h3>The Black Keys</h3><p>Each black key has two names: C♯/D♭, D♯/E♭, F♯/G♭, G♯/A♭, A♯/B♭.</p>',
                 'highlight_keys' => 'C#4,D#4,F#4,G#4,A#4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Chromatic Scale</h3><p>Play every key from C3 to C4: C, C♯, D, D♯, E, F, F♯, G, G♯, A, A♯, B, C — that\'s all 12 notes!</p>',
                 'highlight_keys' => 'C3,C#3,D3,D#3,E3,F3,F#3,G3,G#3,A3,A#3,B3,C4',
                 'expected_keys' => 'C3,C#3,D3,D#3,E3,F3,F#3,G3,G#3,A3,A#3,B3,C4', 'sheet_notes' => 'C3,C#3,D3,D#3,E3,F3,F#3,G3,G#3,A3,A#3,B3,C4'],
            ]
        ],
        // Lesson 11: Rests in Music
        [
            'title'      => 'Rests in Music',
            'content'    => 'Learn the different types of rests and how silence is part of music.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>What Are Rests?</h3><p>Rests represent <strong>silence</strong>. They\'re just as important as notes — they give music shape and breathing room.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Types of Rests</h3><p>Whole rest = 4 beats. Half rest = 2 beats. Quarter rest = 1 beat. Each matches a note type.</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Counting with Rests</h3><p>When you see a rest, keep counting but lift your fingers. Try: <strong>C(1) - rest(2) - E(3) - rest(4)</strong>.</p>',
                 'highlight_keys' => 'C4,E4', 'expected_keys' => 'C4,E4', 'sheet_notes' => 'C4,E4'],
                ['content' => '<h3>Practice Exercise</h3><p>In 4/4 time, play: <strong>C - D - [rest] - E | [rest] - D - C - [rest]</strong>. Count throughout.</p>',
                 'highlight_keys' => 'C4,D4,E4', 'expected_keys' => 'C4,D4,E4,D4,C4', 'sheet_notes' => 'C4,D4,E4,D4,C4'],
            ]
        ],
        // Lesson 12: Dynamics
        [
            'title'      => 'Dynamics: Loud & Soft',
            'content'    => 'Learn how to add expression to your playing with dynamic markings.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>What Are Dynamics?</h3><p>Dynamics control how <strong>loud or soft</strong> you play. They add emotion — without them, music sounds flat.</p>',
                 'highlight_keys' => '', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Dynamic Markings</h3><p><strong>pp</strong> = very soft, <strong>p</strong> = soft, <strong>mp</strong> = mod. soft, <strong>mf</strong> = mod. loud, <strong>f</strong> = loud, <strong>ff</strong> = very loud.</p>',
                 'highlight_keys' => 'C4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Crescendo & Decrescendo</h3><p>Crescendo (&lt;) = gradually louder. Decrescendo (&gt;) = gradually softer. These create beautiful swells.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Practice Exercise</h3><p>Play C-D-E-F-G with a crescendo going up and a decrescendo coming back down. Exaggerate the difference!</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'C4,D4,E4,F4,G4', 'sheet_notes' => 'C4,D4,E4,F4,G4'],
            ]
        ],
        // Lesson 13: First Complete Song
        [
            'title'      => 'Your First Complete Song',
            'content'    => 'Put everything together to play "Ode to Joy" with both hands.',
            'difficulty' => 'beginner',
            'sections'   => [
                ['content' => '<h3>Putting It All Together</h3><p>You\'ve learned the fundamentals! Now combine note reading, rhythm, and dynamics into a complete song: <strong>"Ode to Joy"</strong> by Beethoven.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => '', 'sheet_notes' => ''],
                ['content' => '<h3>Right Hand Melody</h3><p><strong>E E F G | G F E D | C C D E | E D D</strong><br><strong>E E F G | G F E D | C C D E | D C C</strong><br>Practice slowly until smooth.</p>',
                 'highlight_keys' => 'C4,D4,E4,F4,G4', 'expected_keys' => 'E4,E4,F4,G4,G4,F4,E4,D4,C4,C4,D4,E4,E4,D4,D4,E4,E4,F4,G4,G4,F4,E4,D4,C4,C4,D4,E4,D4,C4,C4', 'sheet_notes' => 'E4,E4,F4,G4,G4,F4,E4,D4,C4,C4,D4,E4,E4,D4,D4,E4,E4,F4,G4,G4,F4,E4,D4,C4,C4,D4,E4,D4,C4,C4'],
                ['content' => '<h3>Left Hand Accompaniment</h3><p>Left hand plays whole notes: <strong>C — — — | G — — — | C — — — | G — — —</strong></p>',
                 'highlight_keys' => 'C3,G3', 'expected_keys' => 'C3,G3,C3,G3', 'sheet_notes' => 'C3,G3,C3,G3'],
                ['content' => '<h3>Hands Together</h3><p>Combine both hands measure by measure. Start <strong>extremely slowly</strong>. When solid, play the whole piece. <strong>You\'ve played your first song!</strong></p>',
                 'highlight_keys' => 'C3,C4,G3,G4,E4,F4', 'expected_keys' => 'E4,E4,F4,G4', 'sheet_notes' => 'E4,E4,F4,G4'],
            ]
        ],
    ];

    // 4. Insert
    $topicStmt = $db->prepare(
        "INSERT INTO tutorial_topic (title, content, difficulty, order_index) VALUES (:title, :content, :difficulty, :order_index)"
    );
    $sectionStmt = $db->prepare(
        "INSERT INTO tutorial_section (tutorial_id, content, order_index, expected_keys, highlight_keys, sheet_notes)
         VALUES (:tutorial_id, :content, :order_index, :expected_keys, :highlight_keys, :sheet_notes)"
    );

    $db->beginTransaction();

    foreach ($lessons as $i => $lesson) {
        $topicStmt->execute([
            'title'       => $lesson['title'],
            'content'     => $lesson['content'],
            'difficulty'  => $lesson['difficulty'],
            'order_index' => $i + 1,
        ]);
        $topicId = $db->lastInsertId();

        foreach ($lesson['sections'] as $j => $sec) {
            $sectionStmt->execute([
                'tutorial_id'   => $topicId,
                'content'       => $sec['content'],
                'order_index'   => $j + 1,
                'expected_keys' => $sec['expected_keys'],
                'highlight_keys'=> $sec['highlight_keys'],
                'sheet_notes'   => $sec['sheet_notes'],
            ]);
        }
    }

    $db->commit();

    $tc = $db->query("SELECT COUNT(*) FROM tutorial_topic")->fetchColumn();
    $sc = $db->query("SELECT COUNT(*) FROM tutorial_section")->fetchColumn();

    echo "<h1>✓ Success!</h1>";
    echo "<p>Inserted <strong>$tc topics</strong> and <strong>$sc sections</strong> with interactive piano metadata.</p>";
    echo "<a href='tutorial.php'>→ Go to Tutorials</a>";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "<h1>Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
