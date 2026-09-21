# Content Automation and Admin Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make taxonomy, AI/TMDB automation, TMDB poster-to-S3 ingestion, duplicate merge/restore, native duplicate-name maintenance, and mixed-case public identities work visibly and safely through the MACCMS administrator UI.

**Architecture:** Extend the existing `vod_ext`, content-job, field-governance, taxonomy, TMDB, duplicate, upload, and audit services. Native administrator and collection writes enqueue idempotent background work; external calls stay outside write transactions; the video editor is the per-video control surface and Content Workspace remains the batch/review surface.

**Tech Stack:** PHP 8.1, ThinkPHP 5-era MACCMS, MySQL 5.7/8.0, Layui/jQuery administrator templates, existing content-job CLI/Cron, existing hardened HTTP and S3 upload drivers.

**Spec:** `docs/superpowers/specs/2026-09-21-content-automation-admin-repair-design.md`

## Global Constraints

- Preserve `mac_vod`, numeric `vod_id`, native `vod_play_*`, existing collection return values, and legacy APIs.
- Public IDs are immutable, exactly six case-sensitive characters matching `^[A-Za-z0-9]{6}$`.
- Existing valid uppercase public IDs remain valid.
- MySQL 5.7 and 8.0 must both pass.
- External AI, TMDB, image-download, and S3 work must not run inside native video-save transactions.
- Duplicate videos are never merged automatically.
- Manual field locks override AI, TMDB, and import values.
- Repair commands default to dry-run and require explicit confirmation for writes.
- Every product behavior change follows RED → GREEN → full-suite verification.
- Complete one task at a time; update `tasks.md` and `CURRENT_STATE.md`, commit, and push after each task.

## Review Focus

1. A taxonomy synonym shared by two active terms of the same kind must be rejected rather than matched arbitrarily; covered by T-112 synonym-collision regression.
2. Repeated native/collection saves with an unchanged fingerprint must retain exactly one queued workflow job; covered by T-113 idempotency integration.
3. A valid image whose server redirects to a private/reserved address must be rejected without changing either poster field; covered by T-114 outbound-policy regression.
4. A restore attempted after either merged projection changed must fail closed and leave both videos and the active snapshot untouched; covered by T-115 conflict integration.
5. Public IDs differing only by letter case must coexist and resolve to their exact videos without cache/route collision; covered by T-117 MySQL and API integration.

---

### Task 1 (T-112): Taxonomy dictionary domain service and migration guardrails

**Files:**
- Create: `application/common/util/TaxonomyDictionaryService.php`
- Create: `tests/regression/taxonomy_dictionary.php`
- Create: `tests/integration/taxonomy_dictionary_mysql.php`
- Modify: `application/common/model/MetaTerm.php`
- Modify: `tests/regression/run_foundation.php`
- Modify: `.github/workflows/mysql57-foundation.yml`
- Modify: `.github/workflows/mysql80-foundation.yml`

**Interfaces:**
- Consumes: `meta_term`, `vod_meta_term`, and `TaxonomySuggestionService::match()` normalization semantics.
- Produces: `TaxonomyDictionaryService::list(array $filters): array`, `save(array $input, int $actorId): array`, and `deactivate(int $termId, int $actorId): array`.

- [ ] **Step 1: Write the failing domain regression**

Create fixtures asserting:

```php
$service->save([
    'kind' => 'genre',
    'slug' => 'science-fiction',
    'name_tw' => '科幻',
    'name_cn' => '科幻',
    'name_en' => 'Science Fiction',
    'synonyms' => ['Sci-Fi', '科幻'],
    'status' => 1,
    'sort' => 10,
], 1);
```

The test must require kind/slug validation, normalized unique synonyms, rejection of a synonym already owned by another active term of the same kind, deactivation instead of deletion for referenced terms, and hard deletion only for unreferenced terms.

- [ ] **Step 2: Verify RED**

Run:

```bash
php tests/regression/taxonomy_dictionary.php
```

Expected: FAIL because `TaxonomyDictionaryService` does not exist.

- [ ] **Step 3: Implement the minimal dictionary service**

Use transactions for writes. Normalize synonyms with whitespace collapse and case-folding, compare slug/names/synonyms across active same-kind terms, persist canonical JSON arrays, and append safe audit summaries. Do not add new taxonomy tables.

- [ ] **Step 4: Add MySQL behavior coverage**

