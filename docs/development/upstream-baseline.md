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
2. `tests/regression/user_register_validate.php`

GitHub Actions runs the same command on PHP 8.1. This baseline covers source structure and the existing user-registration validation regression only. It does not connect to MySQL, boot an HTTP server, or prove any manual admin, collection, playback, or API flow.

## Baseline verification still required

- Fresh installation on MySQL 5.7 and 8.0.
- Admin login and video create/edit.
- Native collection insert and update.
- Member registration/login.
- Native playback parsing and rendering.
- Inventory of all default outbound hosts and triggers.

Results must be appended here with the exact PHP version, database version, command or manual flow, result, and date. Unverified items must not be reported as passing.
