# UniProject / Piano Course — Agent & Project Guide

> **Project version documented:** 0.3.6  
> **Repository:** `thengchun01/UniProject`  
> **Application:** PHP/MySQL web application for interactive piano learning, tutorials, MIDI practice, and performance tracking.

This file is the project-level guide for human developers and coding agents. It describes the project architecture, implemented functionality, development rules, database conventions, UI direction, and safe code-generation practices.

---

## 1. Project Purpose

UniProject is a browser-based piano-learning platform. The current 0.3.6 project combines:

- An interactive piano.
- Browser-based piano sound generation.
- Visual piano-key highlighting.
- MIDI import and playback-related functionality.
- Music-sheet rendering.
- Modular piano/learning JavaScript structure.
- Beginner-oriented tutorial content.
- User registration and login.
- Role-based users such as administrator, teacher, and student.
- Tutorial progress tracking.
- Performance-session recording/saving.
- Student activity/progress views.
- Teacher/admin-facing management functionality.
- A MySQL/MariaDB database accessed from PHP.

The application is a traditional PHP web application rather than a framework-based SPA. Preserve this architecture unless a deliberate migration is requested.

---

## 2. Repository Root Structure

The current repository root contains these major areas:

```text
UniProject/
├── admin/
├── api/
├── assets/
├── database/
├── docs/
├── includes/
├── student/
├── teacher/
├── tutorials/
├── account.php
├── activity.php
├── car_race.php
├── create_account_dev.php
├── dir.txt
├── game.php
├── index.php
├── login.php
├── logout.php
├── piano.php
├── rankings.php
├── register.php
├── setup_songs.php
├── setup_tutorials.php
├── songs.php
├── temp_dump.php
├── tutorial.php
└── README.txt
```

### Directory responsibilities

| Directory | Responsibility |
|---|---|
| `admin/` | Administrator-facing pages and user/system management. |
| `api/` | Server endpoints used by browser-side JavaScript/AJAX. |
| `assets/` | Shared CSS, JavaScript, images, audio, and other frontend resources. |
| `database/` | SQL schema/data/setup files. |
| `docs/` | Design, database, review, and project documentation. |
| `includes/` | Shared PHP configuration, reusable layout/components, and common helpers. |
| `student/` | Student-specific pages and workflows. |
| `teacher/` | Teacher-specific pages and workflows. |
| `tutorials/` | Tutorial lessons/content and tutorial-specific resources. |

### Root page responsibilities

The root PHP pages currently include the public landing page, authentication, piano, tutorials, games, songs, rankings, activity/progress, and development/setup utilities.

Do not move a root page into another directory merely for aesthetic reasons. Existing URLs and relative include paths may depend on the current structure.

### Homepage and account presentation

The homepage hero is intentionally full viewport width while its surrounding content remains within the shared layout container. Its primary calls to action lead to account registration and login. Individual headings, text, actions, cards, and data rows use lightweight `IntersectionObserver`-based reveal animations defined in `assets/js/main.js`; preserve the `prefers-reduced-motion` fallback when extending them.

Reveal animation styles use a JavaScript-added `reveal-pending` state so content remains visible if initialization is delayed or unavailable. Keep the safety fallback when extending the animation system.

The shared footer is defined in `includes/footer.php` and has navigation links styled by `assets/css/style.css`. Reuse it rather than adding page-specific footer markup.

Logout confirmation is a shared styled dialog in `includes/header.php`, activated by `assets/js/main.js`. Keep direct logout links as the no-JavaScript fallback.

The account page keeps its existing server-side authentication and dashboard logic. Its presentation is split between `assets/css/account.css` for guests and `assets/css/account_log.css` for signed-in users.

---

## 3. Technology Stack

### Server side

- PHP 8+
- Apache through XAMPP/Laragon or equivalent local PHP server
- MySQL or MariaDB
- PDO for the PSM database connection

### Client side

- HTML5
- CSS3
- Vanilla JavaScript
- Browser Web Audio / MIDI-related APIs where supported
- JSON for client/server data exchange

### Known frontend libraries used by the 0.3.6 architecture

The piano/music functionality has been built around:

- **Tone.js** for browser-side audio/synthesis.
- **@tonejs/midi** for MIDI parsing.
- **VexFlow** for music notation/sheet rendering.