The integration test must create two terms, prove the unique kind/slug constraint, prove synonym collision refusal, attach one term to a video, and prove deactivation preserves `vod_meta_term`.

- [ ] **Step 5: Verify GREEN and suites**

Run:

```bash
php -l application/common/util/TaxonomyDictionaryService.php
php tests/regression/taxonomy_dictionary.php
php tests/regression/run_foundation.php
php tests/integration/taxonomy_dictionary_mysql.php
```

Expected: all PASS on MySQL 5.7 and 8.0 CI.

- [ ] **Step 6: Record and commit**

Update T-112 evidence in `tasks.md` and architecture facts in `CURRENT_STATE.md`.

```bash
git add application/common/util/TaxonomyDictionaryService.php application/common/model/MetaTerm.php tests/regression/taxonomy_dictionary.php tests/integration/taxonomy_dictionary_mysql.php tests/regression/run_foundation.php .github/workflows/mysql57-foundation.yml .github/workflows/mysql80-foundation.yml tasks.md CURRENT_STATE.md
git commit -m "feat: add managed taxonomy dictionary"
git push origin feature/headless-ai-v1
```

### Task 2 (T-112): Taxonomy administrator UI and video-editor visualization

**Files:**
- Create: `application/admin/controller/TaxonomyDictionary.php`
- Create: `application/admin/view_new/taxonomy_dictionary/index.html`
- Create: `application/admin/view_new/taxonomy_dictionary/info.html`
- Create: `application/common/util/VodTaxonomyPanel.php`
- Create: `tests/regression/taxonomy_admin_ui.php`
- Modify: `application/admin/controller/Vod.php`
- Modify: `application/admin/view_new/vod/info.html`
- Modify: `application/admin/controller/ContentWorkspace.php`
- Modify: `application/admin/view_new/content_workspace/review.html`
- Modify: `application/extra/quickmenu.php`
- Modify: `tests/regression/run_admin.php`

**Interfaces:**
- Consumes: Task 1 dictionary service and existing `TaxonomySuggestionService::review()`.
- Produces: `VodTaxonomyPanel::forVideo(int $vodId): array` and permission-protected dictionary/review endpoints.

- [ ] **Step 1: Write the failing UI contract**

Assert that the video form contains an “自動分類／詞典” panel under “多語言／進階資料”, renders accepted and pending region/genre/tag rows, displays source and match state, and submits CSRF-protected accept/reject/rerun actions without a manually typed `vod_id`.

Assert the dictionary templates expose kind, slug, three language names, synonyms, status, sort, filters, and conflict errors.

- [ ] **Step 2: Verify RED**

Run:

```bash
php tests/regression/taxonomy_admin_ui.php
```

Expected: FAIL on missing controller, panel service, and template markers.

- [ ] **Step 3: Implement the panel reader and controllers**

`VodTaxonomyPanel::forVideo()` returns:

```php
[
    'accepted' => ['region' => [], 'genre' => [], 'tag' => []],
    'pending' => [],
    'locks' => ['region' => false, 'genre' => false, 'tag' => false],
    'workflow' => [],
]
```

Controllers resolve the selected video on the server, enforce permission/CSRF, call existing review services, and return explicit JSON `code`/`msg`.

- [ ] **Step 4: Implement templates and feedback**

