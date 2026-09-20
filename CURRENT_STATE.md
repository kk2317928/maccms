# MACCMS Headless AI — Current State

Last updated: 2026-09-20
State document version: 76
Repository: `kk2317928/maccms`  
Integration branch: `feature/headless-ai-v1`  
Pinned upstream: `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`

## Session start summary

Read `AGENTS.md`, this file, and `tasks.md`. The repository remains pinned to the approved upstream while the programme is implemented incrementally on the integration branch.

**Last completed checkpoint:** CP-08 — events, rankings and recommendations.
**Active checkpoint:** CP-09 — official communication removal and release hardening.
**Active task:** T-096 — release candidate acceptance and rollback rehearsal (`IN_PROGRESS`).
**Starting commit:** T-073 final implementation commit `326515e67b85ac6e7f4509eb9dd4c3e2f99a5cea`.
**Last completed task:** T-095 — deployment, migration, Cron, backup, rollback and disaster-recovery runbook; final implementation commit `e0f4d23f658c2158fce9373dad525357b2eb71c9`.
**Last verification:** PHP regression `35510417972` passed T-095.
**Next action:** add RC acceptance contract and execute application/database rollback rehearsal.

## 1. Confirmed product direction

- MACCMS remains the video CMS and compatibility backend.
- A future independent Next.js frontend consumes a versioned `/api/v1`.
- Phase 1 covers video only.
- Workflow is semi-automatic: import/collection → AI normalization → duplicate review → TMDB matching → manual review → publish.
- Runtime target is ordinary Linux/Nginx/PHP 8.1/MySQL 5.7 or 8.0.
- Background work uses MySQL plus short-lived Cron; Redis and a resident worker are not required.
- Official nonessential update, announcement, affiliate, and telemetry communication must be removed.

## 2. Repository shape verified from source

### Entrypoints and framework

| Area | Path | Verified role |
|---|---|---|
| Public site | `index.php` | Binds the `index` module and boots ThinkPHP |
| API | `api.php` | Binds the existing `api` module |
| Admin | renamed copy of `admin.php` | Boots admin entrance; literal `admin.php` is rejected |
| Installer | `install.php` | Installation entry and schema/bootstrap setup |
| Framework | `thinkphp/` | Bundled ThinkPHP 5-era framework |
| Shared config | `application/config.php`, `application/extra/maccms.php` | Framework and runtime product configuration |
| Routes | `application/route.php` | Existing public-site route map |
| CLI | `think`, `application/command.php` | Neutral `command` entrance; registers `SeoAiGenerate` and `MaccmsMigrate` |

Upstream Composer metadata declares PHP `>=7.0`; the new programme's primary supported runtime is PHP 8.1.

### Main application layers

| Layer | Location | Snapshot |
|---|---|---|
| Admin controllers | `application/admin/controller/` | 71 PHP controllers after T-061 added the intelligent-content workspace dashboard |
| Admin templates | `application/admin/view_new/` | Current admin UI templates; not `view/` |
| Legacy/broad API | `application/api/controller/` | 39 controllers |
| Public web | `application/index/controller/` | 24 controllers |
| Shared models | `application/common/model/` | 68 models after T-052 added immutable merge snapshots |
| Shared services/utilities | `application/common/util/` | 122 utility/service classes after T-073 added access-token, refresh-token and device-session services |
| Request behaviors | `application/common/behavior/` | Security, audit, monitoring, preview and initialization hooks |
| Upgrade schema | `application/data/update/database.php` | Large monolithic legacy upgrade script |
| Tests | `tests/regression/` | Only `user_register_validate.php` currently exists |

Counts describe the pinned source snapshot and are not architectural limits.

## 3. Critical native data flows

### Video writes

- Admin/native video content writes pass through `application/common/model/Vod.php::saveData()`.
- Collection writes do not share that boundary; they pass through `application/common/model/Collect.php::vod_data()`.
- `Collect.php` is approximately 3,182 lines and contains separate insert/update/merge branches.
- Any extension-row or workflow hook must cover both paths after their native success conditions without changing collection return semantics.

### Playback

- Native playback remains stored in `vod_play_from`, `vod_play_url`, `vod_play_server`, and `vod_play_note`.
- `VodPlaybackCodec` is the lossless native playback codec for parse/serialize/merge operations.
- Future import, merge, and `/api/v1` output must reuse it rather than reimplement delimiter logic.

### Schema changes

- Existing installs/upgrades use `application/data/update/database.php` and installer SQL/config injection.
- The programme has a versioned/checksummed migration runner exposed as `php think maccms:migrate`.
- Existing `task` and `task_log` tables are member reward/sign-in tasks. `ext_sync_job` and `ext_sync_log` schedule external-provider feeds. Neither is a general leased/idempotent content queue.

## 4. Existing capabilities that overlap the design

These are verified as present. T-012 decisions are recorded in `docs/development/capability-reconciliation.md`; test coverage still expands through later checkpoints.

