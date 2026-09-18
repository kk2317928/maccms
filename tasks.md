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
| CP-05 | Add duplicate review, reversible merge and TMDB workflow | READY | CP-04 |
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

### T-052 Merge snapshots and playback merge — `IN_PROGRESS`
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
Checkpoint: CP-04
Next task: T-041
Task status: IN_PROGRESS
Required starting state: feature/headless-ai-v1 at `8a2c3433e55a1b5e59c1db649546df3ce704ba75`
First verification: PHP regression plus MySQL 5.7/8.0 content-job lifecycle
Task commit: test: define content job lifecycle contract
Push required: yes, immediately after task verification
```
