-- Run this ONLY if you already imported the older schema.sql and have data
-- you want to keep. It adds the new columns without touching your rows.
--   mysql -u root -p ulibrary < migrate.sql

ALTER TABLE members      ADD COLUMN school_id VARCHAR(30) NOT NULL DEFAULT '' AFTER id;
ALTER TABLE transactions ADD COLUMN member_id INT NULL AFTER book_id;
ALTER TABLE settings     ADD COLUMN max_loans INT NOT NULL DEFAULT 3;
ALTER TABLE settings     ADD COLUMN allow_signup TINYINT(1) NOT NULL DEFAULT 1;

-- Give existing members a placeholder School ID so the unique index can be
-- added. Edit them to the real numbers on the Members page afterwards.
UPDATE members SET school_id = CONCAT('TMP-', LPAD(id, 5, '0')) WHERE school_id = '';
ALTER TABLE members ADD UNIQUE KEY uniq_school_id (school_id);

-- Back-fill member_id on old transactions by matching the stored name.
UPDATE transactions t
  JOIN members m ON m.name = t.member_name
  SET t.member_id = m.id
  WHERE t.member_id IS NULL;
