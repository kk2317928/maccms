# Headless AI content workflow operations

This document covers the production content lifecycle added on `feature/headless-ai-v1`. The OpenAPI schema at `GET /api/v1/openapi.json` remains authoritative for request and response fields.

## Lifecycle

A video created by the native administrator, collection importer, or protected import API receives one `vod_ext` row, a six-character public ID, and one idempotent AI job.

| State | Operator action |
|---|---|
| `ai_processing` | Run `maccms:jobs`; review provider/budget failures |
| `duplicate_review` | Mark every candidate different or explicitly merge |
| `tmdb_matching` | Select a stored TMDB candidate or record no match |
| `manual_review` | Close AI/taxonomy decisions, preview, then publish |
| `published` | Verify API v1 detail and playback policy |
| `failed` | Inspect redacted error class and retry the exact failed stage |
| `merged` | Access through the canonical primary; restore only from a verified snapshot |

The system never auto-merges, never creates unknown taxonomy terms, and never overwrites manually locked fields.

## Protected import

`POST /api/v1/import/videos` uses `MACCMS_CONTENT_IMPORT_SECRET`, not member authentication. Clients must send the headers and signature defined in OpenAPI, including a current timestamp, one-time nonce, and payload-bound `Idempotency-Key`.

Operational requirements:

- distribute the secret only through the protected deployment channel;
- synchronize client and server clocks;
- generate a new nonce for every request;
- reuse an idempotency key only for the byte-identical logical request;
- reject direct publication/merge fields at the client before transmission;
- rotate the secret after suspected disclosure and invalidate the old deployment value.

`GET /api/v1/people/{slug}` and `GET /api/v1/site-config` are public but return closed DTOs. They must not expose native IDs or private configuration.

## Existing-content repair

Always begin with dry-run:

```bash
php think maccms:repair-content --limit=100
```

Apply only after reviewing the counts:

```bash
php think maccms:repair-content --apply --confirm=REPAIR --limit=100
```

Run additional batches until `scanned=0`. Limits are 1–500. Each row is re-read and locked inside its transaction. Published, merged, manual-review, and manually locked rows fail closed. Repair stages taxonomy suggestions for human review and uses existing job idempotency keys.

## Merge and restoration

A version-2 merge snapshot includes both videos, playback, aliases, locale rows, taxonomy relations, external source mappings, field states, and the exact merged projection. Before restore, the service verifies snapshot integrity and detects post-merge edits. A conflict is an operator decision; do not edit the snapshot JSON or force SQL updates.

After restore, confirm:

1. both videos recovered their pre-merge workflow/canonical state;
2. playback and locale rows match the snapshot;
3. taxonomy and external mappings returned to their original owner;
4. the candidate reopened and the immutable audit event exists.

Version-1 snapshots remain readable through their original compatibility path.

## Monitoring and troubleshooting

Monitor queue age, failed/paused jobs, worker lease expiry, AI daily budget, TMDB review backlog, manual-review backlog, and audit-write errors. Logs must use redacted error classes and never contain authorization headers, API keys, signed playback URLs, or provider secrets.

For a stuck item, inspect its `vod_ext.workflow_status`, latest `content_job`, pending duplicate candidates, latest TMDB review, pending AI field reviews, pending taxonomy suggestions, and manual locks—in that order. Do not skip workflow states with direct SQL.

## Acceptance

Automated release gates run `tests/integration/content_lifecycle_mysql.php` on MySQL 5.7 and 8.0. It covers native creation, one AI job, real pipeline persistence, taxonomy adoption, duplicate decision, TMDB handoff, publication, API visibility, idempotent replay, and version-2 merge/restore.

Automated evidence does not replace the fresh Web/browser checklist in `docs/testing/native-smoke-checklist.md`. The final `headless-ai-v1.0.3` tag remains blocked until that checklist is recorded.
