-- UniLibrary OS database schema + seed data
-- Import with: mysql -u root -p ulibrary < schema.sql
-- (create the database first: CREATE DATABASE ulibrary;)
--
-- ALREADY HAVE AN OLDER ulibrary DATABASE? Don't re-import this file --
-- it would wipe your data. Run migrate.sql instead; it only adds the
-- new columns (migrate.sql, then migrate_isbn.sql if you don't have ISBNs yet).

CREATE TABLE IF NOT EXISTS books (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  isbn      CHAR(13) NULL,
  title     VARCHAR(255) NOT NULL,
  author    VARCHAR(255) NOT NULL,
  genre     VARCHAR(100) NOT NULL,
  copies    INT NOT NULL DEFAULT 1,
  available INT NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_isbn (isbn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS members (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  school_id VARCHAR(30)  NOT NULL,
  name      VARCHAR(255) NOT NULL,
  type      VARCHAR(20)  NOT NULL DEFAULT 'Student',
  email     VARCHAR(255) NOT NULL,
  joined    DATE NOT NULL,
  status    VARCHAR(20)  NOT NULL DEFAULT 'active',
  UNIQUE KEY uniq_school_id (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  email         VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role          VARCHAR(50)  NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'invited'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  book_id     INT NOT NULL,
  member_id   INT NULL,
  member_name VARCHAR(255) NOT NULL,
  borrow_date DATE NOT NULL,
  due_date    DATE NOT NULL,
  return_date DATE NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  FOREIGN KEY (book_id) REFERENCES books(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  id            INT PRIMARY KEY DEFAULT 1,
  library_name  VARCHAR(255) NOT NULL DEFAULT 'University Library',
  fine_rate     DECIMAL(6,2) NOT NULL DEFAULT 0.50,
  loan_period   INT NOT NULL DEFAULT 14,
  max_loans     INT NOT NULL DEFAULT 3,
  allow_signup  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login / sign-up / password-change throttling (also auto-created by the app if missing)
CREATE TABLE IF NOT EXISTS rate_limits (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(40) NOT NULL,
  k          CHAR(64)    NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  KEY idx_lookup (bucket, k, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- seed data (mirrors the original mock dataset) --

INSERT INTO books (isbn, title, author, genre, copies, available) VALUES
('9780132350884', 'Clean Code', 'Robert C. Martin', 'Software Engineering', 4, 1),
('9780262033848', 'Introduction to Algorithms', 'Cormen, Leiserson, Rivest, Stein', 'Computer Science', 3, 0),
('9780306447907', 'Principles of Quantum Mechanics', 'R. Shankar', 'Physics', 2, 0),
('9781449373320', 'Designing Data-Intensive Applications', 'Martin Kleppmann', 'Software Engineering', 3, 0),
('9780061120084', 'To Kill a Mockingbird', 'Harper Lee', 'Literature', 5, 5),
('9780553380163', 'A Brief History of Time', 'Stephen Hawking', 'Physics', 3, 3);

INSERT INTO members (school_id, name, type, email, joined, status) VALUES
('2025-00141', 'Marcus Vance', 'Student', 'm.vance@student.ulib.edu', '2025-01-14', 'active'),
('2025-00202', 'Alexandra Chen', 'Student', 'a.chen@student.ulib.edu', '2025-02-02', 'active'),
('2025-00320', 'Sophia Rodriguez', 'Student', 's.rodriguez@student.ulib.edu', '2025-03-20', 'active'),
('FAC-2022-08', 'Dr. Robert Oppenheimer', 'Faculty', 'r.oppenheimer@ulib.edu', '2022-08-01', 'active'),
('FAC-2021-11', 'Prof. Margaret Hamilton', 'Faculty', 'm.hamilton@ulib.edu', '2021-09-11', 'active');

-- password_hash is left NULL for seed users on purpose - nobody can log
-- into an account with no password set. After importing this file, open
-- setup.php in the browser once per account to set a real password, then
-- delete setup.php.
INSERT INTO users (name, email, role, status) VALUES
('Ana User', 'admin@ulibrary.edu', 'Administrator', 'active'),
('Bea Santos', 'b.santos@ulibrary.edu', 'Librarian', 'active'),
('Carlo Reyes', 'c.reyes@ulibrary.edu', 'Staff', 'invited');

INSERT INTO transactions (book_id, member_id, member_name, borrow_date, due_date, return_date, status) VALUES
(1, 2, 'Alexandra Chen', '2026-07-20', '2026-08-03', NULL, 'active'),
(2, 1, 'Marcus Vance', '2026-08-01', '2026-08-15', NULL, 'active'),
(3, 4, 'Dr. Robert Oppenheimer', '2026-08-01', '2026-08-15', NULL, 'active'),
(5, 3, 'Sophia Rodriguez', '2026-07-01', '2026-07-15', '2026-07-14', 'returned'),
(4, 5, 'Prof. Margaret Hamilton', '2026-07-27', '2026-08-10', NULL, 'active');

INSERT INTO settings (id, library_name, fine_rate, loan_period, max_loans, allow_signup) VALUES
(1, 'University Library', 0.50, 14, 3, 1);