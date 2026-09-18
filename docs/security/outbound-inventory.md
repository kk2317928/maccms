# Outbound communication inventory

This source-level inventory covers PHP, JavaScript, templates, add-ons and default configuration in the pinned repository. It separates browser navigation/media retrieval from server-side communication, records nonessential official traffic, and does not claim that any endpoint was contacted during verification.

## Classification policy

| Classification | Meaning |
|---|---|
| **required/configured** | Needed only for an administrator-enabled product feature or user-requested content; credentials/destination must be explicit. |
| **optional disabled-by-default** | Nonessential integration that must make no request until explicitly enabled. |
| **prohibited** | Official update, announcement, affiliate, telemetry or executable-download communication that must be removed before release. |
| **test/documentation-only** | Examples, standards namespaces, attribution or documentation links that are not runtime calls. |

## Endpoint families

| Family and representative destinations | Classification | Main callers and trigger | Required treatment |
|---|---|---|---|
| AI providers: `api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`, `api.deepseek.com`, `dashscope.aliyuncs.com`, `open.bigmodel.cn`, and administrator-supplied compatible base URLs | **required/configured** | `AiProvider`, `SeoAi`, `AiSearch`, `AiChatService`, `AiEmbeddingService`, `VodAiCover`, `UeditorAiProxy`, ThemeDesign, AdminAssistant and `addons/aicontent`; explicit AI actions/jobs | Disabled without provider/key; require HTTPS, host/IP validation, timeout, response cap and secret redaction. |
| TMDB API/images: `api.themoviedb.org`, `image.tmdb.org`, `www.themoviedb.org` | **required/configured** | `TmdbExternalSourceProvider` and `ExternalFederationService`; configured search/sync and later enrichment | Disabled without TMDB enable/key; API/image hosts are distinct allowlist entries. |
| IMDb and Douban lookup: `v3.sg.media-imdb.com`, `www.imdb.com`, `movie.douban.com` | **optional disabled-by-default** | external-source providers; administrator-enabled search/feed sync | Treat results as clues; bound redirects/body size and never forward unrelated secrets. |
| S3/object upload: administrator-selected S3 endpoint and bucket | **required/configured** | `application/common/extend/upload/S3.php`; explicit upload/media operation | Require explicit credentials/region/endpoint, TLS by default, safe object keys and redacted failures. |
| SMTP and SMS providers, including Aliyun/Qcloud adapters | **required/configured** | email/SMS extensions and registration/recovery flows when enabled | No send without configuration; redact credentials and phone/email payloads from logs. |
| Payment providers: WeChat Pay, Alipay, Jeepay, Zhapay, Codepay and administrator-selected gateways | **required/configured** | payment adapters; user/admin initiated payment flow | Preserve only if enabled; validate callbacks and prevent arbitrary gateway substitution. |
| push/webhook notifications: Telegram, DingTalk, WeCom, ServerChan, generic webhook and browser Web Push endpoints | **required/configured** | `PushHttp`, `PushDispatcher` and notification extensions; configured alerts/subscriptions | Keep current private-IP/endpoint restrictions, disable without keys/URL, reject redirects or revalidate each hop. |
| Search submission and remote upload: Baidu URL submission, Alibaba/Uomg/Sina upload | **optional disabled-by-default** | urlsend/upload adapters selected by administrator | Consider legacy/high-risk; isolate credentials and apply the common outbound policy before continued use. |
| collection/resource endpoints and playback/media URLs supplied by administrators or imports | **required/configured** | collection, `Collect`, ResourceHub/import, remote image helpers and players; explicit collection/playback | Destinations are unbounded by nature and therefore SSRF-sensitive; validate scheme/DNS/IP per request and never attach platform secrets. |
| MPT service base URL | **optional disabled-by-default** | `addons/mpt/service/MptClient.php`; only after add-on configuration | Preserve its path/redirect safety and apply common DNS/private-address policy according to documented self-hosted exceptions. |
| Web Push vendor endpoints (`googleapis.com`, Mozilla push, Apple push) | **optional disabled-by-default** | stored browser subscriptions and `PushDispatcher` | Existing suffix allowlist and no-redirect behavior remain mandatory. |
| Live-channel seed URLs under `pili-live-hls.cntv.myqcloud.com` | **required/configured** | seeded live records; browser/player requests when a user plays a channel | Content traffic, not control-plane telemetry; administrators must be able to remove/disable seeds. |
| External login-background image `img.infinitynewtab.com` | **optional disabled-by-default** | `addons/adminloginbg` when installed/enabled | Avoid mixed HTTP and remote admin-page tracking; migrate to local/configured media or retire during hardening. |
| Template/add-on/resource clouds: `api.maccms.ai`, `cdn.maccms.ai`, legacy `api.maccms.com`, `maccmsbox.com` | **prohibited** | cloud catalog/resource controllers and admin pages | Nonessential official/cloud communication; remove/disable routes and remote assets by CP-09. |
| Official version/update code: packed dynamic script to `update.maccms.la`, `static_new/js/update.js`, `magicblack/maccms_down` and jsDelivr mirrors | **prohibited** | admin page load/version check and update UI/download flow | Quarantined now so the regression baseline remains green; remove dynamic injection, download/execution paths and dead admin routes in CP-09. |
| Installer telemetry/official embeds: `www.maccms.la/tongji.html` and official FAQ/footer navigation | **prohibited** | installer step iframe and official links | Remove iframe/telemetry. Preserve Apache 2.0 notices locally; attribution does not require a request. |
| Telegram/GitHub promotional links embedded by the updater | **prohibited** | update UI rendered links | Remove with the updater; source attribution may remain as local text and repository metadata. |
| GitHub issue/source links, Apache/OOXML namespace URIs, example domains and API documentation comments | **test/documentation-only** | comments, docs, language examples and XML namespace identifiers | Exclude from runtime enforcement unless code actually dereferences them. |

