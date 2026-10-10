-- ============================================================
-- Session and Legislative Meeting Management System — MySQL setup
--
-- Run this FIRST, before schema.sql (or before just loading the app,
-- since store.php auto-creates tables on first use once this user/
-- database exists).
--
-- In XAMPP: open http://localhost/phpmyadmin, click "SQL", paste this,
-- click Go. On a real host, run:
--   mysql -u root -p < setup_database.sql
--
-- Matches the default values already in includes/db_config.php —
-- if you use these exact names, you don't need to edit that file.
-- If you change the DB name/user/password here, update
-- includes/db_config.php to match (or set the SSMS_DB_* environment
-- variables instead).
-- ============================================================

CREATE DATABASE IF NOT EXISTS ssms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ssms_user'@'localhost' IDENTIFIED BY 'ssms_pass_change_me';
GRANT ALL PRIVILEGES ON ssms_db.* TO 'ssms_user'@'localhost';
FLUSH PRIVILEGES;
