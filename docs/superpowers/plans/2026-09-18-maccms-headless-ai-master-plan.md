# MACCMS v10 Headless AI Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan phase-by-phase. Every phase must finish its verification gate before the next begins.

**Goal:** Turn the pinned MACCMS v10 upstream into a maintainable video CMS backend with AI-assisted metadata processing, duplicate review and reversible merging, TMDB enrichment, an integrated administration workspace, and a secure `/api/v1` for a future Next.js frontend.

**Architecture:** Preserve `mac_vod`, native collection, membership, permissions, and playback fields as the compatibility core. Add versioned extension tables and focused services around that core; long-running work uses a MySQL queue and short-lived Cron commands. Public clients consume explicit DTOs and six-character public IDs rather than database rows or numeric `vod_id` values.

**Tech Stack:** MACCMS v10 / ThinkPHP 5.x, PHP 8.1, MySQL 5.7 and 8.0, Nginx, native PHP regression scripts, OpenAI-compatible API, TMDB API, future Next.js frontend.

**Spec:** `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`

**Pinned upstream:** `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`

## Global constraints

- Phase 1 handles video content only; no article, comic, or image AI pipeline.
- Do not require Redis, Docker, Node.js, or a resident worker on the MACCMS host.
- Preserve native collection, video editing, membership, permission, and `vod_play_*` behavior.
- Keep workflow state separate from native publication state.
- AI and TMDB suggestions never overwrite a manually locked field.
- Duplicate candidates are never merged automatically.
- All schema changes are versioned, repeatable migrations, never web-request auto-migrations.
- All external calls use explicit configuration, timeouts, secret redaction, and SSRF controls.
- Remove nonessential official update, announcement, affiliate, and telemetry traffic while retaining Apache 2.0 attribution.
- Develop test-first, commit small reviewable units, and create a release/rollback point at every phase gate.

## Branch and release model

- `main`: clean pinned upstream baseline plus project documentation.
- `feature/headless-ai-v1`: integration branch for the complete programme.
- `feature/foundation-data`, `feature/ai-jobs`, `feature/dedup-tmdb`, `feature/smart-admin`, `feature/api-v1`, and `feature/security-release`: optional short-lived phase branches cut from the integration branch.
- Merge each phase only after its tests and manual acceptance gate pass. Tag accepted milestones as `foundation-v1`, `ai-jobs-v1`, `dedup-tmdb-v1`, `smart-admin-v1`, `api-v1-rc1`, and `headless-ai-v1.0.0`.

## Phase 0 — Re-establish the upstream baseline

### Deliverables

- Record upstream repository, branch, commit SHA, PHP/MySQL compatibility, license, and local test commands in `docs/development/upstream-baseline.md`.
- Inventory existing API, AI, multilingual, task, statistics, update, announcement, affiliate, and outbound-network code before adding parallel structures.
- Add a regression runner that exercises existing lightweight tests without changing production behavior.
- Document all discovered outbound hosts and classify each as required, optional/configured, or prohibited.

### Verification gate

- Working tree contains only intended documentation/test-harness changes.
- Existing PHP files used by the application pass syntax checks under PHP 8.1.
- Native installation, admin login, video save, collection, member login, and playback are recorded as baseline smoke tests.
- No upstream source behavior has changed.

## Phase 1 — Foundation and data compatibility

Execute `docs/superpowers/plans/2026-09-18-maccms-foundation-data.md`.

### Deliverables

- Idempotent migration command and migration ledger.
- `vod_ext`, taxonomy relations, field provenance/lock state, workflow state, and stable six-character `public_id`.
- Lossless native playback codec shared by import, merge, and future API code.
- Extension-record creation at real admin and collection persistence boundaries.
- MySQL 5.7/8.0 installation and rollback documentation.

### Verification gate

- Foundation regression suite and existing registration regression pass.
- Migration applies once and the second run produces no schema changes on both supported MySQL versions.
- Admin and collection writes create exactly one extension row each.
- Playback strings decode and encode byte-for-byte.

