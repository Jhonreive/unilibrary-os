# UniLibrary OS

## Setting up

**Fresh install**

1. Create the database: `CREATE DATABASE ulibrary;`
2. Import: `mysql -u root -p ulibrary < schema.sql`
3. Copy `.env.example` to `.env` and fill in your DB settings (the supplied `.env` is a local-development default).
4. To set a first password for a seed account: set `ENABLE_SETUP=1` in `.env`, open `setup.php` **on the server itself** (localhost), then set it back to `0`.
5. **Delete `setup.php`** when you're done.

See `SECURITY_AUDIT.md` for the security model, roles and deployment checklist.
Existing database? Also run `migrate_security.sql`.

**Upgrading an existing database**

Do NOT re-import `schema.sql` — it would wipe your data. Run this instead:

```
mysql -u root -p ulibrary < migrate.sql
```

If you upgraded before ISBN support existed, also run (once):

```
mysql -u root -p ulibrary < migrate_isbn.sql
```

`migrate.sql` adds `members.school_id`, `transactions.member_id`, and the two new
settings columns, then back-fills them. Existing members get a placeholder
School ID like `TMP-00003`; edit them to the real numbers on the Members page.

## Accounts

- New staff can register themselves at `signup.php`.
- Passwords need 10+ characters with at least 3 of: lowercase, UPPERCASE, numbers, symbols.
- Roles: **Administrator** (everything), **Librarian** (catalog, members, loans, reports), **Staff** (borrow/return + read-only browsing).
- Accounts start as **Staff / Pending** and cannot sign in until an
  administrator approves them on the Users page.
- An administrator can switch self sign-up off in Settings.
- The system will not let you delete, demote, or suspend the last active
  administrator.

## ISBNs

- Every book needs an ISBN. Type it with or without hyphens; ISBN-10 and
  ISBN-13 are both accepted, check digits are verified, and everything is
  stored as 13 digits (an ISBN-10 is converted).
- Two books can't share an ISBN. To add more of the same title, raise its
  copy count instead.
- Books added before ISBN support show "ISBN missing" until you click Edit and fill it in.
- Search bars show live suggestions as you type (titles, authors, ISBNs, members), served by `suggest.php`. Use the arrow keys + Enter, or click one. To add suggestions to another search bar, give its input `data-suggest="books"`, `"members"` or `"all"`.
- You can search by full or partial ISBN on Books, Search and the Borrow page.

## Notes

- Loan period, fine rate, and the per-member loan limit all live in Settings
  and are applied when issuing loans.
- A book with loan history can't be deleted (the foreign key protects the
  history) — reduce its copy count instead.
- A member with books still out can't be deleted until those are returned.

## Styling (Tailwind CSS)

All styling now goes through Tailwind. The old `includes/theme.css` and `includes/responsive.css` are gone; their rules live in `src/input.css` (as `@layer` components built with `@apply`) and `tailwind.config.js` (colors, fonts, keyframes, animations).

After changing any class in a `.php` file, `src/input.css` or `tailwind.config.js`, rebuild the stylesheet:

    npm install      # first time only
    npm run build    # writes assets/app.css  (npm run dev to watch)

`header.php` still carries a large inline `<style>` block with the original page styles. Tailwind's preflight reset is ON; a small "legacy defaults" block at the top of `src/input.css` puts back the browser defaults those inline styles rely on (paragraph/heading/list spacing, line-height, inline SVGs). Remove rules from it as the inline styles get migrated.
