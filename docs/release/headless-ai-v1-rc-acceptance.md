# Headless AI v1.0.3 release-candidate acceptance

Status: AUTOMATED ACCEPTED / MANUAL SMOKE PENDING  
Candidate branch: `feature/headless-ai-v1`  
Verified implementation SHA: `5bc9257b884c5d75f5a738170f458054d452f62d`  
Acceptance date: 2026-09-21  
Permitted tag: `headless-ai-v1.0.3-rc1` only  
Final tag: `headless-ai-v1.0.3` is blocked until the fresh Web/browser checklist is recorded.

## Acceptance evidence

| Gate | Result | Evidence |
|---|---|---|
| PHP 8.1 regression | PASS | GitHub Actions `35572763658` |
| MySQL 5.7 focused lifecycle | PASS | GitHub Actions `35572763659` |
| MySQL 8.0 focused lifecycle | PASS | GitHub Actions `35572763735` |
| MySQL 5.7/8.0 release matrix | PASS | GitHub Actions `35572763646` |
| Native admin/collection/playback | PASS | Executed on both release-matrix database families in `35572763646` |
| Complete Headless AI lifecycle | PASS | `tests/integration/content_lifecycle_mysql.php` through production worker dispatch |
| API v1/OpenAPI/security/outbound | PASS | Programme suites and outbound enforcement in `35572763646` |
| Package/install dependency contracts | PASS | PHP regression `35572763658` |
| Database/application rollback | PASS | RC rollback rehearsal `35572763663` |
| Independent review | PASS after fixes | Three Important findings received one RED→GREEN fix pass; no Critical findings |
| Fresh Web/browser smoke | NOT_RUN | `docs/testing/native-smoke-checklist.md` remains the manual gate |

## Lifecycle scope accepted

The automated lifecycle creates a video through the native model and confirms one AI job. The production worker registry consumes the coordinated `ai_normalize` and `tmdb_review` job types while retaining historical dotted aliases. The test persists an AI run, stages and reviews taxonomy, resolves the duplicate branch, records and selects a TMDB candidate, enters manual review, publishes, and reads the public API v1 DTO.

Replay checks verify that job and taxonomy uniqueness are retained without attempting an illegal transition out of the terminal `published` state.

The version-2 merge fixture uses the same workflow coordinator as the administrator controller. It verifies merged playback, locale and taxonomy projections, then restores both videos and relations. The snapshot projection/hash is refreshed after the post-commit workflow handoff so an immediate legitimate restore does not report a false conflict.

## Operational scope accepted

The Traditional Chinese usage guide and deployment documentation cover:

- automatic workflow and human review boundaries;
- protected HMAC video import and idempotency;
- people and public site-config APIs;
- safe existing-content repair with dry-run and explicit confirmation;
- version-2 merge restoration and conflict handling;
- queue/workflow troubleshooting and secret-safe diagnostics.

## Known limitations and manual gate

- Browser-driven installation, administrator login, menu visibility, multilingual form persistence, collection UI, playback and permission behavior still require environment-specific smoke testing.
- SMTP, SMS, payment, S3 and push integrations remain disabled unless separately configured and accepted.
- Large existing `mac_vod` tables may require an extended maintenance window for InnoDB conversion.
- The legacy broad API remains available alongside `/api/v1`; no deprecation date is declared.
- Firewall/proxy capture remains an operator responsibility during rollout.

## Release decision

The automated gates authorize creation of the immutable test tag `headless-ai-v1.0.3-rc1`. This candidate is not approved for production deployment. Do not create `headless-ai-v1.0.3` until every required manual case is marked PASS with the environment, commit, operator and evidence recorded in `docs/testing/native-smoke-checklist.md`.
