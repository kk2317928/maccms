# MACCMS Content Automation and Admin Repair Design

Date: 2026-09-21  
Repository: `kk2317928/maccms`  
Integration branch: `feature/headless-ai-v1`  
Scope: T-112 through T-117

## 1. Purpose

Complete the administrator-facing and automatic wiring of the existing Headless AI content pipeline without replacing MACCMS native video storage or breaking prior secondary development.

A successful implementation lets an administrator create or collect a video and then observe, control, and audit automatic AI normalization, taxonomy classification, duplicate detection, TMDB matching, poster ingestion, merge, restore, and publication. Routine processing must not require manually entering a numeric `vod_id`.

The native numeric `vod_id` remains the internal relational identifier. Every video also receives an immutable, case-sensitive, six-character public identifier made from uppercase letters, lowercase letters, and digits.

## 2. Confirmed findings

The repository already contains:

- `vod_ext`, `meta_term`, `vod_meta_term`, taxonomy suggestions, field governance, content jobs, AI and TMDB workers;
- a six-character `public_id`, currently generated from an uppercase-only reduced alphabet;
- administrator workspaces for AI review, TMDB review, duplicate comparison, merge, restore, and publication;
- native S3 upload support;
- workflow hooks on native administrator saves and collection writes.

The missing or broken integration is:

1. Taxonomy terms have no complete administrator dictionary UI.
2. Taxonomy status and actions are not fully visible in the video editor's “多語言／進階資料” tab.
3. AI/TMDB operations are exposed as workspace/manual operations even though a queue pipeline exists.
4. TMDB poster URLs are not passed through a safe native media-to-S3 ingestion service.
5. Duplicate comparison depends on candidates created by earlier worker stages, so missing/stalled jobs leave an apparently inert page.
6. Native `vod_repeat` maintenance can insert the whole duplicate set repeatedly and has no uniqueness guarantee.
7. `public_id` is not sufficiently visible/searchable and its current alphabet does not meet the approved mixed-case requirement.

## 3. Architectural decision

Extend the existing pipeline rather than create parallel AI, taxonomy, TMDB, duplicate, media, or identity systems.

The write path remains:

`Vod::saveData()` or `Collect::vod_data()`
→ ensure `vod_ext`
→ enqueue idempotent workflow job
→ short-lived Cron worker
→ AI normalization and taxonomy staging
→ duplicate detection/review
→ TMDB matching/review
→ media ingestion
→ manual publication.

The video editor becomes the primary per-video control surface. The content workspace remains the cross-video queue and batch surface.

No external request may execute inside the database transaction that saves a native video. All AI, TMDB, poster download, and S3 work runs through leased content jobs.

## 4. T-112 — Taxonomy dictionary and video-editor integration

### 4.1 Dictionary management

Add an administrator page for `meta_term` with permission and CSRF enforcement.

The page supports:

- kinds: `region`, `genre`, and `tag`;
- slug;
- Traditional Chinese, Simplified Chinese, and English names;
- synonyms;
- active/inactive status;
- sort order;
- create, edit, deactivate, search, and filter.

Deletion is not the normal operation. Terms already referenced by videos are deactivated so historical relations remain resolvable. A hard delete may only be allowed for an unreferenced term.

Slug uniqueness is scoped by kind. Synonyms are normalized, deduplicated, and stored as JSON. A synonym may not ambiguously resolve to multiple active terms of the same kind; conflicts are displayed to the administrator and rejected on save.

### 4.2 Video editor

Inside “多語言／進階資料”, add an “自動分類／詞典” section showing:

- currently accepted regions, genres, and tags;
- pending suggestions grouped by source;
- proposed value, matched dictionary term, match state, source, and confidence/evidence where available;
- accept, reject, and rerun controls;
- links to create or edit a missing dictionary term;
- manual taxonomy lock state.

Actions use public identity in the UI while resolving to `vod_id` server-side. Existing field locks take precedence.

### 4.3 Safe automatic adoption

Classification uses a hybrid policy:

- a unique active dictionary match with a configured high-confidence result may be automatically accepted;
- missing, ambiguous, low-confidence, or conflicting values remain pending;
- automatic processing never silently creates dictionary terms;
- manual locks prevent AI, TMDB, and import sources from changing accepted taxonomy.

Every automatic or manual decision creates audit evidence.

## 5. T-113 — Automatic AI and TMDB workflow

### 5.1 Automatic entry