When changing library versions, check every dependent feature before updating. Do not upgrade a library simply because a newer version exists.

### Local development

Typical environment:

```text
XAMPP
├── Apache
└── MySQL/MariaDB
```

The project can be placed under the web server's document root (for example `htdocs`).

---

## 4. Application Features

### 4.1 Authentication and accounts

Implemented account functionality includes:

- User registration.
- User login.
- Logout/session termination.
- Database-backed user accounts.
- Stored password hashes.
- User roles.
- Last-login/account timestamps.
- Account-related pages and UI.

Known roles:

```text
ADMIN
TEACHER
STUDENT
GUEST
```

Authentication is session-based.

Typical session values used by the application include:

```php
$_SESSION['user_id']
$_SESSION['username']
$_SESSION['email']
$_SESSION['role']
```

Roles from the server are normalized with `normalize_role()` in `includes/config.php` to uppercase `ADMIN`, `TEACHER`, `STUDENT`, `GUEST`. Authorization checks use strict uppercase comparison.

### 4.2 Interactive piano

The piano subsystem provides:

- Virtual keyboard interaction.
- Piano sound generation.
- Key press/release visual feedback.
- Highlighting/dimming of keys for guided learning.
- Piano-learning UI.
- JavaScript-driven keyboard/audio behaviour.

Keep piano interaction logic separate from unrelated authentication/database logic.

### 4.3 MIDI functionality

The application supports MIDI-related learning workflows, including MIDI import/parsing and music-note handling.

Device connection is centralized in `assets/js/midi-manager.js`, loaded on every page via `includes/footer.php`. It requests Web MIDI access once, watches `onstatechange`, shows a non-blocking toast when a device appears, remembers the accepted/declined choice in `sessionStorage` across navigation, and forwards notes via the `globalMidiMessage` window event consumed by `piano-core.js` and `piano.js`.

When changing MIDI code:

1. Preserve note-name/octave conventions.
2. Preserve timing units used by the existing parser.
3. Do not silently change MIDI tempo interpretation.
4. Test both short and multi-track MIDI files.
5. Keep parsing/rendering responsibilities separate where possible.

### 4.4 Music-sheet display

Music notation is rendered in the browser.

Current implementation uses VexFlow-based rendering.

When modifying notation:

- Keep note duration calculations explicit.
- Keep octave/note mapping consistent with the piano engine.
- Avoid hard-coding notation for only one song.
- Keep rendering code reusable for tutorials and imported MIDI where possible.

### 4.5 Tutorials

Tutorial functionality includes:

- Tutorial topics/lessons.
- Tutorial sections.
- Beginner learning content.
- Guided piano-note display.
- Progress tracking.

The application stores tutorial progress using a section-oriented model. Do not reintroduce a free-text `title` field as the primary progress identifier when the existing schema expects `sectionID`.

### 4.6 Performance sessions

Performance/session data is saved for later review.

The database model stores performance metrics such as score-related/session information. Some metric data is represented as JSON strings.

When extending performance data:

- Prefer adding a well-defined JSON property when the data belongs to the same logical session.
- Use stable property names.
- Keep JSON serializable.
- Document new metrics.
- Do not store arbitrary executable content.

### 4.7 Activity/progress

User activity and progress pages summarize learning behaviour and performance.

Some activity metrics and record series are stored as stringified JSON. Preserve the existing format when reading/writing these fields.

### 4.8 Rankings / game functionality

The project contains ranking/game-related pages, including the existing game/ranking pages.

Do not let game-specific logic leak into the core piano engine unless the functionality is intentionally shared.

---

## 5. Database

The active 0.3.6 database schema is:

```text
database/psm_schema.sql
```

The older:

```text
database/piano_course.sql
```

is retained as a historical/old draft and must not be treated as the active schema.

The database name used by the current documentation is:

```text
PSM
```

Typical local development configuration:

```text
Host: localhost
Database: PSM
User: root
Password: blank (common XAMPP default)
```

The actual values must always come from the local `includes/config.php` configuration. Never assume production credentials.

### Database rules for agents

- Read the active schema before writing SQL.
- Do not invent column names.
- Use prepared statements.
- Use PDO consistently with the existing connection layer.
- Do not mix a second database abstraction into an existing feature.
- Do not silently change column meaning.
- If a schema change is required, update `database/psm_schema.sql` and the code that depends on it.
- Treat historical SQL files as reference material, not the source of truth.

