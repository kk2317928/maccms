# Foundation data deployment and rollback

This runbook deploys migration `20260918000100_foundation.sql` and the PHP code that creates `vod_ext` rows from native video writes. Use it only for a named MACCMS installation after testing the same release artifact on a disposable copy.

## Supported environment

- PHP 8.1 CLI and FPM with `pdo_mysql`, `mbstring`, JSON, fileinfo, and the extensions already required by the installed MACCMS features.
- MySQL 5.7.x or MySQL 8.0.x.
- A database user allowed to create and alter tables and indexes and to read/write `mac_schema_migration` (replace `mac_` below when the configured prefix differs).
- Enough disk space for an uncompressed logical backup plus application release files.

No content worker or new Cron entry is enabled by this foundation release. It creates no background jobs.

## 1. Pre-deployment record and safety checks

1. Schedule a write-maintenance window. The native schema contains MyISAM tables, so a transaction-only dump cannot provide a consistent backup while writes continue.
2. Record the release commit, application path, database host, exact database name, table prefix, PHP version, and MySQL version.
3. Confirm `application/database.php` points to the intended environment. Never infer a production database name from a shell wildcard or an unset variable.
4. Disable admin/collection/API writes for the backup and migration window while keeping a rollback operator session available.
5. Confirm the migration files in the release have not been edited after previous application. The runner rejects a changed checksum for an applied version.

Example preflight (replace every example value):

```bash
cd /srv/maccms/releases/RELEASE_SHA
php -v
php -m | grep -E '^(PDO|pdo_mysql|mbstring|json|fileinfo)$'
git rev-parse --verify HEAD
php -r '$c=require "application/database.php"; printf("host=%s database=%s prefix=%s\n", $c["hostname"], $c["database"], $c["prefix"]);'
```

Stop if the printed host, database, or prefix differs from the approved deployment record.

## 2. Create and verify the backup

Put database credentials in an operator-owned option file instead of the process arguments. Keep it outside the repository and restrict it to mode `0600`:

```ini
[client]
host=127.0.0.1
port=3306
user=maccms_backup
password=REPLACE_WITH_SECRET
```

Set explicit names, validate them, and create the backup while writes are disabled:

```bash
export MACCMS_DB_NAME='maccms_production'
export MACCMS_BACKUP_DIR='/srv/backups/maccms/2026-09-18-foundation'
export MACCMS_MY_CNF='/secure/operator/maccms-client.cnf'

case "$MACCMS_DB_NAME" in
  ''|mysql|information_schema|performance_schema|sys|*[!A-Za-z0-9_]*)
    echo 'Refusing unsafe database name' >&2
    exit 1
    ;;
esac
test -r "$MACCMS_MY_CNF"
test "$(stat -c '%a' "$MACCMS_MY_CNF")" = '600'
install -d -m 0700 "$MACCMS_BACKUP_DIR"

mysqldump \
  --defaults-extra-file="$MACCMS_MY_CNF" \
  --lock-all-tables \
  --routines --triggers --events \
  --hex-blob --set-gtid-purged=OFF \
  --databases "$MACCMS_DB_NAME" \
  > "$MACCMS_BACKUP_DIR/pre-foundation.sql"

test -s "$MACCMS_BACKUP_DIR/pre-foundation.sql"
sha256sum "$MACCMS_BACKUP_DIR/pre-foundation.sql" \
  > "$MACCMS_BACKUP_DIR/pre-foundation.sql.sha256"
sha256sum -c "$MACCMS_BACKUP_DIR/pre-foundation.sql.sha256"
cp -p application/database.php "$MACCMS_BACKUP_DIR/application.database.php"
```

`--lock-all-tables` is deliberate because the application mixes MyISAM and InnoDB. Keep writes disabled until the migration and forward checks finish.

## 3. Deploy files and run the migration

Deploy the exact reviewed release artifact, preserve writable runtime/upload directories according to the existing deployment procedure, and run from the release root:

```bash
php -l think
php -l application/command/MaccmsMigrate.php
php -l application/common/util/SchemaMigrationService.php
php think maccms:migrate
php think maccms:migrate
```

Expected output:

- First run on an unmodified installation: `done, applied=1, skipped=0`.
- Immediate second run: `done, applied=0, skipped=1`.

If the first run stops before recording the ledger row, keep writes disabled. The foundation DDL uses `CREATE TABLE IF NOT EXISTS`, so it can be inspected and rerun after correcting the cause. Do not insert or alter a ledger row manually.

## 4. Verify database invariants

Connect with the same protected option file and the exact database name. Replace `mac_` in these queries with the recorded configured prefix:

