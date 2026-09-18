# Capability reconciliation

This document reconciles the pinned MACCMS source with the Headless AI design before new production structures are added. It is based on static inspection of `magicblack/maccms10@4466885edc38744c4a8cbfbea171546dfb67d84d`; runtime database state remains unverified.

## Decision rules

- **reuse**: use the capability without changing its contract.
- **extend**: preserve its current contract and add a compatible boundary, fields, or behavior.
- **replace**: preserve required surrounding compatibility but substitute the named implementation boundary.
- **retire**: remove the capability and do not provide a successor at that boundary.

Each subsystem below has exactly one programme decision. A decision applies only to that subsystem; it does not authorize schema or behavior changes before the relevant checkpoint.

## Decision summary

| Subsystem | Decision | Programme direction |
|---|---|---|
| AI provider and configuration | **extend** | Consolidate calls behind the existing multi-provider boundary and add secure, budgeted job execution. |
| AI content annotation and review | **extend** | Keep the pending-review concept while adding video metadata schema, immutable runs, provenance, locks, and field governance. |
| TMDB, IMDb, Douban and external sync | **extend** | Keep the provider registry/cache/sync foundation and add authoritative matching and enrichment workflow. |
| Multilingual content and fallback | **extend** | Keep `ContentLang` overlays and define video title fields, locales, fallback, provenance, and locks. |
| API and OpenAPI | **extend** | Preserve the legacy API for compatibility and add a separately versioned `/api/v1` DTO surface. |
| Authentication, JWT and sessions | **replace** | Replace the frontend token/session boundary with short-lived access tokens and rotating hashed refresh sessions. |
| Ulog favorites, history and progress | **extend** | Retain native Ulog semantics and add stable progress DTOs and anonymous reconciliation. |
| Recommendations, quality and user profile | **extend** | Reuse the rule-based foundation through new canonical-content and public-ID constraints. |
| Analytics and ranking events | **extend** | Retain generic analytics ingestion/aggregation and add deduplicated playback events and ranking windows. |
| Audit, security and monitoring | **extend** | Preserve existing controls and add explicit coverage for new high-risk actions and outbound clients. |
| S3, media and AI cover | **extend** | Retain upload and cover workflows while adding explicit poster object fields and reversible media provenance. |
| Reward tasks, external sync jobs and content queue | **extend** | Keep current purpose-specific tables and add a separate leased, idempotent content-job queue. |

## 1. AI provider and configuration

- **Existing entrypoints:** `application/common/util/AiProvider.php` resolves configuration, builds provider-specific requests, extracts responses, and performs chat calls. `SeoAi.php` is an older OpenAI-only SEO path. Admin video/article/manga actions, `ContentAnnotator`, `SeoAiGenerate`, and scheduled API timing code call these services.
- **Existing schema/configuration:** provider, API base, key, model, timeout and SSL settings live under the AI portion of `application/extra/maccms.php`; SEO results are stored in `mac_seo_ai_result`.
- **Callers:** admin content controllers, `application/command/SeoAiGenerate.php`, `application/api/controller/Timming.php`, and the annotation pipeline.
- **Gaps:** configuration is not an encrypted secret store; outbound validation, redirect/DNS revalidation, response-size limits, unified retry policy, daily budget, usage accounting, prompt versioning and redacted diagnostics are not one enforced boundary. `SeoAi` can bypass the newer multi-provider abstraction.
- **Decision — extend:** make `AiProvider` the single transport boundary, adapt `SeoAi` callers to it, and add the security, budgeting and observability contract without changing native content writes.

## 2. AI content annotation and review

- **Existing entrypoints:** `ContentAnnotator` builds and validates tag/summary/category suggestions; `ContentAiAnnotation` persists them; `AnnotationAdopter` adopts or rejects them; admin `AiAnnotation` exposes generate/adopt/reject actions.
- **Existing schema:** `mac_content_ai_annotation` has one row per `(mid, content_id)` with suggested tags, summary, type, confidence, source hash, provider/model, status and error. `mac_seo_ai_result` is a separate SEO result store.
- **Callers:** the admin review controller invokes annotation and adoption; content controllers expose related AI actions.
- **Gaps:** the row is mutable rather than an immutable run history; the schema does not cover normalized/original/multilingual titles, aliases, year/media type, taxonomy sets, TMDB clues, token/cost usage or prompt version. Adoption writes main records directly and has no shared precedence rule such as `manual > confirmed_tmdb > ai > import`, per-field provenance, or manual lock enforcement.
- **Decision — extend:** retain the useful pending-review interaction, but route all accepted values through a new field-governance service and immutable run/provenance records. Phase 1 remains video-only even though the legacy annotator also accepts articles.

