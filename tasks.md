# MACCMS Headless AI Execution Tasks

This is the resumable execution ledger. Follow `AGENTS.md`. Update task status, evidence, and checkpoint state in the same commit as the related work, then push immediately.

## Status rules

- `TODO`: prerequisites incomplete.
- `READY`: may be selected next.
- `IN_PROGRESS`: exactly one task at a time.
- `BLOCKED`: reason and required resolution recorded.
- `DONE`: committed, pushed, and verified; include commit SHA and evidence.

Do not mark a task `DONE` with a future or local-only SHA. A checkpoint completes only when all required tasks and its gate are complete.

## Checkpoint index

| Checkpoint | Purpose | Status | Depends on |
|---|---|---|---|
| CP-00 | Reset repository and establish architecture/master plan | DONE | — |
| CP-01 | Reconcile existing capabilities and establish regression baseline | DONE | CP-00 |
| CP-02 | Add migrations, extension data, public IDs, workflow, playback codec | READY | CP-01 |
| CP-03 | Verify foundation on real MySQL and native write paths | TODO | CP-02 |
| CP-04 | Add content job queue, AI normalization, provenance and locks | TODO | CP-03 |
| CP-05 | Add duplicate review, reversible merge and TMDB workflow | TODO | CP-04 |
| CP-06 | Add intelligent-content admin workspace and permissions | TODO | CP-05 |
| CP-07 | Add versioned Headless API, sessions, favorites and progress | TODO | CP-06 |
| CP-08 | Add event deduplication, rankings and recommendations | TODO | CP-07 |
| CP-09 | Remove prohibited communication and complete release hardening | TODO | CP-08 |

---

## CP-00 — Repository reset and planning

### T-001 Reset target repository from pinned upstream — `DONE`

- Pinned source: `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`
- Target branch: `feature/headless-ai-v1`
- Evidence: complete remote tree contained 3,112 entries after one-time bootstrap.
- Commit: `7059ec9a7846a9ca5df36d8bfebd0729d6730904`

### T-002 Add architecture and implementation plans — `DONE`

- Files:
  - `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`
  - `docs/superpowers/plans/2026-09-18-maccms-foundation-data.md`
  - `docs/superpowers/plans/2026-09-18-maccms-headless-ai-master-plan.md`
  - `docs/development/upstream-baseline.md`
- Evidence: all files verified in remote Git tree.
- Commit: `7059ec9a7846a9ca5df36d8bfebd0729d6730904`

---

## CP-01 — Existing-capability reconciliation and regression baseline

### T-010 Record reproducible source inventory — `DONE`

**Goal:** Make codebase shape changes visible without rescanning the repository manually.

**Files:**
- Create: `tests/regression/source_inventory.php`
- Create: `docs/development/source-inventory.md`
- Modify: `tests/regression/run_baseline.php` if created by this task
- Modify: `CURRENT_STATE.md`
- Modify: `tasks.md`
- Create: `.github/workflows/php-regression.yml` (PHP 8.1 verification because the current Work runtime has no PHP interpreter)

**Actions:**

- Assert required entrypoints and critical classes exist.
- Record controller/model/util counts and high-risk file line counts.
- Assert `application/admin/view_new` is the active admin-template tree.
- Assert current CLI registration and the single pre-existing regression test are represented accurately.
- Generate a concise inventory document; do not copy source bodies.

**Verification:**

```bash
php tests/regression/source_inventory.php
git diff --check
```

**Commit:** `test: capture maccms source inventory baseline`

**Implementation commits:**

- `e3fc1a6352141d06ff4eb3c59f82305b36787e5b` — initial RED test and PHP 8.1 workflow.
- `0a781368a163c459147c0dbaa742b866c635a4c8` — align newline-count semantics; confirmed the only RED failure was the missing inventory document.
- `04a4cc462f2d9bcdfa0ff961c19124a2def7883b` — add the source inventory document and reach GREEN.

**Verification evidence:**

- RED: GitHub Actions run `35335533101` failed only with `Source inventory document is missing.`
- GREEN: GitHub Actions run `35335644796` passed on PHP 8.1, including `source_inventory.php` and `user_register_validate.php`.

### T-011 Build the baseline regression runner — `DONE`

**Depends on:** T-010

**Files:**
- Create or modify: `tests/regression/run_baseline.php`
- Modify only when needed: `tests/regression/user_register_validate.php`
- Modify: `docs/development/upstream-baseline.md`
- Modify: `CURRENT_STATE.md`
- Modify: `tasks.md`