Add visible queued/success/error messages, links from missing suggestions into a prefilled dictionary form, and a dictionary quick-menu entry. Do not place database logic in templates.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
php -l application/admin/controller/TaxonomyDictionary.php
php -l application/common/util/VodTaxonomyPanel.php
php tests/regression/taxonomy_dictionary.php
php tests/regression/taxonomy_admin_ui.php
php tests/regression/run_admin.php
```

Expected: all PASS.

- [ ] **Step 6: Record and commit**

```bash
git add application/admin/controller/TaxonomyDictionary.php application/admin/view_new/taxonomy_dictionary application/common/util/VodTaxonomyPanel.php application/admin/controller/Vod.php application/admin/view_new/vod/info.html application/admin/controller/ContentWorkspace.php application/admin/view_new/content_workspace/review.html application/extra/quickmenu.php tests/regression/taxonomy_admin_ui.php tests/regression/run_admin.php tasks.md CURRENT_STATE.md
git commit -m "feat: expose taxonomy workflow in admin"
git push origin feature/headless-ai-v1
```

### Task 3 (T-113): Automatic workflow dispatch and per-video controls

**Files:**
- Create: `application/common/util/ContentWorkflowStatus.php`
- Create: `tests/regression/content_workflow_admin.php`
- Create: `tests/integration/content_workflow_dispatch_mysql.php`
- Modify: `application/common/util/VodExtensionService.php`
- Modify: `application/common/util/ContentWorkflowCoordinator.php`
- Modify: `application/common/model/Vod.php`
- Modify: `application/common/model/Collect.php`
- Modify: `application/command/MaccmsJobs.php`
- Modify: `application/admin/controller/Vod.php`
- Modify: `application/admin/view_new/vod/info.html`
- Modify: `application/admin/controller/ContentWorkspace.php`
- Modify: `application/admin/view_new/content_workspace/jobs.html`
- Modify: `tests/regression/run_ai_jobs.php`
- Modify: release/MySQL workflows that enumerate integration tests.

**Interfaces:**
- Consumes: `VodExtensionService::enqueueAiAfterWrite()`, `ContentWorkflowCoordinator`, `ContentJobRepository`, and `MaccmsJobs::handlerMap()`.
- Produces: `ContentWorkflowStatus::forVideo(int $vodId): array`, `rerunAi(int $vodId, int $actorId): array`, and `rerunTmdb(int $vodId, int $actorId): array`.

- [ ] **Step 1: Write failing integration scenarios**

Use real MySQL repositories to prove:

```php
$first = $vodModel->saveData($payload);
$second = $vodModel->saveData($payload + ['vod_id' => $first['vod_id']]);
assertSame(1, queuedAiJobsFor($first['vod_id']));
```

Also prove collection writes enqueue once, unchanged fingerprints do not duplicate jobs, no-duplicate AI completion enqueues `tmdb_review`, and all production handler aliases resolve.

- [ ] **Step 2: Verify RED**

Run the focused regression and integration test. Expected failure: existing UI/status and/or one native path lacks the required observable idempotent dispatch.

- [ ] **Step 3: Repair dispatch at the source**

Keep external processing asynchronous. Normalize job type names in the production handler map, preserve idempotency keys, and ensure both write paths call the same extension/workflow boundary only after native success.

- [ ] **Step 4: Add per-video status and actions**

Render stage, timestamps, latest job state, safe failure class, and links to relevant review records. Rerun actions receive the current video identity from the loaded editor record, not a free-form numeric input.

Batch workspace selection uses listed/search results and checkboxes. Keep numeric ID only in an explicitly labelled recovery/diagnostic section.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
php tests/regression/content_workflow_admin.php
php tests/regression/run_ai_jobs.php
php tests/integration/content_workflow_dispatch_mysql.php
php tests/integration/content_lifecycle_mysql.php
```

Expected: PASS on MySQL 5.7 and 8.0; exactly one job per idempotency key.

- [ ] **Step 6: Record and commit**

```bash
git add application/common/util/ContentWorkflowStatus.php application/common/util/VodExtensionService.php application/common/util/ContentWorkflowCoordinator.php application/common/model/Vod.php application/common/model/Collect.php application/command/MaccmsJobs.php application/admin/controller/Vod.php application/admin/view_new/vod/info.html application/admin/controller/ContentWorkspace.php application/admin/view_new/content_workspace/jobs.html tests/regression/content_workflow_admin.php tests/integration/content_workflow_dispatch_mysql.php tests/regression/run_ai_jobs.php .github/workflows tasks.md CURRENT_STATE.md
git commit -m "fix: automate visible content workflow"
git push origin feature/headless-ai-v1
```

### Task 4 (T-114): Hardened TMDB poster-to-S3 ingestion

**Files:**
- Create: `application/common/util/ExternalImageIngestionService.php`
- Create: `application/common/util/TmdbPosterJobHandler.php`
- Create: `tests/regression/external_image_ingestion.php`
- Create: `tests/integration/tmdb_poster_ingestion_mysql.php`
- Modify: `application/common/util/TmdbImportService.php`
- Modify: `application/common/util/TmdbReviewJobHandler.php`
- Modify: `application/command/MaccmsJobs.php`
- Modify: `application/common/extend/upload/S3.php`
- Modify: `tests/regression/tmdb_review_ui.php`
- Modify: `tests/regression/run_duplicate_tmdb.php`

