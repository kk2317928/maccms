# Headless AI v1 release-candidate acceptance

Status: ACCEPTED  
Candidate branch: `feature/headless-ai-v1`  
Candidate SHA: `ac0b9b58cfe655e6fc318ddfb68fb39ec6f6978e` (review-fix implementation exercised by rollback rehearsal; later report-only commits do not change runtime code)  
Acceptance date: 2026-09-20  
Release tag: not created; T-097 remains a separate operator-confirmed publication action.

## Acceptance evidence

| Gate | Result | Evidence |
|---|---|---|
| PHP regression | PASS | GitHub Actions run `35510268063` |
| MySQL 5.7 | PASS | Release matrix run `35510268068`, job `MySQL 5.7` |
| MySQL 8.0 | PASS | Release matrix run `35510268068`, job `MySQL 8.0` |
| Native video | PASS | Release matrix executes native admin/collection write and playback round-trip on both MySQL 5.7 and 8.0 |
| Prohibited outbound | PASS | `outbound_inventory.php --enforce` in the release matrix and rehearsal |
| Database restore | PASS | RC rollback rehearsal run `35510999172`; a pre-upgrade dump was restored into `maccms_ci_restore`, its marker row and absence of the candidate migration ledger were verified |
| Application rollback | PASS | RC rollback rehearsal run `35510999172`; immutable candidate/previous archives were checksummed, both CLI applications booted, the previous application read the restored marker through its rebound database configuration, and the active symlink returned to current |
| Migration idempotency | PASS | 18 migrations applied once and the second run applied 0 / skipped 18 on both supported MySQL families |
| Security edge cases | PASS | URL validation covers metadata/private IPv4 and IPv6, redirect revalidation, Host/header injection, request/response bounds and credential redaction; transport-bound DNS pinning remains a documented test limitation |
| Operations documentation | PASS | Deployment, migration, Cron, backup, rollback, health-check and disaster-recovery runbook contract |

## Scope accepted

The candidate preserves native MACCMS video and playback fields while adding extension tables, content jobs, AI normalization, duplicate review/restore, TMDB review, the intelligent-content workspace, API v1 sessions/catalog/activity/playback, events, rankings and recommendations.

Official updater execution, announcement/catalog defaults, affiliate preload/buffer values and installer telemetry are absent and protected by recursive regression gates. Retained AI and metadata HTTP calls use the centralized outbound policy.

## Rollback rehearsal result

The automated rehearsal created immutable candidate and previous application trees using `git archive`, verified their version-file checksums, and booted each archived CLI application. It switched the active symlink to the previous tree, rebound that release to the restored database, verified a representative marker through the application database layer, and switched back to the candidate.

A disposable MySQL 5.7 native installation was backed up before candidate migrations, then migrated and exercised through native video paths. The transaction-consistent pre-upgrade dump was restored into a separately named database; its representative marker and pre-upgrade migration state were verified before the previous application booted against it. No production system or credential was used.

## Known limitations

- Browser-driven installation, administrator login, collection UI, member UI and playback observation still require environment-specific smoke testing during the production change window.
- SMTP, SMS, payment, S3 and push integrations were not contacted; they remain disabled until explicitly configured and require provider-specific acceptance.
- Large existing `mac_vod` tables may require an extended maintenance window for the InnoDB conversion.
- The legacy broad API remains available alongside `/api/v1`; no deprecation date is declared in this candidate.
- Network-capture evidence depends on the deployment environment. Source-level prohibited-endpoint enforcement is automated, but transport-bound DNS pinning does not yet have an integration assertion; the operator must review firewall/proxy logs during rollout.

## Release decision

The release candidate meets the automated CP-09 acceptance and rollback requirements. T-097 may create `headless-ai-v1.0.0` only after explicit final authorization, confirmation of the intended commit SHA, and review of the known limitations. This report does not create or publish a tag.