## Phase 2 — Queue, AI normalization, and field governance

### Work packages

1. Add `content_job` and `content_job_run` migrations with type, payload, status, priority, attempts, next-run time, lock owner/expiry, idempotency key, error class, and redacted summary.
2. Implement atomic claiming, expired-lock recovery, exponential backoff, maximum attempts, per-run job/time limits, and a `maccms:jobs` Cron command.
3. Add encrypted configuration for OpenAI-compatible Base URL, API key, model, timeout, retries, batch size, prompt version, and daily budget.
4. Implement an HTTP client boundary with HTTPS-by-default, DNS/IP validation, redirect revalidation, response-size limits, and masked logging.
5. Define and validate the AI JSON schema for normalized/original/multilingual titles, aliases, year, media type, TMDB clues, taxonomy suggestions, confidence, and reason.
6. Store immutable AI runs with model, prompt version, usage, raw-response fingerprint, validation status, and accepted/rejected decisions.
7. Apply accepted values through one field-governance service enforcing `manual > confirmed_tmdb > ai > import` and field locks.
8. Add retry, budget exhaustion, malformed JSON, schema mismatch, timeout, rate-limit, and manual-review tests.

### Verification gate

- Two concurrent workers cannot claim the same job.
- Interrupted work is reclaimable only after lock expiry.
- Repeated enqueue with the same idempotency key creates one logical job.
- Invalid AI output never changes canonical fields.
- Secrets and raw authorization headers never appear in logs or admin output.

## Phase 3 — Duplicate review, reversible merge, and TMDB

### Work packages

1. Persist duplicate candidates, evidence, score, decision, reviewer, and timestamps.
2. Implement deterministic scoring for TMDB ID; normalized original title/year/type; multilingual title/alias/year; title plus cast/director; and weak title-only evidence.
3. Build a side-by-side review service that never auto-merges and can mark different works permanently.
4. Create transactional merge snapshots covering extensions, aliases, taxonomy, playback sources, external IDs, field states, and dependent relations.
5. Merge playback through the shared codec, deduplicate by normalized URL, retain primary ordering, mark the secondary as `merged`, and expose its canonical target.
6. Implement authorized restoration with conflict detection and a complete audit record.
7. Add TMDB search in original, English, Traditional Chinese, Simplified Chinese, and alias order, with year/type/region/cast scoring.
8. Store all candidates and allow high-confidence preselection, manual ID lookup, explicit no-match, and rematch.
9. Import localized titles, overview, dates, countries, genres, people, images, trailers, ratings, and external IDs without overwriting locks.

### Verification gate

- Same-name different works remain separate in regression fixtures.
- Merge plus restore returns all snapshotted values and relationships.
- Secondary public IDs resolve to canonical content without exposing numeric IDs.
- TMDB unique, ambiguous, no-match, manual-ID, API failure, and stale-result cases pass.

## Phase 4 — Intelligent-content administration workspace

### Work packages

1. Add permissions for viewing, running AI/TMDB, reviewing, merge/restore, publishing, and security settings.
2. Add dashboard counts, queue age/depth, success/failure rate, budget usage, external rate limits, and Cron heartbeat.
3. Add queues and filters for imported, AI processing, duplicate review, TMDB matching, manual review, failed, published, rejected, and merged states.
4. Add field-level before/after review, accept/edit/reject/lock actions, provenance display, and conflict warnings.
5. Add duplicate comparison, merge confirmation, snapshot preview, restoration, and different-work decision screens.
6. Add TMDB candidates, manual ID, no-match, rematch, and per-field difference preview.
7. Add final validation and publication preview for multilingual content, taxonomy, images, trailers, and playback sources.
8. Ensure batch actions enqueue work rather than waiting for external APIs in HTTP requests.

### Verification gate

- Every action is denied without its exact permission.
- Merge, restore, bulk overwrite, publication, and key changes require confirmation and audit entries.
- Large batch actions return promptly and produce traceable jobs.
- Native MACCMS admin video pages and collection remain operational.

