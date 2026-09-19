# T-070 Headless API v1 Foundation Design

Date: 2026-09-19  
Status: approved  
Task: T-070  
Parent design: `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`

## Goal

Establish the stable, versioned boundary used by every future `/api/v1` endpoint without changing the existing `/api.php/*` compatibility API.

## Chosen approach

Add a dedicated v1 controller namespace inside the existing API module and route clean `/api/v1/*` paths to it. Keep response construction, pagination validation, request identifiers, and DTO allowlisting in focused pure-PHP classes so later controllers remain thin and the contracts can be tested without a database.

Alternatives rejected:

- Relabel the existing API: rejected because it exposes internal numeric IDs, raw records, and mixed envelopes.
- Create a second application module/entrypoint: rejected because it duplicates bootstrap and deployment configuration without providing additional isolation.

## Route boundary

- Public route prefix: `/api/v1`.
- T-070 exposes `GET /api/v1` as a discovery/probe endpoint returning the API version.
- Later T-071 through T-077 endpoints live under the same namespace.
- Legacy `/api.php/*` routes and responses remain unchanged.
- Unknown v1 resources use the stable v1 error envelope; they must not fall through to legacy controllers.
- The route boundary accepts JSON responses only.

## Response contract

Successful singleton response:

```json
{
  "data": {},
  "meta": {
    "request_id": "01H..."
  }
}
```

Successful collection response:

```json
{
  "data": [],
  "meta": {
    "request_id": "01H...",
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total_items": 100,
      "total_pages": 5
    }
  }
}
```

Error response:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The request parameters are invalid.",
    "details": {}
  },
  "meta": {
    "request_id": "01H..."
  }
}
```

Rules:

- Success contains `data` and `meta`, never `error`.
- Failure contains `error` and `meta`, never `data`.
- Machine error codes are stable uppercase snake case.
- HTTP status and machine error code are separate.
- `details` is optional and contains only safe, field-oriented information.
- Responses never expose stack traces, SQL, filesystem paths, secrets, authorization values, cookies, or raw external-provider responses.
- Every response carries the same request ID accepted from a syntactically valid inbound `X-Request-ID`, otherwise generated locally.

## Pagination contract

Page-number pagination is the only v1 pagination mode in CP-07.

- Query parameters: `page` and `per_page`.
- Defaults: `page=1`, `per_page=20`.
- Bounds: `page >= 1`, `1 <= per_page <= 100`.
- Values must be canonical positive decimal integers; arrays, signs, decimals, scientific notation, whitespace-padded values, and mixed strings are invalid.
- Invalid values return HTTP 422 with `VALIDATION_ERROR` and safe field details.
- `total_pages` is zero when `total_items` is zero; otherwise it is `ceil(total_items / per_page)`.
- Offset calculation is checked before use and rejects integer overflow.
- Collection metadata uses the exact keys shown above.

## DTO boundary

All public payloads implement an explicit allowlist mapper contract:

```php
interface ApiV1Dto
{
    public function toArray();
}
```

The foundation includes a generic allowlist mapper for tests and small composition only. Endpoint-specific DTOs introduced in later tasks must name every output field and may compose other DTOs. They must not return ORM/model objects or pass database rows through unchanged.

Forbidden public identity fields include `vod_id` and other internal relational IDs. Videos use six-character `public_id`; canonical redirect behavior remains T-072.

## Components

- `application/api/controller/v1/Base.php`: v1-only controller base and JSON response helpers.
- `application/api/controller/v1/Index.php`: version probe.
- `application/common/util/ApiV1Response.php`: deterministic success/error envelope builder.
- `application/common/util/ApiV1Pagination.php`: strict input parsing and metadata calculation.
- `application/common/util/ApiV1Dto.php`: DTO interface.
- `application/common/util/ApiV1AllowlistDto.php`: explicit allowlist mapper.
- `application/common/util/ApiV1RequestId.php`: inbound validation and safe generation.
- `tests/regression/api_v1_foundation.php`: behavioral contract.
- `tests/regression/run.php` and PHP workflow: register the focused test.

## Error taxonomy established here

- `VALIDATION_ERROR` — 422
- `NOT_FOUND` — 404
- `METHOD_NOT_ALLOWED` — 405
- `INTERNAL_ERROR` — 500

Later tasks may add documented codes while preserving the envelope.

## Compatibility and security

- No schema or migration changes.
- No outbound network calls.
- No legacy controller behavior changes.
- PHP 8.1 is the primary runtime; syntax remains compatible with the repository bootstrap constraints.
- Error handling fails closed and maps unexpected throwables to `INTERNAL_ERROR` without leaking exception content.
- Request IDs are bounded to 64 ASCII characters matching `[A-Za-z0-9._:-]+`.

## Acceptance

- The v1 probe resolves under the versioned path and returns only the new envelope.
- Success, collection, and error envelopes have mutually exclusive top-level fields.
- Pagination defaults, limits, totals, zero totals, malformed values, and overflow are covered.
- DTO mapping drops extra and internal fields.
- Request ID propagation/generation is covered.
- Existing API source contract remains unchanged.
- Focused test, complete PHP regression, MySQL 5.7, MySQL 8.0, and native video compatibility workflows pass.
