# API v1 delivery policy

T-076 applies CORS, endpoint-specific request limits and conditional caching to the isolated `/api/v1` surface.

## CORS

Cross-origin access is denied unless the exact HTTPS origin is configured:

```php
$GLOBALS['config']['app']['api_v1_cors_origins'] = array(
    'https://app.example.com',
    'https://www.example.com',
);
```

Wildcards, HTTP origins, origins containing credentials, and partial hostname matches are rejected. Same-origin requests normally omit the `Origin` header and continue without CORS headers. Allowed cross-origin requests expose `ETag`, `X-Request-ID`, and `Retry-After`. Preflight requests allow only the methods assigned to the endpoint group and the headers `Authorization`, `Content-Type`, and `X-Request-ID`.

The API uses bearer tokens and does not enable credentialed CORS cookies.

## Endpoint limits

Default fixed-window limits are:

| Bucket | Default |
|---|---:|
| Public catalog | 120 requests / 60 seconds |
| Search | 30 requests / 60 seconds |
| Authentication | 10 requests / 60 seconds |
| Playback delivery | 30 requests / 60 seconds |
| Member activity | 60 requests / 60 seconds |

Override a bucket only when operational evidence requires it:

```php
$GLOBALS['config']['app']['api_v1_rate_limits'] = array(
    'catalog' => array('limit'=>120, 'window'=>60),
    'search' => array('limit'=>30, 'window'=>60),
    'auth' => array('limit'=>10, 'window'=>60),
    'playback' => array('limit'=>30, 'window'=>60),
    'member' => array('limit'=>60, 'window'=>60),
);
```

Counters are stored beneath the runtime directory and updated under an exclusive file lock. If the limiter cannot safely open or lock its state, requests fail closed with 503. Exhausted buckets return 429 and `Retry-After`.

The limiter keys clients from the direct peer `REMOTE_ADDR`. It deliberately ignores `X-Forwarded-For` because that header is attacker-controlled unless a trusted reverse-proxy boundary validates and replaces it. At the web server or load balancer, restore the real client address into `REMOTE_ADDR` only from explicitly trusted proxy addresses.

## ETag and invalidation

Published catalog GET responses include a strong ETag calculated from the stable response data and metadata, excluding the per-request request ID. They use:

`Cache-Control: public, no-cache, must-revalidate`

Every reuse therefore requires validation. `If-None-Match` returns 304 only when the current representation is byte-semantically unchanged. Publication, unpublication, locale fallback, taxonomy, episode, or DTO changes alter the representation and immediately produce a different validator without a separate invalidation queue.

Responses vary by `Accept-Language` and `Origin`. Authentication tokens remain `no-store`; playback URLs remain `private, no-store`; neither participates in public conditional caching.

## Operations

The runtime rate-limit directory must be writable by the PHP worker and must not be publicly served. Monitor 429 and 503 counts. Do not log bearer tokens, playback URLs, or signing secrets when investigating rejected requests.