## Phase 5 — Headless API v1 and member sessions

### Work packages

1. Add a versioned route group, JSON error envelope, request ID, pagination contract, and explicit DTO mappers.
2. Implement home, video list/detail/episodes, search, taxonomies, recommendations, rankings, people, and site-config endpoints.
3. Return only published content; support `zh-TW`, `zh-CN`, and `en` with configurable fallback order.
4. Add short-lived access tokens and rotating hashed refresh tokens with device sessions, revocation, replay detection, and login audit.
5. Keep frontend tokens isolated from administrator sessions and incrementally upgrade legacy password hashes after successful login.
6. Implement favorites, recent watching, source/episode/progress/duration, and timestamp-based anonymous-to-account reconciliation.
7. Validate playback URLs and return only enabled sources with optional short-lived signatures.
8. Add endpoint-specific CORS, rate limits, ETag/conditional requests, and precise invalidation on publication changes.
9. Publish OpenAPI 3 documentation and stable example payloads.

### Verification gate

- DTO tests prove no internal or unlisted field leaks.
- Token rotation rejects reuse and revocation closes all selected sessions.
- Locale fallback, pagination, cache validation, CORS, rate limits, and malicious URL fixtures pass.
- The API never returns unpublished, rejected, or noncanonical duplicate records as independent items.

## Phase 6 — Events, rankings, and rule-based recommendations

### Work packages

1. Accept play-start, valid-watch, progress, completion, and favorite events using member/session/device deduplication windows.
2. Aggregate today, 7-day, 30-day, and all-time rankings incrementally.
3. Implement deterministic recommendations using genre, region, tags, people, popularity, and freshness; exclude hidden, merged, rejected, and current content.
4. Add retention and purge policies for raw events and derived aggregates.
5. Add abuse limits and tests for duplicated, reordered, anonymous, and authenticated events.

### Verification gate

- Replayed events do not inflate counts.
- Ranking windows produce reproducible fixtures across date boundaries.
- Recommendations are explainable and never expose unavailable content.

## Phase 7 — Official communication removal and release hardening

### Work packages

1. Disable/remove remote version checks, update downloads/execution, official announcements, affiliate preload/buffer URLs, telemetry, and dead admin routes.
2. Centralize approved outbound destinations for collection, AI, TMDB, S3, SMTP, and SMS; all are off until explicitly configured where applicable.
3. Add SSRF, redirect, DNS rebinding, private/link-local/metadata IP, response-size, timeout, and secret-redaction tests.
4. Run SQL injection, stored/reflected XSS, token leakage, malicious playback URL, privilege, and audit-log tests.
5. Complete native collection, video management, membership, permissions, playback, installation, and upgrade regression.
6. Write installation, update, backup, migration, Cron, configuration, rollback, health-check, and disaster-recovery guides.
7. Produce a release checklist, acceptance report, known limitations, and `headless-ai-v1.0.0` tag only after all gates pass.

### Verification gate

- Network-capture tests show no prohibited MACCMS-official connection during install, login, dashboard, collection, video save, playback, or Cron.
- All automated suites pass on PHP 8.1 with MySQL 5.7 and 8.0.
- Backup restoration and application rollback are rehearsed in a disposable environment.
- OpenAPI, deployment documents, migration checksums, and acceptance evidence match the release commit.

## Commit sequence for this reset

1. `chore: establish pinned maccms upstream baseline`
2. `docs: add headless ai architecture specification`
3. `docs: add phased headless ai implementation plans`

Feature implementation begins only after this reset branch is reviewed. Each phase then uses test-first commits matching the work packages above; do not squash the entire programme into one commit.

## Completion definition

The programme is complete only when every phase gate is evidenced, the future Next.js client can operate exclusively through `/api/v1`, native MACCMS compatibility tests remain green, prohibited official communication is absent, and a documented backup/rollback rehearsal succeeds on the release candidate.
