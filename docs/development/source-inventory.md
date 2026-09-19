# MACCMS Source Inventory Baseline

Baseline date: 2026-09-18  
Pinned upstream: `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`  
Inventory task: `T-010`

This document is a compact navigation baseline for implementation sessions. The executable contract is `tests/regression/source_inventory.php`; update this document and that test together only after reviewing an intentional source-layout change.

## Entrypoints

| Entrypoint | Module or role |
|---|---|
| `index.php` | Public `index` module |
| `api.php` | Existing broad `api` module |
| renamed `admin.php` | Administrator entrance; the literal filename is rejected |
| `install.php` | Installer and initial configuration |
| `application/command.php` | ThinkPHP CLI command registration |

## Layer counts

| Inventory key | PHP files |
|---|---:|
| `admin_controllers` | 71 |
| `api_controllers` | 39 |
| `index_controllers` | 24 |
| `common_models` | 68 |
| `common_utilities` | 112 |

Counting rule: direct `*.php` children only. Nested templates, validation classes, behaviors, extensions, vendor code, and framework code are intentionally excluded from these five figures.

## Active directories and conventions

- Administrator templates live in `application/admin/view_new`; there is no active `application/admin/view` tree.
- Shared models live in `application/common/model`.
- Focused services and utilities live in `application/common/util`.
- Existing public/API/admin request behavior is distributed across `application/common/behavior` and the three controller modules.
- The bundled framework is under `thinkphp`, while Composer packages use `vendor`.
- Runtime and upload directories are deployment data, not programme source boundaries.

## High-risk file sizes

The figures use newline counts equivalent to `wc -l`.

| File | Lines | Reason for caution |
|---|---:|---|
| `application/common/model/Collect.php` | 3192 | Independent collection insert/update/merge flows |
| `application/common/model/Vod.php` | 1025 | Core video query and persistence behavior |
| `application/common/util/OpenApiSpec.php` | 1158 | Large legacy API specification surface |
| `application/data/update/database.php` | 1520 | Monolithic historical schema/config upgrade path |

New behavior should be placed in focused services with minimal hooks into these files.

## Existing programme-adjacent components

- AI: `AiProvider`, `SeoAi`, `ContentAnnotator`, `ContentAiAnnotation`, `AnnotationAdopter`
- Metadata providers: `TmdbExternalSourceProvider`, `ImdbExternalSourceProvider`, `DoubanExternalSourceProvider`
- Multilingual content: `ContentLang` and overlay helpers
- API/authentication: the existing `application/api` module, `JwtService`, and `OpenApiSpec`
- Member activity: `Ulog` for favorites, history, and progress
- Operations: analytics, monitoring, administrator audit, security behaviors, S3 upload configuration, and AI cover support

Presence does not mean the component already satisfies the Headless AI design. Task T-012 must classify each area as reuse, extend, replace, or retire.

## CLI and regression baseline

- The only registered CLI command is `SeoAiGenerate`.
- Before T-010, the only file under the regression suite was `user_register_validate.php`.
- T-010 adds `source_inventory.php` and GitHub Actions PHP 8.1 execution because the current Work runtime has no PHP interpreter.
- T-011 will add the deterministic baseline runner; this document does not claim that runner already exists.

## Critical video boundaries

- Administrator/native video saves: `application/common/model/Vod.php::saveData()`
- Collection video writes: `application/common/model/Collect.php::vod_data()`
- Native playback compatibility fields: `vod_play_from`, `vod_play_url`, `vod_play_server`, `vod_play_note`
- Existing `task` and `task_log` tables: member reward tasks, not a content-processing queue

## Refresh procedure

Run:

```bash
php tests/regression/source_inventory.php
```

If it fails after an intentional source change:

1. review the affected architecture and direct callers;
2. update this document with the new verified values;
3. update the literal contract in `source_inventory.php`;
4. update `CURRENT_STATE.md` if the change affects navigation, risk, or architecture;
5. run the baseline suite;
6. commit and push the task immediately.

Do not regenerate figures blindly. An unexpected inventory change is a review signal.