**Interfaces:**
- Consumes: `HardenedHttpClient`, the configured MACCMS upload driver, `FieldGovernance`, and content jobs.
- Produces: `ExternalImageIngestionService::ingest(string $url, string $sourceRef): array{stored_url:string,local_path:string,mime:string,sha256:string}` and a `tmdb_poster_ingest` handler.

- [ ] **Step 1: Write failing service tests**

Fixtures must cover allowed JPEG/PNG/WebP, byte limit, invalid MIME, corrupt image bytes, redirect to private IP, upload-driver failure, local retention rules, random file naming, and redacted exceptions.

Assert failure leaves `vod_pic`, `old_poster_s3`, and `poster_s3` unchanged.

- [ ] **Step 2: Verify RED**

Run:

```bash
php tests/regression/external_image_ingestion.php
```

Expected: FAIL because the ingestion service/handler does not exist.

- [ ] **Step 3: Implement isolated ingestion**

Download through the hardened client, validate decoded image data, write under the configured upload root, pass the path through the configured upload driver, and return only verified normalized metadata. Make S3 failure explicit instead of treating the unchanged local input path as remote success.

- [ ] **Step 4: Wire reviewed TMDB application**

TMDB review enqueues poster ingestion after candidate selection. The poster handler preserves old values, applies the stored URL through field governance, honors locks, and records safe provenance/audit data.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
php -l application/common/util/ExternalImageIngestionService.php
php -l application/common/util/TmdbPosterJobHandler.php
php tests/regression/external_image_ingestion.php
php tests/regression/tmdb_review_ui.php
php tests/regression/run_duplicate_tmdb.php
php tests/integration/tmdb_poster_ingestion_mysql.php
```

Expected: all PASS; logs contain no credentials or raw signed URLs.

- [ ] **Step 6: Record and commit**

```bash
git add application/common/util/ExternalImageIngestionService.php application/common/util/TmdbPosterJobHandler.php application/common/util/TmdbImportService.php application/common/util/TmdbReviewJobHandler.php application/command/MaccmsJobs.php application/common/extend/upload/S3.php tests/regression/external_image_ingestion.php tests/integration/tmdb_poster_ingestion_mysql.php tests/regression/tmdb_review_ui.php tests/regression/run_duplicate_tmdb.php tasks.md CURRENT_STATE.md
git commit -m "feat: ingest TMDB posters through S3"
git push origin feature/headless-ai-v1
```

### Task 5 (T-115): Duplicate-state observability and action reliability

**Files:**
- Create: `application/common/util/DuplicateWorkflowStatus.php`
- Create: `tests/regression/duplicate_workflow_ui.php`
- Create: `tests/integration/duplicate_admin_actions_mysql.php`
- Modify: `application/common/util/DuplicateReviewWorkspace.php`
- Modify: `application/common/util/DuplicateCandidateDetector.php`
- Modify: `application/common/util/DuplicateMergeService.php`
- Modify: `application/common/util/DuplicateRestoreService.php`
- Modify: `application/admin/controller/ContentWorkspace.php`
- Modify: `application/admin/view_new/content_workspace/merge_restore.html`
- Modify: `application/admin/controller/Vod.php`
- Modify: `application/admin/view_new/vod/info.html`
- Modify: `tests/regression/run_duplicate_tmdb.php`

**Interfaces:**
- Consumes: existing candidate repository, version-2 snapshots, workflow coordinator, playback codec, CSRF/policy/audit services.
- Produces: `DuplicateWorkflowStatus::forVideo(int $vodId): array` with states `not_run|queued|running|no_candidates|pending|merged|restored|failed`.

- [ ] **Step 1: Write failing status/UI tests**

Assert every state has visible copy and an allowed next action. Assert AJAX handles non-2xx/network errors, `code !== 1`, CSRF rejection, permission denial, stale candidate, snapshot conflict, and success without silent failure.

- [ ] **Step 2: Write failing real-database lifecycle**

Create two videos with playback, locales, taxonomy, external maps, locks, and mixed public IDs. Detect/record a candidate, compare, merge, restore, and assert exact recovery. Then mutate a merged projection and assert restore refuses with no partial writes.

- [ ] **Step 3: Verify RED**

Run both focused tests. Expected failure must identify the missing status/action behavior rather than fixture setup.

- [ ] **Step 4: Implement status and reliable controller responses**

The controller returns `code: 1` only after commit, returns safe conflict/error messages otherwise, and never swallows exceptions into a blank response. The template disables impossible actions, shows progress/no-result states, and includes an error callback.

- [ ] **Step 5: Preserve merge/restore invariants**

Keep explicit confirmation, locks, immutable hash, playback codec, public-ID aliases, post-handoff snapshot refresh, audit, and conflict detection. Do not auto-merge.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
php tests/regression/duplicate_workflow_ui.php
php tests/regression/run_duplicate_tmdb.php
php tests/integration/duplicate_admin_actions_mysql.php
php tests/integration/content_lifecycle_mysql.php
```