```bash
mysql --defaults-extra-file="$MACCMS_MY_CNF" \
  --database="$MACCMS_DB_NAME" \
  --batch --raw <<'SQL'
SELECT VERSION() AS mysql_version;
SELECT version, name, checksum, executed_at
FROM mac_schema_migration
ORDER BY version;
SHOW CREATE TABLE mac_vod_ext;
SHOW CREATE TABLE mac_meta_term;
SHOW CREATE TABLE mac_vod_meta_term;
SHOW CREATE TABLE mac_vod_field_state;
SQL
```

Verify all of the following before restoring writes:

- Exactly one ledger row exists for `20260918000100` and its checksum contains 64 lowercase hexadecimal characters.
- All four extension tables use InnoDB and an `utf8mb4` collation.
- `mac_vod_ext` has primary key `vod_id`, unique `uk_public_id`, and indexes `idx_workflow`, `idx_tmdb`, and `idx_merged_into`.
- `mac_meta_term` has unique `uk_kind_slug` and `idx_kind_status_sort`.
- `mac_vod_meta_term` has unique `uk_vod_term` and `idx_term_id`.
- `mac_vod_field_state` has unique `uk_vod_field`, `idx_source`, and `idx_locked`.

Then perform a controlled native video save and collection insert/update in the target environment. Confirm one `vod_ext` row per video, distinct six-character public IDs, unchanged collection return behavior, and byte-identical `vod_play_from`, `vod_play_url`, `vod_play_server`, and `vod_play_note` round trips. Record manual/UI results separately from automated model-boundary tests.

## 5. Complete or abort deployment

If every check passes:

1. Switch the application release symlink or deployment target to the verified release if it was staged separately.
2. Reload PHP-FPM so opcode caches use the new files.
3. Restore writes and run the focused health checks.
4. Retain the backup and its checksum according to the site's retention policy.
5. Record the release commit, migration output, schema query output, operator, and completion time.

If any check fails, keep writes disabled and follow the rollback procedure. Do not continue with partially verified schema.

## 6. Application rollback

Use the deployment system's immutable release directories or artifacts. Record the reviewed Git release tag and its full commit SHA before deployment; verify the tag with `git rev-list -n 1 RELEASE_TAG`. Point the application symlink back to that exact previously recorded tag/artifact and reload PHP-FPM. Do not use a broad recursive delete or a destructive Git reset on the live tree.

Rolling back files alone is sufficient only when no new extension data has been written and the old application safely ignores the added tables. Once production writes occurred, preserve database consistency by restoring the pre-deployment backup as described below.

## 7. Database restore

Do not present `DROP TABLE mac_vod_ext ...` as a complete rollback: native writes may already have created extension rows, and future migrations depend on the ledger. The authoritative rollback is the verified pre-deployment database backup.

The safer restore creates a separately named database, verifies it, and then changes the application configuration during maintenance:

```bash
export MACCMS_RESTORE_DB='maccms_restore_20260918'
case "$MACCMS_RESTORE_DB" in
  ''|mysql|information_schema|performance_schema|sys|*[!A-Za-z0-9_]*)
    echo 'Refusing unsafe restore database name' >&2
    exit 1
    ;;
esac

mysql --defaults-extra-file="$MACCMS_MY_CNF" \
  -e "CREATE DATABASE \`$MACCMS_RESTORE_DB\` CHARACTER SET utf8mb4"

# The dump contains its original CREATE/USE database statements. Make a reviewed
# copy that targets MACCMS_RESTORE_DB before importing; never use an unreviewed
# search/replace against a live database name.
cp "$MACCMS_BACKUP_DIR/pre-foundation.sql" \
  "$MACCMS_BACKUP_DIR/restore-reviewed.sql"
editor "$MACCMS_BACKUP_DIR/restore-reviewed.sql"
mysql --defaults-extra-file="$MACCMS_MY_CNF" \
  < "$MACCMS_BACKUP_DIR/restore-reviewed.sql"
```

Before cutover, confirm the reviewed dump names only `MACCMS_RESTORE_DB`, compare core table counts and selected rows, and verify administrator access using the disposable/maintenance route. Back up the current `application/database.php`, change only its database name to the verified restore database, reload PHP-FPM, and run read-only health checks before restoring writes.

Keep the failed database intact and access-restricted until incident review finishes. Deleting either database is a separate operator-approved action and is not part of this runbook.

## 8. Evidence checklist

Record these artifacts with secrets removed:

- Release and previous-release commit SHAs.
- PHP and MySQL versions and configured table prefix.
- Backup path, byte size, and SHA-256 verification result.
- First and second migration output.
- Ledger and `SHOW CREATE TABLE` results.
- Native admin-boundary, collection insert/update, and playback round-trip results.
- Roll-forward or rollback decision, operator, and timestamps.
- Confirmation that no foundation Cron job was added.
