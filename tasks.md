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
| CP-02 | Add migrations, extension data, public IDs, workflow, playback codec | DONE | CP-01 |
| CP-03 | Verify foundation on real MySQL and native write paths | DONE | CP-02 |
| CP-04 | Add content job queue, AI normalization, provenance and locks | DONE | CP-03 |
| CP-05 | Add duplicate review, reversible merge and TMDB workflow | DONE | CP-04 |
| CP-06 | Add intelligent-content admin workspace and permissions | DONE | CP-05 |
| CP-07 | Add versioned Headless API, sessions, favorites and progress | DONE | CP-06 |
| CP-08 | Add event deduplication, rankings and recommendations | DONE | CP-07 |
| CP-09 | Remove prohibited communication and complete release hardening | IN_PROGRESS | CP-08 |

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

### T-026 Hook both native video write paths — `DONE`

- Cover `Vod::saveData()` and collection insert/update paths in `Collect::vod_data()`.
- Preserve existing return values and transaction semantics.
- Commit: `feat: ensure video extension records on save`

**Implementation commits:**

- `e13279f519664fd0148582c84b26f8a619ebfa80` — add the initial structural RED contract.
- `0b6fe6589a38580f587179b9204d6ca14db6215f` — correct interpolated test literals and obtain the valid RED result.
- `c5d60729dcc750ece6d758e10d9ae1dc5b3e9125` — add the shared failure-mapping helper and three native post-save hooks.

**Verification evidence:**

- Initial RED run `35346208054` stopped at a test-file parser error; no production code was written from this invalid RED.
- Valid RED: GitHub Actions run `35346322469` passed syntax and every pre-existing suite, then failed at the missing native persistence hooks.
- GREEN: GitHub Actions run `35346507561` passed PHP syntax, both native-path structural contracts, and the complete baseline/foundation suites.

### CP-02 gate

- [x] Focused foundation suite passes — GitHub Actions run `35346673637`.
- [x] Existing baseline suite passes — GitHub Actions run `35346673637`.
- [x] Migration second run is a no-op in an isolated test double — commit `f8907cf4d395aaa134abcb9bf01d4c72f17b0074`, run `35346673637`.
- [x] No external request was added — production additions contain no HTTP/cURL/socket client primitives; outbound contract passed in run `35346673637`.
- [x] Changes are split into T-020 through T-026 commits and pushed individually.

---

## CP-03 — Real database and native-path verification

### T-030 Verify migrations on MySQL 5.7 — `DONE`

- Requires an explicitly named disposable database and recorded version.
- Commit evidence: `test: verify foundation on mysql 5.7`

**Implementation commits:**

- `eb3440e02208ade184b49c144430f185645c0cea` — add the isolated MySQL 5.7 service workflow and schema invariant test.
- `be930fe14588b9aa5303752220f2503d1c6916d3` — expose migration command output on CI failure.
- `3db86d645b96bfbdae2024791be0e086dd48a06b` — add the missing ThinkPHP console entrypoint.
- `bc6d66492a944969e3184d621ec719f133647df9` / `fee03f902a534e7fa98816a77a0e758b10caca76` — require and initialize the neutral CLI entrance.
- `0bd26168054580b185b2b752ca226b31b374abd9` / `a24acb7dd716bb4284c5db4c8506c0496857f4ec` — reproduce and fix MySQL DDL implicit-commit handling.

**Verification evidence:**

- Disposable database: `maccms_ci_57`; server family/version assertion: MySQL `5.7.*` (service image resolved to 5.7.44).
- RED CLI contract: run `35347666606` failed only because `think` did not define the neutral command entrance.
- RED DDL behavior: run `35348240031` failed at `Db::startTrans()`, reproducing MySQL DDL transaction incompatibility.
- GREEN: PHP 8.1 regression run `35348332053` and MySQL 5.7 run `35348332012` passed.
- The database run verified first-run `applied=1, skipped=0`, second-run `applied=0, skipped=1`, one checksummed ledger row, and all four InnoDB/utf8mb4 extension tables and indexes.

### T-031 Verify migrations on MySQL 8.0 — `DONE`

- Requires a separate explicitly named disposable database.
- Commit evidence: `test: verify foundation on mysql 8.0`

**Implementation commits:**

- `85d2437982a169aba1fa11594c40468e8a406187` — add the separate MySQL 8.0 service workflow and disposable `maccms_ci_80` database.
- `3c517f1fe34034127e1e21cdc1a386450574a6a1` — parameterize the shared exact-family assertion and verify both supported MySQL families together.

**Verification evidence:**

- RED: run `35348585866` applied the migration, passed the second-run no-op, and failed only because the invariant script still required MySQL 5.7; actual server version was `8.0.46`.
- GREEN: PHP 8.1 run `35348806445`, MySQL 8.0 run `35348806475`, and MySQL 5.7 regression run `35348806492` all passed.
- The MySQL 8.0 run verified first-run `applied=1, skipped=0`, second-run `applied=0, skipped=1`, the checksummed ledger row, and all four InnoDB/utf8mb4 tables and indexes.

### T-032 Verify admin/collection writes and playback round trip — `DONE`

- Create one draft via admin and one via collection.
- Verify one extension row per video, distinct IDs, and byte-identical playback round trip.
- Commit: `docs: record foundation integration verification`

**Implementation commits:**

- `863bcc0dab2f07bbe791e3f58143e60d860bfc64` — add the full native-schema MySQL workflow and admin/collection integration harness.
- `48ff96c0721bce1de1791b7373db3e06665864a6` — bind the harness to the native admin module and require an explicit success marker so framework-rendered exceptions cannot produce false-green CI.

**Verification evidence:**

- The first run exposed `app\model\Vod` resolution while returning process status zero; it was rejected as a false positive and is not completion evidence.
- GREEN: native integration run `35349617886` used disposable `maccms_ci_native`, PHP 8.1, and MySQL 5.7.44; PHP regression run `35349617888` also passed.
- The native run imported the complete install schema, applied migration `20260918000100`, called `Vod::saveData()`, exercised `Collect::vod_data()` insert and update, and verified two native rows, exactly two extension rows, distinct valid public IDs, no collection duplicate, persisted update remarks, and byte-identical playback round trips.
- This automated model-boundary verification does not claim the browser/admin-form manual smoke case; that remains `NOT_RUN` in `docs/testing/native-smoke-checklist.md`.