A successful new or materially changed native administrator save or collection write must:

1. ensure the extension row and public identity;
2. calculate the existing content fingerprint;
3. enqueue one idempotent AI normalization job;
4. expose the job and workflow state immediately in the video editor.

Repeated saves with the same fingerprint do not create duplicate jobs.

### 5.2 Stage progression

The worker progresses through the existing state machine:

- `imported`;
- `ai_processing`;
- `duplicate_review` when candidates exist;
- otherwise `tmdb_matching`;
- `manual_review`;
- `published`, `failed`, `rejected`, or `merged`.

No duplicate candidate may be merged automatically. If no candidate crosses the configured threshold, the pipeline proceeds to TMDB automatically.

TMDB search runs automatically after duplicate clearance. A deterministic high-confidence candidate may be preselected, but field application remains governed by the existing reviewed-field and lock rules. Ambiguous and no-match cases remain visible in the review queue.

### 5.3 Administrator controls

The video editor provides:

- current stage and timestamps;
- worker/job state and safe failure class;
- “立即執行 AI／分類”;
- “重新執行 TMDB 配對”;
- link to the relevant review item;
- retry for retryable failures.

The workspace retains batch controls, but administrators select videos through search and checkboxes. Manual numeric `vod_id` entry is removed from the normal workflow and retained only as an explicitly labelled diagnostic fallback if required for recovery.

Cron remains the production execution model. The UI never pretends a queued job has completed.

## 6. T-114 — TMDB poster ingestion through native S3

Create one media-ingestion service used by TMDB import and reusable by future external sources.

The service:

1. accepts a validated provider URL and source reference;
2. fetches it with the hardened outbound HTTP policy;
3. enforces scheme, DNS/IP, redirects, timeout, byte limit, MIME allowlist, and image decoding;
4. writes a randomized temporary/local upload path;
5. invokes the configured MACCMS upload driver, including S3;
6. confirms that the returned location differs from the local source when remote storage is required;
7. returns a normalized stored URL plus provenance;
8. cleans temporary files without deleting a successful local-storage result.

On reviewed TMDB application:

- preserve the current poster in `old_poster`;
- preserve the current stored poster in `old_poster_s3`;
- write the newly stored URL to `poster_s3`;
- update native `vod_pic` only according to the reviewed field selection and field-lock policy;
- never replace a working poster when download, validation, or upload fails.

No access keys, authorization headers, signed URLs, or raw provider responses may be logged.

## 7. T-115 — Duplicate comparison, merge, and restore repair

### 7.1 Observability

The duplicate screen must distinguish:

- detection not run;
- detection queued/running;
- detection completed with no candidates;
- candidates awaiting review;
- merged with active snapshot;
- restored;
- failed with a safe error and retry action.

The video editor links directly to its candidate or no-candidate state. The workspace supports search by public ID, title, and numeric ID.

### 7.2 Actions

Comparison shows native fields, multilingual fields, taxonomy, playback, external mappings, posters, workflow state, and public IDs.

Merge remains explicit and confirmed. It must:

- lock both videos and the candidate;
- retain the selected primary video;
- preserve both immutable public IDs, with the secondary resolving as an alias to the primary;
- merge the approved projections without corrupting native playback delimiters;
- create and integrity-hash a versioned snapshot;
- mark the secondary as merged;
- refresh the snapshot projection after workflow handoff;
- write an immutable audit event.

Restore must:

- require permission and confirmation;
- verify snapshot hash and active status;
- detect conflicting changes rather than overwrite them;
- restore both videos, language data, taxonomy, playback, field states, and external mappings;
- reactivate the secondary public identity;
- update the snapshot status and audit record.

Every AJAX action returns a clear success or error message. CSRF, authorization, invalid state, and conflict responses must not appear as a silent button.

## 8. T-116 — Native duplicate-name data repair

Correct `vod_repeat` maintenance without deleting videos.

The repair includes:

- a unique key on the normalized duplicate-name cache entry;
- idempotent per-name refresh that deletes/rebuilds only the affected name;
- full rebuild that truncates once and inserts each duplicate title once;
- exclusion of recycled videos;
- a safe CLI repair command with dry-run summary and explicit confirmation for writes;
- verification counts before and after repair.

The native `mac_vod` rows are not deduplicated automatically. The cache reports duplicate names; actual merge decisions remain in the reviewed duplicate workflow.

