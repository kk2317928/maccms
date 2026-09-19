# T-072 API v1 Locale and Canonical Redirect Design

Status: approved under the established automatic-execution policy (2026-09-19)

## Goal

Add deterministic locale selection and fallback to the T-071 public catalog, and redirect merged public IDs to their published canonical video without exposing numeric database IDs.

## Locale contract

The API accepts an optional `locale` query parameter on all T-071 catalog routes. Supported canonical values are:

- `zh-TW`
- `zh-CN`
- `en`

Matching is case-insensitive and also accepts underscore forms. When the query parameter is absent, the first supported language in `Accept-Language` is used; otherwise the default is `zh-TW`. An explicitly supplied unsupported locale is a validation error rather than silently changing language.

The response keeps the existing `titles` and taxonomy `names` objects. The scalar display fields are localized:

- `zh-TW`: Traditional Chinese → native title → original → Simplified Chinese → English
- `zh-CN`: Simplified Chinese → Traditional Chinese → native title → original → English
- `en`: English → original → Traditional Chinese → native title → Simplified Chinese

Taxonomy display names follow the selected language, then Traditional Chinese, Simplified Chinese, English, and finally slug, skipping empty values. The resolved canonical locale is returned in response metadata.

This task uses the indexed title/name columns already introduced by the programme. It does not add per-request `content_lang` N+1 queries.

## Canonical redirect contract

Detail and episode requests resolve the requested six-character public ID through `VodExt::resolvePublicId()`.

- A canonical ID continues normally.
- A merged alias returns HTTP 308 with a relative `Location` header pointing to the same resource under the canonical public ID.
- Redirect payload data contains only `canonical_public_id`.
- The canonical target must itself satisfy the T-071 published-content invariant. If it does not, the request returns 404.
- Invalid IDs, missing IDs, broken chains, cycles, or invalid canonical IDs fail closed through the existing 404/internal-error boundary.

List, home, search, and taxonomy routes never include merged aliases because the repository predicate continues to require `merged_into_vod_id = 0`.

## Components

- `ApiV1Locale`: pure parsing, normalization, fallback selection, and response metadata.
- `ApiV1VideoDto`: locale-aware display title and taxonomy display name while preserving multilingual maps.
- `ApiV1CatalogService`: canonical resolution and published-target validation.
- `Catalog` controller and v1 base: pass locale and emit stable 308 JSON responses.
- Regression suite: locale parsing/fallback, alias redirects, no numeric-ID leakage, and repository detail-field correctness.

## Compatibility

Existing clients that omit locale keep Traditional Chinese-first behavior. DTO keys remain additive except taxonomy gains a scalar `name`. Playback URLs remain excluded until T-075.
