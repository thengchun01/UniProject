<?php
/**
 * SongData - Built-in song resource class.
 *
 * Each song has a name and an array of "pieces" (movements/hands/sections).
 * Each piece defines:
 *  - file_name   : label shown to the user (e.g. "Melody", "Right Hand")
 *  - difficulty  : "Beginner" | "Intermediate"
 *  - notes       : array of note objects, each with:
 *      - name         : e.g. "E4"  (used for piano key detection)
 *      - beat         : start beat (0-indexed, quarter note = 1 beat)
 *      - durationBeats: length in beats (1=quarter, 2=half, 4=whole, 0.5=eighth)
 *      - midi         : MIDI number (middle C = 60)
 *
 * To add a new song, simply add another entry to the array returned by getAll().
 */
class SongData
{
    // Mapping of note name -> MIDI number for convenience
    private static function midi(string $note): int
    {
        $noteMap = [
            'C'  => 0,  'C#' => 1,  'D'  => 2,  'D#' => 3,
            'E'  => 4,  'F'  => 5,  'F#' => 6,  'G'  => 7,
            'G#' => 8,  'A'  => 9,  'A#' => 10, 'B'  => 11
        ];
        if (!preg_match('/^([A-G]#?)(\d+)$/', $note, $m)) return 60;
        return ($m[2] + 1) * 12 + $noteMap[$m[1]];
    }

    /**
     * Build a note entry array.
     * @param string $name        e.g. "E4"
     * @param float  $beat        start beat (0-indexed)
     * @param float  $durBeats    duration in beats
     */
    private static function n(string $name, float $beat, float $durBeats): array
    {
        return [
            'name'          => $name,
            'beat'          => $beat,
            'durationBeats' => $durBeats,
            'time'          => $beat * 0.5,       // 120 BPM => 1 beat = 0.5s
            'duration'      => $durBeats * 0.5,
            'midi'          => self::midi($name),
        ];
    }

