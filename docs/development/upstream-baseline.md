# Upstream baseline

- Upstream repository: `https://github.com/magicblack/maccms10`
- Upstream branch: `master`
- Pinned commit: `4466885edc38744c4a8cbfbea171546dfb67d84d`
- Target repository: `https://github.com/kk2317928/maccms`
- Primary runtime target: PHP 8.1, Nginx, MySQL 5.7/8.0
- License: Apache License 2.0; original notices must remain intact.

This commit is the clean source baseline for the MACCMS Headless AI programme. Product changes belong on `feature/headless-ai-v1` or its phase branches. Upstream updates must be merged into a test branch, reviewed for outbound communication and schema conflicts, and pass the native and extension regression suites before promotion.

## Baseline verification still required

- Fresh installation on MySQL 5.7 and 8.0.
- Admin login and video create/edit.
- Native collection insert and update.
- Member registration/login.
- Native playback parsing and rendering.
- Inventory of all default outbound hosts and triggers.

Results must be appended here with the exact PHP version, database version, command or manual flow, result, and date. Unverified items must not be reported as passing.
