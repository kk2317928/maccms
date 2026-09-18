# MACCMS Development Agent Guide

This file is the mandatory entry point for every implementation session in this repository. It applies to the entire repository unless a deeper `AGENTS.md` explicitly narrows a rule.

## 1. Required reading order

Before inspecting or changing implementation code, read these files in order:

1. `AGENTS.md`
2. `CURRENT_STATE.md`
3. `tasks.md`
4. The design document named by the active task
5. The phase plan named by the active task
6. Only the source files listed in that task, plus their direct dependencies

Do not rescan the whole repository unless `CURRENT_STATE.md` is missing, stale, internally inconsistent, or the active task discovers an undocumented dependency.

## 2. Source of truth

- Product architecture: `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`
- Programme sequence: `docs/superpowers/plans/2026-09-18-maccms-headless-ai-master-plan.md`
- Foundation details: `docs/superpowers/plans/2026-09-18-maccms-foundation-data.md`
- Current verified facts: `CURRENT_STATE.md`
- Execution queue and checkpoint status: `tasks.md`

When these disagree, stop implementation. Record the conflict in `CURRENT_STATE.md` under **Open decisions**, resolve it against actual code and the design, then update all affected documents in the same commit.

## 3. Working branch and commit policy

- Integration branch: `feature/headless-ai-v1`.
- Start each task from the latest remote integration branch.
- Exactly one task may be `IN_PROGRESS` in `tasks.md`.
- Complete one independently testable task at a time.
- After every completed task or feature:
  1. run its listed verification commands;
  2. update `tasks.md` status and evidence;
  3. update `CURRENT_STATE.md` if architecture, schema, interfaces, risks, or the next checkpoint changed;
  4. commit immediately;
  5. push immediately to the repository.
- Do not accumulate several completed tasks in an uncommitted batch.
- Use focused conventional commits such as `test:`, `feat:`, `fix:`, `refactor:`, `docs:`, or `chore:`.
- Never claim a task is complete if its commit is only local or its verification evidence is absent.

## 4. Checkpoint protocol

A checkpoint is a resumable boundary, not a substitute for task commits.

At checkpoint start:

- confirm every prerequisite task is `DONE`;
- record the active task ID and starting commit in `CURRENT_STATE.md`;
- confirm the working tree is clean;
- run the checkpoint baseline command.

At checkpoint completion:

- run every checkpoint gate in `tasks.md`;
- record command, result, environment, and commit SHA;
- update **Last completed checkpoint** and **Next checkpoint** in `CURRENT_STATE.md`;
- commit and push the checkpoint documentation update if it was not already included in the final task commit;
- do not start the next checkpoint when any gate is unverified or failing.

If interrupted, leave the current task as `IN_PROGRESS` and write a short resumption note containing changed files, last command, result, and exact next action.

## 5. Architecture boundaries

- `mac_vod` and native `vod_play_*` remain the MACCMS compatibility source of truth.
- Native collection and admin saves have separate persistence paths: `Vod::saveData()` and `Collect::vod_data()`. Never assume one automatically covers the other.
- New video workflow state must remain separate from native `vod_status`.
- Public interfaces use a stable six-character `public_id`; internal relations continue using `vod_id`.
- Long-running external work uses a dedicated content-job queue and short-lived CLI/Cron execution. Existing `task` and `task_log` tables are member reward tasks and must not be repurposed.
- Existing AI, TMDB, multilingual, API, authentication, audit, monitoring, and analytics code must be evaluated for reuse before adding a parallel service.
- Future `/api/v1` DTOs must be explicit allowlists. Never serialize database rows directly.
- Duplicate candidates are never merged automatically. Merge must preserve a snapshot and support authorized restoration.
- Manual field locks take precedence over TMDB, AI, and import values.

## 6. High-risk files and areas

Inspect direct callers and add regression coverage before modifying:

