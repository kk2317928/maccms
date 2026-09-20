# MACCMS Headless AI Completion Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the existing Headless AI extension so a freshly installed MACCMS video can move safely from creation/import through AI, taxonomy, duplicate review, TMDB, manual review, publication and API delivery, with complete reversible merges.

**Architecture:** Preserve the current extension tables and services. Add one workflow coordinator, one taxonomy suggestion boundary and focused API/import services. Stage outputs first; only reviewed decisions mutate canonical taxonomy, merged relations or publication state. Every task is independently RED→GREEN, committed and pushed.

**Tech Stack:** PHP 8.1, ThinkPHP 5.x, MySQL 5.7/8.0, GitHub Actions, existing standalone PHP regression suites.

**Spec:** `docs/superpowers/specs/2026-09-20-headless-ai-completion-repair-design.md`

## Global constraints

- Preserve native MACCMS video, collection, member, playback and permission behavior.
- Never auto-merge duplicate videos.
- Never overwrite manually locked fields.
- Never create unreviewed taxonomy terms.
- Do not change checksums of applied migrations.
- Keep `headless-ai-v1.0.2` immutable.
- One task, one RED→GREEN cycle, one independently reviewable commit range.
- Update `tasks.md` and `CURRENT_STATE.md` with evidence in the same task commit.
- Push every completed task to `feature/headless-ai-v1`.

## File map

| Path | Responsibility |
|---|---|
| `application/common/util/ContentWorkflowCoordinator.php` | Legal stage transitions, timestamps and next-job enqueue |
| `application/common/util/TaxonomySuggestionService.php` | Existing-term matching and reviewed taxonomy adoption |
| `application/common/util/AiNormalizationPipeline.php` | Stage AI fields/taxonomy and notify coordinator |
| `application/common/util/AiNormalizationProvider.php` | Provider request and real/estimated usage normalization |
| `application/common/util/DuplicateCandidateDetector.php` | Bounded indexed candidate search and scan completion |
| `application/common/util/DuplicateMergeService.php` | Version-2 relational merge |
| `application/common/util/DuplicateRestoreService.php` | Version-1 compatibility and version-2 relational restoration |
| `application/common/util/TmdbReviewWorkspace.php` | TMDB completion and workflow handoff |
| `application/common/util/ContentImportService.php` | Validated idempotent video import |
| `application/common/util/ApiV1PeopleService.php` | Published people lookup |
| `application/common/util/ApiV1SiteConfig.php` | Public configuration allowlist |
| `application/api/controller/v1/Import.php` | Protected import endpoint |
| `application/api/controller/v1/People.php` | People endpoint |
| `application/api/controller/v1/SiteConfig.php` | Site-config endpoint |
| `application/command/MaccmsRepairContent.php` | Dry-run/apply repair command |
| `application/data/migrations/20260920000600_*.sql` and later | New ledgers/indexes only |
| `tests/regression/*` | Pure/source/API regression |
| `tests/integration/*` | MySQL lifecycle and merge/restore integration |

---

### Task 1: Add workflow coordinator and legal state persistence

**Files:**
- Create: `application/common/util/ContentWorkflowCoordinator.php`
- Create: `tests/regression/content_workflow_coordinator.php`
- Modify: `tests/regression/run_ai_jobs.php`
- Modify: `.github/workflows/php-regression.yml`
- Modify: `tasks.md`, `CURRENT_STATE.md`

**Produces:**
- `beginAi(int $vodId, string $fingerprint): array`
- `completeAi(int $vodId, int $runId, bool $hasDuplicates): array`
- `completeDuplicateReview(int $vodId): array`
- `completeTmdbReview(int $vodId, int $reviewId): array`
- `failStage(int $vodId, string $stage, string $safeClass): void`

**Step 1 — RED:** Add a memory-backed test covering every legal stage, timestamps, idempotent enqueue keys, duplicate waiting, failure and retry. Assert illegal skips and terminal-state mutation fail closed.

Run: `php tests/regression/content_workflow_coordinator.php`  
Expected: FAIL because the class is absent.

**Step 2 — GREEN:** Implement injected persistence/enqueue callables with production defaults using `Db`, `VodWorkflow` and `ContentJobRepository`. Lock `vod_ext` during transitions. Never enqueue inside a failed transaction.

**Step 3 — verify:** Run focused test, `run_ai_jobs.php`, PHP lint and `git diff --check`.

