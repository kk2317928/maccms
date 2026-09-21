# Native compatibility smoke-test checklist

This checklist protects the pinned MACCMS behavior while Headless AI features are added. It is a manual acceptance procedure, not evidence of a completed run. Every case below is **UNVERIFIED** until its result is recorded against an explicitly named disposable environment.

## Required environment record

Create one record per database/runtime combination before executing any case:

| Field | Required value |
|---|---|
| Environment name | Unique disposable name, never “local” or “test” alone |
| Git commit | Full SHA from `feature/headless-ai-v1` |
| Test date/operator | ISO date/time and operator identifier |
| Base URL | Disposable HTTP(S) origin |
| PHP | Exact `php -v` version and SAPI |
| Web server | Product and version |
| Database | MySQL 5.7.x or 8.0.x exact version and disposable database name |
| Browser/client | Browser version plus curl/API client version |
| Outbound capture | Capture/proxy/firewall log location or “not captured” |
| Backup/cleanup | Snapshot identifier and destruction confirmation |

Do not use production credentials, media, member records, buckets, payment gateways, SMS, SMTP, AI or metadata-provider keys. Keep all optional outbound integrations disabled unless a case explicitly tests a controlled stub.

## Preconditions

1. Verify the commit SHA and run `php tests/regression/run_baseline.php` successfully on the target host.
2. Create a new empty disposable database with `utf8mb4`; grant only the permissions required by installation.
3. Route mail, SMS, payment, push, collection and remote metadata to disabled settings or controlled local stubs.
4. Capture application/web/PHP/database logs from the start of the run.
5. Prepare unique markers such as `SMOKE-{environment}-{timestamp}` so created records can be traced and removed.
6. Record every case as `PASS`, `FAIL`, `BLOCKED`, or `NOT_RUN`; attach evidence. Never infer a result from another environment.

## A. Fresh installation

**Status: UNVERIFIED**

1. Open `install.php` on a clean checkout and empty disposable database.
2. Complete requirements validation and database/admin configuration using disposable credentials.
3. Confirm installation reaches its success page without PHP warning/fatal output.
4. Confirm the configured table prefix exists and core tables including admin, vod, user and ulog were created.
5. Reload the public home page, `api.php`, and the renamed admin entrance.
6. Confirm no repeated installer mutation occurs and the installer is no longer openly reusable.

Evidence: screenshots, HTTP status, install log, exact table count, selected core-table existence queries, and outbound capture.

Run separately on MySQL 5.7 and MySQL 8.0; one result cannot substitute for the other.

## B. Administrator login and permission boundary

**Status: UNVERIFIED**

1. Visit the renamed admin entrance while signed out and confirm the login page loads.
2. Submit an invalid password and confirm access is denied without disclosing sensitive details.
3. Sign in with the disposable super administrator and confirm the dashboard loads.
4. Create a restricted role/admin lacking video-edit permission.
5. Sign in as that administrator and attempt video create, video edit, batch action and system configuration by both UI and direct URL.
6. Confirm each unauthorized action is denied and authorized read-only pages remain usable.
7. Sign out and confirm protected pages no longer accept the previous session.

Evidence: account/role identifiers, response status/body summary, screenshots and relevant admin audit rows.

## C. Native admin video create and edit

**Status: UNVERIFIED**

1. As an authorized administrator, create a video with the unique marker, category, year, area, poster and two playback sources containing at least two episodes each.
2. Confirm save succeeds through the native admin form and capture the new numeric `vod_id` only in private test evidence.
3. Reopen the record and verify all native fields and `vod_play_*` ordering round-trip unchanged.
4. Edit title, remarks and one episode URL; save and reopen again.
5. Confirm unrelated fields/sources remain unchanged and the public detail/play pages reflect the edit.

Evidence: before/after form exports or screenshots, selected database row, HTTP responses and application log excerpt.

## D. Native collection insert and update

**Status: UNVERIFIED**

1. Configure a controlled local collection source returning one uniquely marked video with deterministic playback data.
2. Run collection once and confirm exactly one video is inserted.
3. Change the source title/remarks and one playback episode according to the configured update rule.
4. Run collection again and confirm the existing video is updated rather than duplicated.
5. Confirm collection return messages/counts remain accurate and native playback delimiters preserve source/episode order.
6. Disable/remove the collection source after the case.

Evidence: sanitized source fixture, collection configuration/update rule, both run outputs, row count and before/after database rows.

## E. Member register, login and logout

**Status: UNVERIFIED**

1. With registration enabled and external verification disabled or stubbed, register a uniquely named member with password confirmation.
2. Confirm duplicate username/email/phone behavior follows configuration and no plaintext password appears in storage/logs.
3. Sign out, attempt an invalid password, then sign in with the correct password.
4. Access one authenticated member page and `api.php/auth/me` or equivalent identity endpoint.
5. Log out using the native endpoint and confirm authenticated pages/API no longer accept the session/token.