---

## 6. PHP Architecture Rules

### Shared configuration

Use the existing shared configuration in:

```text
includes/config.php
```

Do not create another database connection file for a new page unless there is a strong architectural reason.

For files inside subdirectories, use the correct relative path, for example:

```php
require_once '../includes/config.php';
```

For root-level pages:

```php
require_once 'includes/config.php';
```

Never assume the current working directory is the same as the PHP file's directory.

### Shared layout

Reuse the existing header/footer and shared includes where they already exist.

Do not copy a large header/footer implementation into every page.

### URL generation

The project uses a shared URL/base-path approach in its existing architecture. Prefer the existing `BASE_URL` / URL helper conventions where available instead of hard-coding deployment-specific paths.

### Sessions

Before using authenticated session values:

- Start the session through the existing project/session convention.
- Do not call `session_start()` repeatedly when the shared bootstrap already handles it.
- Do not trust a browser-provided role.
- Re-check authorization on the server for protected operations.

---

## 7. Security Rules

All new code must follow these rules.

### SQL

Use prepared statements:

```php
$stmt = $pdo->prepare(
    'SELECT user_id, username, role FROM users WHERE user_id = ?'
);
$stmt->execute([$userId]);
```

Do not concatenate user input into SQL.

### HTML output

Escape user-controlled values before rendering them:

```php
<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>
```

### Authentication

Password handling must use password hashing APIs such as:

```php
password_hash()
password_verify()
```

Never store plaintext passwords.

### Authorization

Checking whether a button is visible is not authorization.

Protected PHP/API endpoints must enforce the required role server-side.

### Input validation

Validate:

- Required fields.
- Numeric IDs.
- Allowed enum/role values.
- JSON shape.
- File type/extension/size for uploads.
- MIDI-related inputs.
- Any URL supplied by users before using it.

### Secrets

Never commit:

- API keys.
- Database production passwords.
- Access tokens.
- Session secrets.
- Personal authentication cookies.
- `.env`-style secrets.

---

## 8. API / AJAX Rules

Browser-side API calls should follow the existing project conventions.

For JSON endpoints:

```php
header('Content-Type: application/json; charset=utf-8');
```

Return predictable JSON structures.

Recommended success shape:

```json
{
  "success": true,
  "data": {}
}
```

Recommended error shape:

```json
{
  "success": false,
  "error": "Human-readable message"
}
```

Do not return a mixture of HTML, warnings, PHP notices, and JSON from an API endpoint.

For errors:

- Log technical details where appropriate.
- Return a safe user-facing message.
- Do not expose database passwords, SQL statements, stack traces, or filesystem paths.

---

## 9. JavaScript Rules

Keep frontend behaviour modular.

Prefer:

```text
HTML/PHP
   ↓
DOM structure/data attributes
   ↓
JavaScript controller/module
   ↓
API endpoint
   ↓
PHP/database
```

Avoid putting large application logic directly inside inline `<script>` tags.

### Piano code

Do not duplicate note-to-frequency/note-to-key logic across multiple files.

Use the existing piano engine abstractions.

When adding a feature that needs piano notes:

1. Reuse the existing note representation.
2. Reuse the existing key lookup/highlight mechanism.
3. Reuse the existing audio path.
4. Only add a new abstraction when the current one cannot support the feature.

### Third-party libraries

Keep third-party imports versioned and explicit.

Example:

```text
Tone.js
@tonejs/midi
VexFlow
```

Before changing a CDN version or API call, search the project for every use of that library.

---

## 10. CSS / UI Architecture

The project intentionally separates CSS and JavaScript from page structure.

Prefer:

```text
assets/
├── css/
└── js/
```

Do not add large blocks of page-wide styling directly to PHP unless the existing page already uses that pattern.

Use page/component-specific classes rather than generic selectors such as:

```css
button { ... }
.card { ... }
.title { ... }
```

when those selectors could unintentionally affect unrelated screens.

Prefer names such as:

```css
.piano-key
.tutorial-card
.account-form
.performance-table
.admin-user-row
```

---

## 11. Colour Schema / Design System

### Verified visual direction

The project has been developed with a **clean, high-contrast piano-learning interface**, with an established black/white-first design direction in the earlier UI/wireframe work.

