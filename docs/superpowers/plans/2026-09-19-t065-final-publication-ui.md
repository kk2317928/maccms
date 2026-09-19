# T-065 Final Validation and Publication UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fail-closed administrator workspace that previews a `manual_review` video, reports publication blockers and warnings, and atomically publishes valid canonical content only after exact authorization and explicit confirmation.

**Architecture:** A focused `FinalPublicationWorkspace` reads native video, `vod_ext`, multilingual overlays, taxonomy and decoded playback into one escaped preview contract. A `FinalPublicationService` re-locks and revalidates the video and extension rows inside a transaction, changes the separate workflow state to `published`, activates native `vod_status`, and appends the immutable audit event before commit. `ContentWorkspace::publish()` remains a thin CSRF/policy/controller boundary and renders a new `view_new` template.

**Tech Stack:** PHP 8.1, ThinkPHP 5-era DB/query APIs, MySQL 5.7/8.0, native MACCMS video/playback fields, standalone PHP regression scripts.

**Spec:** `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`

## Global Constraints

- `mac_vod` and native `vod_play_*` remain the MACCMS compatibility source of truth.
- `vod_ext.workflow_status` remains separate from native `vod_status`.
- Only `manual_review -> published` is allowed; merged, rejected, already-published, invalid-public-ID, and stale rows fail closed.
- Publication requires exact `content_workspace/publish` authorization, stable CSRF validation, and `confirmed=1`.
- The transaction must roll back content and workflow changes if validation, concurrency checks, or immutable audit persistence fails.
- New UI files belong under `application/admin/view_new`; no obsolete parallel view tree.
- Primary runtime is PHP 8.1 with MySQL 5.7 and 8.0 compatibility; add no dependency.

## Review Focus

- A valid-looking row whose `workflow_status` changes after preview must be rejected after row locking; covered by the stale-state publication test.
- Playback containing an unsafe scheme or no playable episode must remain a blocker; covered by malformed-playback tests.
- A merged/noncanonical extension row must never publish even when native metadata is complete; covered by canonical-identity tests.
- An audit insert failure must roll back `vod_status`, `workflow_status`, and `published_at`; covered by transaction callback tests.
- Missing optional locale/image/trailer metadata must be warnings rather than blockers; covered by severity-classification tests.

---

### Task 1: Pin the publication contract with a failing regression

**Files:**
- Create: `tests/regression/final_publication_ui.php`
- Modify: `tests/regression/run_admin.php`
- Modify: `tasks.md`
- Modify: `CURRENT_STATE.md`

**Interfaces:**
- Consumes: `VodWorkflow::assertTransition()`, `VodPlaybackCodec::decode()`, `ContentAdminPolicy::assertAllowed()`.
- Produces: executable expectations for `FinalPublicationWorkspace::preview(int): array`, `FinalPublicationWorkspace::queue(int,int): array`, and `FinalPublicationService::publish(int,int,string,array,bool): array`.

- [ ] **Step 1: Mark T-065 active and write the failing regression**

  Add fixture-driven assertions for queue filtering, multilingual/taxonomy/media/playback preview, blocker/warning classification, exact permission and confirmation, stable CSRF/controller structure, row locks, transaction boundaries, state/native activation, audit metadata, escaping, and dashboard discoverability.

- [ ] **Step 2: Run the focused test to verify RED**

  Run: `php tests/regression/final_publication_ui.php`

  Expected: FAIL because `FinalPublicationWorkspace.php`, `FinalPublicationService.php`, the controller action, and template do not exist.

- [ ] **Step 3: Check and publish the RED commit**

  Run: `git diff --check && git add CURRENT_STATE.md tasks.md tests/regression/final_publication_ui.php tests/regression/run_admin.php && git commit -m "test: define final publication workspace contract" && git push origin HEAD:feature/headless-ai-v1`

  Expected: the remote PHP workflow fails specifically at the new regression, preserving evidence that the test detects the missing behavior.

### Task 2: Build preview validation and atomic publication