### T-033 Publish foundation deployment/rollback guide — `DONE`

- Exact backup, migration, verification, application rollback, and database restore procedure.
- Commit: `docs: add foundation deployment and rollback guide`

**Implementation commit:** `7931350d13c89a6ed3f5e0ad5157d5f7079295ca`

**Verification evidence:**

- `git diff --check`, required-file checks, and static coverage checks for runtime requirements, exact named backup, migration, schema verification, application rollback, full database restore, and Cron status passed before push.
- GitHub Actions PHP 8.1 regression run `35350022475` passed after the guide was pushed.
- Review against design sections 3, 4, 9, 11, 12, and 14 found the foundation keeps `mac_vod`/`vod_play_*` authoritative, isolates extension state, adds no queue/Cron or outbound request, uses versioned migrations, and provides MySQL 5.7/8.0 plus native-path evidence. Remaining queue/outbound enforcement work stays in CP-04/CP-09.

### CP-03 gate

- [x] Both MySQL versions verified — runs `35348332012`, `35348806475`, and cross-family run `35348806492`.
- [x] Native admin and collection persistence boundaries verified — run `35349617886`; manual browser smoke remains separately `NOT_RUN`.
- [x] Backup/restore procedure reviewed — `docs/deployment/foundation-data.md`, commit `7931350d13c89a6ed3f5e0ad5157d5f7079295ca`.
- [x] No premature release tag was created; all CP-03 evidence is committed and pushed. A deployment release tag remains an operator release action after manual acceptance, as required by the runbook.

---

## CP-04 — Content job queue and AI normalization

### T-040 Dedicated content-job schema and repository — `DONE`

- Added checksummed migration `20260918000200_content_jobs.sql` with separate `content_job` and `content_job_run` tables; no reward-task or external-sync table is reused.
- Added focused `ContentJob` and `ContentJobRun` models plus `ContentJobRepository::enqueue()`/`find()` with validated JSON payloads and `(job_type,idempotency_key)` identity.
- RED commit: `8d62749980d44975264e8818f2d99a5ea7c2409f`; PHP run `35355224245` failed only in the new AI jobs suite because the migration was absent.
- Implementation commit: `06c809d47cf5de07e3a46c3892f2ee56c4612fc0`.
- GREEN evidence: PHP regression `35355631036`, MySQL 5.7 `35355630985`, MySQL 8.0 `35355630926`, and native video foundation `35355630932` all passed.

### T-041 Atomic claim, lease recovery, retry and idempotency — `DONE`

- Atomic claim uses a single conditional ordered update and connection-local `LAST_INSERT_ID(job_id)`, with lease expiry recovery and owner-checked completion/failure.
- Retry delay is exponential from 60 seconds and capped at 3600 seconds; exhausted jobs become terminal `failed`.
- RED commit: `6b423ce473a081822be4f44793bb487929a63fe8`; run `35357590685` failed only because lifecycle methods were absent.
- Implementation commit: `6a47bbbae42e345dac1a208db8c8db589cb8c027`; MySQL acceptance commit: `149b01760c9639d121fe253106a1a48dc7aa4c73`.
- GREEN evidence: PHP `35357937169`, MySQL 5.7 `35357937449`, and MySQL 8.0 `35357937262` passed.

### T-042 Cron/CLI worker budgets and heartbeat — `DONE`

- Added `maccms:jobs` with explicit job-count, wall-time, lease, and worker-ID options; the worker emits running/idle heartbeat rows and redacts handler failures.
- RED commit: `e2da691d96155fdf7e8fbe2a1fa954303daadc46`; implementation commit: `190fd1199a374b5c8216f216eac346e34de444a7`.
- GREEN evidence: PHP `35358498991`, MySQL 5.7 `35358499027`, MySQL 8.0 `35358499003`, native foundation `35358499120`.

### T-043 Hardened external HTTP boundary — `DONE`

- Added shared HTTPS/allowlist/DNS public-IP policy, DNS pinning, redirect revalidation, timeout and response-size limits, header validation, and secret redaction.
- RED commit: `5a9892d2de18ba71a52299fe00be708e5feccf93`; GREEN commit: `db9fca5ade0dd6cdb419ea55de2990b985aa61e4`; PHP run `35367531362` passed.

### T-044 AI JSON schema and validation — `DONE`

- Added strict bounded schema validation for titles, aliases, year/type, TMDB clues, taxonomy suggestions, confidence, and reason; malformed, missing, unknown, and wrongly typed values are rejected.
- RED commit: `46a9c192c4cd6412dfc605d5caf1f94025959dbc`; GREEN commit: `3103631ab3ba6317b07db0561d9478bb49b23655`; PHP run `35367850824` passed.

### T-045 AI run provenance, prompt version and usage — `DONE`

- Added immutable AI-run provenance with prompt/request/response fingerprints, validation and decision statuses, token/cost accounting, UTC daily usage, and a strict daily-budget boundary.
- RED commit: `fe3b606357a41a517c011c01c5a2a16ed53cab21`; GREEN commit: `d7615bf893877de21af5f3928c8bf7958db7565b`; PHP run `35372769277`, MySQL 5.7 run `35372770568`, MySQL 8.0 run `35372769449`, and native video run `35372769363` passed.

### T-046 Field precedence and manual locks — `DONE`

- Added one governed write boundary enforcing `manual > confirmed_tmdb > ai > import`, automatic manual locks, background lock protection, and explicit reviewed overrides.
- RED commit: `4f7687c541182f042900793d49211a3099e5bf6e`; GREEN commit: `4d1f27d4b4eb450033cb64b831e17770b7e43a28`; PHP run `35373257715` passed.

### T-047 AI pipeline integration tests — `DONE`

- Integrated the daily-budget gate, provider usage envelope, strict schema validation, immutable valid/invalid run audit, governed field application, and callable `ai.normalize` worker handler.
- RED commit: `4f0fd2cf0e7439b3bd03ed37e29c29b721181c31`; GREEN commit: `d5b76fd4dba4933afe1879cd606cef23080e84c2`; PHP run `35373626629` passed.

