# Database Review Notes for 0.3.6

The Chapter 4 ERD identifies these tables:

- User
- Tutorial_Topic
- Tutorial_Section
- User_Progress
- Performance_Session
- MIDI_File
- Custom_Song_List
- Virtual_Piano_Session

Implemented in `database/psm_schema.sql`:

- `users`
- `tutorial_topics`
- `tutorial_sections`
- `user_progress`
- `performance_sessions`
- `midi_files`
- `custom_song_list`

Temporarily left for review:

- `Virtual_Piano_Session`: this overlaps with `performance_sessions`, because the current web app already saves raw note events in `performance_sessions.notes_played` and scoring data in `performance_sessions.analysis_json`. Add this as a separate table only if raw piano input must be stored before or outside a scored performance session.

Small corrections made from the report tables:

- The report lists `Password VARCHAR(100)`. The implementation uses `password_hash VARCHAR(255)` so PHP password hashes fit safely.
- The report repeats `progressID` in the User Progress table. The implementation keeps it once and adds a unique `(user_id, section_id)` key.
- The report uses `Custom_Song_List` as a song row table. The implementation keeps the same idea, but links each row to both `users` and `midi_files`.