Evidence: sanitized requests/responses, member ID, password-hash prefix only, access-log entries and post-logout denial.

## F. Playback

**Status: UNVERIFIED**

1. Open the test video detail page and select every created source/episode.
2. Confirm the generated route targets the intended `sid`/`nid`, source label and episode name.
3. Confirm `mac_play_list` interpretation matches the stored `vod_play_from`, `vod_play_url`, `vod_play_server` and `vod_play_note` order.
4. Verify free playback succeeds with the controlled media fixture; verify disabled, password-protected or points-protected behavior if configured for the case.
5. Confirm malformed or missing episode coordinates fail safely without exposing filesystem/configuration details.

Evidence: stored playback strings, resolved list summary, player/network screenshot and relevant HTTP/application logs.

## G. Legacy API video list and detail

**Status: UNVERIFIED**

1. Request the legacy video list endpoint through `api.php` with a small page size and the test category.
2. Confirm a successful JSON response, stable pagination fields and presence of the published test video.
3. Request detail for its numeric ID and confirm title/category/poster/remarks and parsed playback list match native data.
4. Confirm raw `vod_play_url`, server and note fields are not unintentionally exposed where the controller removes them.
5. Request an unknown ID and invalid pagination/filter values; confirm controlled JSON errors rather than HTML/PHP output.

Evidence: exact sanitized curl commands, status/headers and response files.

## H. Ulog progress and continuation

**Status: UNVERIFIED**

1. Sign in as the disposable member and POST progress for the test video with source, episode, position and duration.
2. GET progress and confirm the saved coordinates and bounded numeric values.
3. Submit a later position and confirm the expected update/last-write behavior.
4. Confirm the video appears in the continuation list with a valid detail/play link.
5. Delete progress and confirm get/continuation no longer returns it.
6. Repeat one write signed out and confirm authentication is required; test anonymous merge only when that feature is implemented.

Evidence: sanitized requests/responses and the corresponding `mac_ulog` before/update/delete rows.

## I. Outbound communication observation

**Status: UNVERIFIED**

During installation, login, dashboard load, video save, collection, playback and scheduled commands, review capture logs for destinations. Record any connection attempt not explicitly required by the controlled case. In particular, detect `update.maccms.la`, `www.maccms.la/tongji.html`, official cloud catalogs, remote update archives and dynamic scripts.

Until CP-09 removal, discovery of the known official request is an expected security failure, not a passing result.

## J. Headless AI lifecycle and administrator review

**Status: UNVERIFIED**

1. Create one uniquely marked video through the native administrator form and confirm the multilingual/advanced fields persist after reopening.
2. Confirm exactly one AI job appears; run the worker against controlled AI/TMDB stubs and confirm taxonomy suggestions and a duplicate-review state appear.
3. Accept one uniquely matched region and genre; confirm native taxonomy projection updates only after review.
4. Create a duplicate fixture, open the duplicate workspace, merge it into the primary, and verify playback, aliases, locales, taxonomy and external mappings are preserved.
5. Restore that merge and confirm both records and their relations match the pre-merge evidence.
6. Resolve the duplicate branch as different works, select a TMDB candidate or record no match, close all AI/taxonomy decisions, and reach manual review.
7. Preview and publish with explicit confirmation; verify API v1 detail is available by public ID while internal IDs and secrets remain absent.
8. Run a signed disposable import twice with the same idempotency key/body and confirm the same result, one video and one AI job.
9. Run `maccms:repair-content` in dry-run mode and confirm no database change; do not apply repair to the smoke fixture unless separately approved.
10. Confirm `GET /api/v1/people/{slug}` returns only published related videos and `GET /api/v1/site-config` returns only public allowlisted keys.

Evidence: screenshots for each workspace stage, sanitized request/response bodies, job/candidate/review identifiers, before/after relation counts, audit events and API responses.

## Result table

| Case | Status | Evidence reference | Failure/observation |
|---|---|---|---|
| A Fresh installation | NOT_RUN | — | No disposable environment recorded |
| B Admin login/permissions | NOT_RUN | — | No disposable environment recorded |
| C Admin video create/edit | NOT_RUN | — | No disposable environment recorded |
| D Collection insert/update | NOT_RUN | — | No disposable environment recorded |
| E Member register/login/logout | NOT_RUN | — | No disposable environment recorded |
| F Playback | NOT_RUN | — | No disposable environment recorded |
| G API video list/detail | NOT_RUN | — | No disposable environment recorded |
| H Ulog progress | NOT_RUN | — | No disposable environment recorded |
| I Outbound observation | NOT_RUN | — | No disposable environment recorded |
| J Headless AI lifecycle | NOT_RUN | — | No disposable environment recorded |

## Cleanup

1. Export the evidence needed for the task record without retaining secrets.
2. Remove test collection sources, accounts, content, files and object-storage artifacts.
3. Destroy the disposable database and environment; record the time and operator.
4. If a failure is retained for diagnosis, label the environment, restrict access and record its expiry instead of claiming cleanup.