Do not introduce a new visual language for one page.

### Important documentation rule

The repository does not currently document a single authoritative hex palette in its README. Therefore:

> **The exact existing CSS values are the source of truth.**

Before changing colours, inspect the active stylesheets (especially shared and piano/account styles) and reuse the existing values.

### Agent colour rules

- Do not randomly add new accent colours.
- Do not replace black/white/neutral styling with gradients unless the existing design already uses them.
- Reuse existing border, background, text, hover, and success/error colours.
- Prefer CSS variables/tokens when introducing a new reusable colour system.
- If the codebase is migrated to tokens, keep the token names semantic.

Recommended semantic token names:

```css
:root {
    --color-bg: ...;
    --color-surface: ...;
    --color-text: ...;
    --color-text-muted: ...;
    --color-border: ...;
    --color-primary: ...;
    --color-primary-hover: ...;
    --color-success: ...;
    --color-warning: ...;
    --color-danger: ...;
}
```

**Do not fill these values with guessed hex codes when modifying an existing page. Pull the actual values from the project's CSS first.**

### Piano-specific colours

The piano keyboard may use state colours such as:

```text
Normal key
Pressed/highlighted key
Tutorial-target key
Disabled/dimmed key
Correct note
Incorrect note
```

These are functional states, not decoration. Keep them visually distinguishable and accessible.

---

## 12. Responsive UI Rules

All new screens should work on:

- Desktop.
- Laptop.
- Tablet.
- Phone-sized browser windows.

Avoid:

- Fixed widths that prevent mobile use.
- Horizontal page overflow.
- Text that becomes unreadable when the viewport shrinks.
- Controls that depend only on hover.
- Piano keys that cannot be used on touch devices.

For piano/tutorial pages, preserve usable keyboard proportions even when the screen becomes narrow.

---

## 13. Code-Generation Rules for AI Agents

When generating or modifying code for this repository, follow this sequence.

### Step 1 — Understand before changing

Inspect:

1. The target PHP page.
2. Its included configuration/layout files.
3. Related JavaScript.
4. Related CSS.
5. The relevant database schema/table.
6. Any API endpoint involved.

Do not immediately create a new file because the requested functionality might already exist elsewhere.

### Step 2 — Find the existing pattern

Search for an existing example that performs a similar operation.

Examples:

```text
Existing CRUD → reuse for another CRUD.
Existing API JSON response → reuse response structure.
Existing role check → reuse role-checking convention.
Existing piano key highlight → reuse key-state function.
Existing tutorial progress save → reuse progress persistence pattern.
```

### Step 3 — Make the smallest coherent change

Prefer:

```text
1 existing file edited
```

over:

```text
5 new files
```

when the feature can reasonably be implemented in the current architecture.

Add files only when they provide a real separation of responsibility.

### Step 4 — Preserve behaviour

Do not change:

- Database meaning.
- Existing URLs.
- Existing session variable names.
- Existing role semantics.
- MIDI note representation.
- Existing tutorial identifiers.
- Existing API response format.

unless the task explicitly requires it.

### Step 5 — Validate

For PHP changes:

```text
Syntax check
↓
Run locally
↓
Check browser console
↓
Check PHP/server logs
↓
Test database interaction
↓
Test unauthenticated access
↓
Test the required roles
```

For JavaScript changes:

```text
Console check
↓
Normal interaction
↓
Error state
↓
Reload
↓
Mobile/touch if relevant
```

For database changes:

```text
Schema
↓
INSERT/UPDATE/SELECT
↓
Existing data
↓
Empty data
↓
Invalid input
```

---

## 14. Agent Decision Rules

When a request is ambiguous, use this priority:

```text
1. Existing working project behaviour
2. Active database schema
3. Existing shared components/helpers
4. Existing CSS/JS conventions
5. This AGENTS.md
6. New implementation only where no existing pattern exists
```

Do not allow this file to override actual working code when the documentation is stale. Update this file when architecture changes.

---

## 15. Do Not Do These Things

Agents should not:

