# Job queue migration

Phase 5 replaces the legacy SMS `Queue` table with the generic `Jobs` table.
Do not enable `APP_AUTO_INSTALL` or `APP_INSTALL_FORCE` for this update.

## Existing installation

1. Back up the `Queue` and `Cfg` tables, or take a full database backup.
2. Deploy the application code.
3. Run the idempotent migration while the old `Queue` table is still present:

   ```bash
   APP_DOMAIN=example.com php bin/migrate-jobs.php
   ```

4. Check the reported migrated/skipped counts.
5. Remove the legacy table only after the backup and verification:

   ```bash
   APP_DOMAIN=example.com php bin/migrate-jobs.php --drop-legacy
   ```

The command creates `Jobs`, migrates every legacy SMS row once, verifies the
row mapping, and synchronizes `Const_DBVer`. Use the installation's real domain
because it is part of the database credential key. Re-running the command is
safe.

## New installation

New installations create `Jobs` directly from `_dbstru.php`; no migration is
required.
