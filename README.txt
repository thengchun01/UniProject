
PIANO COURSE WEBSITE

Version 0.3.6 adds database-backed user accounts, tutorial progress, and
performance-session saving through the PSM MySQL database.

Interface updates in 0.4.1:
- Full-width homepage banner with account registration and login actions
- Homepage section reveal animations that respect reduced-motion preferences
- Updated account login, registration, and signed-in dashboard presentation
- Piano is the consistent visible label for the browser-based piano feature

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