## 3. TMDB, IMDb, Douban and external sync

- **Existing entrypoints:** `ExternalSourceProviderRegistry` creates `TmdbExternalSourceProvider`, `ImdbExternalSourceProvider`, and `DoubanExternalSourceProvider`; `ExternalSourceRepository` stores provider snapshots, normalized items, cache, mappings and schedules; `ExternalSyncRunner` bootstraps and runs due sync jobs. System configuration and resource/provider callers expose the feature.
- **Existing schema:** `mac_ext_provider`, `mac_ext_source_item`, `mac_ext_source_map`, `mac_ext_search_cache`, `mac_ext_sync_job`, and `mac_ext_sync_log` provide a real external-source foundation.
- **Callers:** system configuration, `ExternalFederationService`, API provide/receive paths, collection-related code, and timing execution call the registry/repository/runner.
- **Gaps:** current TMDB normalization is list-oriented and does not implement locale-ordered candidate search, deterministic year/type/region/cast scoring, full detail/credits/images/videos import, manual ID match, no-match/rematch state, per-field difference review, provenance or field locks. IMDb and Douban must remain optional evidence rather than silently overriding canonical data. Sync jobs lack atomic leasing and idempotency guarantees required for content workflow jobs.
- **Decision — extend:** build matching and enrichment on the provider/repository foundation, with TMDB as the confirmed metadata source and IMDb/Douban as optional clues under the same outbound-security policy.

## 4. Multilingual content and fallback

- **Existing entrypoints:** `application/common/model/ContentLang.php` reads and saves per-module language fields; admin controllers expose translation actions; common content rendering applies language overlays.
- **Existing schema:** `mac_content_lang` stores `(content_mid, content_id, lang_code, field_name, field_value, status)` style overlays. Application language packs cover `zh-cn`, `zh-tw`, English and other UI locales.
- **Callers:** admin Vod, Art, Manga, Actor, Type, Topic, Website, Link and Task controllers, plus common rendering helpers.
- **Gaps:** no explicit phase-1 contract exists for `title_tw`, `title_cn`, `title_en`, `original_title` and aliases; locale fallback order is not a stable API rule; high-frequency titles lack a deliberate storage/indexing decision; translation origin, confidence and locks are absent.
- **Decision — extend:** keep `ContentLang` as the general overlay mechanism, add the video-specific indexed fields selected by the data plan, and make `zh-TW`, `zh-CN`, `en` fallback plus provenance/locks explicit in the future DTO layer.

## 5. API and OpenAPI

- **Existing entrypoints:** `api.php` boots the legacy API module; `application/api/controller/` contains broad content, account, commerce, social, analytics and provider endpoints. Admin `ApiDoc::openapi()` emits a specification built by `OpenApiSpec` with `/api.php` as its default base.
- **Existing schema/contract:** controllers commonly return `{code,msg,info}` and expose native numeric IDs and model-shaped fields. `OpenApiSpec` documents legacy paths for Vod, Art, User, Ulog, Auth, Order, Task and many other modules.
- **Callers:** native themes, existing third-party integrations and administrators using the API documentation may depend on this surface.
- **Gaps:** there is no canonical `/api/v1`, public six-character video ID, uniform error envelope/request ID, explicit DTO allowlist, publication/workflow filtering, locale fallback contract, endpoint CORS/rate policy or precise cache invalidation. The large generated specification is not proof of response privacy.
- **Decision — extend:** leave the legacy API labeled and compatible, then add `/api/v1` routes, DTO mappers and a separate versioned OpenAPI contract. Do not relabel legacy controllers as v1.

