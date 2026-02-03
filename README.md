# Rhymix/XE → GNUBoard Migration Scripts 🔧

**Summary:** Small PHP scripts to migrate boards, posts, members, menus and basic site config from Rhymix/XE to GNUBoard (g5).

---

## Files

- `migrate2gb.php` — Main migration script. Detects `rhymix_` or `xe_` table prefix and migrates:
  - Creates entries in `g5_board`, `g5_write_{bo_table}` (creates minimal table if missing)
  - Migrates posts (documents), simple content path fixes, and inserts members into `g5_member` (with randomized passwords; original hash stored in `mb_memo`)
  - Adds simple menu entries (`g5_menu`) and updates `g5_config.cf_title` from source site
- `config.php` — Database connection settings for **source** (Rhymix/XE) and **target** (GNUBoard). Edit this before running.

---

## Quick start ✅

1. **Backup both databases** (source and target) — DO NOT skip this. ⚠️
2. Edit `config.php` and set correct DB connection values for `$src_config` and `$gn_config`.
3. Run the migration script from the project root or migration directory. Use the `--dry-run` flag to preview actions without making changes, and `--log=FILE` to save a log.

```bash
# Regular run (writes to target DB)
php migrate2gb.php

# Dry run (no writes, prints what would be done)
php migrate2gb.php --dry-run

# Dry run and write log to file
php migrate2gb.php --dry-run --log=migration.log
```

4. Check the console output (and optional log file) for created boards, posts, members and any warnings.

---

## Safety notes & limitations ⚠️

- The script **does not** attempt to perfectly translate custom fields, permissions, or advanced module settings.
- Passwords are reinitialized: a random password is stored in `mb_password` and the original hash is saved to `mb_memo`. Notify users to reset passwords.
- File path replacement is basic (e.g., `/storage/app/public/` → `/data/file/{bo_table}/`). Verify and adjust as needed.
- Always test on a copy of the databases first. Use a dev/staging environment.

---

## Troubleshooting 💡

- If you see `Rhymix 또는 XE의 모듈 테이블을 찾을 수 없습니다`, check that the source DB has `rhymix_modules` or `xe_modules` and that `config.php` credentials are correct.
- If inserts fail due to schema differences, inspect source column names (e.g., `documents`, `member`) and adapt the script accordingly.

---

## Suggested improvements

- Add a `--dry-run` mode to only count and report (no INSERTs).
- Migrate attachments and comments properly, preserving file records and references.
- Add configurable field mappings and a basic rollback plan.

---

## License

Use at your own risk. No warranty provided. Please keep a backup and validate migrated content.

---

If you'd like, I can add a `--dry-run` flag and a small unit of tests or add a `README` section with a sample `config.php` snippet. 👍