**Actions:**

- Run source inventory and every safe existing regression script in deterministic order.
- Propagate the first nonzero exit status and identify the failing script.
- Record PHP version and exact output without claiming database coverage.

**Verification:**

```bash
php tests/regression/run_baseline.php
git diff --check
```

**Commit:** `test: add repeatable baseline regression runner`

**Implementation commits:**

- `8cedf9e9782cfa0eafeedd3bed4d7e092e39b7c5` — add the runner behavior contract and record T-011 in progress.
- `cdc337d73c889ee334129c01183aff3b39d055c5` — implement the deterministic runner and document its database-free scope.

**Verification evidence:**

- RED: GitHub Actions run `35338906406` passed PHP syntax and failed at `Baseline runner contract` before the runner existed.
- GREEN: GitHub Actions run `35339024233` passed the runner contract and the complete PHP 8.1 baseline suite.

### T-012 Inventory overlapping subsystems — `DONE`

**Depends on:** T-011

**Files:**
- Create: `docs/development/capability-reconciliation.md`
- Modify: `CURRENT_STATE.md`
- Modify: `tasks.md`

**Required decisions:**

For each area, record existing entrypoints, schema, callers, gaps, and exactly one decision: **reuse**, **extend**, **replace**, or **retire**.

- AI provider and configuration
- AI content annotation/review
- TMDB/IMDb/Douban providers and sync
- multilingual content and fallback
- API/OpenAPI
- JWT/login/session behavior
- Ulog favorites/history/progress
- recommendations/content quality/user profile
- analytics/rankings events
- audit/security/monitoring
- S3/media/AI cover
- task tables versus new content-job queue

**Verification:**

```bash
for area in ai tmdb multilingual api authentication ulog recommendation analytics audit media queue; do
  rg -qi "$area" docs/development/capability-reconciliation.md || exit 1
done
! rg -n 'TBD|TODO|implement later|fill in' docs/development/capability-reconciliation.md
git diff --check
```

**Commit:** `docs: reconcile existing maccms capabilities`

**Implementation commit:** `6128c771788586b18b13632f6f4d75947e654ee5`

**Verification evidence:**

- All 11 required area keywords were present and no placeholder terms were found.
- The decision summary contained exactly 12 subsystem rows, each with one `reuse`, `extend`, `replace`, or `retire` decision.
- Referenced primary entrypoint files existed and `git diff --check` passed.
- GitHub Actions PHP 8.1 baseline run `35340449661` passed.

### T-013 Inventory and classify outbound communication — `DONE`

**Depends on:** T-012

**Files:**
- Create: `tests/regression/outbound_inventory.php`
- Create: `docs/security/outbound-inventory.md`
- Modify: `tests/regression/run_baseline.php`
- Modify: `CURRENT_STATE.md`
- Modify: `tasks.md`

**Actions:**

- Scan PHP, JavaScript, templates, add-ons, and default configuration for URLs, curl, sockets, remote downloads, and dynamic script insertion.
- Classify every endpoint family as required/configured, optional disabled-by-default, prohibited, or test/documentation-only.
- Add a failing policy assertion for the known `update.maccms.la` request; removal belongs to CP-09 unless it blocks earlier safe work.
- Identify SSRF-sensitive callers separately from official communication.

**Verification:**

```bash
php tests/regression/outbound_inventory.php --report
php tests/regression/run_baseline.php
git diff --check
```

Expected at this checkpoint: inventory succeeds; prohibited-endpoint enforcement remains explicitly failing or quarantined with a recorded reason until CP-09.

**Commit:** `test: inventory outbound network dependencies`

**Implementation commits:**

- `193fed4228658f6972ab8abf6d4762528c695fe2` — add the report/enforcement behavior contract and record T-013 in progress.
- `37a909705aeade67c8d28efecc30ca7277c32384` — add the static scanner, endpoint classification and baseline integration.

**Verification evidence:**

- RED: GitHub Actions run `35341174607` passed existing contracts and failed only at the missing outbound inventory implementation.
- GREEN: GitHub Actions run `35341433065` passed PHP syntax, the outbound contract and the complete baseline suite.
- Report mode succeeds with the official update endpoint marked `QUARANTINED`; enforcement mode returns nonzero with `PROHIBITED` while removal remains scheduled for CP-09.

### T-014 Record native smoke-test procedure — `DONE`

**Depends on:** T-013