**Step 4 — commit:** `feat: coordinate content workflow stages`

---

### Task 2: Hook native creation and collection paths to idempotent AI enqueue

**Files:**
- Modify: `application/common/model/Vod.php`
- Modify: `application/common/model/Collect.php`
- Modify: `application/common/util/VodExtensionService.php`
- Create: `tests/regression/content_workflow_hooks.php`
- Modify: `tests/regression/run_foundation.php`
- Modify: `tests/integration/native_vod_paths.php`
- Modify ledgers

**Consumes:** Task 1 `beginAi`.

**Step 1 — RED:** Assert admin create, collection insert and content-affecting collection update call one shared enqueue boundary after successful native persistence. Playback-only/non-input changes must not enqueue. Test deterministic content fingerprint and repeated save idempotency.

Expected: new regression fails on missing hook.

**Step 2 — GREEN:** Add a focused helper invoked at the three existing persistence boundaries. Build fingerprints only from AI identity inputs. Preserve existing native return/failure semantics.

**Step 3 — verify:** Foundation suite plus native MySQL integration on both supported versions.

**Step 4 — commit:** `feat: enqueue AI work from native video writes`

---

### Task 3: Add reviewed taxonomy suggestion and adoption

**Files:**
- Create: `application/common/util/TaxonomySuggestionService.php`
- Add migration: `application/data/migrations/20260920000600_taxonomy_suggestions.sql`
- Modify: `application/common/util/AiNormalizationPipeline.php`
- Modify: `application/common/util/AiFieldReviewService.php`
- Modify: `application/common/util/TmdbImportService.php`
- Modify: `application/admin/view_new/content_workspace/review.html`
- Create: `tests/regression/taxonomy_suggestions.php`
- Create: `tests/integration/taxonomy_review_mysql.php`
- Modify runners/workflows/ledgers

**Produces:** existing-term candidates; reviewed `vod_meta_term`; native compatibility sync.

**Step 1 — RED:** Cover exact slug/name/synonym unique match, ambiguous match, missing match, disabled term, manual lock, reviewed replacement and deterministic native synchronization. Assert AI/TMDB cannot directly complete taxonomy by writing native strings.

**Step 2 — migration:** Add suggestion rows with source, kind, proposed value, matched term, decision, actor and timestamps. Verify migration discovery and both MySQL versions.

**Step 3 — GREEN:** Implement matching without term creation. Stage AI/TMDB suggestions. Extend field review with term decisions and call `replaceTerms` then `syncNativeTaxonomy`.

**Step 4 — verify:** AI, admin, foundation and MySQL suites.

**Step 5 — commit:** `feat: review and adopt canonical taxonomy terms`

---

### Task 4: Connect AI completion, duplicate review and TMDB handoff

**Files:**
- Modify: `application/common/util/AiNormalizationPipeline.php`
- Modify: `application/common/util/DuplicateCandidateDetector.php`
- Modify: `application/common/util/DuplicateCandidateDecisionService.php`
- Modify: `application/common/util/DuplicateMergeService.php`
- Modify: `application/common/util/TmdbReviewWorkspace.php`
- Modify: `application/common/util/TmdbReviewJobHandler.php`
- Create: `tests/regression/content_workflow_handoff.php`
- Modify runners/ledgers

**Consumes:** coordinator and taxonomy staging.

**Step 1 — RED:** Prove successful AI records timestamps and either waits on duplicates or enqueues one TMDB job; all-different and merge decisions unblock the primary; TMDB select/no-match enters manual review; repeated calls are idempotent.

**Step 2 — GREEN:** Invoke coordinator only after each stage transaction commits. A merged secondary never receives downstream jobs.

**Step 3 — verify:** AI, duplicate/TMDB, admin suites and transition integration.

**Step 4 — commit:** `feat: connect AI duplicate and TMDB workflow`

---

### Task 5: Implement complete version-2 merge and restoration

**Files:**
- Modify: `application/common/util/DuplicateMergeService.php`
- Modify: `application/common/util/DuplicateRestoreService.php`
- Modify: `application/common/util/DuplicateReviewWorkspace.php`
- Create: `tests/regression/duplicate_relational_merge.php`
- Create: `tests/integration/duplicate_restore_mysql.php`
- Modify duplicate runner/workflows/ledgers