Expected: PASS on MySQL 5.7 and 8.0.

- [ ] **Step 7: Record and commit**

```bash
git add application/common/util/DuplicateWorkflowStatus.php application/common/util/DuplicateReviewWorkspace.php application/common/util/DuplicateCandidateDetector.php application/common/util/DuplicateMergeService.php application/common/util/DuplicateRestoreService.php application/admin/controller/ContentWorkspace.php application/admin/view_new/content_workspace/merge_restore.html application/admin/controller/Vod.php application/admin/view_new/vod/info.html tests/regression/duplicate_workflow_ui.php tests/integration/duplicate_admin_actions_mysql.php tests/regression/run_duplicate_tmdb.php tasks.md CURRENT_STATE.md
git commit -m "fix: make duplicate merge and restore observable"
git push origin feature/headless-ai-v1
```

### Task 6 (T-116): Native duplicate-name cache repair

**Files:**
- Create: `application/common/util/VodRepeatRepairService.php`
- Create: `application/command/MaccmsRepairVodRepeat.php`
- Create: `application/data/migrations/20260921000100_vod_repeat_unique.sql`
- Create: `tests/regression/vod_repeat_repair.php`
- Create: `tests/integration/vod_repeat_repair_mysql.php`
- Modify: `application/common/model/Vod.php`
- Modify: `application/command.php`
- Modify: `tests/regression/migration_contract.php`
- Modify: `tests/regression/run_foundation.php`

**Interfaces:**
- Consumes: native `vod_repeat` semantics and configurable table prefix.
- Produces: `VodRepeatRepairService::refreshName(string $name): array`, `inspect(): array`, `rebuild(bool $confirmed): array`, and CLI `maccms:repair-vod-repeat --confirm`.

- [ ] **Step 1: Write failing regression**

Call per-name refresh twice and assert one cache row. Build multiple duplicate title groups plus recycled videos and assert the expected counts. Assert `rebuild(false)` reports changes but writes nothing.

- [ ] **Step 2: Verify RED**

Run:

```bash
php tests/regression/vod_repeat_repair.php
```

Expected: FAIL because repeated current maintenance duplicates rows and the repair service is absent.

- [ ] **Step 3: Add safe migration and service**

The migration cleans duplicate cache rows deterministically before adding a unique index on `name1`. The service validates the prefix, excludes recycled rows, refreshes one title idempotently, and performs a confirmed full truncate/rebuild transaction.

- [ ] **Step 4: Replace native maintenance calls**

Make `Vod::cacheRepeatWithName()` and `createRepeatCache()` delegate to the service while preserving their public signatures and cache timestamp behavior.

- [ ] **Step 5: Add CLI and verify GREEN**

Run:

```bash
php think maccms:repair-vod-repeat
php think maccms:repair-vod-repeat --confirm
php tests/regression/vod_repeat_repair.php
php tests/integration/vod_repeat_repair_mysql.php
php tests/regression/migration_contract.php
php tests/regression/run_foundation.php
```

Expected: dry run does not mutate; confirmed run produces one row per duplicate name on both MySQL families.

- [ ] **Step 6: Record and commit**

```bash
git add application/common/util/VodRepeatRepairService.php application/command/MaccmsRepairVodRepeat.php application/data/migrations/20260921000100_vod_repeat_unique.sql application/common/model/Vod.php application/command.php tests/regression/vod_repeat_repair.php tests/integration/vod_repeat_repair_mysql.php tests/regression/migration_contract.php tests/regression/run_foundation.php tasks.md CURRENT_STATE.md
git commit -m "fix: repair native duplicate-name cache"
git push origin feature/headless-ai-v1
```

### Task 7 (T-117): Mixed-case public identity foundation