- Rewrite the project into Laravel/React/Vue unless explicitly requested.
- Introduce Composer/npm build tooling solely for convenience.
- Replace PDO with MySQLi in one feature.
- Create a second configuration/database layer.
- Hard-code production URLs.
- Hard-code absolute Windows paths.
- Duplicate shared header/footer/database logic.
- Store passwords in plaintext.
- Trust client-side roles.
- Put secrets in JavaScript.
- Delete old schema files simply because they are historical unless requested.
- Rename public PHP files without checking all links.
- Change database column meanings without migration/schema updates.
- Change third-party library versions without testing dependent code.
- Add a new color palette to a single page in isolation.
- Generate placeholder/demo functionality and present it as completed functionality.

---

## 16. Common Local Setup

### Requirements

- PHP 8+
- Apache
- MySQL/MariaDB
- Browser with JavaScript enabled

### Setup

1. Place the repository in the Apache web root.

2. Start Apache and MySQL/MariaDB.

3. Import:

```text
database/psm_schema.sql
```

4. Check:

```text
includes/config.php
```

5. Open the project through the local Apache URL, for example:

```text
http://localhost/UniProject/
```

The exact folder/URL depends on the local Apache configuration.

---

## 17. Troubleshooting Checklist

### PHP page gives a blank/500 response

Check:

```text
Apache/PHP error log
PHP syntax
include/require paths
database connection
```

### Database connection fails

Check:

```text
MySQL/MariaDB running
database name = PSM
username/password
host
config.php
```

### JavaScript feature does not work

Check:

```text
Browser console
Network tab
Script loading order
CDN/library availability
DOM element IDs/classes
API response JSON
```

### API appears to work but UI does not update

Check:

```text
HTTP status
Content-Type
JSON.parse()
Expected property names
Success/error response shape
```

### Login works but protected page is accessible without login

Check server-side session/role enforcement. Hiding a navigation link is not enough.

---

## 18. Documentation Rules

Documentation should be updated when any of these change:

- Database schema.
- Authentication/session fields.
- User roles.
- Major page structure.
- API response format.
- Third-party libraries.
- Piano engine architecture.
- MIDI representation.
- Tutorial progress model.
- Performance-session data model.
- Colour/design tokens.

Keep documentation factual. Do not document a planned feature as implemented.

For a new feature, document:

```text
Feature name
Purpose
Files involved
Database tables/fields
API endpoints
User roles
Important UI states
Known limitations
```

---

## 19. Change Log Guidance

When making a meaningful architectural change, add a short entry to the project's changelog or documentation with:

```text
Version/date
What changed
Why it changed
Files affected
Database impact
Backward-compatibility notes
```

Avoid vague statements such as:

```text
Updated system.
Fixed things.
Improved UI.
```

Prefer:

```text
Added API endpoint for saving tutorial section progress and updated
the student progress page to consume the new JSON response.
```

---

## 20. Current Repository Baseline

The GitHub repository currently documents version **0.3.6** as the baseline release. Its existing README identifies:

- PHP client/server structure.
- Top navigation.
- Interactive piano.
- Piano sound generation.
- Key lighting.
- MIDI import.
- Music-sheet display.
- Modular piano engine.
- Separate CSS/JS architecture.
- PDO database connection.
- Registration/login.
- Tutorial progress.
- Performance-session saving.

The active database file is documented as:

```text
database/psm_schema.sql
```

and the older:

```text
database/piano_course.sql
```

is historical.

---

## 21. Source-of-Truth Policy

When information conflicts, use this order:

```text
Actual running code
      ↓
Active SQL schema
      ↓
Shared configuration/helpers
      ↓
Current docs
      ↓
Historical docs / old SQL
```

Never assume that an old note, screenshot, or historical SQL file represents the current implementation.

---

## 22. Definition of Done for Agent-Generated Code

A change is considered complete only when:

- The requested feature exists.
- Existing features still work.
- The correct role can access it.
- Unauthorized users are rejected server-side.
- Database operations use the existing connection strategy.
- SQL uses prepared statements.
- User-controlled output is escaped.
- JavaScript errors are resolved.
- Existing UI conventions are preserved.
- Existing colour/design conventions are preserved.
- No secrets are added.
- Required documentation is updated.
- Any database changes are represented in the active schema documentation.

---

## 23. Important Maintenance Note

This file is intended to become the stable contract between the project and coding agents.

When the application architecture changes, update this file at the same time as the code. Do not allow the agent guide to describe features or conventions that no longer exist.

For major changes, include a concise migration note so a future coding agent can understand what changed without reconstructing the entire history.