**Step 1 — RED:** Fixture two videos with playback, old titles, `content_lang`, `vod_meta_term`, `ext_source_map`, field states and conflicting/non-conflicting data. Assert version-2 snapshot includes every changed relation, union/dedupe rules, canonical resolution and byte-equivalent restoration. Keep version-1 restore test green.

**Step 2 — GREEN merge:** Merge playback, aliases, missing language rows, taxonomy union and non-conflicting external maps inside one transaction. Preserve primary conflicts and record them in snapshot.

**Step 3 — GREEN restore:** Validate hash/current merged projection; replace both videos and all changed relations from the snapshot; reopen candidate and audit.

**Step 4 — verify:** Duplicate suite, MySQL 5.7/8.0 integration and rollback rehearsal.

**Step 5 — commit:** `feat: preserve related data in reversible duplicate merges`

---

### Task 6: Improve candidate search and configurable AI usage/budget

**Files:**
- Add migration: `application/data/migrations/20260920000700_duplicate_lookup_indexes.sql`
- Modify: `application/common/util/DuplicateCandidateDetector.php`
- Modify: `application/common/util/AiNormalizationProvider.php`
- Modify: `application/common/util/AiProvider.php`
- Modify: `application/admin/controller/System.php`
- Modify: `application/admin/view_new/system/configaicontent.html`
- Modify: `application/command/MaccmsJobs.php`
- Create: `tests/regression/ai_usage_and_duplicate_config.php`
- Modify runners/workflows/ledgers

**Step 1 — RED:** Assert candidate lookup is identity-filtered and configured, not a recent-row-only scan. Cover prompt version, retry count/delay, threshold/limit, provider usage parsing, fallback estimation, configurable token prices and budget rejection before request.

**Step 2 — GREEN:** Add safe defaults, validation and redacted persistence. Use query predicates/indexes for TMDB/title/year candidate narrowing with a bounded fallback.

**Step 3 — verify:** migration, AI, duplicate and security suites on both MySQL versions.

**Step 4 — commit:** `feat: configure AI usage and indexed duplicate lookup`

---

### Task 7: Complete intelligent-content admin operations

**Files:**
- Modify: `application/admin/view_new/content_workspace/index.html`
- Modify: `application/admin/view_new/content_workspace/jobs.html`
- Modify: `application/admin/controller/ContentWorkspace.php`
- Modify: `application/common/util/ContentJobAdminService.php`
- Modify: `application/common/util/ContentAdminPolicy.php`
- Create: `tests/regression/content_job_admin_controls.php`
- Modify admin runner/workflow/ledgers

**Step 1 — RED:** Assert visible AI review and pending-content links. Test pause, resume, skip-with-reason and retry for exact allowed states; permissions, CSRF, confirmation, immutable audit and secret redaction.

**Step 2 — GREEN:** Add transactional state changes and UI controls. Never interrupt running jobs; pause/skip only queued jobs. Resume preserves attempt history.

**Step 3 — verify:** admin suite and PHP lint.

**Step 4 — commit:** `feat: complete intelligent content administration`

---

### Task 8: Add protected idempotent video import API

**Files:**
- Add migration: `application/data/migrations/20260920000800_video_import_ledger.sql`
- Create: `application/common/util/ContentImportAuthenticator.php`
- Create: `application/common/util/ContentImportService.php`
- Create: `application/api/controller/v1/Import.php`
- Modify: `api.php` or existing API route registration
- Modify: `application/common/util/ApiV1EndpointPolicy.php`
- Modify: `docs/api/v1/openapi.json`
- Create: `tests/regression/api_v1_import.php`
- Create: `tests/integration/api_v1_import_mysql.php`
- Modify API runner/workflows/ledgers

**Step 1 — RED:** Cover independent credential, timestamp/replay protection, idempotency key, payload size, field allowlist, unsafe playback URLs, native write, extension/public ID, one AI job and replay returning the same result. Assert import cannot publish or merge.

**Step 2 — GREEN:** Implement HMAC/high-entropy bearer verification from environment configuration; persist request fingerprint/result ledger; delegate compatible native persistence and coordinator enqueue.

**Step 3 — OpenAPI/security:** Add exact schema, responses, rate limit and CORS behavior without exposing secrets.

**Step 4 — verify:** API, security and MySQL suites.

**Step 5 — commit:** `feat: add protected idempotent video import API`

---

### Task 9: Add people and public site-config APIs