| Planned area | Existing code/data | Current assessment |
|---|---|---|
| AI provider | `AiProvider`, `SeoAi`, `ContentAnnotator`, `addons/aicontent` | Extend; consolidate transport/security/budget behavior behind `AiProvider` |
| AI review | `ContentAiAnnotation`, `AnnotationAdopter`, admin `AiAnnotation` | Extend with immutable runs, field governance, provenance and locks |
| TMDB | `TmdbExternalSourceProvider` and external-source registry/sync classes | Extend; candidate scoring/manual review workflow remains incomplete for target design |
| Other metadata | IMDb and Douban providers | Optional clues; must not bypass source/provenance rules |
| Multilingual data | `ContentLang` and overlay helpers | Extend with target locale/fallback, indexed titles, provenance and locks |
| API | Broad `application/api` module | Extend by keeping legacy compatibility and adding a separate `/api/v1` DTO boundary |
| API docs | `OpenApiSpec` and admin API docs | Extend with a separate versioned v1 specification |
| Authentication | `JwtService`, API user login/logout | Replace the frontend token/session boundary; retain native user accounts |
| Favorites/history/progress | `Ulog` model and API controller | Extend with public-ID DTOs and anonymous reconciliation |
| Recommendations | `application/api/controller/Recommend.php`, profiles/quality tools | Extend with canonical availability filters and target signals |
| Analytics | analytics controllers, tables, aggregators | Extend with deduplicated playback events and ranking windows |
| Audit/security | admin audit, safety checks, CSRF/XSS/security behaviors | Extend; high-risk target actions still require explicit audit coverage |
| S3/media | S3 upload admin configuration, `vod_pic_original`, AI cover service | Extend; `old_poster_s3` and `poster_s3` target fields remain absent |
| Queue | reward task tables and provider-specific external sync jobs | Extend with separate leased/idempotent content-job tables |

## 5. Planned capabilities verified as absent

Source searches found no implementation of:

- `vod_ext`
- stable six-character `public_id`
- separate video `workflow_status`
- `merged_into_vod_id`
- reversible video merge snapshots
- target `title_tw`, `title_cn`, `title_en`, `original_title`, `old_titles`, `type2`
- target `poster_s3` and `old_poster_s3`
- dedicated content-job queue with atomic claims, leases, retry scheduling, and idempotency keys
- rotating hashed refresh-token device sessions
- canonical `/api/v1/videos/{public_id}` contract

Absence was established by repository text search against the pinned snapshot. It does not prove a runtime database has no manually added columns.

## 6. Known risks and technical debt

1. `static_new/js/admin_common.js` contains an obfuscated request to `//update.maccms.la/v10/...`; this conflicts with the confirmed outbound policy.
2. `application/admin/controller/Update.php`, add-on/template cloud services, resource hub, collection, upload, notification, and AI/provider clients all require outbound inventory and policy classification.
3. Existing API and OpenAPI surface is extensive but exposes numeric internal IDs and mixed response conventions; it must not be relabeled as `/api/v1` without DTO/security review.
4. Existing JWT has no durable rotating refresh-session model.
5. Existing schema evolution is large and imperative; adding another parallel upgrade path without a ledger would create drift.
6. Regression coverage is extremely thin relative to the codebase size.
7. `Vod.php`, `Collect.php`, `OpenApiSpec.php`, and the upgrade script are large files; changes need focused services and structural regression tests.
8. PHP minimums differ between entry files, Composer metadata, upstream compatibility, and the new target. Deployment checks must enforce the supported environment explicitly.

## 7. Verification status

