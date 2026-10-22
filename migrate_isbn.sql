-- Adds ISBN support to an existing database. Run it ONCE:
--   mysql -u root -p ulibrary < migrate_isbn.sql
--
-- The column has to allow NULL here only because your existing books don't
-- have an ISBN yet. The app REQUIRES an ISBN for every new book and every
-- edit, and flags books that are still missing one on the Books page -- fill
-- them in with Edit. (MySQL allows many NULLs under a UNIQUE index, so the
-- not-yet-filled books don't clash; real ISBNs must be unique.)
-- ISBNs are stored as 13 digits, no hyphens (the app converts ISBN-10s).

ALTER TABLE books ADD COLUMN isbn CHAR(13) NULL AFTER id;
ALTER TABLE books ADD UNIQUE KEY uniq_isbn (isbn);

-- Optional: fill in the six original sample books.
UPDATE books SET isbn = '9780132350884' WHERE title = 'Clean Code'                            AND isbn IS NULL;
UPDATE books SET isbn = '9780262033848' WHERE title = 'Introduction to Algorithms'            AND isbn IS NULL;
UPDATE books SET isbn = '9780306447907' WHERE title = 'Principles of Quantum Mechanics'       AND isbn IS NULL;
UPDATE books SET isbn = '9781449373320' WHERE title = 'Designing Data-Intensive Applications' AND isbn IS NULL;
UPDATE books SET isbn = '9780061120084' WHERE title = 'To Kill a Mockingbird'                 AND isbn IS NULL;
UPDATE books SET isbn = '9780553380163' WHERE title = 'A Brief History of Time'               AND isbn IS NULL;