**Files:**
- Create: `application/common/util/FinalPublicationWorkspace.php`
- Create: `application/common/util/FinalPublicationService.php`
- Modify: `application/admin/controller/ContentWorkspace.php`
- Create: `application/admin/view_new/content_workspace/publish.html`
- Modify: `application/admin/view_new/content_workspace/index.html`
- Modify: `application/admin/controller/Base.php` only if exact action normalization requires an explicit mapping.
- Test: `tests/regression/final_publication_ui.php`

**Interfaces:**
- Consumes: native `vod` rows, `vod_ext`, `ContentLang`, `vod_meta_term`, `VodPlaybackCodec`, `VodWorkflow`, `ContentAdminPolicy`, and `ContentAdminAudit`.
- Produces: `preview()` with `video`, `ext`, `locales`, `taxonomy`, `media`, `playback`, `blockers`, `warnings`, and `publishable`; `publish()` returning `vod_id`, `public_id`, `workflow_status`, `vod_status`, and `published_at`.

- [ ] **Step 1: Implement the read-only workspace**

  Query only `manual_review` rows in `queue()`. In `preview()`, validate six-character public identity, nonmerged canonical state, main title, mapped taxonomy/region, decoded playable URLs, and safe playback; classify absent secondary locales, poster/backdrop, trailer, and optional metadata as warnings.

- [ ] **Step 2: Implement the transaction service**

  Start a transaction, lock native video and extension rows, rebuild the preview from locked data, reject all blockers and stale/nonmanual states, call `VodWorkflow::assertTransition('manual_review', 'published')`, update `vod_status=1`, `vod_publish_time=0`, `workflow_status='published'`, and `published_at`, then append `content.publish` audit data before commit. Catch any failure, roll back, and rethrow.

- [ ] **Step 3: Add the controller and escaped UI**

  Add `ContentWorkspace::publish()` with stable/legacy CSRF compatibility, exact grant normalization, `ContentAdminPolicy::assertAllowed('publish', $grants, $confirmed)`, generic failure messages, queue/preview assignments, and the `content_workspace/publish` template. Render blockers separately from warnings, disable publication when blockers exist, and require a visible confirmation control.

- [ ] **Step 4: Run focused verification to GREEN**

  Run: `php -l application/common/util/FinalPublicationWorkspace.php && php -l application/common/util/FinalPublicationService.php && php -l application/admin/controller/ContentWorkspace.php && php tests/regression/final_publication_ui.php && git diff --check`

  Expected: all commands PASS.

- [ ] **Step 5: Commit and push implementation**

  Run: `git add application/common/util/FinalPublicationWorkspace.php application/common/util/FinalPublicationService.php application/admin/controller/ContentWorkspace.php application/admin/view_new/content_workspace/publish.html application/admin/view_new/content_workspace/index.html application/admin/controller/Base.php tests/regression/final_publication_ui.php && git commit -m "feat: add final publication workspace" && git push origin HEAD:feature/headless-ai-v1`

### Task 3: Verify, review, and close T-065

**Files:**
- Modify: `tasks.md`
- Modify: `CURRENT_STATE.md`
- Modify: `docs/development/source-inventory.md` if the verified utility count changes.

**Interfaces:**
- Consumes: the complete T-065 implementation and CI evidence.
- Produces: resumable task status, remote commit SHAs, workflow run IDs, and the next checkpoint action.

- [ ] **Step 1: Run the full local admin regression gate**

  Run: `php tests/regression/run_admin.php && git diff --check`

  Expected: all admin regressions PASS in deterministic order.

- [ ] **Step 2: Run independent code review**

  Use `superpowers:requesting-code-review` against the T-065 diff. Resolve every critical or important finding with a failing regression before changing implementation, then rerun the focused and full gates.

- [ ] **Step 3: Verify remote workflows**

  Confirm the pushed implementation SHA is the head of `feature/headless-ai-v1` and the repository's PHP and applicable MySQL/native compatibility workflows pass. Record exact run IDs; never substitute a local-only result.

- [ ] **Step 4: Close documentation and push**

  Mark T-065 `DONE` with RED/GREEN/final SHAs and verification evidence; update current-state version, last task, verification, source count, and next action to T-066. Commit as `docs: complete T-065 publication workspace` and push immediately.

- [ ] **Step 5: Confirm final remote state**

  Run read-only remote checks proving the documentation commit is the integration branch head, the worktree is clean, and no workflow is failing or pending before reporting completion.
