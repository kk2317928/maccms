# T-071 API v1 Public Catalog Design

Status: approved (2026-09-19)

## Goal

Expose read-only public catalog data through stable API v1 DTOs for home, video list, video detail, episode structure, search, and taxonomy discovery.

## Public visibility invariant

Every video query must require all of:

- native `vod_status = 1`
- extension `workflow_status = published`
- `merged_into_vod_id = 0`
- `published_at > 0`

Merged aliases are not resolved in T-071. Canonical redirects and locale fallback belong to T-072.

## Routes

All routes are GET-only:

- `/api/v1/home`
- `/api/v1/videos`
- `/api/v1/videos/{public_id}`
- `/api/v1/videos/{public_id}/episodes`
- `/api/v1/search?q=...`
- `/api/v1/taxonomies`

Known routes with another method return 405. Unknown v1 routes return 404.

## Query contract

List and search use `page` and `per_page` through the existing pagination policy. Video list accepts allowlisted `sort` values `latest`, `popular`, and `rating`, plus optional `type2`, `year`, `taxonomy_kind`, and `taxonomy_slug`. Search requires a trimmed query of 1–100 characters.

## DTO contract

Video summaries expose only stable public fields: `public_id`, title fields, poster, year, remarks, score, and publication timestamp. Detail adds synopsis, people credits, area/language, episode progress, trailer/preview metadata, and taxonomy DTOs. Taxonomies expose `kind`, `slug`, localized names, and sort order; internal numeric IDs are omitted.

The `title` display field defaults to Traditional Chinese, then native title. A `titles` object preserves available Traditional Chinese, Simplified Chinese, English, and original values so T-072 can add locale selection without changing the envelope shape.

## Episode security boundary

T-071 decodes native playback data only to publish deterministic source and episode structure:

- source: `source_id`, `name`, `position`
- episode: `episode_id`, `name`, `position`

It must never expose URL, server, note, format, tokens, or raw playback strings. Playback policy and signed delivery belong to T-075.

## Architecture

- A repository owns database reads and the visibility predicate.
- Pure presenter/DTO classes map allowlisted fields only.
- A catalog service validates filters and orchestrates repository plus presenters.
- A v1 catalog controller returns the existing response envelope.
- The bootstrap dispatcher maps only the exact public routes above.

## Error behavior

Invalid query input returns the established validation error envelope. Missing or unpublished resources return 404. Database/internal failures remain normalized by the scoped v1 exception handler.
