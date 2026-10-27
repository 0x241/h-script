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

For staging CI, set the protected, environment-scoped `RECOVERY_ENABLED=1`
only after the first successful verified bundle and isolated drill. The deploy
job persists recovery settings in its private `.env`, including the external
secrets path, limited drill account and shared freshness policy. Leaving the
worker disabled does not waive production update checks: SQL backups from the
Configurator cannot refresh the separate database/files recovery report.

The scheduler repeats a drill when its previous backup is no longer retained,
its source schema changes, or its evidence is incomplete or expired. Keep row
bounds installation-specific; review legitimate count changes against a freshly
verified isolated restore rather than disabling bounds or increasing age limits.