Each task requires failing tests, implementation, focused verification, an immediate commit, and immediate push. CP-04 cannot reuse member `task` tables.

---

## CP-05 — Duplicate review, merge/restore and TMDB

### T-050 Duplicate candidate schema and deterministic scoring — `DONE`

- Added canonical unordered candidate-pair persistence with evidence, bounded score, pending decision/reviewer timestamps, and MySQL 5.7/8.0 indexes. Added symmetric five-tier scoring for exact TMDB ID, original title/year/type, multilingual title or alias/year, title plus people, weak title-only evidence, and explicit year-conflict downgrading. No path performs an automatic merge.
- RED commit: `b625a269e57cf7263c262c38db8cea5c5553e2f7`; GREEN commit: `8ac4a1951458cdcf21395c604efdcb20cfd41112`; PHP run `35377005776`, MySQL 5.7 run `35377005526`, MySQL 8.0 run `35377005625`, and native video run `35377005595` passed.

### T-051 Different-work decisions and candidate invalidation — `DONE`

- Added canonical content fingerprints, permanent reviewed `different` decisions, pair-level suppression lookup, and atomic stale invalidation for pending candidates only. Permanent decisions survive later content changes; invalid candidates cannot silently remain reviewable.
- RED commit: `7b04a844c340d1df4994010c9e8eac3534e4df2d`; GREEN commit: `9696b267a6db7f2e54cf1e28bbd8fc6e03c5d7c5`; PHP run `35381357761`, MySQL 5.7 run `35381357697`, MySQL 8.0 run `35381357765`, and native video run `35381357708` passed.

### T-052 Merge snapshots and playback merge — `DONE`

- Added an explicit reviewer-triggered transactional merge service with immutable pre-merge snapshots for native video, extension/aliases, multilingual overlays, taxonomy, field states, external mappings, and Ulog relations. Playback is merged only through `VodPlaybackCodec`, retaining primary order and metadata while deduplicating normalized URLs; any failure rolls back snapshot and mutations.
- RED commit: `8b8752ebaeae608338fe260969c7851530de758e`; GREEN commit: `78dea91470d8450623973f5632c33ca1ca57114c`; PHP run `35384542597`, MySQL 5.7 run `35384542565`, MySQL 8.0 run `35384542812`, and native video run `35384542591` passed.

### T-053 Canonical public-ID resolution — `DONE`

- Added normalized public-ID lookup that follows bounded canonical chains, returns alias/canonical redirect metadata only, rejects invalid or missing IDs, and never exposes numeric video IDs.
- RED commit: `f9c11cf16aba5d50acd1110569ea63d69509aa39`; GREEN commit: `9df6b05afac22f25a8602afcd4820f92e83be76e`; PHP run `35386811289` passed.

### T-054 Authorized conflict-aware restoration — `DONE`

- Added exact restore permission and confirmation gates, snapshot hash verification, deterministic expected-post-merge comparison, atomic rollback on conflicts, candidate reopening, and immutable actor/timestamp restoration audit fields.
- RED commit: `1d7faa223ab5795597916977f8e0f4d4d0e4c96e`; GREEN commit: `8421e86fcf9b4a5364beb7b5253e7468aff945b1`; PHP run `35387273739` passed.

### T-055 TMDB search/candidate scoring/manual ID/no-match — `DONE`

- Added ordered and deduplicated original/English/Traditional/Simplified/alias searches, deterministic title/year/type/region/people scoring, review-only unique high-confidence preselection, exact manual-ID fetch, and explicit no-match results.
- RED commit: `0e26b77ab134e0ddda99ee2801ef6355f723d71c`; GREEN commit: `9e7bf04aaab0870386b9dd075515cdb60244b3d6`; PHP run `35387700115` passed.

### T-056 TMDB field import with provenance and locks — `DONE`

- Added field-level current/candidate/lock previews, reviewed allowlisted application, exact TMDB/reviewer provenance, missing-locale preservation for AI fallback, manual-lock blocking, and explicit reviewed override behavior.
- RED commit: `b41dcaf8c7e15ad187dc355a1e25a35adc4491a5`; GREEN commit: `4f563a596ce45d22e26244a116d2d8244606d02f`; PHP run `35388048661` passed.

### T-057 End-to-end duplicate/TMDB regression suite — `DONE`

- Added a fail-closed lifecycle suite contract and deterministic CI order covering scoring, different-work decisions, transactional merge, canonical public IDs, authorized restoration, TMDB matching, and reviewed field import. The runner propagates the first failure and no component permits automatic merge or unreviewed TMDB application.
- RED commit: `f560042a7bc4d46debe06cebf27b0faa3cb85c44`; GREEN commit: `2fb42c9a7d8e69b367f37a406d12c93cda38da88`; PHP run `35388283844` passed.

No task may introduce automatic merge.

---

## CP-06 — Intelligent-content administration

### T-060 Define granular permissions and audit events — `DONE`

- Extended native `admin_auth` with eight exact intelligent-content permissions. Added default-deny policy checks, confirmation gates for merge/restore, publication, bulk overwrite and security changes, plus an immutable InnoDB domain-event ledger with deterministic before/after hashes, recursive secret redaction and fail-closed persistence.
- RED commit: `75be542cba08e3853d440c43720205e5831540f0`; GREEN commit: `a9d072693f5a4078810cdebb05ced1c7cc7fe0cc`; PHP run `35391110548`, MySQL 5.7 run `35391110547`, MySQL 8.0 run `35391110450`, and native video run `35390914302` passed.

### T-061 Dashboard metrics, queue health and Cron heartbeat — `DONE`

- Extended the existing workflow, content-job, AI-run and worker-heartbeat stores with a read-only intelligent-content dashboard. It reports all workflow-state counts, runnable queue depth/age including expired leases, 24-hour success/failure and provider-rate-limit metrics, UTC daily token/cost budget health, and active Cron heartbeat health. The native `content_workspace/view` permission controls a visible `view_new` entry; AI budget configuration and zero-as-unlimited enforcement now share one persisted contract, while provider HTTP 429 failures remain redacted and observable.
- RED commit: `e2fa8d972b909c94ce4abf2a125c5f7e3029766c`; GREEN/final fix commit: `c00ea9dfe58e3c88cd707ef7a05b77c58b7f7ddd`; PHP run `35394733235` passed. Reuse decision: extend the existing queue, AI-run, workflow and heartbeat tables; no parallel dashboard schema or migration was added.

