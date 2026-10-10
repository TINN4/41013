# Storage & Migration

## Decision
JSON storage was **kept** (no silent switch). It is now safe for a single-server, low-concurrency deployment: exclusive locks around every read-modify-write, atomic temp-file+rename writes, hard failure on corrupt files (never overwritten), unique ids assigned inside the lock, per-table lock files. 9 simultaneous writes were tested without loss (I5).

**Limits:** flat files lock the *whole table*, do not support multi-table transactions (a crash between two table writes can leave related records out of step — each module orders writes so the official record is written first), and do not scale to many concurrent users. For real multi-user production use, migrate to MySQL/MariaDB:
`users, members, sessions(UNIQUE title+date), agenda(UNIQUE session_id+order), attendance(UNIQUE session_id+member_id, FK→sessions/members), attendance_history, proceedings(+history), minutes(+versions), badges, audit_log`, with InnoDB foreign keys, transactions and prepared statements. `includes/store.php` is the single data-access layer; swap it behind the same function names. This migration was **not** performed or tested.

## Data layout (`session-system/data/`, all private)
| File | Contents |
|---|---|
| `users.json` | accounts, role, password hash, encrypted TOTP secret, hashed recovery codes |
| `badges.json` | hashed QR badge tokens, device binding (hashed secret) |
| `sessions/agenda/attendance/proceedings/minutes.json` | original legislative data (+ new fields) |
| `attendance_history, proceedings_history, agenda_revisions, minutes_versions.json` | change history |
| `settings.json` | quorum rule + scheduling defaults |
| `audit.log` | append-only, hash-chained |
| `ratelimit.json` | login/MFA/QR throttling |
| `backups/` | timestamped copies made by `tools/setup.php` and break-glass |

## Migration performed by `php tools/setup.php` (idempotent, additive)
1. Backs up all data to `data/backups/<timestamp>_pre-migration/`.
2. Adds missing fields: sessions (`end_time, agenda_status='Draft', agenda_revision, ended_at, cancel_reason, updated_at`), members (`eligible_for_quorum=true`), attendance (`time_out, recorded_by, updated_at`), proceedings (`status='Draft', kind, agenda_id, speaker, version, author_id`). **No record is removed or altered.** Existing proceedings become *Draft* (they have not been through the new review step).
3. Creates empty `users/badges/history` tables and default `settings.json` (quorum = majority, **unconfirmed**).
4. Moves legacy `qr_users.json` (plaintext tokens) into the backup. Old badges are therefore invalid: issue new ones in *QR Badges & Devices*. This is deliberate — those tokens were publicly readable.

**Rollback:** copy the files from `data/backups/<timestamp>_pre-migration/` back over `data/`; or restore your original ZIP (unchanged, kept by you). Legacy sessions keep working; note legacy session 1 is *Ongoing* with a *Draft* agenda (allowed; the finalize-before-start rule applies only to starting).
