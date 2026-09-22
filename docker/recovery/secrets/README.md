# Local recovery configuration mount

Only this README and `.gitignore` are tracked. Other files are for local testing.
For a server, set `RECOVERY_SECRETS_PATH` to an operator-managed directory outside
the checkout. Compose mounts it read-only at `/run/secrets/hscript-recovery` in
the recovery container.

- `row-bounds.json`: expected minimum/maximum restored table row counts, selected
  for this installation (`RECOVERY_ROW_BOUNDS_FILE`). This is policy, not a secret.
- `drill-db-password`: password for an account limited to disposable drill
  databases. Set `RECOVERY_DRILL_DB_ADMIN_PASSWORD_FILE` to its mounted path.

Backups stay in `backup/` (the persistent backup volume in Docker). H-Script does
not copy them to another server or require remote storage credentials/encryption
keys. Replication and encryption are the responsibility of external operator tools.
Existing backup archives must not be placed in this configuration directory.

Keep recovery disabled until row bounds and isolated DB access are verified.
The non-secret RPO/RTO policy is shared with the web updater. Do not mount drill
credentials in `app`. The scheduler runs as `www-data` after container startup;
one-off backup/drill commands should also use `--user www-data` so private
archives and reports remain readable by the Configurator. The mounted policy
and password files must be readable by that user, never world-readable.
The default interval is one hour. Failed one-shot jobs exit nonzero; the scheduler
retains retries. Notification delivery is bounded to five seconds.
