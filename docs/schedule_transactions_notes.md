# Schedule & Transactions — developer notes

> Additive subsystem (0.4.x). The old classroom scheme (`users.classroom`,
> `classroom_status`, `classroom_settings`, `teacher/teacher_classroom.php`,
> `student/student_classroom.php`) is untouched and still works.

## Purpose

- **Schedule** (`schedule.php`): week timetable of lessons (Mon–Sat, 30-min
  slots, weekday 9am–10pm, Sat 9am–6:30pm, Sun closed), lesson CRUD, teacher
  availability, repeat series.
- **Transactions** (`transactions.php`): per-student-per-lesson fee rows with
  totals, payment/commission status, proof upload/delete with audit log,
  commission defaults.

## Database (import after `psm_schema.sql`)

`database/psm_lessons.sql` creates, without touching existing tables:

| Table | One row per | Key columns |
|---|---|---|
| `lessons` | scheduled lesson | `teacher_id` (nullable = Unassigned), `lesson_date`, `start_time`, `end_time`, `status` (`SCHEDULED`/`COMPLETED`/`CANCELLED`), `recurrence_group` (repeat series tag, nullable), per-lesson `commission_type`/`commission_value` override, `commission_status`, `commission_proof_path` |
| `lesson_enrollments` | student in a lesson | `lesson_id`, `student_id` (unique pair), `fee_amount`, `payment_status` (`UNPAID`/`PENDING`/`PAID`), `proof_path`, `proof_uploaded_at` |
| `commission_settings` | global default | single `'default'` row: `commission_type` (`PERCENT`/`FIXED`), `commission_value` |
| `teacher_commission` | per-teacher override | `teacher_id` PK, type, value |
| `proof_logs` | every proof upload/delete | `kind` (`FEE`/`COMMISSION`), `lesson_id`, `enrollment_id`, `file_path`, `action`, `performed_by`, `created_at` (date log) |

Proof files live in `uploads/proofs/` (hashed names, 5MB cap,
jpg/png/webp/pdf); the DB stores paths only. `.htaccess` denies PHP
execution there.

Commission resolution (see `Lessons::resolveCommission()`): lesson override
→ teacher override → global default. Payout = percent of lesson fee total,
or fixed amount.

## Roles (all enforced server-side, never client-side)

| | ADMIN | TEACHER | STUDENT |
|---|---|---|---|
| Schedule | full CRUD, all lessons | own lessons + availability editor | own enrolments, read-only |
| Transactions | full edit, proofs, defaults | own rows, read-only | own rows, read-only |
| Mutations | all `api/*` below | none (403) | none (403) |

## API (`api/`, JSON `{success, data|error}`)

- `lessons_save.php` — `create` (single + repeat), `update`, `delete`,
  `delete_group` (whole repeat series). COMPLETED is rejected while end time
  is in the future; past SCHEDULED lessons auto-complete on page load
  (`Lessons::autoCompletePast()`).
- Repeat modes: `once` | `weekly` | `biweekly` | `monthly`, with
  `repeat_until` (YYYY-MM) or legacy `repeat_count`. Unusable dates are
  skipped and reported, capped at 24. Repeat-from-existing sends `skip_base`
  + `join_group` + `base_lesson_id` so clones join the original series
  instead of duplicating it.
- `enrollments_save.php` — `add`, `set_fee`, `set_payment`,
  `set_commission_status`, `set_lesson_fees` (one fee for all students),
  `remove` (deletes proof file, keeps log rows).
- `proof_upload.php` / `proof_delete.php` — admin-only, every action logged.
- `commission_save.php` — `default` + per-`teacher` rates.
- `save_settings.php` (extended) — `user_settings` JSON now also carries
  teacher `availability` (`[{dow: 1-6, start, end}]`); partial updates never
  wipe piano keybinds.

## Frontend notes

- Pages: `schedule.php`, `transactions.php`; styles
  `assets/css/schedule.css` (`.sched-*`), `assets/css/transactions.css`
  (`.txn-*`); shared header/footer, design tokens per skill `psm-web-style`.
- Account page (`account.php`): credentials + Schedule/Transactions highlight
  cards are primary; tutorial/game summaries sit under Learning activity.
- Timetable chips show time/duration/title, student names, teacher (or
  Unassigned), status; student-less lessons are red-flagged everywhere.

## Known limitations

- Server timezone drives auto-complete; Series Paid column is read-only by
  design (edit per-student fees instead); payment status on the schedule page
  is read-only (set it in Transactions); empty lessons are flagged, never
  auto-deleted.
