# API v1 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver T-070's isolated `/api/v1` route, stable response/error envelopes, strict page pagination, request IDs, and explicit DTO allowlisting.

**Architecture:** Pure-PHP utility classes define the contract independently of ThinkPHP. A dedicated `app\api\controller\v1` namespace adapts those classes into JSON responses while leaving legacy API controllers untouched.

**Tech Stack:** PHP 8.1, ThinkPHP 5-era routing/controllers, repository regression scripts, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-19-api-v1-foundation-design.md`

## Global Constraints

- Preserve all legacy `/api.php/*` behavior.
- Never serialize database/model rows directly through v1.
- Never expose numeric internal video IDs.
- Use `page`/`per_page` with defaults 1/20 and maximum 100.
- Do not add migrations, dependencies, or outbound requests.
- Errors must not disclose stack traces, SQL, paths, secrets, cookies, authorization values, or provider payloads.
- Primary target is PHP 8.1; run every listed syntax and regression check.
- Follow RED → GREEN for every behavior change.

## Review Focus

- Malformed pagination values (arrays, signs, decimals, exponent notation, padded strings) must fail with safe 422 details.
- Very large page/total values must not overflow offsets or total-page calculations.
- User-provided request IDs must be bounded and syntactically validated before reflection.
- DTO allowlists must drop unexpected and internal identifier fields even when input contains them.
- v1 routing must not fall through to or mutate legacy API routing/controller behavior.

---

### Task 1: Pure API v1 contract primitives

**Files:**
- Create: `application/common/util/ApiV1Dto.php`
- Create: `application/common/util/ApiV1AllowlistDto.php`
- Create: `application/common/util/ApiV1RequestId.php`
- Create: `application/common/util/ApiV1Pagination.php`
- Create: `application/common/util/ApiV1Response.php`
- Create: `tests/regression/api_v1_foundation.php`

**Interfaces:**
- Produces: `ApiV1Dto::toArray(): array`.
- Produces: `ApiV1AllowlistDto::__construct(array $source, array $fields)` and `toArray(): array`.
- Produces: `ApiV1RequestId::resolve($candidate = null): string`.
- Produces: `ApiV1Pagination::fromQuery(array $query): ApiV1Pagination`, `offset(): int`, and `meta(int $totalItems): array`.
- Produces: `ApiV1Response::success($data, string $requestId, array $meta = array()): array`, `collection(array $items, ApiV1Pagination $pagination, int $totalItems, string $requestId): array`, and `error(string $code, string $message, string $requestId, array $details = array()): array`.
- Consumes: no ThinkPHP or database state.

- [ ] **Step 1: Write the failing primitive contract test**

Create a standalone regression script that autoloads the five classes and asserts:

```php
$dto = new ApiV1AllowlistDto(
    array('public_id' => 'ABC234', 'title' => 'Example', 'vod_id' => 99),
    array('public_id', 'title')
);
assertSame(array('public_id' => 'ABC234', 'title' => 'Example'), $dto->toArray());

$page = ApiV1Pagination::fromQuery(array());
assertSame(0, $page->offset());
assertSame(array(
    'current_page' => 1,
    'per_page' => 20,
    'total_items' => 0,
    'total_pages' => 0,
), $page->meta(0));

assertThrowsValidation(array('page' => '0'), 'page');
assertThrowsValidation(array('page' => ' 1'), 'page');
assertThrowsValidation(array('per_page' => '101'), 'per_page');
assertThrowsValidation(array('per_page' => array('20')), 'per_page');

$requestId = ApiV1RequestId::resolve('client-123');
assertSame('client-123', $requestId);
assertMatches('/^[A-Za-z0-9._:-]{16,64}$/', ApiV1RequestId::resolve("bad\nvalue"));

assertSame(
    array('data' => array('version' => 'v1'), 'meta' => array('request_id' => 'req-1')),
    ApiV1Response::success(array('version' => 'v1'), 'req-1')
);
```

Also assert exclusive `data`/`error` fields, pagination totals (`100/20 = 5`, `101/20 = 6`), maximum valid values, and offset overflow rejection.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php tests/regression/api_v1_foundation.php`  
Expected: FAIL because `ApiV1Dto.php` and the contract classes do not exist.

- [ ] **Step 3: Implement the DTO and request-ID classes**

Implement `ApiV1Dto` with `public function toArray();`. Implement `ApiV1AllowlistDto` so it iterates the configured field list, copies only keys present via `array_key_exists`, rejects empty/non-string field names, and never adds source extras.

Implement `ApiV1RequestId::resolve()`:

```php
if (is_string($candidate)
    && strlen($candidate) >= 1
    && strlen($candidate) <= 64
    && preg_match('/\A[A-Za-z0-9._:-]+\z/D', $candidate) === 1
) {
    return $candidate;
}
return bin2hex(random_bytes(16));
```

If `random_bytes` throws, generate a non-secret correlation identifier from process/time entropy without including request data.

- [ ] **Step 4: Implement strict pagination**

Parse only integers or canonical decimal strings matching `\A[1-9][0-9]*\z`. Reject booleans, arrays, floats, zero, signs, whitespace, and values beyond `PHP_INT_MAX`. Enforce `per_page <= 100`; check `($page - 1) > intdiv(PHP_INT_MAX, $perPage)` before multiplication. Throw `InvalidArgumentException` with only the field name (`page` or `per_page`). Compute total pages using quotient/remainder rather than addition that can overflow.

- [ ] **Step 5: Implement deterministic envelopes**

Normalize DTO objects through `ApiV1Dto::toArray()`. Build success and collection envelopes with exact `data` and `meta` keys. Build error envelopes with exact `error` and `meta` keys, including `details` only when non-empty. Validate machine codes with `\A[A-Z][A-Z0-9_]*\z`.

- [ ] **Step 6: Run focused syntax and behavioral tests**

Run:

```bash
php -l application/common/util/ApiV1Dto.php
php -l application/common/util/ApiV1AllowlistDto.php
php -l application/common/util/ApiV1RequestId.php
php -l application/common/util/ApiV1Pagination.php
php -l application/common/util/ApiV1Response.php
php -l tests/regression/api_v1_foundation.php
php tests/regression/api_v1_foundation.php
```

Expected: all syntax checks report no errors and the focused script reports every assertion passed.

- [ ] **Step 7: Commit**

```bash
git add application/common/util/ApiV1*.php tests/regression/api_v1_foundation.php
git commit -m "feat: add api v1 response contracts"
```

### Task 2: Versioned route and thin controllers

**Files:**
- Create: `application/api/controller/v1/Base.php`
- Create: `application/api/controller/v1/Index.php`
- Modify: `application/route.php`
- Modify: `tests/regression/api_v1_foundation.php`

**Interfaces:**
- Consumes: all five Task 1 primitives.
- Produces: `GET /api/v1` route to `api/v1.index/index`.
- Produces: v1 controller helpers `successResponse()`, `collectionResponse()`, and `errorResponse()`.
- Produces: discovery payload `{"data":{"version":"v1"},"meta":{"request_id":"..."}}`.

- [ ] **Step 1: Extend the test with failing route/controller contracts**

Add structural and callable assertions that:

```php
$routeSource = file_get_contents(__DIR__ . '/../../application/route.php');
assertContains("'api/v1$'", $routeSource);
assertContains("'api/v1.index/index'", $routeSource);

$baseSource = file_get_contents(__DIR__ . '/../../application/api/controller/v1/Base.php');
assertContains('class Base extends \\app\\api\\controller\\Base', $baseSource);
assertContains('ApiV1Response::error', $baseSource);

$indexSource = file_get_contents(__DIR__ . '/../../application/api/controller/v1/Index.php');
assertContains("'version' => 'v1'", $indexSource);
```

Require exact GET method metadata in the route and an explicit v1 not-found/error action so unknown v1 paths cannot resolve into legacy controllers.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php tests/regression/api_v1_foundation.php`  
Expected: FAIL because the v1 controller files and versioned route are absent.

- [ ] **Step 3: Add the route boundary**

Add exact, highest-priority route entries before broad public routes:

```php
'api/v1$' => array(
    0 => 'api/v1.index/index',
    1 => array(),
    2 => array('method' => 'get'),
),
'api/v1/<path>' => array(
    0 => 'api/v1.index/notFound',
    1 => array('path' => '[\\s\\S]+'),
    2 => array(),
),
```

Verify the actual ThinkPHP route syntax against the existing route array before committing; retain exact-match precedence and do not alter existing entries.

- [ ] **Step 4: Implement the v1 base and index controller**

Extend the existing API `Base` solely for compatible application initialization. Resolve request IDs from `X-Request-ID`. Convert primitive arrays using ThinkPHP's `json($payload, $status, $headers)`, always setting `Content-Type: application/json; charset=utf-8` and `X-Request-ID`. Catch pagination `InvalidArgumentException` and map only its safe field name to a 422 `VALIDATION_ERROR`.

`Index::index()` returns version `v1`; `Index::notFound()` returns 404 `NOT_FOUND`. Do not query models or databases.

- [ ] **Step 5: Run focused tests and syntax checks**

Run:

```bash
php -l application/route.php
php -l application/api/controller/v1/Base.php
php -l application/api/controller/v1/Index.php
php tests/regression/api_v1_foundation.php
```

Expected: syntax passes and all foundation assertions pass.

- [ ] **Step 6: Commit**

```bash
git add application/route.php application/api/controller/v1 tests/regression/api_v1_foundation.php
git commit -m "feat: add versioned api v1 route"
```

### Task 3: Register verification and task evidence

**Files:**
- Modify: `.github/workflows/php-regression.yml`
- Create: `tests/regression/run_api_v1.php`
- Modify: `tasks.md`
- Modify: `CURRENT_STATE.md`

**Interfaces:**
- Consumes: Task 1 focused script and Task 2 route/controllers.
- Produces: stable CP-07 API v1 regression runner for T-071 through T-077.
- Produces: resumable task/checkpoint evidence.

- [ ] **Step 1: Write the failing runner contract**

Create `run_api_v1.php` with a deterministic list containing `api_v1_foundation.php`, execute each test with `PHP_BINARY`, echo its command/output, and exit immediately with the child status on first failure. Extend `api_v1_foundation.php` to assert that the workflow syntax-checks all new PHP files and invokes `php tests/regression/run_api_v1.php`.

- [ ] **Step 2: Run the foundation test and verify RED**

Run: `php tests/regression/api_v1_foundation.php`  
Expected: FAIL because the workflow does not yet register the new files/runner.

- [ ] **Step 3: Register the suite in CI**

Add `php -l` entries for the runner, focused test, utilities, and controllers. Add a named `API v1 regression suite` step executing:

```bash
php tests/regression/run_api_v1.php
```

- [ ] **Step 4: Run the complete local PHP regression set**

Run:

```bash
php tests/regression/api_v1_foundation.php
php tests/regression/run_api_v1.php
php tests/regression/run_baseline.php
php tests/regression/run_foundation.php
php tests/regression/run_ai_jobs.php
php tests/regression/run_duplicate_tmdb.php
php tests/regression/run_admin.php
```

Expected: every command exits 0 with no warnings or failures.

- [ ] **Step 5: Update state documents**

Mark T-070 `DONE` with exact commits and run IDs, T-071 `READY`, active task T-071, and CP-07 still active. Record the chosen page pagination contract, isolated v1 namespace, response envelope, DTO allowlist rule, and no schema/outbound changes in `CURRENT_STATE.md`.

- [ ] **Step 6: Commit and push**

```bash
git add .github/workflows/php-regression.yml tests/regression/run_api_v1.php tasks.md CURRENT_STATE.md
git commit -m "test: verify api v1 foundation"
git push origin feature/headless-ai-v1
```

- [ ] **Step 7: Verify remote workflows**

Wait for the pushed head commit and require successful:

- PHP 8.1 regression workflow.
- MySQL 5.7 foundation workflow.
- MySQL 8.0 foundation workflow.
- Native video foundation workflow.

Record exact run IDs in the state documents. If evidence changes the documentation, commit and push that documentation-only update, then require the PHP workflow on the final documentation commit.

## Self-review result

- Spec coverage: route, envelope, error taxonomy, request ID, strict pagination, DTO allowlist, isolation, CI, and state evidence are each owned by a task.
- Placeholder scan: no implementation placeholder remains.
- Type consistency: Task 2 consumes the exact Task 1 signatures; Task 3 consumes the exact focused test and runner.
- Review Focus: every listed malformed/overflow/reflection/allowlist/fallthrough risk has an explicit Task 1 or Task 2 assertion.