**Files:**
- Create: `application/common/util/ApiV1PeopleService.php`
- Create: `application/common/util/ApiV1SiteConfig.php`
- Create: `application/api/controller/v1/People.php`
- Create: `application/api/controller/v1/SiteConfig.php`
- Modify route registration, endpoint policy and OpenAPI
- Create: `tests/regression/api_v1_people_site_config.php`
- Modify API runner/workflows/ledgers

**Step 1 — RED:** People returns only published related videos and stable non-incrementing slug; unknown person is 404. Site config returns only explicit public keys and never secrets.

**Step 2 — GREEN:** Reuse native actor/director data where safe; use deterministic public slug mapping without exposing IDs. Build a closed site-config DTO.

**Step 3 — verify:** API, OpenAPI, delivery/security suites.

**Step 4 — commit:** `feat: add people and public site configuration APIs`

---

### Task 10: Add safe repair command for existing installations

**Files:**
- Create: `application/common/util/ContentRepairService.php`
- Create: `application/command/MaccmsRepairContent.php`
- Modify: `application/command.php`
- Create: `tests/regression/content_repair_command.php`
- Create: `tests/integration/content_repair_mysql.php`
- Modify foundation runner/workflows/ledgers

**Step 1 — RED:** Dry-run cannot mutate. Apply requires explicit confirmation, repairs safe workflow states, stages uniquely matched taxonomy suggestions, enqueues missing work idempotently and never overrides manual locks.

**Step 2 — GREEN:** Implement bounded batches with counts and safe error summaries. Support repeated execution.

**Step 3 — verify:** focused, foundation, MySQL 5.7/8.0 and security suites.

**Step 4 — commit:** `feat: repair existing headless AI content state`

---

### Task 11: End-to-end lifecycle, documentation and release candidate

**Files:**
- Create: `tests/integration/content_lifecycle_mysql.php`
- Modify: release matrix workflows
- Modify: `使用說明.md`
- Modify: deployment/API/operations documentation
- Modify: `docs/testing/native-smoke-checklist.md`
- Modify: `tasks.md`, `CURRENT_STATE.md`

**Step 1 — RED integration:** One video traverses creation → one AI job → taxonomy suggestions → duplicate branch → TMDB → manual review → publication → public API. Separate fixture covers full merge and restore. Re-running produces no duplicate jobs/relations.

**Step 2 — GREEN fixes only:** Resolve integration failures without weakening earlier contracts.

**Step 3 — full automated verification:**
- PHP regression;
- MySQL 5.7/8.0 release matrix;
- native video matrix;
- API v1 suite;
- security/outbound suite;
- rollback rehearsal;
- package dependency/install contract.

**Step 4 — docs:** Document automatic workflow, taxonomy review, import authentication, people/site-config, repair command, merge restoration and troubleshooting.

**Step 5 — commit:** `test: verify complete headless AI lifecycle`

**Step 6 — release candidate:** After automated gates pass, create `headless-ai-v1.0.3-rc1` at the verified commit. Do not create final `headless-ai-v1.0.3` until the user completes and records the fresh Web/browser smoke checklist.

## Shared-interface pre-flight

| Producer | Consumer | Contract to verify before execution |
|---|---|---|
| Task 1 coordinator | Tasks 2, 4, 8, 10 | Idempotency keys and transition locking remain identical |
| Task 3 taxonomy suggestions | Tasks 4, 5, 10, 11 | Only reviewed term IDs become canonical relations |
| Task 4 workflow handoff | Tasks 7, 11 | Admin queue and final publication read the same states |
| Task 5 snapshot v2 | Tasks 10, 11 | Repair never rewrites active merge snapshots |
| Task 6 config | Tasks 4, 7 | Worker and admin use one normalized config source |
| Task 8 import | Task 11 | Import delegates to the same native persistence/coordinator boundary |
| Task 9 API DTOs | Task 11 | OpenAPI and runtime responses stay exact |

Spec rules over task wording if an implementation detail conflicts.

## Review focus

The final reviewer must explicitly check:

- transactional boundaries between state update and next-job enqueue;
- MySQL 5.7 compatibility and DDL implicit commits;
- taxonomy ambiguity and manual-lock bypass attempts;
- merge/restore relation conflicts and snapshot integrity;
- replay/idempotency races for import and job creation;
- API secrets and internal IDs;
- native collection/admin return-value compatibility;
- behavior for existing version-1 snapshots and old installed databases;
- whether the end-to-end test exercises production wiring rather than test-only callbacks.
