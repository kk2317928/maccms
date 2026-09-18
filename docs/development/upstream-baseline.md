# Upstream baseline

- Upstream repository: `https://github.com/magicblack/maccms10`
- Upstream branch: `master`
- Pinned commit: `4466885edc38744c4a8cbfbea171546dfb67d84d`
- Target repository: `https://github.com/kk2317928/maccms`
- Primary runtime target: PHP 8.1, Nginx, MySQL 5.7/8.0
- License: Apache License 2.0; original notices must remain intact.

This commit is the clean source baseline for the MACCMS Headless AI programme. Product changes belong on `feature/headless-ai-v1` or its phase branches. Upstream updates must be merged into a test branch, reviewed for outbound communication and schema conflicts, and pass the native and extension regression suites before promotion.

## Automated regression baseline

Run the safe, database-free baseline from the repository root:

```bash
php tests/regression/run_baseline.php
```

The runner records the active PHP version, then executes these scripts in a fixed order and stops at the first nonzero exit:

1. `tests/regression/source_inventory.php`
2. `tests/regression/outbound_inventory.php`
3. `tests/regression/user_register_validate.php`

GitHub Actions runs the same command on PHP 8.1. This baseline covers source structure, the static/quarantined outbound inventory and the existing user-registration validation regression. It does not connect to MySQL, boot an HTTP server, or prove any manual admin, collection, playback, or API flow.

The repeatable manual procedure is `docs/testing/native-smoke-checklist.md`. Results remain `UNVERIFIED`/`NOT_RUN` until appended with an explicitly named disposable environment and exact runtime/database versions.

## Baseline verification still required

- Fresh installation on MySQL 5.7 and 8.0.
- Admin login and video create/edit.
- Native collection insert and update.
- Member registration/login.
- Native playback parsing and rendering.
- Runtime network-capture verification of default outbound triggers.

Results must be appended here with the exact PHP version, database version, command or manual flow, result, and date. Unverified items must not be reported as passing.

## Foundation verification results — 2026-09-18

| Disposable environment | Commit | Runtime/database | First migration | Second migration | Native admin boundary | Collection insert/update | Playback round trip |
|---|---|---|---|---|---|---|---|
| `maccms_ci_57` | `a24acb7dd716bb4284c5db4c8506c0496857f4ec` | PHP 8.1 / MySQL 5.7.44 | PASS: applied 1 | PASS: applied 0, skipped 1 | NOT_RUN | NOT_RUN | NOT_RUN |
| `maccms_ci_80` | `3c517f1fe34034127e1e21cdc1a386450574a6a1` | PHP 8.1 / MySQL 8.0.46 | PASS: applied 1 | PASS: applied 0, skipped 1 | NOT_RUN | NOT_RUN | NOT_RUN |
| `maccms_ci_native` | `48ff96c0721bce1de1791b7373db3e06665864a6` | PHP 8.1 / MySQL 5.7.44 | PASS: applied 1 after full schema import | NOT_RUN in this workflow | PASS: `Vod::saveData()` created one draft and one extension row | PASS: insert/update retained one native row and one extension row | PASS: both rows, all four `vod_play_*` strings byte-identical |

Evidence: GitHub Actions runs `35348332012`, `35348806475`, `35348806492`, `35349617886`, and `35349617888`. The native integration test invokes the same model persistence boundaries used by admin and collection code; it is not a browser/admin-form test. Manual HTTP, authentication, rendering, and outbound-observation cases remain `NOT_RUN` in `docs/testing/native-smoke-checklist.md`.