**Files:**
- Create: `docs/testing/native-smoke-checklist.md`
- Modify: `docs/development/upstream-baseline.md`
- Modify: `CURRENT_STATE.md`
- Modify: `tasks.md`

**Coverage:** fresh install, admin login, admin video create/edit, collection insert/update, member register/login/logout, playback, API video list/detail, Ulog progress, and admin permissions.

Do not mark manual steps as passed until executed against an explicitly named disposable environment.

**Verification:**

```bash
! rg -n 'TBD|TODO|implement later|fill in' docs/testing/native-smoke-checklist.md
git diff --check
```

**Commit:** `docs: define native compatibility smoke tests`

**Implementation commit:** `10da419f3efc3b5c7a788495106c40d5dc501bc7`

**Verification evidence:**

- The checklist covers fresh install, administrator authentication/permissions, native video create/edit, collection insert/update, member register/login/logout, playback, API video list/detail, Ulog progress and outbound observation.
- Every manual case remains explicitly `UNVERIFIED`/`NOT_RUN` pending a named disposable environment.
- Placeholder and whitespace checks passed; GitHub Actions PHP 8.1 run `35341664442` passed.

### CP-01 gate

- [x] T-010 through T-014 are `DONE`, committed, and pushed.
- [x] `php tests/regression/run_baseline.php` passes on GitHub Actions PHP 8.1.
- [x] Every overlapping subsystem has a reuse/extend/replace/retire decision.
- [x] Outbound inventory covers known official and configured services.
- [x] Database/manual checks remain honestly marked `UNVERIFIED`; no disposable environment was used.
- [x] `CURRENT_STATE.md` names CP-02 and its first `READY` task.

Checkpoint commit if needed: `docs: close capability reconciliation checkpoint`

---

## CP-02 — Foundation data and compatibility code

Detailed source: `docs/superpowers/plans/2026-09-18-maccms-foundation-data.md`. Reconcile exact paths against T-012 before implementation.

### T-020 Add migration ledger and CLI runner — `DONE`

- Add pure migration-discovery/checksum tests first.
- Add ordered SQL migration service and `maccms:migrate` command.
- Preserve `SeoAiGenerate` registration.
- Commit: `feat: add versioned schema migrations`

**Implementation commits:**

- `da14410e9dfed29e3334ec9c437ad827896987e1` — add the pure discovery/checksum RED contract and foundation runner.
- `f9cfcada3de647f75d69d8c432e08b71c281fe0a` — add the CLI registration RED contract.
- `4099ea796a245f13aadd89cc39ce5bdb4c9c6577` — implement the migration ledger, service and `maccms:migrate` command.
- `d127bc853070f06ef92d8d642914d83b4882fbb9` — reconcile the intentional source-inventory count change.

**Verification evidence:**

- RED service: GitHub Actions run `35341864143` passed the complete baseline and failed only at the foundation suite with `SchemaMigrationService` absent.
- RED CLI: GitHub Actions run `35341976317` failed with the migration command contract before implementation.
- GREEN: GitHub Actions run `35342212142` passed PHP syntax, baseline/outbound contracts, migration discovery/checksum, CLI registration and the foundation suite.
- Fresh `git diff --check` returned exit code 0 after the GREEN run.

### T-021 Add foundation extension schema — `DONE`

- Add video extension, taxonomy relation, field-state, and migration tables.
- Resolve reuse of `ContentLang` explicitly; do not create a competing multilingual system without T-012 evidence.
- Commit: `feat: add video extension schema`

**Implementation commits:**

- `2b5091632395f43c23b5d8d20cbff577e5956f9b` — add the foundation schema RED contract.
- `5add8f640e6825b3aaf8f78d5b500672b92dbe9f` — add the versioned four-table extension schema.

**Verification evidence:**

- RED: GitHub Actions run `35343803947` passed syntax and baseline checks, then failed in the foundation suite because the migration SQL was absent.
- GREEN: GitHub Actions run `35343905926` passed the schema contract and complete PHP 8.1 baseline/foundation suites.
- Contract confirms four InnoDB/utf8mb4 tables, literal `__PREFIX__`, required unique/query indexes, no foreign keys, and no competing `content_lang` table.

### T-022 Add stable public IDs — `DONE`

- Pure collision/retry/exhaustion tests first.
- Add `VodExt` persistence and canonical traversal cycle protection.
- Commit: `feat: add stable video public ids`

**Implementation commits:**