    public static function getAll(): array
    {
        return [

            // ── 1. Mary Had a Little Lamb ─────────────────────────────────
            [
                'name'   => 'Mary Had a Little Lamb',
                'pieces' => [
                    [
                        'file_name'  => 'Melody',
                        'difficulty' => 'Beginner',
                        'notes'      => [
                            // "Ma-ry had a lit-tle lamb, lit-tle lamb, lit-tle lamb"
                            self::n('E4', 0,   1),
                            self::n('D4', 1,   1),
                            self::n('C4', 2,   1),
                            self::n('D4', 3,   1),
                            self::n('E4', 4,   1),
                            self::n('E4', 5,   1),
                            self::n('E4', 6,   2),
                            self::n('D4', 8,   1),
                            self::n('D4', 9,   1),
                            self::n('D4', 10,  2),
                            self::n('E4', 12,  1),
                            self::n('G4', 13,  1),
                            self::n('G4', 14,  2),
                            // "Ma-ry had a lit-tle lamb, its fleece was white as snow"
                            self::n('E4', 16,  1),
                            self::n('D4', 17,  1),
                            self::n('C4', 18,  1),
                            self::n('D4', 19,  1),
                            self::n('E4', 20,  1),
                            self::n('E4', 21,  1),
                            self::n('E4', 22,  1),
                            self::n('E4', 23,  1),
                            self::n('D4', 24,  1),
                            self::n('D4', 25,  1),
                            self::n('E4', 26,  1),
                            self::n('D4', 27,  1),
                            self::n('C4', 28,  4),
                        ],
                    ],
                ],
            ],

            // ── 2. Twinkle Twinkle Little Star ────────────────────────────
            [
                'name'   => 'Twinkle Twinkle Little Star',
                'pieces' => [
                    [
                        'file_name'  => 'Melody',
                        'difficulty' => 'Beginner',
                        'notes'      => [
                            // "Twin-kle twin-kle lit-tle star"
                            self::n('C4', 0,   1),
                            self::n('C4', 1,   1),
                            self::n('G4', 2,   1),
                            self::n('G4', 3,   1),
                            self::n('A4', 4,   1),
                            self::n('A4', 5,   1),
                            self::n('G4', 6,   2),
                            // "How I won-der what you are"
                            self::n('F4', 8,   1),
                            self::n('F4', 9,   1),
                            self::n('E4', 10,  1),
                            self::n('E4', 11,  1),
                            self::n('D4', 12,  1),
                            self::n('D4', 13,  1),
                            self::n('C4', 14,  2),
                            // "Up a-bove the world so high"
                            self::n('G4', 16,  1),
                            self::n('G4', 17,  1),
                            self::n('F4', 18,  1),
                            self::n('F4', 19,  1),
                            self::n('E4', 20,  1),
                            self::n('E4', 21,  1),
                            self::n('D4', 22,  2),
                            // "Like a dia-mond in the sky"
                            self::n('G4', 24,  1),
                            self::n('G4', 25,  1),
                            self::n('F4', 26,  1),
                            self::n('F4', 27,  1),
                            self::n('E4', 28,  1),
                            self::n('E4', 29,  1),
                            self::n('D4', 30,  2),
                            // "Twin-kle twin-kle lit-tle star" (repeat)
                            self::n('C4', 32,  1),
                            self::n('C4', 33,  1),
                            self::n('G4', 34,  1),
                            self::n('G4', 35,  1),
                            self::n('A4', 36,  1),
                            self::n('A4', 37,  1),
                            self::n('G4', 38,  2),
                            // "How I won-der what you are"
                            self::n('F4', 40,  1),
                            self::n('F4', 41,  1),
                            self::n('E4', 42,  1),
                            self::n('E4', 43,  1),
                            self::n('D4', 44,  1),
                            self::n('D4', 45,  1),
                            self::n('C4', 46,  2),
                        ],
                    ],
                ],
            ],

            // ── 3. Ode to Joy (Beethoven) ─────────────────────────────────
            [
                'name'   => 'Ode to Joy',
                'pieces' => [
                    [
                        'file_name'  => 'Right Hand',
                        'difficulty' => 'Beginner',
                        'notes'      => [
                            self::n('E4', 0,   1),
                            self::n('E4', 1,   1),
                            self::n('F4', 2,   1),
                            self::n('G4', 3,   1),
                            self::n('G4', 4,   1),
                            self::n('F4', 5,   1),
                            self::n('E4', 6,   1),
                            self::n('D4', 7,   1),
                            self::n('C4', 8,   1),
                            self::n('C4', 9,   1),
                            self::n('D4', 10,  1),
                            self::n('E4', 11,  1),
                            self::n('E4', 12,  1.5),
                            self::n('D4', 13.5,0.5),
                            self::n('D4', 14,  2),

                            self::n('E4', 16,  1),
                            self::n('E4', 17,  1),
                            self::n('F4', 18,  1),
                            self::n('G4', 19,  1),
                            self::n('G4', 20,  1),
                            self::n('F4', 21,  1),
                            self::n('E4', 22,  1),
                            self::n('D4', 23,  1),
                            self::n('C4', 24,  1),
                            self::n('C4', 25,  1),
                            self::n('D4', 26,  1),
                            self::n('E4', 27,  1),
                            self::n('D4', 28,  1.5),
                            self::n('C4', 29.5,0.5),
                            self::n('C4', 30,  2),
                        ],
                    ],
                    [
                        'file_name'  => 'Left Hand',
                        'difficulty' => 'Intermediate',
                        'notes'      => [
                            self::n('C3', 0,   2),
                            self::n('G3', 2,   2),
                            self::n('C3', 4,   2),
                            self::n('G3', 6,   1),
                            self::n('F3', 7,   1),
                            self::n('C3', 8,   2),
                            self::n('G3', 10,  2),
                            self::n('C3', 12,  2),
                            self::n('G3', 14,  2),

                            self::n('C3', 16,  2),
                            self::n('G3', 18,  2),
                            self::n('C3', 20,  2),
                            self::n('G3', 22,  1),
                            self::n('F3', 23,  1),
                            self::n('C3', 24,  2),
                            self::n('G3', 26,  2),
                            self::n('C3', 28,  4),
                        ],
                    ],
                    [
                        'file_name'  => 'Both Hands',
                        'difficulty' => 'Intermediate',
                        'notes'      => [
                            // Right hand
                            self::n('E4', 0,   1),
                            self::n('E4', 1,   1),
                            self::n('F4', 2,   1),
                            self::n('G4', 3,   1),
                            self::n('G4', 4,   1),
                            self::n('F4', 5,   1),
                            self::n('E4', 6,   1),
                            self::n('D4', 7,   1),
                            self::n('C4', 8,   1),
                            self::n('C4', 9,   1),
                            self::n('D4', 10,  1),
                            self::n('E4', 11,  1),
                            self::n('E4', 12,  1.5),
                            self::n('D4', 13.5,0.5),
                            self::n('D4', 14,  2),
                            // Left hand
                            self::n('C3', 0,   2),
                            self::n('G3', 2,   2),
                            self::n('C3', 4,   2),
                            self::n('G3', 6,   1),
                            self::n('F3', 7,   1),
                            self::n('C3', 8,   2),
                            self::n('G3', 10,  2),
                            self::n('C3', 12,  2),
                            self::n('G3', 14,  2),
                        ],
                    ],
                ],
            ],

            // ── 4. Row Row Row Your Boat ──────────────────────────────────
            [
                'name'   => 'Row Row Row Your Boat',
                'pieces' => [
                    [
                        'file_name'  => 'Melody',
                        'difficulty' => 'Beginner',
                        'notes'      => [
                            // "Row, row, row your boat"
                            self::n('C4', 0,   1),
                            self::n('C4', 1,   1),
                            self::n('C4', 2,   0.75),
                            self::n('D4', 2.75,0.25),
                            self::n('E4', 3,   1),
                            // "Gent-ly down the stream"
                            self::n('E4', 4,   0.75),
                            self::n('D4', 4.75,0.25),
                            self::n('E4', 5,   0.75),
                            self::n('F4', 5.75,0.25),
                            self::n('G4', 6,   2),
                            // "Mer-ri-ly, mer-ri-ly, mer-ri-ly, mer-ri-ly"
                            self::n('C5', 8,   0.5),
                            self::n('C5', 8.5, 0.5),
                            self::n('C5', 9,   0.5),
                            self::n('G4', 9.5, 0.5),
                            self::n('G4', 10,  0.5),
                            self::n('G4', 10.5,0.5),
                            self::n('E4', 11,  0.5),
                            self::n('E4', 11.5,0.5),
                            // "Life is but a dream"
                            self::n('E4', 12,  0.75),
                            self::n('D4', 12.75,0.25),
                            self::n('E4', 13,  0.75),
                            self::n('F4', 13.75,0.25),
                            self::n('G4', 14,  2),
                        ],
                    ],
                ],
            ],

            // ── 5. Jingle Bells (Chorus) ──────────────────────────────────
            [
                'name'   => 'Jingle Bells',
                'pieces' => [
                    [
                        'file_name'  => 'Chorus Melody',
                        'difficulty' => 'Beginner',
                        'notes'      => [
                            // "Jin-gle bells, jin-gle bells"
                            self::n('E4', 0,   1),
                            self::n('E4', 1,   1),
                            self::n('E4', 2,   2),
                            self::n('E4', 4,   1),
                            self::n('E4', 5,   1),
                            self::n('E4', 6,   2),
                            // "Jin-gle all the way"
                            self::n('E4', 8,   1),
                            self::n('G4', 9,   1),
                            self::n('C4', 10,  1),
                            self::n('D4', 11,  1),
                            self::n('E4', 12,  4),
                            // "Oh what fun it is to ride"
                            self::n('F4', 16,  1),
                            self::n('F4', 17,  1),
                            self::n('F4', 18,  1),
                            self::n('F4', 19,  1),
                            self::n('F4', 20,  1),
                            self::n('E4', 21,  1),
                            self::n('E4', 22,  1),
                            self::n('E4', 23,  1),
                            // "In a one-horse o-pen sleigh"
                            self::n('E4', 24,  1),
                            self::n('D4', 25,  1),
                            self::n('D4', 26,  1),
                            self::n('E4', 27,  1),
                            self::n('D4', 28,  2),
                            self::n('G4', 30,  2),
                            // Second verse repeat
                            self::n('E4', 32,  1),
                            self::n('E4', 33,  1),
                            self::n('E4', 34,  2),
                            self::n('E4', 36,  1),
                            self::n('E4', 37,  1),
                            self::n('E4', 38,  2),
                            self::n('E4', 40,  1),
                            self::n('G4', 41,  1),
                            self::n('C4', 42,  1),
                            self::n('D4', 43,  1),
                            self::n('E4', 44,  4),
                            self::n('F4', 48,  1),
                            self::n('F4', 49,  1),
                            self::n('F4', 50,  1),
                            self::n('F4', 51,  1),
                            self::n('F4', 52,  1),
                            self::n('E4', 53,  1),
                            self::n('E4', 54,  1),
                            self::n('E4', 55,  1),
                            self::n('G4', 56,  1),
                            self::n('G4', 57,  1),
                            self::n('F4', 58,  1),
                            self::n('D4', 59,  1),
                            self::n('C4', 60,  4),
                        ],
                    ],
                ],
            ],

        ];
    }
}