### T-062 AI field review UI — `DONE`

- Added a `view_new` field-level AI review queue with current/candidate values, provenance, lock/stale state, accept/edit/reject/lock decisions, stable CSRF protection, escaped rendering and immutable audit events. Canonical candidates and pre-provider baselines are staged atomically with each valid run; acceptance locks and rechecks native rows, fails closed without canonical public identity, never crosses locks/conflicts, and leaves the queue after all seven fields are decided.
- Preserved the checksum of `20260919000100`; baseline columns/defaults use idempotent follow-up migration `20260919000200` for MySQL 5.7/8.0 compatibility. RED commits: `6ac318a4678759a971446d0844d3ed29bd7c7925`, `abdbfc52301432a4a1cc7a3c6eec52dce7215454`, `deb90294af3083141bfcf8dc8165d77e83c2d798`, `8054a1a7041905f55f44fc5afb8b9041c8c43ff7`; final commit: `5f9d85e55c1ed0148ab2137022cd96fa3fac623e`. PHP `35431481425`, MySQL 5.7 `35431315407`, MySQL 8.0 `35431315406`, native video `35431205533` passed.
### T-063 Duplicate comparison, merge and restore UI — `DONE`

- Added a discoverable `view_new` duplicate workspace with pending-first paging, side-by-side native/multilingual/external-ID/playback/provenance/lock comparison, explicit primary selection, different-work decisions, merge confirmation, integrity-checked snapshot detail, active-state restoration and safe conflict feedback. The native authorization boundary now normalizes `ContentWorkspace` to the exact `content_workspace/*` permission namespace, and the snake-case action is directly routable.
- Extended different/merge/restore services with transaction-internal immutable audit callbacks so audit failure rolls back every decision, snapshot and content mutation. No path auto-merges. RED commits: `b23746abfe4e37b8009bc2473b404abdb88f3289`, `a989804e464358005067226b95449d21bc181a20`; final commit: `5f95252e851f545073f24c0c9e60854e28248716`; PHP regression `35442328780` passed.
### T-064 TMDB candidate and manual-match UI — `DONE`

- Added a discoverable `view_new` TMDB review workspace with integrity-checked candidate revisions, pending-first paging, candidate-specific field differences, lock/provenance display, reviewed field allowlists, explicit no-match, manual ID and rematch actions. Exact review/run permissions, stable CSRF, row locking and transaction-internal immutable audit keep mutations fail-closed; manual and rematch requests execute through registered bounded Cron handlers rather than external HTTP inside the admin request.
- Added prefix-aware MySQL 5.7/8.0 candidate storage and wired deterministic search/manual results into it. RED commits: `582ee4ad48ec993cd959cebd6903695f9dab5eff`, `d6a14d632edda667bb8fab5db0c6e361e2db98f5`; final commit: `5045e6bad1c821a8f6eada7f04bcb51d8ba4d4dd`. PHP `35443519455`, MySQL 5.7 `35443519424`, MySQL 8.0 `35443519411` passed.
### T-065 Final validation/publication UI — `DONE`

- Added a discoverable `view_new` manual-review publication queue and revision-bound preview with canonical identity, locale, taxonomy, media and native playback validation. Publication requires exact `content_workspace/publish` authorization, stable CSRF, explicit confirmation and locked revalidation; it atomically activates native visibility, advances the separate workflow, writes immutable audit data, clears detail caches and synchronizes Meilisearch.
- Added versioned migration `20260919000400` to convert an installed native `vod` table to InnoDB while remaining safe in extension-only migration environments. Empty, unknown or disabled native playback sources and unsafe/unplayable URLs fail closed. RED commits: `3e65445bf178b17fbbf72d616cb6ab288736910c`, `4dd0830315c7b7479b6c54e3940712b9dff037e1`, `f0c15f64ba94953b1c363c631100794b8aebbfb5`, `731e530bf2c85788a14fa2c32b0ca4b6e6563d4c`; final commit: `5d71f88fb2240c2e04746140fdb109b2154bfcab`. PHP `35454020791`, MySQL 5.7 `35454020803`, MySQL 8.0 `35454020808`, and native video `35453927974` passed.
- Deferred minor: add visible queue pagination/total controls; the controller already accepts `page` and the service returns paging metadata.
### T-066 Batch enqueue and failure/retry UI — `DONE`

- Added a discoverable `view_new` batch-job administration page for explicit lists of at most 100 video IDs, deterministic idempotent AI-normalization/TMDB-match enqueueing, type/status paging, redacted failure summaries and recent immutable run history. HTTP requests enqueue only and never invoke external handlers.
- Enqueue and terminal-failure retry require stable CSRF, explicit confirmation and exact `run_ai`/`run_tmdb` grants, with immutable audit inside the same transaction. Retry preserves monotonic attempt identifiers and historical `content_job_run` rows while adding one retry allowance and clearing lease/error/terminal fields.
- RED commit: `8a22c0adf78fad7dcdf291916415433fae594366`; implementation/fix commits through `aaef8a5aef6a60fd507485946ec3a7d87571b04b`. RED PHP run `35454565365` failed only for the missing service; GREEN PHP run `35455134189` passed. Independent review found the attempt-history uniqueness conflict; the monotonic-attempt fix and regression contract resolved it.

### T-067 Admin permission and compatibility regression — `DONE`

- Added a centralized, pure `ContentAdminRoutePolicy` with exact case-insensitive grants for dashboard, AI review, merge/restore, TMDB review, publication and batch jobs; unknown routes and permission substrings fail closed, while the numeric super administrator retains access.
- Added a cross-permission/action matrix plus active-`view_new`, CSRF/confirmation, enqueue-only HTTP, native `Vod::saveData()`, `Collect::vod_data()` and `vod_play_*` compatibility contracts. The three database/native workflows now trigger for the complete admin authorization boundary.
- RED commit: `dfca19ded57a2fa2e8fb35867604e404070b9134`; implementation and review-fix commits through `34611dacc75a2f3a1800aa1c9649f0965ab02a6d`.
- RED PHP run `35457184767` failed only for the missing route policy. GREEN: PHP `35457611728`, MySQL 5.7 `35457611738`, MySQL 8.0 `35457611727`, native video `35457611759` passed. Independent review found incomplete future workflow triggers; contract run `35457563047` reproduced it before the fix.