## 6. Authentication, JWT and sessions

- **Existing entrypoints:** `application/api/controller/Auth.php` issues and checks login tokens; User endpoints perform registration/login behavior; `application/common/util/JwtService.php` signs and verifies HS256 JWTs using application configuration.
- **Existing schema:** user identity/password state is stored in `mac_user`; no dedicated hashed refresh-token/device-session table was found.
- **Callers:** authenticated API controllers obtain the current user from the existing authorization path; logout is token/client oriented rather than durable session revocation.
- **Gaps:** access tokens are not paired with rotating refresh tokens; there is no durable device session, refresh-token hash, rotation family, replay detection, selective/all-device revocation or frontend login audit. Frontend tokens are not explicitly isolated from administrator sessions.
- **Decision — replace:** preserve native user accounts, password verification and incremental hash upgrades, but replace the headless frontend JWT/login/session boundary with a dedicated access-plus-refresh session service. Legacy authentication remains compatibility-only until migration is documented.

## 7. Ulog favorites, history and progress

- **Existing entrypoints:** `application/api/controller/Ulog.php`, `application/common/model/Ulog.php`, and `application/common/validate/Ulog.php` implement list/save/delete behavior for Ulog types such as favorites and playback records.
- **Existing schema:** `mac_ulog` keys user/content relationships with `ulog_mid`, `ulog_type`, `ulog_rid` and includes playback position/duration fields used by `UserProfileBuilder`.
- **Callers:** API Ulog endpoints, user pages, playback flows, recommendation profile generation and analytics-related behavior read these records.
- **Gaps:** the public contract still depends on numeric content IDs; source and episode identity are not normalized into a stable progress DTO; anonymous local progress has no timestamp-based account merge; canonical duplicate redirects and last-write conflict rules are undefined.
- **Decision — extend:** keep Ulog as the compatibility store, add a service boundary for favorites/recent/progress keyed externally by public ID, and define source/episode/progress/duration plus anonymous reconciliation semantics.

## 8. Recommendations, content quality and user profile

- **Existing entrypoints:** API `Recommend` reads `mac_user_profile` and `mac_content_quality`; `UserProfileBuilder` derives preferences from Ulog/orders; `ContentQualityScorer` computes deterministic behavior, interaction, completeness and freshness scores; admin `ContentQuality` operates scoring workflows.
- **Existing schema:** `mac_content_quality` stores component and total scores; `mac_user_profile` stores preferred types/tags, completion, activity and spending-window aggregates.
- **Callers:** recommendation API, scheduled/timing aggregation and the content-quality admin page.
- **Gaps:** recommendations return numeric `vod_id`; signals are legacy category/tag based and do not yet include the planned region/people relations or explicit explanations. Exclusion of merged, rejected, hidden and current content is not guaranteed by a single canonical filter, and ranking/event deduplication is separate.
- **Decision — extend:** retain the deterministic scoring/profile foundation, introduce one availability/canonical-content filter and public-ID DTO mapping, then add the planned genre/region/tag/people/popularity/freshness rule set with explanation data.

## 9. Analytics and ranking events

- **Existing entrypoints:** API `Analytics` accepts session, pageview and generic event writes with allowlists/rate policies; `AnalyticsAggregator` builds daily/hourly aggregates; admin `Analytics` displays results.
- **Existing schema:** `mac_analytics_session`, `mac_analytics_pageview`, `mac_analytics_event`, `mac_analytics_content_day`, day/hour dimension tables, overview and retention cohorts are present.
- **Callers:** public analytics ingestion, monitoring/aggregation jobs, content-quality scoring and administrator reports.
- **Gaps:** generic events do not provide the required member/session/device idempotency windows for play-start, valid-watch, progress, completion and favorite events. Today/7-day/30-day/all-time video rankings, replay resistance, raw-event retention and canonical duplicate handling are not one reproducible pipeline.
- **Decision — extend:** keep generic analytics and aggregation, add explicit playback event identities/deduplication and derived ranking aggregates rather than inventing an unrelated second telemetry stack.

## 10. Audit, security and monitoring