MySQL 5.7 and 8.0 must both be supported, with configurable table prefixes.

## 9. T-117 — Mixed-case six-character public identity

### 9.1 Format

A public identifier is exactly six characters from:

`ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789`

Examples: `aB3xY9`, `Q7m2Za`.

The identifier is case-sensitive. `Ab12Cd` and `ab12cd` are different identifiers.

### 9.2 Storage and uniqueness

`vod_ext.public_id` remains immutable and unique. Its database column and unique index must use an ASCII binary/case-sensitive collation.

Generation uses cryptographically secure randomness and retries on collision. Validation must use exactly `^[A-Za-z0-9]{6}$`.

Existing valid uppercase identifiers remain valid and are not regenerated. A migration/repair command fills missing extension rows or invalid/missing identities for historical videos without changing established valid IDs.

### 9.3 Surfaces

Display and support copy/search of the public ID in:

- video list;
- video editor;
- AI/TMDB/taxonomy review;
- duplicate comparison, merge, and restore;
- public API DTOs and canonical routes;
- repair/import reporting.

All layers preserve original case. No route, controller, cache key, lookup, redirect, or JavaScript handler may uppercase or lowercase the identifier.

Numeric `vod_id` remains available internally and in explicitly diagnostic administrator output.

## 10. Permissions and audit

New administrator capabilities use granular permissions for:

- taxonomy dictionary management;
- classification review/rerun;
- AI rerun;
- TMDB rerun/application;
- poster ingestion retry;
- duplicate merge;
- duplicate restore;
- identity and duplicate-cache repair.

Super administrator compatibility remains, but it does not bypass CSRF, confirmation, snapshot integrity, or field locks.

Audit events contain actor, target public ID, safe before/after summaries, source reference, and action result. They exclude secrets and large raw payloads.

## 11. Failure handling

- Queue operations are idempotent and retry only classified transient failures.
- Permanent validation or policy failures enter a visible failed state.
- External failure never rolls back a successful native video save.
- S3 failure preserves the prior poster.
- Dictionary ambiguity produces a pending review, not an arbitrary match.
- Merge conflicts fail closed and preserve the snapshot.
- Repair commands default to dry run.
- UI actions always show queued, succeeded, failed, or conflicted state.

## 12. Migration and compatibility

All schema changes use ordered, checksummed programme migrations and support MySQL 5.7 and 8.0.

The implementation must not:

- rename or repurpose `mac_vod.vod_id`;
- replace native `vod_play_*` storage;
- reuse member reward task tables;
- remove legacy API behavior;
- regenerate valid existing public IDs;
- automatically merge duplicate videos;
- silently overwrite manually locked fields.

Existing installations receive idempotent backfills for public identities and the duplicate-name cache. Fresh installation receives the same final schema through the normal installer/migration path.

## 13. Verification strategy

Each task follows test-first development with a focused regression test that fails before production changes.

Required automated coverage includes:

- dictionary CRUD, synonym collision, inactive terms, and taxonomy locks;
- native admin and collection writes enqueue exactly one workflow;
- automatic no-duplicate handoff into TMDB;
- visible queued/running/failed state without numeric-ID entry;
- poster download validation, S3 success, failure preservation, and secret redaction;
- duplicate no-result, comparison, merge, immediate restore, conflict refusal, and audit;
- repeated native duplicate-name refresh does not duplicate cache rows;
- dry-run and confirmed duplicate-cache rebuild;
- mixed-case generation, collision retry, binary uniqueness, exact-case lookup, routes, cache keys, aliases, and legacy uppercase compatibility;
- MySQL 5.7 and 8.0 lifecycle integration;
- complete PHP regression, release matrix, and rollback rehearsal.

Manual browser acceptance covers the video editor tab, dictionary page, action feedback, S3 configuration, comparison layout, merge/restore confirmation, and case-sensitive public-ID navigation.

## 14. Delivery sequence

- T-112: taxonomy dictionary and video-editor visualization.
- T-113: automatic AI/TMDB workflow and per-video controls.
- T-114: hardened TMDB poster-to-S3 ingestion.
- T-115: duplicate comparison/merge/restore repair and observability.
- T-116: native duplicate-name cache repair.
- T-117: mixed-case public identity migration and UI/API integration.
- Final integration: full lifecycle, documentation, package, and release-candidate verification.

Each task is independently tested, committed, pushed, and recorded in `tasks.md` and `CURRENT_STATE.md`.
