-- ============================================================
-- Session and Legislative Meeting Management System — MySQL schema
--
-- You do NOT need to run this by hand — includes/store.php creates
-- these tables automatically the first time each one is used. This
-- file is here for reference, and for hosts where the DB user isn't
-- allowed to run CREATE TABLE at runtime:
--   mysql -u your_user -p your_database < schema.sql
--
-- Design note: each table has a real MySQL `id` column (indexed
-- primary key, real integer) plus a `data` JSON column holding the
-- rest of the row. Every module in this app reads/writes plain
-- associative arrays with whatever fields it needs (e.g. `minutes`
-- has nested present/absent/excused/agenda/log arrays that don't fit
-- flat columns), so this keeps 100% compatibility with the existing
-- code — nothing in js/, modules/, or api/ had to change. MySQL's
-- native JSON type is still fully queryable (JSON_EXTRACT,
-- generated/virtual columns, indexes on those) if you want to
-- normalize specific fields later.
-- ============================================================

CREATE TABLE IF NOT EXISTS sessions (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agenda (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proceedings (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS minutes (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qr_users (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secretary/admin login accounts (username + bcrypt password_hash + name).
-- Auto-seeded with one account (admin / admin123) the first time
-- login.php runs on a fresh install; manage further accounts from
-- Account Settings inside the app.
CREATE TABLE IF NOT EXISTS staff_users (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every QR badge login attempt (success and rejected), for reviewing
-- suspicious activity — see manage_devices.php > Recent Login Activity.
CREATE TABLE IF NOT EXISTS login_audit (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