| Check | Status | Evidence |
|---|---|---|
| Remote `main` and `feature/headless-ai-v1` aligned after reset | PASS at prior checkpoint | Both were `7059ec9a7846a9ca5df36d8bfebd0729d6730904` |
| Repository tree imported | PASS at prior checkpoint | 3,112 entries, non-truncated Git tree |
| Design and two implementation-plan documents present | PASS at prior checkpoint | Remote tree inspection |
| Baseline PHP syntax | PASS for regression scripts | PHP 8.1 run `35339024233` |
| Baseline regression runner | PASS | PHP 8.1 run `35339024233`; deterministic order and first-failure propagation contract passed |
| Source inventory regression | PASS | PHP 8.1 run `35339024233` |
| Existing registration regression | PASS | PHP 8.1 run `35339024233` |
| Stable public ID foundation | PASS | PHP 8.1 run `35344501044`; generator and canonical traversal contracts passed |
| Canonical public-ID resolution | PASS | PHP 8.1 run `35386811289`; alias redirects remain public-ID-only |
| Conflict-aware duplicate restoration | PASS | PHP 8.1 run `35387273739`; authorization, confirmation, integrity, conflict, rollback and audit contracts passed |
| Deterministic TMDB matching | PASS | PHP 8.1 run `35387700115`; ordered search, scoring, manual-ID, no-match and review-only preselection contracts passed |
| Reviewed TMDB field import | PASS | PHP 8.1 run `35388048661`; preview, provenance, missing-locale and manual-lock contracts passed |
| CP-05 duplicate/TMDB lifecycle suite | PASS | PHP 8.1 run `35388283844`; deterministic fail-closed suite passed all eight lifecycle contracts |
| Granular admin permissions and immutable audit | PASS | PHP `35391110548`, MySQL 5.7 `35391110547`, MySQL 8.0 `35391110450`, native video `35390914302` |
| AI field review UI and conflict controls | PASS | PHP `35431481425`, MySQL 5.7 `35431315407`, MySQL 8.0 `35431315406`, native video `35431205533` |
| TMDB candidate and manual-match UI | PASS | PHP `35443519455`, MySQL 5.7 `35443519424`, MySQL 8.0 `35443519411`; candidate-bound preview, exact permissions, locking, Cron handlers and revision storage passed |
| Final validation and publication UI | PASS | PHP `35454020791`, MySQL 5.7 `35454020803`, MySQL 8.0 `35454020808`, native video `35453927974`; locked revision validation, atomic audit/state activation, InnoDB conversion, playback-source checks, cache and search synchronization passed |
| Batch enqueue and terminal-failure retry UI | PASS | PHP `35455134189`; explicit ID cap, exact permissions, CSRF/confirmation, idempotency, redaction, immutable audit, pagination and monotonic attempt history passed |
| CP-06 permission and native compatibility gate | PASS | PHP `35457611728`, MySQL 5.7 `35457611738`, MySQL 8.0 `35457611727`, native video `35457611759`; exact route/action grants and native admin/collection/playback paths passed |
| API v1 route/error/pagination/DTO foundation | PASS | PHP `35462362148`, MySQL 5.7 `35462362150`, MySQL 8.0 `35462362152`, native video `35462362293`; clean dispatch, stable envelopes, strict page pagination, request-ID and DTO allowlist contracts passed |
| API v1 public catalog | PASS | PHP `35465825379`, MySQL 5.7 `35465825407`, MySQL 8.0 `35465825384`, native video `35465825364`; published-only queries, exact routes, DTO allowlists, search/filter validation and playback-URL non-disclosure passed |
| API v1 locale and canonical redirects | PASS | PHP `35466523627`, MySQL 5.7 `35466454603`, MySQL 8.0 `35466454584`, native video `35466454598`; strict locale selection, quality weights, deterministic fallback, published canonical target checks and safe 308 redirects passed |
| API v1 member activity | PASS | PHP `35498624705`; public-ID favorites/history/progress, deterministic anonymous merge, MyISAM serialization and entitlement isolation passed |
| API v1 playback delivery | PASS | PHP `35499394152`; published canonical lookup, enabled-source/HTTPS host policy, private-address rejection, configured-TTL HMAC signing, trusted signer contract and private no-store responses passed |
| API v1 delivery policy | PASS | PHP `35500353097`; exact HTTPS CORS, route-specific atomic limits, normalized-path enforcement, conditional CORS, weak ETag/304 validation and no-store boundaries passed |
| API v1 OpenAPI contract | PASS | PHP `35500967514`; exact route/method/security coverage, typed DTO envelopes, stable examples, raw OpenAPI JSON delivery and 304/308 contracts passed |
| API v1 access and refresh sessions | PASS | PHP `35468598811`, MySQL 5.7 `35468598872`, MySQL 8.0 `35468598851`, native video `35468598836`; short-lived access tokens, hashed rotating refresh sessions, replay-family revocation, device revocation, legacy-session isolation and no-store responses passed |
| Video workflow state machine | PASS | PHP 8.1 run `35344911819`; exhaustive transition matrix passed |
| Lossless native playback codec | PASS | PHP 8.1 run `35345369100`; record-form, validation, round-trip and merge contracts passed |
| Foundation migration MySQL 5.7 | PASS | Disposable `maccms_ci_57`, MySQL 5.7.44; Actions run `35348332012` |
| Foundation migration MySQL 8.0 | PASS | Disposable `maccms_ci_80`, MySQL 8.0.46; Actions run `35348806475` |
| Native install schema + admin persistence boundary | PASS (automated) | `Vod::saveData()` on `maccms_ci_native`; run `35349617886`; browser form remains `NOT_RUN` |
| Native collection insert/update | PASS (automated) | `Collect::vod_data()` insert/update on `maccms_ci_native`; run `35349617886` |
| Native playback storage round trip | PASS (automated) | Both created rows round-tripped all four `vod_play_*` fields byte-for-byte; run `35349617886` |
| Fresh web-installer/manual smoke | UNVERIFIED | `docs/testing/native-smoke-checklist.md` remains `NOT_RUN` |
| No prohibited outbound request | FAIL by static inspection | `static_new/js/admin_common.js` update check remains |

## 8. Open decisions

- Decide whether the legacy API remains indefinitely or receives a documented deprecation window after `/api/v1` is operational.
- Confirm the exact PHP compatibility floor for all new code after baseline syntax and dependency checks.
- Real MySQL verification requires explicitly named disposable databases and available test environments.

## 9. Resumption template

```text
Task: T-096 Release candidate acceptance and rollback rehearsal
Starting commit: e0f4d23f658c2158fce9373dad525357b2eb71c9
Changed files: pending RC acceptance contract/report/workflow
Last command: PHP regression run 35510417972
Result: T-095 complete; deployment and recovery runbook contract passed
Next action: create failing RC acceptance contract, run database restore and application rollback rehearsal
Blocker: none
```