### CP-06 gate

- [x] Every intelligent-content route and action defaults to deny and requires its exact grant.
- [x] Confirmation, stable CSRF and immutable audit boundaries remain covered for high-risk mutations.
- [x] Large AI/TMDB batches enqueue traceable Cron jobs and do not call external handlers in HTTP requests.
- [x] Native admin save, collection insert/update and playback compatibility pass on the disposable MySQL 5.7 environment.
- [x] PHP, MySQL 5.7, MySQL 8.0 and native compatibility workflows passed at `34611dacc75a2f3a1800aa1c9649f0965ab02a6d`.

Use `application/admin/view_new`; do not add a parallel obsolete `view/` tree.

---

## CP-07 — Headless API v1 and sessions

### T-070 Versioned route/error/pagination/DTO foundation — `DONE`

- Added a clean `/api/v1` entrypoint boundary that safely selects the existing API module without changing non-v1 public or legacy API requests.
- Added stable success, collection and error envelopes; strict `page`/`per_page` validation; bounded request IDs; reserved response metadata; explicit DTO allowlists; generic v1 JSON exception handling; and deterministic 404/405 responses.
- RED commit: `2a15f2e9b91666a1a9fbf2ce695f636b415fb70e`; valid review-finding RED commit: `6c97c1e115f860a8c7c6c7dc7e5d707216c096c2`; implementation and fixes through `56e7154fd0649a356386d3809d8c4f86d3a06c92`.
- RED PHP run `35461612663` passed all existing suites and failed only at the missing API v1 foundation. GREEN: PHP `35462362148`, MySQL 5.7 `35462362150`, MySQL 8.0 `35462362152`, and native video `35462362293` passed.
- Independent review found module-binding, route option/pattern, exception leakage and request-ID override risks; the entrypoint dispatcher, scoped exception handler and reserved metadata fix resolved them.

### T-071 Public home/list/detail/episodes/search/taxonomy endpoints — `DONE`

- Added exact GET routes for home, videos, detail, episodes, search and taxonomies with shared published/canonical visibility filtering and explicit public DTOs.
- Episode DTOs expose deterministic source/episode identifiers, names and positions only; raw URL/server/note/format fields remain deferred to T-075.
- RED contract commit: `9a67b146336391b11cb2bb6e20cc28094e9f1907`; implementation and review fixes through `3eaabf1aadcd451f0e8fc5f0846aca874533aa6d`.
- GREEN evidence: PHP `35465825379`, MySQL 5.7 `35465825407`, MySQL 8.0 `35465825384`, native video `35465825364`.
- Independent review found home nested-DTO serialization and empty S3-poster fallback issues; both were covered by regression tests and fixed. Summary queries also avoid loading full synopsis HTML.

### T-072 Locale fallback and canonical redirects — `DONE`

- Added strict `zh-TW`/`zh-CN`/`en` locale resolution with explicit-query precedence, quality-weighted `Accept-Language`, deterministic display-field fallback and locale response metadata.
- Added published-target validation and HTTP 308 redirects for merged detail/episode public IDs; redirects expose only the canonical public ID and a safe relative Location.
- Corrected the T-071 detail query to use `DETAIL_FIELDS` instead of the removed `VIDEO_FIELDS` constant.
- RED contract commit: `d7745fa46794efe7d7610be368b54680706bb77e`; implementation and review fixes through `e8178021b6c59f0ecafca81fdc4c12b691774bbf`.
- GREEN evidence: PHP `35466523627`, MySQL 5.7 `35466454603`, MySQL 8.0 `35466454584`, native video `35466454598`.
- Independent review found missing locale metadata on redirects and ignored Accept-Language quality weights; both received RED→GREEN coverage and fixes.

### T-073 Access plus rotating hashed refresh sessions — `DONE`

- Added isolated 15-minute HS256 access tokens, a dedicated v1 signing secret, opaque refresh tokens stored only as SHA-256 digests, atomic rotation, replay-family revocation, device-session listing and immediate access-token invalidation after revocation.
- Preserved legacy MACCMS cookie/JWT sessions during v1 login, retained configured CAPTCHA checks, returned no-store token responses, keyed IP/UA fingerprints, and documented deployment secret configuration.
- Design and plan: `docs/superpowers/specs/2026-09-19-api-v1-session-security-design.md`, `docs/superpowers/plans/2026-09-19-api-v1-session-security.md`.
- Implementation and review fixes through `326515e67b85ac6e7f4509eb9dd4c3e2f99a5cea`.
- GREEN evidence: PHP `35468598811`, MySQL 5.7 `35468598872`, MySQL 8.0 `35468598851`, native video `35468598836`.
- Independent security review found and verified fixes for deterministic device state, secret preflight/isolation, keyed fingerprints, issuer validation, no-store headers, v1 exception handling, legacy-session isolation and CAPTCHA forwarding.

### T-074 Favorites/history/progress DTO and anonymous merge — `DONE`

- RED contract: `855936a557e6ccd393dd2f0c6925dff2cc08fb3d`; implementation and independent-review fixes through `60a6e4bcbfa2f23560804e81b3ad7b917d4bafab`.
- Added authenticated public-ID favorites/history/progress routes and allowlisted DTOs, canonical alias resolution, published-only access, deterministic anonymous merge, advisory locking for native MyISAM `ulog`, duration persistence, episode/range validation, and strict separation from paid type-4 entitlement rows.
- GREEN evidence: PHP regression `35498624705` passed syntax plus baseline, foundation, AI jobs, duplicate/TMDB, admin and API v1 suites. Independent review fixes covered MyISAM serialization, prevalidation, duration persistence, episode/range validation, idempotent favorites, legacy whole-video rows and paid-entitlement isolation.
### T-075 Playback source policy and optional signing — `DONE`