- **Existing entrypoints:** request behaviors include `AdminAudit`, `MonitorRequest`, `RequestSecurity`, `CsrfGuard`, `SecurityHeaders` and `AntiScrape`; admin controllers expose audit, safety and monitor pages; monitoring utilities collect metrics, alerts and runtime state.
- **Existing schema:** `mac_admin_audit_log`, `mac_user_access_log`, monitor metric/state/alert rule/event tables and related configuration already exist.
- **Callers:** framework behavior hooks cover admin and request lifecycles; security/monitor controllers and Cron-style monitoring code consume stored data.
- **Gaps:** new AI key changes, batch overwrite, TMDB decisions, duplicate merge/restore, publication and refresh-session revocation need explicit action schemas and secret redaction. Existing network clients do not share one SSRF/redirect/DNS policy. Permission checks and audit completeness for the future workspace remain unproven.
- **Decision — extend:** preserve these controls and require every new high-risk action and outbound client to integrate with centralized permissions, structured audit entries, redaction and monitoring.

## 11. S3, media and AI cover

- **Existing entrypoints:** `application/common/extend/upload/S3.php` uploads through the configured S3 client; `VodAiCover` generates, applies and reverts AI covers; admin Vod exposes generate/revert actions and system configuration embeds cover settings.
- **Existing schema:** `mac_vod` includes `vod_pic`, `vod_pic_thumb` and `vod_pic_original`; upload configuration exists, but explicit `poster_s3` and `old_poster_s3` target fields do not.
- **Callers:** upload adapters, admin video editing, resource-hub/poster tooling and AI cover actions.
- **Gaps:** S3 upload success is not represented as a stable poster object contract; prior/current poster provenance, checksums and reversible replacement are incomplete; AI cover transport and storage must share outbound safety, size/type checks and audit policy.
- **Decision — extend:** retain the S3 adapter and reversible cover interaction, then add explicit poster object fields and provenance while preserving native `vod_pic*` compatibility.

## 12. Reward tasks, external sync jobs and content queue

- **Existing entrypoints:** admin/API Task controllers and `Task`/`TaskLog` models implement member rewards; `ExternalSyncRunner` and `ExternalSourceRepository` operate external provider schedules. Only `SeoAiGenerate` is registered in `application/command.php`.
- **Existing schema:** `mac_task` and `mac_task_log` are daily/new-user reward definitions and member completion/claim records. `mac_ext_sync_job` and `mac_ext_sync_log` schedule provider feed sync with status, next-run, interval and retry fields. A general `mac_content_job` table does not exist.
- **Callers:** member task APIs/admin pages use reward tables; the external-source timing path uses sync jobs; SEO AI has its own command and persistence.
- **Gaps:** neither subsystem provides content job type/payload/priority, atomic claim owner and lease expiry, maximum attempts/backoff, idempotency key, per-run record, redacted error classification, or a bounded `maccms:jobs` worker. Reusing reward tables would corrupt their domain meaning; external sync scheduling does not safely coordinate concurrent content workers.
- **Decision — extend:** keep both purpose-specific subsystems unchanged and add dedicated `content_job`/`content_job_run` tables plus a short-lived command. External sync may enqueue content work through an adapter later, but it remains the owner of provider feed schedules.

## Cross-subsystem boundaries fixed by this review

1. Native `mac_vod`, collection writes, membership, Ulog and `vod_play_*` remain compatibility boundaries.
2. New APIs expose public IDs and DTO allowlists, never raw rows or internal numeric video IDs.
3. AI, TMDB and imports propose values; the field-governance service alone applies values according to provenance and locks.
4. Generic analytics remains the ingestion foundation; ranking semantics add deduplicated event identity and canonical-content filtering.
5. Reward tasks, external-source schedules and content processing are distinct domains with distinct tables.
6. All external AI, metadata and media clients must use the same configured outbound-security and secret-redaction policy.

## Verification limits

- This inventory is a source-level reconciliation, not a runtime acceptance result.
- No MySQL 5.7/8.0 database was created or migrated for this task.
- No remote AI, TMDB, IMDb, Douban or S3 request was made.
- Native admin, collection, playback and API smoke flows remain unverified until the named disposable environment in CP-01/CP-03 is available.