- `a0412f55610998c9625bb5fb8e44be474de8313d` — add the public-ID and canonical traversal RED contracts.
- `9239f41107ae8e73392dee804f18d8f415fecad5` — add the generator, `VodExt` persistence, canonical traversal, and inventory updates.
- `14405a0b68ce8d6efb78d78a81d4e4d554d85347` — correct the generator syntax found by the GREEN syntax gate.

**Verification evidence:**

- RED: GitHub Actions run `35344205411` passed syntax and all pre-existing suites, then failed at the missing public-ID implementation as expected.
- Initial GREEN run `35344396710` exposed a parser error before tests; the isolated syntax fix was committed separately.
- GREEN: GitHub Actions run `35344501044` passed PHP syntax, collision/retry/exhaustion, canonical chain/cycle/depth, and all baseline/foundation suites.

### T-023 Add workflow state machine — `DONE`

- Keep it separate from `vod_status`.
- Test every allowed and forbidden transition.
- Commit: `feat: define video processing workflow`

**Implementation commits:**

- `47a38f49b744b93bd82793b9224c20af730f9adc` — add the exhaustive workflow transition RED contract.
- `30fe98116456ad5b9ae2898fa18e0eda5198565c` — add the pure `VodWorkflow` state machine and inventory update.

**Verification evidence:**

- RED: GitHub Actions run `35344769008` passed syntax and all pre-existing suites, then failed at the missing workflow implementation as expected.
- GREEN: GitHub Actions run `35344911819` passed all 81 state-pair checks, unknown-state/retry rejection, PHP syntax, and the complete baseline/foundation suites.

### T-024 Add lossless playback codec — `DONE`

- Test all native delimiters, empty records, unsafe schemes, round trip, and deterministic merge.
- Commit: `feat: add lossless video playback codec`

**Implementation commits:**

- `3e13cd9b0edaaf3648327f5338c7ddd2e75588fb` — add the lossless decode/encode/merge RED contract.
- `75428a041563cadd4569458b7d3a480f6126ea09` — add the codec, validation boundaries, deterministic merge, and inventory update.

**Verification evidence:**

- RED: GitHub Actions run `35345156553` passed syntax and all pre-existing suites, then failed at the missing codec as expected.
- GREEN: GitHub Actions run `35345369100` passed lossless record-form, delimiter, unsafe-input, merge, PHP syntax, and complete baseline/foundation checks.

### T-025 Add taxonomy/provenance/lock services — `DONE`

- Reconcile with existing AI annotation and `ContentLang` behavior.
- Sync only native compatibility taxonomy fields.
- Commit: `feat: add video metadata extension services`

**Implementation commits:**

- `42c644f8efea5deb96b3a1abce8bf8d040dbb154` — add the extension models/service RED source contract.
- `76321134eaaa04d42d1f767a0caad736916257ce` — add taxonomy, provenance/lock models and native compatibility synchronization.

**Verification evidence:**

- RED: GitHub Actions run `35345571249` passed syntax and all pre-existing suites, then failed because the extension sources were absent.
- GREEN: GitHub Actions run `35345760601` passed model bindings, exact kind/source allowlists, service interfaces, deterministic taxonomy ordering/mapping, and all suites.

### T-026 Hook both native video write paths — `IN_PROGRESS`

- Cover `Vod::saveData()` and collection insert/update paths in `Collect::vod_data()`.
- Preserve existing return values and transaction semantics.
- Commit: `feat: ensure video extension records on save`

### CP-02 gate

- [ ] Focused foundation suite passes.
- [ ] Existing baseline suite passes.
- [ ] Migration second run is a no-op in an isolated test double or parser test.
- [ ] No external request was added.
- [ ] Changes are split into T-020 through T-026 commits and pushed individually.

---

## CP-03 — Real database and native-path verification

### T-030 Verify migrations on MySQL 5.7 — `TODO`

- Requires an explicitly named disposable database and recorded version.
- Commit evidence: `test: verify foundation on mysql 5.7`

### T-031 Verify migrations on MySQL 8.0 — `TODO`

- Requires a separate explicitly named disposable database.
- Commit evidence: `test: verify foundation on mysql 8.0`

### T-032 Verify admin/collection writes and playback round trip — `TODO`

- Create one draft via admin and one via collection.
- Verify one extension row per video, distinct IDs, and byte-identical playback round trip.
- Commit: `docs: record foundation integration verification`

### T-033 Publish foundation deployment/rollback guide — `TODO`