- RED contracts: `1cd5cfdcb7be5723a2fa9599bff64f00dd3cdf58`, `6c8ccf9a90163c6a6acf12ddf69e5e2f45e6a80a`, `666fdcf0f697cdcaf33241cd8ada46bcc329754a`, and `5e294e475da274de52e3d67fc5db99531949cfee`.
- Implementation and independent-review fixes through `04d0269af6395b765b4a640cccd04ce26c8e47a1`.
- Added published canonical playback resolution over the native codec, per-source enablement and exact HTTPS host allowlists, DNS/private-address rejection, optional HMAC signing with configured TTL enforcement, server-only Next.js signer documentation, allowlisted DTOs, safe alias redirects and private no-store delivery responses.
- GREEN evidence: PHP regression `35499394152`; independent final review found no Critical or Important issues.

### T-076 CORS, per-endpoint limits, ETag and invalidation — `DONE`

- RED contracts: `fb9d42a4c670124903a2051763722d1aa4c4e238`, `ec587d42cdd8bc4af91a19ad3a10fa8bcc946914`, `1c39a00ff6b68a5f31ffceea3dc9d5f5892d565e`, `59911141260cd035096398a1ea0b728d039c7a3a`, and review-finding contract `15d13487e9c018189a851312493b18d47066b55b`.
- Implementation and review fixes through `2111a6d285911eef517334e7b63e354cdde4fda9`.
- Added exact HTTPS-origin CORS with route-specific preflight methods, atomic fail-closed endpoint buckets keyed by direct peer IP, public catalog weak ETag revalidation, 304 responses, locale/origin variation and deployment guidance. Authentication and playback responses remain no-store.
- GREEN evidence: PHP regression `35500353097`. Independent review found no Critical and identified three Important issues; normalized prefixed paths, `If-None-Match` preflight support and semantically correct weak validators received RED→GREEN fixes.

### T-077 OpenAPI v1 and contract tests — `DONE`

- RED contract: `383fe26482529924745b4d024a45b3362a748eaf`; independent-review findings contract: `4616957950f78030e9f122ef144c207459098c4c`.
- Implementation and review fixes through `876c969a5c8070d503c3f8e8480c0dd589eaa888`.
- Added a directly consumable OpenAPI 3.0.3 document at `GET /api/v1/openapi.json`, exact route/method and bearer/public security coverage, closed DTO schemas, typed collection/pagination envelopes, stable examples, 304/308 response contracts and original-byte conditional serving.
- GREEN evidence: PHP regression `35500967514`. Independent review found no Critical and five Important issues; raw JSON object identity, anonymous progress merge, flattened video detail, operation-specific response schemas and conditional/canonical responses received RED→GREEN fixes.

Legacy API compatibility is a separate concern; do not expose raw DB records in `/api/v1`.

---

## CP-08 — Events, rankings and recommendations

### T-080 Playback/favorite event contract and deduplication — `DONE`
- Member/session/device identity, fixed event windows, strict validation, database uniqueness and atomic batch ingestion.
- Evidence: PHP regression run `35502082444`; MySQL 5.7 `35502081543`; MySQL 8.0 `35502082452`.
- Final implementation: `6bfac1cbef76f0af2804cb8eaf47c4373fe1a2c1`.

### T-081 Today/7-day/30-day/all-time aggregates — `DONE`
- Bounded unprocessed-event aggregation, UTC windows and durable per-video lifetime totals.
- Final implementation: `7df86964b05800b3dc55f732fb3b9c1b39eb4268`.

### T-082 Deterministic explainable recommendation service — `DONE`
- Stable feature/popularity/freshness scoring with current and unavailable content excluded.
- Final implementation: `c096fa92ce80f2f6fe0b7cfd471ffb2a1c2b7ac3`.

### T-083 Retention, purge and abuse limits — `DONE`
- Ninety-day processed-raw retention, bounded purge, event request/actor limits and Cron command.
- Final implementation: `d085b4c7f9dae9916aabeeb99e06b6fcb0e6ca1e`.

### T-084 Ranking/recommendation API and boundary tests — `DONE`
- Event, ranking and recommendation routes/controllers/OpenAPI plus PHP and MySQL boundary coverage.
- Final implementation: `b17289d92cb8dfc22aaf0486bc244d6da9723b44`.

### CP-08 gate
- [x] Replayed events do not inflate counts.
- [x] Ranking windows are deterministic across UTC date boundaries and lifetime totals survive raw-event purge.
- [x] Recommendations are explainable and exclude current/unavailable content.
- [x] PHP regression, MySQL 5.7 and MySQL 8.0 gates pass.
- [x] Independent review completed; all Critical/Important findings received one RED→GREEN fix pass.

---

## CP-09 — Official communication removal and release hardening

### T-090 Remove official update check and admin update routes — `DONE`
### T-091 Remove/disable announcements, affiliate defaults and telemetry — `DONE`
### T-092 Centralize outbound policy and SSRF controls — `DONE`
### T-093 Security regression suite — `DONE`
### T-094 Full native and programme regression matrix — `DONE`
### T-095 Deployment, migration, Cron, backup and disaster-recovery docs — `DONE`
### T-096 Release candidate acceptance and rollback rehearsal — `DONE`
### T-097 Tag `headless-ai-v1.0.0` — `DONE`
- Annotated tag object: `1a23eb496a0a55b59f6e8fb829fe031f44d09082`.
- Tag target: accepted RC commit `fbd5521d7c688c2e430a7a746e07c771e30eb84e`.

### T-098 Restore installer runtime dependencies and publish `headless-ai-v1.0.1` — `AWAITING_TAG`
- Root cause: `/upload` was fully ignored, while the installer requires `./upload` to exist and be writable.
- Upload-directory fix candidate: `bab28456825e1cf170f3a4b3fb07c61f1a49b581`.
- Captcha-runtime fix candidate: `0b70890bba914dd161672eca681dc4b44eed7a2c`.
- Final evidence: PHP `35517149734`, release matrix `35517149693`, rollback rehearsal `35517149692`.

Release is blocked until prohibited outbound tests pass and both MySQL verification tracks are evidenced.

---


### T-099 Make secondary-development features available after normal installation — `DONE`

