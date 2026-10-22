-- Security hardening migration. Safe to run more than once.
--   mysql -u root -p ulibrary < migrate_security.sql

-- 1) Rate-limit storage (the app also creates this itself if it has CREATE rights)
CREATE TABLE IF NOT EXISTS rate_limits (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(40) NOT NULL,
  k          CHAR(64)    NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  KEY idx_lookup (bucket, k, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Least-privilege application account (STOP using root in .env).
--    Change the password, then put these values in .env:
--      DB_USER=ulibrary_app    DB_PASS=<the password you chose>
--    The app only ever needs data access, never DROP / ALTER / FILE / GRANT.
--    (CREATE is included only so the app can self-create rate_limits; remove it
--     once this migration has run, since the table then already exists.)
CREATE USER IF NOT EXISTS 'ulibrary_app'@'localhost' IDENTIFIED BY 'CHANGE-THIS-LONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON ulibrary.* TO 'ulibrary_app'@'localhost';
FLUSH PRIVILEGES;
