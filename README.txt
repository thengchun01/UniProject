
PIANO COURSE WEBSITE

Version 0.3.6 adds database-backed user accounts, tutorial progress, and
performance-session saving through the PSM MySQL database.

Interface updates in 0.4.1:
- Full-width homepage banner with account registration and login actions
- Reliable element-level reveal animations for headings, text, actions, cards, and table rows
- Animation fallback keeps content visible if a page is still loading other scripts
- Shared responsive footer and motion across pages that respect reduced-motion preferences
- Homepage separates non-link information cards from linked practice features
- Logout links open a styled confirmation dialog before ending the session
- Updated account login, registration, and signed-in dashboard presentation
- Piano is the consistent visible label for the browser-based piano feature

Feature updates in 0.4.1 (continued):
- Animation system fixed so reveal animations no longer block app-like pages (piano,
  songs, game). Complex UI controls inside .piano-workspace, .game-menu, and
  .piano-actions are excluded from automatic reveal targeting. In-viewport elements
  now animate immediately via double-rAF instead of waiting for the IntersectionObserver.
  The safety fallback timer was reduced from 1800ms to 800ms.
- Global MIDI device manager (assets/js/midi-manager.js) loaded on every page via
  includes/footer.php. Detects MIDI devices being plugged in at any time and shows a
  non-blocking toast prompting the user to connect. The accepted device is remembered in
  sessionStorage so navigating between pages (tutorial, piano, game) does not require
  reconnection. All piano widgets receive notes via the globalMidiMessage window event.
  The piano.php "Connect MIDI" button delegates to MidiManager instead of managing its
  own requestMIDIAccess call.

Features:
- PHP client/server structure
- Top navigation layout
- Interactive piano
- Piano sound generation
- Key lighting effect
- MIDI import
- Music sheet display
- Modular piano engine structure
- Separate CSS and JS architecture
- PSM database connection with PDO
- User registration and login
- Tutorial progress table
- Performance session table
- Global MIDI device detection on all pages (midi-manager.js)

Requirements:
- PHP 8+
- Apache/XAMPP/Laragon
- MySQL or MariaDB

Run:
1. Put project inside htdocs
2. Start Apache and MySQL
3. Import database/psm_schema.sql into MySQL
4. Confirm database settings in includes/config.php
   Default: host=localhost, database=PSM, user=root, password blank
5. Visit localhost/project-folder

Database files:
- database/psm_schema.sql is the active 0.3.6 schema.
- database/piano_course.sql is kept only as a note for the old 0.3.5 draft.

Review note:
- docs/database_review_notes.md explains which ERD table was left for review.