## Known prohibited evidence and quarantine

`static_new/js/admin_common.js` contains packed `eval` code that reconstructs and injects a remote script from `//update.maccms.la/v10/`. `static_new/js/update.js` also names `update.maccms.la` and offers remote update archives. The installer embeds `//www.maccms.la/tongji.html?v10-php`.

These paths violate the confirmed product policy. T-013 records them without changing upstream production behavior. `php tests/regression/outbound_inventory.php --report` therefore succeeds with an explicit `QUARANTINED` message, while `--enforce` returns nonzero and identifies the endpoint. Removal and a passing enforcement gate belong to CP-09.

## SSRF-sensitive callers

The following are separate from official communication because their destination may be supplied by an administrator, imported record or remote response:

- collection/resource and remote-image acquisition paths;
- configurable AI-compatible base URLs and ThemeDesign/Ueditor AI proxies;
- TMDB/IMDb/Douban configurable URLs and external federation sync;
- generic webhook, MPT, S3-compatible endpoints and Web Push subscriptions;
- remote database/import restore and any archive/catalog downloader;
- playback, live, manga chapter and poster URLs stored as content;
- redirecting payment, OAuth, upload and URL-submission adapters.

Every retained server-side caller must use a centralized policy: permit only required schemes, resolve and reject loopback/private/link-local/metadata addresses, pin/revalidate DNS and redirects, cap response size/time, restrict methods, avoid credential forwarding, and redact secrets. Browser-only navigation still requires clear administrator control but is not a server-side SSRF request.

## Dynamic execution and download findings

- The packed admin update script is dynamic cross-origin script insertion and is prohibited.
- The update UI exposes executable application/archive downloads; automatic download or extraction is prohibited.
- Template/add-on catalogs can lead to archive retrieval and installation, so catalog allowlisting alone is insufficient; signatures/checksums, extraction path safety and explicit confirmation would be required if the capability were retained.
- User/theme-controlled script fields and advertisement snippets are an XSS/content-policy concern even when they do not make a server-side request; they remain subject to later security review.

## Verification boundary

This inventory is static. It did not run installers, log into the admin UI, enable add-ons, play media, send notifications, submit payments, or contact external services. Network-capture proof that install/login/dashboard/collection/save/playback/Cron produce no prohibited traffic remains a CP-09 release gate.