- Root cause confirmed: the Web installer imports only the native SQL and never executes `application/data/migrations`; the intelligent-content workspace is not present in the normal admin menu; and `vod_ext` multilingual/advanced fields are not exposed by the native video edit form.
- RED contract: `tests/regression/installed_feature_contract.php`.
- Implemented through `398b00dc8ad4a7b4d773720690c28ab331b27433`: Web installation runs ordered/checksummed migrations before `install.lock`; the normal admin menu opens `ContentWorkspace/view`; native video editing exposes validated `vod_ext` multilingual, TMDB, preview and poster fields; omitted extension payloads preserve existing metadata; manual changes use `FieldGovernance` locks.
- GREEN evidence: PHP regression `35520773620`, MySQL 5.7/8.0 release matrix `35520773720`, and RC rollback rehearsal `35520773582` passed.


### T-100 Traditional Chinese operator usage guide — `DONE`

- Added root `使用說明.md` covering fresh install, existing-site migration, multilingual fields, intelligent-content permissions, AI/TMDB, Cron, API v1, RC acceptance, backup/recovery and troubleshooting.
- Added `tests/regression/usage_guide_contract.php` to keep critical commands, fields, security settings and test-only release status discoverable.

## Current execution pointer

```text
Checkpoint: completion repair
Next task: T-105 relational duplicate merge/restore
Task status: IN_PROGRESS
Starting commit: d818cb3088593a84309ca256bfac68c3501b7960
Release status: headless-ai-v1.0.2 is retained for traceability but is not accepted for deployment
Next action: add idempotent AI enqueue hooks to native admin and collection writes
```

### T-101 Central content workflow coordinator — `DONE`

- Added a transaction-bound coordinator for legal AI, duplicate, TMDB, failure and retry transitions.
- Added deterministic AI/TMDB idempotency keys and post-commit downstream enqueue.
- RED contract: `tests/regression/content_workflow_coordinator.php` (local runner unavailable because this execution image has no PHP binary).
- Commits: `2e8471ecd01116afbbc6c0a68c4e7fed7ff605e1`, `28d060d808223066a3df23ffb2b3334d660ecbc3`, `c25a29843fab9dd85152119ccf5ad7d310506c77`.
- GREEN evidence: PHP 8.1 full regression `35526191574` passed.

### T-102 Native video workflow hooks — `DONE`

- RED contract: `tests/regression/content_workflow_hooks.php`.
- Covers admin create, collection insert/update, deterministic fingerprints, repeated equivalent writes and playback-only updates.
- Implementation: `290eef71b520c2544a0b7de682ec3618057a3fce`; CI environment repair: `0586c2f003700f2d862734a5f95c323ef8a8e04d`.
- GREEN evidence: PHP `35529934949`, native MySQL 5.7 `35529934957`, release matrix MySQL 5.7/8.0 `35529839406`, rollback rehearsal `35529839402`.

### T-103 Reviewed taxonomy suggestions — `DONE`

- RED contract: `tests/regression/taxonomy_suggestions.php`.
- Enforces active existing-term matching, ambiguity/missing review, manual locks and reviewed-only canonical adoption.
- Implementation range: `d0137b1dac52095c783606d4589d514f753ed3e7` through `e4a0c2de4d983c447cd0255d72729f73c66f2beb`.
- GREEN evidence: PHP `35530726030`, MySQL 5.7 `35530726006`, MySQL 8.0 `35530726051`, release matrix `35530629907`, rollback rehearsal `35530629915`.

### T-104 AI/duplicate/TMDB workflow handoff — `DONE`

- Contract: `tests/regression/content_workflow_handoff.php`.
- Coordinator calls occur after stage commits; merged secondary videos never receive downstream TMDB work.
- Implementation: `21856ab6e26d302a4d632f8ec30dad3ff43fd535`.
- GREEN evidence: PHP `35531033623`, MySQL 5.7 `35531033626`, MySQL 8.0 `35531033568`, native `35531033533`, release matrix `35531033595`, rollback `35531033597`.

### T-105 Version-2 relational duplicate merge/restore — `DONE`

- RED contract: `tests/regression/duplicate_relational_merge.php`.
- Scope includes aliases, multilingual rows, taxonomy, external mappings, projection integrity and version-1 restore compatibility.
- Version-2 snapshots retain both immutable pre-merge bundles and the exact merged projection used for conflict detection.
- Merge rules union playback, aliases, taxonomy, language rows and field provenance; primary values win conflicts, while conflicting external mappings remain on the secondary record.
- Restore replaces every changed relationship from the hashed snapshot; legacy version-1 restore remains supported.
- MySQL round-trip: `tests/integration/duplicate_restore_mysql.php` on 5.7 and 8.0.
- Implementation: `e8b5fcd74d72c61d97bac256bd5a46a9dc4c040f`, `8e339621eba1de57c1dc189d2247eee55f6ae2ac`.
- GREEN evidence: PHP `35532349970`, release matrix MySQL 5.7/8.0 `35532349836`, rollback `35532349888`.

Next task: T-106 indexed duplicate search and configurable AI usage/budget

### T-106 Indexed duplicate search and configurable AI usage/budget — `DONE`

- RED contract: `tests/regression/ai_usage_and_duplicate_config.php` (`64b23c89d9cea8c0f11fa035cd7b5168913fa8b1`).
- Duplicate lookup now filters first by TMDB identity or title/year identity and uses only a bounded recent-row fallback.
- Added MySQL lookup indexes for native and extension title/TMDB fields in migration `20260920000700`.
- AI content settings now validate prompt version, retry count/delay, duplicate threshold/limits, token prices and daily budget without rendering the API key.
- OpenAI-compatible, Claude and Gemini token usage is parsed when supplied; missing usage falls back to conservative token estimation and configured micro-cost calculation.
- Daily-budget exhaustion remains checked before the external provider call.
- Implementation: `4a1cc5f300f089f339b5e7088165df2f657e7403`, `c0b8a32bcd3f123d82bb0fe0e3f441d2153cb6a0`, `3e30c4cb8100f2b832c4fbfcbdaba2b96ffd3591`, `7dfde4c373dbf4f93bc2315082324622bda7e88c`, `f4366b9d5a18690e18237d285a060b5b5a9ce232`.
- GREEN evidence: PHP `35533319406`, MySQL 5.7 `35533319413`, MySQL 8.0 `35533319407`, release matrix `35533077996`, rollback `35533077987`.