- Exact backup, migration, verification, application rollback, and database restore procedure.
- Commit: `docs: add foundation deployment and rollback guide`

### CP-03 gate

- [ ] Both MySQL versions verified.
- [ ] Native admin and collection flows verified.
- [ ] Backup/restore procedure reviewed.
- [ ] Foundation release checkpoint tagged only after all evidence is committed and pushed.

---

## CP-04 — Content job queue and AI normalization

### T-040 Dedicated content-job schema and repository — `TODO`
### T-041 Atomic claim, lease recovery, retry and idempotency — `TODO`
### T-042 Cron/CLI worker budgets and heartbeat — `TODO`
### T-043 Hardened external HTTP boundary — `TODO`
### T-044 AI JSON schema and validation — `TODO`
### T-045 AI run provenance, prompt version and usage — `TODO`
### T-046 Field precedence and manual locks — `TODO`
### T-047 AI pipeline integration tests — `TODO`

Each task requires failing tests, implementation, focused verification, an immediate commit, and immediate push. CP-04 cannot reuse member `task` tables.

---

## CP-05 — Duplicate review, merge/restore and TMDB

### T-050 Duplicate candidate schema and deterministic scoring — `TODO`
### T-051 Different-work decisions and candidate invalidation — `TODO`
### T-052 Merge snapshots and playback merge — `TODO`
### T-053 Canonical public-ID resolution — `TODO`
### T-054 Authorized conflict-aware restoration — `TODO`
### T-055 TMDB search/candidate scoring/manual ID/no-match — `TODO`
### T-056 TMDB field import with provenance and locks — `TODO`
### T-057 End-to-end duplicate/TMDB regression suite — `TODO`

No task may introduce automatic merge.

---

## CP-06 — Intelligent-content administration

### T-060 Define granular permissions and audit events — `TODO`
### T-061 Dashboard metrics, queue health and Cron heartbeat — `TODO`
### T-062 AI field review UI — `TODO`
### T-063 Duplicate comparison, merge and restore UI — `TODO`
### T-064 TMDB candidate and manual-match UI — `TODO`
### T-065 Final validation/publication UI — `TODO`
### T-066 Batch enqueue and failure/retry UI — `TODO`
### T-067 Admin permission and compatibility regression — `TODO`

Use `application/admin/view_new`; do not add a parallel obsolete `view/` tree.

---

## CP-07 — Headless API v1 and sessions

### T-070 Versioned route/error/pagination/DTO foundation — `TODO`
### T-071 Public home/list/detail/episodes/search/taxonomy endpoints — `TODO`
### T-072 Locale fallback and canonical redirects — `TODO`
### T-073 Access plus rotating hashed refresh sessions — `TODO`
### T-074 Favorites/history/progress DTO and anonymous merge — `TODO`
### T-075 Playback source policy and optional signing — `TODO`
### T-076 CORS, per-endpoint limits, ETag and invalidation — `TODO`
### T-077 OpenAPI v1 and contract tests — `TODO`

Legacy API compatibility is a separate concern; do not expose raw DB records in `/api/v1`.

---

## CP-08 — Events, rankings and recommendations

### T-080 Playback/favorite event contract and deduplication — `TODO`
### T-081 Today/7-day/30-day/all-time aggregates — `TODO`
### T-082 Deterministic explainable recommendation service — `TODO`
### T-083 Retention, purge and abuse limits — `TODO`
### T-084 Ranking/recommendation API and boundary tests — `TODO`

---

## CP-09 — Official communication removal and release hardening

### T-090 Remove official update check and admin update routes — `TODO`
### T-091 Remove/disable announcements, affiliate defaults and telemetry — `TODO`
### T-092 Centralize outbound policy and SSRF controls — `TODO`
### T-093 Security regression suite — `TODO`
### T-094 Full native and programme regression matrix — `TODO`
### T-095 Deployment, migration, Cron, backup and disaster-recovery docs — `TODO`
### T-096 Release candidate acceptance and rollback rehearsal — `TODO`
### T-097 Tag `headless-ai-v1.0.0` — `TODO`

Release is blocked until prohibited outbound tests pass and both MySQL verification tracks are evidenced.

---

## Current execution pointer

```text
Checkpoint: CP-01
Next task: T-011
Task status: READY
Required starting state: clean feature/headless-ai-v1 at latest remote commit
First command after checkout: php -v
Task commit: test: add repeatable baseline regression runner
Push required: yes, immediately after task verification
```