**Files:**
- Create: `application/data/migrations/20260921000200_public_id_mixed_case.sql`
- Create: `application/common/util/PublicIdRepairService.php`
- Create: `application/command/MaccmsRepairPublicIds.php`
- Create: `tests/regression/public_id_mixed_case.php`
- Create: `tests/integration/public_id_mixed_case_mysql.php`
- Modify: `application/common/util/PublicIdGenerator.php`
- Modify: `application/common/model/VodExt.php`
- Modify: `application/command.php`
- Modify: `tests/regression/public_id_generator.php`
- Modify: `tests/regression/canonical_public_id.php`
- Modify: migration runners/workflows.

**Interfaces:**
- Consumes: `vod_ext.public_id`, canonical/alias traversal, migrations, and native extension creation.
- Produces: mixed-case `PublicIdGenerator::generate(callable $exists): string` and `PublicIdRepairService::repair(bool $confirmed): array`.

- [ ] **Step 1: Write failing generator and validator tests**

Require the exact alphabet `ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789`, exact regex `^[A-Za-z0-9]{6}$`, collision retry, invalid-character refusal, and preservation of a legacy uppercase ID.

- [ ] **Step 2: Write failing MySQL case test**

Insert `Ab12Cd` and `ab12cd` for different videos and assert both unique rows coexist and exact-case lookup returns the correct `vod_id`.

- [ ] **Step 3: Verify RED**

Run:

```bash
php tests/regression/public_id_mixed_case.php
php tests/integration/public_id_mixed_case_mysql.php
```

Expected: FAIL because the current generator/validators uppercase input and use a reduced uppercase alphabet.

- [ ] **Step 4: Implement binary storage and exact-case identity**

Keep or migrate the column to `CHAR(6) CHARACTER SET ascii COLLATE ascii_bin`. Remove all normalization that changes case. Use the exact regex at every trust boundary and retain secure `random_int` collision retries.

- [ ] **Step 5: Implement historical repair**

The dry run reports missing extension rows and invalid/missing IDs. Confirmed mode creates only missing/invalid identities and never regenerates an existing valid uppercase or mixed-case ID.

- [ ] **Step 6: Verify foundation GREEN**

Run:

```bash
php -l application/common/util/PublicIdGenerator.php
php -l application/common/util/PublicIdRepairService.php
php tests/regression/public_id_generator.php
php tests/regression/public_id_mixed_case.php
php tests/regression/canonical_public_id.php
php tests/integration/public_id_mixed_case_mysql.php
```

Expected: PASS on MySQL 5.7 and 8.0.

- [ ] **Step 7: Record and commit**

```bash
git add application/data/migrations/20260921000200_public_id_mixed_case.sql application/common/util/PublicIdGenerator.php application/common/model/VodExt.php application/common/util/PublicIdRepairService.php application/command/MaccmsRepairPublicIds.php application/command.php tests/regression/public_id_generator.php tests/regression/public_id_mixed_case.php tests/regression/canonical_public_id.php tests/integration/public_id_mixed_case_mysql.php tasks.md CURRENT_STATE.md
git commit -m "feat: support mixed-case public video IDs"
git push origin feature/headless-ai-v1
```

### Task 8 (T-117): Public-ID UI, search, API, route, and cache integration

**Files:**
- Create: `tests/regression/public_id_admin_api.php`
- Modify: `application/admin/controller/Vod.php`
- Modify: `application/admin/view_new/vod/index.html`
- Modify: `application/admin/view_new/vod/info.html`
- Modify: `application/common/util/ApiV1CatalogRepository.php`
- Modify: `application/common/util/ApiV1CanonicalResource.php`
- Modify: `application/api/controller/v1/Catalog.php`
- Modify: duplicate/TMDB/taxonomy workspace readers and templates.
- Modify: `tests/regression/api_v1_locale_canonical.php`
- Modify: `tests/regression/run_admin.php`
- Modify: `tests/regression/run_api_v1.php`

**Interfaces:**
- Consumes: Task 7 exact-case lookup and existing API v1 allowlist DTOs.
- Produces: exact-case administrator search, copy/display controls, and canonical API routing without lower/upper normalization.

- [ ] **Step 1: Write failing surface contract**

Assert video list/editor and every review workspace displays public ID. Assert administrator search passes the exact value, API lookup does not normalize case, canonical alias redirects preserve the canonical ID's stored case, and cache keys distinguish case-only IDs.

- [ ] **Step 2: Verify RED**

Run:

```bash
php tests/regression/public_id_admin_api.php
php tests/regression/api_v1_locale_canonical.php
```