Next task: T-107 intelligent-content admin operations

### T-107 Intelligent-content admin operations — `DONE`

- Added queued-job pause, paused-job resume and queued-job skip-with-reason controls; running jobs cannot be interrupted.
- Existing terminal-failure retry remains available and preserves attempt history.
- Every control requires exact AI/TMDB permission, explicit confirmation and controller CSRF validation.
- Control actions append immutable audit events; displayed error and skip summaries use existing secret redaction.
- Workspace now links directly to AI pending review and exposes visible job controls.
- RED: `edcce6bd1653ddf2c5e8975801b28748a2fe4789`; implementation: `7b1065b73121e682a21b66cc9b0aed295ab84d4a`, `3ec7cb3893e8192b7878d9e7ba1eed926a9544fc`.
- GREEN evidence: PHP `35534034311`, release matrix MySQL 5.7/8.0 `35534034267`, rollback `35534034274`.

### T-108 Protected idempotent video import API — `DONE`

- Independent HMAC-SHA256 credential uses timestamp, one-time nonce, method, path and body digest; secret is read from `MACCMS_CONTENT_IMPORT_SECRET`.
- Persistent nonce and request ledgers enforce replay protection and payload-bound `Idempotency-Key` results on MySQL 5.7/8.0.
- Import validates size, exact field allowlist and HTTP(S)-only playback URLs; publishing and merge-control fields are rejected.
- Persistence delegates to native `Vod::saveData()`, retaining extension/public-ID creation and the existing single idempotent AI enqueue hook.
- Public OpenAPI uses `playback_sources` and never exposes native IDs or `vod_play_url`.
- RED contract: `d2b7f60aa6f8d2501ca4a578b745f4e903af0eba`; implementation/fixes: `09c35165966373fbcebf359a6311f61d8ab88002` through `f571b7df1aba146e9ba0e5d3cc598c5fd439cae2`.
- GREEN evidence: PHP `35535111718`, MySQL 5.7 `35535111683`, MySQL 8.0 `35535111687`, release matrix `35535111709`, rollback `35535111684`.

### T-109 People and public site-config APIs — `DONE`

- Added `GET /api/v1/people/{slug}` with a stable six-character non-sequential mapping, direct bounded actor lookup and at most 100 SQL-prefiltered related videos.
- Person DTO exposes no internal actor/video IDs and returns only native-active people plus published, unmerged videos; unknown slugs return 404.
- Added `GET /api/v1/site-config` as a closed seven-key DTO sourced from the native runtime configuration; mail, storage, cache, tracking and other secrets are excluded.
- Registered bootstrap routes, cache/rate policies and exact OpenAPI schemas; also repaired the missing T-108 `POST /api/v1/import/videos` bootstrap route.
- RED contract: `83ef36ff3eebff23a8015ed170cd6a121eaba986`; implementation/fixes: `d4645d88e5c7c8a5e33d78e44d29f42ed6b1e2f2` through `801207df9ae719cbcf615d62b02700a4b94da79e`.
- Review fixes: native runtime config RED `976d3c8dcd731a37b8951dccaf6080ab9e14cb2c`; collision-free/bounded person lookup `801207df9ae719cbcf615d62b02700a4b94da79e`.
- GREEN evidence: PHP `35536300731`, MySQL 5.7 `35536300681`, MySQL 8.0 `35536300682`, native `35536300680`, release matrix `35536300688`, rollback `35536300677`.

### T-110 Safe repair command for existing installations — `DONE`

- Added `maccms:repair-content` with mutation-free dry-run, explicit `--apply --confirm=REPAIR`, and a bounded 1–500 row batch.
- Eligible-row filtering prevents completed or protected low-ID records from starving later batches.
- Apply mode locks and re-reads native, extension, manual-lock, job and taxonomy state inside the transaction before changing anything.
- Existing published, merged, manual-review or manually locked videos fail closed; repair taxonomy is staged for review and missing AI work uses the existing idempotent coordinator.
- RED/implementation range: `fc07e3aa08b084be874af818f202a5566785b829` through `72d3c3aa53234353dea1972d27dd98c40fd876c6`.
- Independent-review fixes: `616b0fa093b43cbe7ef65e5cf1aef73d25ec0600` through `56c62db42d62a8d65b0ae2e5fabb4fab899c069b`.
- GREEN evidence: PHP `35537228886`, MySQL 5.7 `35537228865`, MySQL 8.0 `35537228822`, release matrix `35537228874`, rollback `35537228823`.

### T-111 End-to-end lifecycle, documentation and release candidate — `DONE`

- Added `tests/integration/content_lifecycle_mysql.php`, exercising native creation, the production worker registry, AI persistence, reviewed taxonomy, duplicate decision, TMDB worker handoff, manual review, publication and API v1 delivery.
- The same gate verifies idempotent job/taxonomy replay plus version-2 merge/restore for playback, locales, taxonomy and external mappings.
- Independent review found three Important wiring defects: coordinator job names were absent from the worker registry, terminal replay was tested through an illegal transition, and merge snapshots did not include the post-commit workflow handoff projection.
- RED evidence: MySQL 5.7 `35572049151`, MySQL 8.0 `35572049080`, release matrix `35572049132` failed at the missing production `handlerMap`.
- Fixed in `1e340cf10798349bd0cfe98d7ecac4bcf93f05ec` and `6b602fae8a6cf4f807d4df9202956fa24bb4d5e6`; replay verification now exercises idempotent persistence without violating the published terminal state.
- Added Traditional Chinese workflow/repair/import/people/site-config/merge troubleshooting documentation, an operations guide, and the manual lifecycle smoke section.
- GREEN evidence at `5bc9257b884c5d75f5a738170f458054d452f62d`: PHP `35572763658`, MySQL 5.7 `35572763659`, MySQL 8.0 `35572763735`, release matrix `35572763646`, rollback `35572763663`.
- Automated RC gates are complete. Fresh Web/browser smoke remains `NOT_RUN`; therefore `headless-ai-v1.0.3` remains blocked and only `headless-ai-v1.0.3-rc1` may be tagged.

Next task: execute and record the fresh Web/browser smoke checklist before final `headless-ai-v1.0.3`.
