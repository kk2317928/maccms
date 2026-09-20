# Deployment and recovery runbook

This runbook is the operator procedure for the Headless AI v1 release. Commands must first be rehearsed on an isolated environment. Do not run destructive, migration, restore, or rollback commands against production until the target, backup identifier, commit SHA, and maintenance window have been independently checked.

## Supported baseline

- PHP 8.1 with the extensions used by the application, including PDO MySQL and mbstring.
- MySQL 5.7 or MySQL 8.0 using utf8mb4.
- A renamed administrator entry point; never deploy the default literal admin filename.
- Writable runtime, upload, cache, and log locations owned by the application account.
- Cron access for the same release and configuration used by web requests.

The authoritative pre-release gate is [release-regression.yml](../../.github/workflows/release-regression.yml). It runs the programme suites, native video paths, migrations, MySQL 5.7/8.0 checks, and the prohibited outbound enforcement test.

## Deployment inputs and change record

Record before deployment:

| Field | Required evidence |
|---|---|
| Release commit | Full immutable Git SHA |
| Artifact checksum | SHA-256 of the staged application archive |
| Database | Host, exact version and database name |
| Previous application | Previous release SHA/artifact identifier |
| Database backup | Encrypted dump identifier and checksum |
| File backup | Snapshot identifier for configuration and uploads |
| Maintenance window | Start, owner, approver and abort deadline |
| RPO | Maximum accepted data loss, explicitly approved |
| RTO | Maximum accepted restoration time, explicitly approved |

Never place database passwords, API keys, JWT secrets, SMTP credentials, object-storage secrets, member data, or signed playback URLs in tickets or command history.

## Pre-deployment checks

1. Confirm the checked-out SHA and compare it with the approved release candidate.
2. Confirm all checks from `.github/workflows/release-regression.yml` passed for that exact SHA.
3. Run the source policy gate:

   ```bash
   php tests/regression/outbound_inventory.php --enforce
   ```

4. Run a configuration diff with secret values redacted. Required state includes explicit database settings, non-default admin entry, strong API/JWT secrets, and disabled optional outbound integrations unless deliberately configured.
5. Verify free disk space for the code artifact, database dump, uploads snapshot, migration table rebuild, and rollback copy.
6. Confirm backup restoration has been tested recently and that the previous application artifact is available.
7. Put monitoring dashboards and web/PHP/MySQL logs where the deployment operator can observe them.

## Backup

Place the site in maintenance mode before the final backup when the approved RPO requires a write-consistent cutover.

Database example (supply credentials through a protected option file or prompt, not the command line):

```bash
mysqldump --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 DATABASE_NAME > maccms-before-SHA.sql
sha256sum maccms-before-SHA.sql
```

Back up these deployment-owned paths while preserving permissions:

- `application/extra` for configuration;
- `application/data` for migrations and application-managed data;
- `upload/` for locally stored media;
- the renamed administrator entry file;
- deployment-specific web-server and process-manager configuration.

Do not include runtime cache, sessions, plaintext credentials, temporary update archives, or unrelated host files in a distributable artifact. Encrypt backups at rest and restrict their retention and access.

## Deployment and migration

1. Enable maintenance mode or drain traffic according to the approved window.
2. Stage the immutable release in a new directory; do not overwrite the active release in place.
3. Restore environment-specific configuration without copying development secrets.
4. Verify ownership and permissions, then run PHP syntax and the baseline regression suite.
5. Run migrations exactly once from the staged release:

   ```bash
   php think maccms:migrate
   ```

6. Read the complete output. A second invocation must report no newly applied migrations:

   ```bash
   php think maccms:migrate
   ```

7. Atomically switch the active application symlink or deployment pointer.
8. Clear only application caches documented for this installation.
9. Disable maintenance mode only after health checks pass.

The migration ledger is checksum protected. Do not edit an applied migration. Add a new versioned migration for every correction. The InnoDB conversion of `mac_vod` may rebuild a large table, so estimate duration and free space before the maintenance window.

## Cron

Use absolute paths, a locked-down service account, and one scheduler owner. Example entries:

```cron
* * * * * cd /srv/maccms/current && /usr/bin/php think maccms:jobs --limit=20 >> /var/log/maccms/jobs.log 2>&1
*/5 * * * * cd /srv/maccms/current && /usr/bin/php think maccms:analytics >> /var/log/maccms/analytics.log 2>&1
```

The required commands are:

```bash
php think maccms:jobs
php think maccms:analytics
```

Prevent overlapping workers with the platform scheduler or a non-blocking lock. Alert on nonzero exit status, stale worker heartbeat, growing queue age, repeated retries, analytics lag, or log write failure. Cron must use the same code SHA and configuration as the web application.

## Health check and acceptance

A health check must verify more than an HTTP 200:

- public home, legacy API, `/api/v1`, and renamed admin login respond without PHP warnings;
- database connectivity and the migration ledger are readable;
- `mac_vod` is InnoDB and expected extension tables/indexes exist;
- one controlled native video read and playback decode succeeds;
- queue heartbeat and analytics freshness are within the approved threshold;
- optional integrations remain disabled unless explicitly approved;
- logs contain no secrets, repeated exceptions, migration attempts, or prohibited destinations;
- `php tests/regression/outbound_inventory.php --enforce` still passes.

Keep maintenance mode enabled and begin rollback if a critical health check fails.

## Application rollback

Rollback is safe only after evaluating whether the new release wrote schema or data the prior code cannot understand.

1. Stop Cron and background workers.
2. Enable maintenance mode and capture diagnostic logs.
3. Point the active release back to the recorded previous immutable SHA/artifact.
4. Restore the prior compatible configuration.
5. If migrations are backward-compatible, retain the database and run the prior release health check.
6. If they are not compatible, perform the database and file restore procedure below.
7. Restart workers only after web and database health checks pass.
8. Record the cause, timestamps, data interval affected, and final active SHA.

Do not attempt ad-hoc reverse SQL. There are no automatic down migrations.

## Restore and disaster recovery

Use this procedure when the database or deployment state cannot be trusted:

1. Declare the incident, identify the approved restore point, and record expected RPO and RTO impact.
2. Isolate the damaged environment and preserve logs for investigation.
3. Provision a clean host/database of a supported version.
4. Verify backup checksums before restore.
5. Restore the database into a newly named database, never over the only surviving copy.
6. Restore `application/extra`, `application/data`, `upload/`, the admin entry, and deployment configuration.
7. Deploy the exact application SHA paired with the backup.
8. Run read-only schema checks and health checks before allowing traffic.
9. Reconcile uploads/object storage and jobs created after the restore point.
10. Rotate any credential whose confidentiality may have been affected.
11. Re-enable traffic gradually, monitor errors/queue depth, and document achieved RPO/RTO.

Example database restore:

```bash
mysql --default-character-set=utf8mb4 RESTORE_DATABASE < maccms-before-SHA.sql
php think maccms:migrate
```

A restore is not complete until application, database, files, permissions, Cron, outbound policy, playback, and administrator access have been verified together.

## Post-deployment record

Attach the exact check-run URLs, migration output, backup/snapshot identifiers, smoke-test evidence, observed outbound destinations, active SHA, operator, approval, start/end time, and any accepted limitation. Retain sanitized evidence according to the project security policy.