- `application/common/model/Collect.php` — approximately 3,182 lines; native collection has many branches.
- `application/common/model/Vod.php` — core video querying and persistence.
- `application/data/update/database.php` — monolithic legacy upgrade path; new programme migrations must not silently depend on web requests.
- `application/common.php` and behavior hooks — broad request-wide side effects.
- `application/admin/controller/System.php` and `application/extra/maccms.php` — large shared configuration surface.
- `application/api/controller/*` — existing API is broad but does not equal the planned `/api/v1` contract.
- `static_new/js/admin_common.js` — currently contains an obfuscated official update check and is part of the prohibited outbound inventory.
- Authentication, URL fetching, upload, update, add-on, and template-market code — treat as security-sensitive.

Do not perform unrelated refactors in these files. Isolate new logic in focused services and keep compatibility hooks minimal.

## 7. Existing capabilities to inspect before building

The pinned upstream already contains:

- `AiProvider`, `ContentAnnotator`, `ContentAiAnnotation`, `AnnotationAdopter`
- `TmdbExternalSourceProvider`, `ImdbExternalSourceProvider`, `DoubanExternalSourceProvider`
- `ContentLang` and multilingual overlays
- `JwtService` and API login endpoints
- `OpenApiSpec` and a broad legacy `application/api` module
- favorites/history/progress through `Ulog`
- recommendations, content quality, analytics, monitoring, audit, security checks
- S3 upload configuration and AI cover support

For each planned subsystem, document one decision in the relevant task: **reuse**, **extend**, **replace**, or **retire**. Include the reason and migration impact.

## 8. Development and test rules

- Primary target: PHP 8.1, Nginx, MySQL 5.7 and 8.0.
- Upstream declares PHP `>=7.0`; new programme code may target PHP 8.1 but must not break bootstrap before environment checks.
- Use test-first development for every behavior change: failing test, minimal implementation, passing test.
- Keep pure parsing, scoring, validation, and state-machine logic independent of ThinkPHP/DB where practical.
- Add focused scripts under `tests/regression/` and register phase runners there.
- Run `php -l` on every changed PHP file.
- Run `git diff --check` before every commit.
- Database verification must use a named disposable database. Never run migration or destructive SQL against an unresolved environment variable, production, or a shared database.
- External-service tests use fixtures/fakes unless a task explicitly defines a credentialed manual test.
- Do not log passwords, cookies, tokens, authorization headers, API keys, or unredacted external payloads.

Minimum documentation-only verification:

```bash
git diff --check
test -s AGENTS.md
test -s CURRENT_STATE.md
test -s tasks.md
```

Minimum PHP task verification:

```bash
php -l path/to/changed.php
php tests/regression/<focused_test>.php
git diff --check
```

The active task may require more; its commands in `tasks.md` are authoritative.

## 9. Database and migration rules

- Use ordered, checksummed, idempotent migrations and a migration ledger.
- Do not add programme schema only to `application/data/update/database.php`.
- Every migration task must document backup, forward verification, repeat-run behavior, and rollback/recovery.
- Preserve configurable table prefixes.
- Support MySQL 5.7 and 8.0; avoid features unavailable in 5.7.
- Keep large raw AI/TMDB responses and job error details outside `mac_vod`.

## 10. Network and security rules

- No implicit MACCMS-official request may be added or preserved without an explicit product decision.
- Keep Apache 2.0 and original attribution notices.
- All administrator-configured external HTTP clients must enforce scheme, DNS/IP, redirect, timeout, response-size, allowlist, and secret-redaction controls.
- Never bypass permission or confirmation checks for merge, restore, publish, bulk overwrite, or secret changes.
- Do not expose stack traces, SQL, secrets, internal file paths, or raw provider responses in API errors.

## 11. Documentation maintenance

Update `CURRENT_STATE.md` only with verified facts. Mark unexecuted checks as `UNVERIFIED`, not passing.

Update `tasks.md` in the same commit as implementation:

- `TODO` — not started
- `READY` — prerequisites complete
- `IN_PROGRESS` — exactly one task
- `BLOCKED` — include reason and required decision
- `DONE` — include commit SHA and verification evidence

When discovering an undocumented subsystem, add a concise entry to `CURRENT_STATE.md`; do not paste large source excerpts.

