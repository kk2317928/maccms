# MACCMS Headless AI — Current State

Last updated: 2026-09-18  
State document version: 7  
Repository: `kk2317928/maccms`  
Integration branch: `feature/headless-ai-v1`  
Pinned upstream: `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`

## Session start summary

Read `AGENTS.md`, this file, and `tasks.md`. The repository has been reset to the pinned upstream and the design/implementation documents have been added. No Headless AI programme feature has been implemented yet.

**Last completed checkpoint:** CP-01 — capability reconciliation and regression baseline.  
**Active checkpoint:** CP-02 — foundation data and compatibility code.  
**Active task:** none; T-021 is the next `READY` implementation task.  
**Last completed task:** T-020 — migration ledger/CLI implementation commit `4099ea796a245f13aadd89cc39ce5bdb4c9c6577`, with source-inventory reconciliation at `d127bc853070f06ef92d8d642914d83b4882fbb9`.  
**Last verification:** GitHub Actions PHP 8.1 run `35342212142` and a fresh `git diff --check` passed.  
**Next action:** execute T-021 test-first and add the versioned foundation extension schema without creating a competing multilingual store.

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
| CLI | `application/command.php` | Currently registers only `SeoAiGenerate` |

Upstream Composer metadata declares PHP `>=7.0`; the new programme's primary supported runtime is PHP 8.1.

### Main application layers

| Layer | Location | Snapshot |
|---|---|---|
| Admin controllers | `application/admin/controller/` | 70 PHP controllers |
| Admin templates | `application/admin/view_new/` | Current admin UI templates; not `view/` |
| Legacy/broad API | `application/api/controller/` | 39 controllers |
| Public web | `application/index/controller/` | 24 controllers |
| Shared models | `application/common/model/` | 59 models |
| Shared services/utilities | `application/common/util/` | 74 utility/service classes after T-020 added `SchemaMigrationService` |
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
- The project does not yet have the planned single lossless playback codec.
- Future import, merge, and `/api/v1` output must share one codec rather than reimplement delimiter logic.

### Schema changes

- Existing installs/upgrades use `application/data/update/database.php` and installer SQL/config injection.
- The programme's versioned/checksummed migration runner does not exist yet.
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
| Fresh install MySQL 5.7 | UNVERIFIED | CP-02/CP-03 gate |
| Fresh install MySQL 8.0 | UNVERIFIED | CP-02/CP-03 gate |
| Native admin video write | UNVERIFIED | CP-01 baseline task |
| Native collection insert/update | UNVERIFIED | CP-01 baseline task |
| No prohibited outbound request | FAIL by static inspection | `static_new/js/admin_common.js` update check remains |

## 8. Open decisions

- Decide whether the legacy API remains indefinitely or receives a documented deprecation window after `/api/v1` is operational.
- Confirm the exact PHP compatibility floor for all new code after baseline syntax and dependency checks.
- Real MySQL verification requires explicitly named disposable databases and available test environments.

## 9. Resumption template

When pausing mid-task, replace this block with current facts:

```text
Task: <task id and title>
Starting commit: <sha>
Changed files: <paths>
Last command: <exact command>
Result: <pass/fail and concise evidence>
Next action: <one exact action>
Blocker: <none or explicit requirement>
```