Expected: FAIL on current normalization or missing UI/search surfaces.

- [ ] **Step 3: Implement exact-case surfaces**

Join/read `vod_ext` through focused services rather than exposing database rows directly. Add copy buttons and searchable public-ID fields. Preserve DTO allowlists and keep numeric IDs out of public responses.

- [ ] **Step 4: Verify GREEN**

Run:

```bash
php tests/regression/public_id_admin_api.php
php tests/regression/run_admin.php
php tests/regression/run_api_v1.php
php tests/integration/public_id_mixed_case_mysql.php
```

Expected: all PASS and case-only IDs remain isolated.

- [ ] **Step 5: Record and commit**

```bash
git add application/admin/controller/Vod.php application/admin/view_new/vod/index.html application/admin/view_new/vod/info.html application/common/util/ApiV1CatalogRepository.php application/common/util/ApiV1CanonicalResource.php application/api/controller/v1/Catalog.php application/admin/controller/ContentWorkspace.php application/admin/view_new/content_workspace tests/regression/public_id_admin_api.php tests/regression/api_v1_locale_canonical.php tests/regression/run_admin.php tests/regression/run_api_v1.php tasks.md CURRENT_STATE.md
git commit -m "feat: expose exact-case public IDs"
git push origin feature/headless-ai-v1
```

### Task 9: Full lifecycle, documentation, and release gates

**Files:**
- Modify: `tests/integration/content_lifecycle_mysql.php`
- Modify: `.github/workflows/php-regression.yml`
- Modify: `.github/workflows/mysql57-foundation.yml`
- Modify: `.github/workflows/mysql80-foundation.yml`
- Modify: `.github/workflows/release-regression.yml`
- Modify: `使用說明.md`
- Modify: `docs/deployment/content-workflow.md`
- Modify: `docs/deployment/operations-runbook.md`
- Modify: `docs/testing/native-smoke-checklist.md`
- Modify: `tasks.md`
- Modify: `CURRENT_STATE.md`

**Interfaces:**
- Consumes: all T-112 through T-117 behavior.
- Produces: one production-wired lifecycle gate and operator/browser acceptance instructions.

- [ ] **Step 1: Extend the lifecycle test before documentation changes**

The real MySQL lifecycle must create a native video, receive a mixed-case public ID, run production AI/TMDB handlers, stage/adopt taxonomy, show duplicate status, execute a reversible merge/restore fixture, ingest a poster through a fake transport plus real upload abstraction, and read the exact-case API DTO.

- [ ] **Step 2: Verify RED against any missing integration**

Run the lifecycle on both MySQL versions. Expected: a focused failure identifying any unwired boundary; do not weaken the test.

- [ ] **Step 3: Complete only the missing integration wiring**

Keep provider transports as fixtures, but use production repositories, coordinators, handler maps, governance, merge/restore, and API services.

- [ ] **Step 4: Update operator documentation**

Document:

- taxonomy dictionary and hybrid adoption;
- required Cron job command and visible queue states;
- automatic TMDB matching and reviewed application;
- S3 prerequisites and failure preservation;
- duplicate no-result/merge/restore/conflict states;
- native duplicate-cache dry-run/repair;
- mixed-case public-ID behavior and compatibility;
- manual browser cases for every administrator surface.

- [ ] **Step 5: Run exact final gates**

Run or trigger:

```bash
php tests/regression/run_baseline.php
php tests/regression/run_foundation.php
php tests/regression/run_ai_jobs.php
php tests/regression/run_duplicate_tmdb.php
php tests/regression/run_admin.php
php tests/regression/run_api_v1.php
php tests/integration/content_lifecycle_mysql.php
git diff --check
```

CI must pass PHP 8.1 regression, MySQL 5.7, MySQL 8.0, release matrix, and rollback rehearsal on the same candidate SHA.

- [ ] **Step 6: Record completion and commit**

Mark T-112 through T-117 DONE only with commit/run evidence. Keep final release blocked until the updated fresh browser checklist is completed.

```bash
git add tests/integration/content_lifecycle_mysql.php .github/workflows 使用說明.md docs/deployment/content-workflow.md docs/deployment/operations-runbook.md docs/testing/native-smoke-checklist.md tasks.md CURRENT_STATE.md
git commit -m "test: complete content automation repair gates"
git push origin feature/headless-ai-v1
```
