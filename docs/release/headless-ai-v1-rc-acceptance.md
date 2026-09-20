# Headless AI v1 release-candidate acceptance

Status: ACCEPTED  
Candidate branch: `feature/headless-ai-v1`  
Acceptance date: 2026-09-20  
Release tag: not created; T-097 remains a separate operator-confirmed publication action.

## Acceptance evidence

| Gate | Result | Evidence |
|---|---|---|
| PHP regression | PASS | GitHub Actions run `35510268063` |
| MySQL 5.7 | PASS | Release matrix run `35510268068`, job `MySQL 5.7` |
| MySQL 8.0 | PASS | Release matrix run `35510268068`, job `MySQL 8.0` |
| Native video | PASS | MySQL 5.7 release-matrix native admin/collection write and playback round-trip |
| Prohibited outbound | PASS | `outbound_inventory.php --enforce` in the release matrix and rehearsal |
| Database restore | PASS | RC rollback rehearsal run `35510613365`; dump restored into `maccms_ci_restore`, table counts matched, and `mac_vod` remained InnoDB |
| Application rollback | PASS | RC rollback rehearsal run `35510613365`; immutable HEAD/HEAD-parent archives were checksummed and the active symlink switched current → previous → current |
| Migration idempotency | PASS | 18 migrations applied once and the second run applied 0 / skipped 18 on both supported MySQL families |
| Security edge cases | PASS | Metadata/private IPv4 and IPv6, DNS rebinding, Host/header injection, request/response bounds and credential redaction |
| Operations documentation | PASS | Deployment, migration, Cron, backup, rollback, health-check and disaster-recovery runbook contract |

## Scope accepted

The candidate preserves native MACCMS video and playback fields while adding extension tables, content jobs, AI normalization, duplicate review/restore, TMDB review, the intelligent-content workspace, API v1 sessions/catalog/activity/playback, events, rankings and recommendations.

Official updater execution, announcement/catalog defaults, affiliate preload/buffer values and installer telemetry are absent and protected by recursive regression gates. Retained AI and metadata HTTP calls use the centralized outbound policy.

## Rollback rehearsal result

The automated rehearsal created immutable current and previous application trees using `git archive`, verified their version-file checksums, switched the active symlink to the previous tree, and switched it back to the candidate.

A disposable MySQL 5.7 native installation was migrated and exercised through native video paths. The database was exported with a transaction-consistent dump, restored into a separately named database, and compared for table count and the required InnoDB video engine. No production system or credential was used.

## Known limitations

- Browser-driven installation, administrator login, collection UI, member UI and playback observation still require environment-specific smoke testing during the production change window.
- SMTP, SMS, payment, S3 and push integrations were not contacted; they remain disabled until explicitly configured and require provider-specific acceptance.
- Large existing `mac_vod` tables may require an extended maintenance window for the InnoDB conversion.
- The legacy broad API remains available alongside `/api/v1`; no deprecation date is declared in this candidate.
- Network-capture evidence depends on the deployment environment. Source-level prohibited-endpoint enforcement is automated, but the operator must still review firewall/proxy logs during rollout.

## Release decision

The release candidate meets the automated CP-09 acceptance and rollback requirements. T-097 may create `headless-ai-v1.0.0` only after explicit final authorization, confirmation of the intended commit SHA, and review of the known limitations. This report does not create or publish a tag.
