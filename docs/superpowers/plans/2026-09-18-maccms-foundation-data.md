# MACCMS Foundation and Data Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the pinned upstream baseline, versioned schema, video extension data, taxonomy relations, workflow state, stable public IDs, and one lossless playback codec required by every subsequent AI and Headless API subsystem.

**Architecture:** Preserve `mac_vod` as the MACCMS compatibility record and add focused InnoDB extension tables through an idempotent migration service. Put pure parsing and state-transition logic in small `application/common/util` classes so regression tests can run without a database; keep persistence in focused `application/common/model` classes.

**Tech Stack:** PHP 8.1 primary target, ThinkPHP 5.x, MySQL 5.7/8.0, existing standalone PHP regression-test convention, Nginx/Linux deployment.

**Spec:** `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md`

## Global Constraints

- First release handles Video content only.
- Preserve native MACCMS collection, video editing, membership, and permission behavior.
- Keep `mac_vod` and native `vod_play_*` fields as the compatibility source of truth.
- Use MySQL and short-lived Cron-compatible commands; do not require Redis, Docker, Node.js, or a resident worker.
- Use `/api/v1` and six-character `public_id` values in future external interfaces; do not expose incrementing `vod_id` as the primary public identifier.
- Target Nginx, PHP 8.1, and both MySQL 5.7 and 8.0.
- Keep Apache 2.0 and original attribution notices.
- No feature code may make an implicit MACCMS-official network request.
- Follow test-first development and commit after each independently reviewable task.

## Plan Boundary and Follow-on Plans

This plan deliberately implements only the shared foundation and data subsystem. The confirmed design must be completed through separate plans, in this order:

1. AI client, JSON schema validation, field provenance, field locking, and job queue.
2. Duplicate scoring, merge snapshots, canonical redirects, restoration, and TMDB matching.
3. Intelligent-content admin workspace, permissions, review screens, and settings.
4. Headless API v1, authentication, sessions, favorites, watch progress, statistics, rankings, and recommendations.
5. Official-communication removal, outbound allowlisting, deployment hardening, OpenAPI, and full regression/security acceptance.

Each follow-on plan consumes the interfaces produced below and must remain independently reviewable.

## File Map

| Path | Responsibility |
|---|---|
| `application/common/util/SchemaMigrationService.php` | Discover and apply ordered SQL migrations exactly once |
| `application/command/MaccmsMigrate.php` | CLI entry point for pending schema migrations |
| `application/command.php` | Register the migration command |
| `application/data/migrations/20260918000100_foundation.sql` | Foundation tables and indexes |
| `application/common/model/VodExt.php` | One-to-one video extension persistence |
| `application/common/model/MetaTerm.php` | Region/genre/tag dictionary persistence |
| `application/common/model/VodMetaTerm.php` | Video-to-term relation persistence |
| `application/common/model/VodFieldState.php` | Per-field source and lock persistence |
| `application/common/util/PublicIdGenerator.php` | Generate unambiguous six-character IDs |
| `application/common/util/VodWorkflow.php` | Validate workflow transitions |
| `application/common/util/VodPlaybackCodec.php` | Parse and serialize all native `vod_play_*` fields |
| `application/common/util/VodExtensionService.php` | Ensure extension records and synchronize compatibility strings |
| `tests/regression/migration_contract.php` | Migration schema contract test |
| `tests/regression/public_id_generator.php` | Public-ID format and collision tests |
| `tests/regression/vod_workflow.php` | Workflow transition tests |
| `tests/regression/vod_playback_codec.php` | Lossless playback parsing tests |
| `tests/regression/vod_extension_contract.php` | Model/table and compatibility-sync contract tests |
| `tests/regression/run_foundation.php` | Runs all foundation regression scripts |
| `docs/deployment/foundation-data.md` | Migration and rollback operator instructions |

---

### Task 1: Pin the Baseline and Add the Foundation Test Runner

**Files:**
- Create: `docs/development/upstream-baseline.md`
- Create: `tests/regression/run_foundation.php`
- Create: `tests/regression/foundation_file_contract.php`

**Interfaces:**
- Consumes: Current Git `HEAD`, `composer.json`, and the existing standalone regression-test style.
- Produces: `php tests/regression/run_foundation.php`, the single verification command used by every task in this plan.

- [ ] **Step 1: Record the exact upstream parent and supported runtime**

Run:

```bash
git rev-parse HEAD
git remote -v
php -v
```

Create `docs/development/upstream-baseline.md` with the exact commit hash and remote URL printed by those commands, plus these fixed decisions:

```markdown
# Upstream Baseline

- Upstream repository: https://github.com/magicblack/maccms10
- Baseline commit: `4466885edc38744c4a8cbfbea171546dfb67d84d`
- Primary runtime: PHP 8.1
- Database test matrix: MySQL 5.7 and MySQL 8.0
- Update policy: upstream changes enter through reviewed Git merges; runtime official-update checks are disabled in the hardening phase.
```

- [ ] **Step 2: Write the failing file-contract test**

Create `tests/regression/foundation_file_contract.php`:

```php
<?php
$root = dirname(__DIR__, 2);
$required = [
    'application/common/util/SchemaMigrationService.php',
    'application/command/MaccmsMigrate.php',
    'application/data/migrations/20260918000100_foundation.sql',
    'application/common/model/VodExt.php',
    'application/common/util/PublicIdGenerator.php',
    'application/common/util/VodWorkflow.php',
    'application/common/util/VodPlaybackCodec.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        fwrite(STDERR, "FAIL missing foundation file: {$path}\n");
        exit(1);
    }
}
echo "OK: foundation files exist.\n";
```

- [ ] **Step 3: Create the runner and verify the contract fails**

Create `tests/regression/run_foundation.php`:

```php
<?php
$tests = [
    'foundation_file_contract.php',
    'migration_contract.php',
    'public_id_generator.php',
    'vod_workflow.php',
    'vod_playback_codec.php',
    'vod_extension_contract.php',
];
foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing regression script: {$test}\n");
        exit(1);
    }
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        exit($code);
    }
}
echo "OK: foundation regression suite passed.\n";
```

Run: `php tests/regression/foundation_file_contract.php`  
Expected: FAIL naming `SchemaMigrationService.php` as the first missing file.

- [ ] **Step 4: Commit the baseline and intentionally failing harness**

```bash
git add docs/development/upstream-baseline.md tests/regression/foundation_file_contract.php tests/regression/run_foundation.php
git commit -m "test: define MACCMS foundation contract"
```

### Task 2: Add an Idempotent Versioned Migration Command

**Files:**
- Create: `application/common/util/SchemaMigrationService.php`
- Create: `application/command/MaccmsMigrate.php`
- Create: `application/data/migrations/20260918000100_foundation.sql`
- Create: `tests/regression/migration_contract.php`
- Modify: `application/command.php`

**Interfaces:**
- Consumes: `think\Db`, `ROOT_PATH`, and SQL files named `<14-digit-version>_<name>.sql`.
- Produces: `SchemaMigrationService::pending(): array`, `SchemaMigrationService::migrate(): array`, and CLI command `php think maccms:migrate`.

- [ ] **Step 1: Write the failing migration contract**

Create `tests/regression/migration_contract.php` to assert the migration contains every required table, required unique index, and InnoDB/utf8mb4 declarations:

```php
<?php
$root = dirname(__DIR__, 2);
$sqlPath = $root . '/application/data/migrations/20260918000100_foundation.sql';
$sql = is_file($sqlPath) ? file_get_contents($sqlPath) : '';
$needles = [
    'CREATE TABLE IF NOT EXISTS `__PREFIX__schema_migration`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_ext`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__meta_term`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_meta_term`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_field_state`',
    'UNIQUE KEY `uk_public_id` (`public_id`)',
    'UNIQUE KEY `uk_kind_slug` (`kind`,`slug`)',
    'UNIQUE KEY `uk_vod_term` (`vod_id`,`term_id`)',
    'UNIQUE KEY `uk_vod_field` (`vod_id`,`field_name`)',
    'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];
foreach ($needles as $needle) {
    if (strpos($sql, $needle) === false) {
        fwrite(STDERR, "FAIL migration missing: {$needle}\n");
        exit(1);
    }
}
$command = file_get_contents($root . '/application/command.php');
if (strpos($command, "app\\command\\MaccmsMigrate") === false) {
    fwrite(STDERR, "FAIL migration command is not registered.\n");
    exit(1);
}
echo "OK: migration contract passed.\n";
```

Run: `php tests/regression/migration_contract.php`  
Expected: FAIL because the SQL file is missing.

- [ ] **Step 2: Implement the migration SQL**

Create `application/data/migrations/20260918000100_foundation.sql`. Use `__PREFIX__` for the configured table prefix. Define:

```sql
CREATE TABLE IF NOT EXISTS `__PREFIX__schema_migration` (
  `version` varchar(32) NOT NULL,
  `name` varchar(191) NOT NULL,
  `checksum` char(64) NOT NULL,
  `executed_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__vod_ext` (
  `vod_id` int(10) unsigned NOT NULL,
  `public_id` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title_tw` varchar(255) NOT NULL DEFAULT '',
  `title_cn` varchar(255) NOT NULL DEFAULT '',
  `title_en` varchar(255) NOT NULL DEFAULT '',
  `original_title` varchar(255) NOT NULL DEFAULT '',
  `old_titles_json` text,
  `type2` varchar(32) NOT NULL DEFAULT '',
  `tmdb_id` int(10) unsigned NOT NULL DEFAULT '0',
  `tmdb_type` varchar(8) NOT NULL DEFAULT '',
  `trailer_url` varchar(1024) NOT NULL DEFAULT '',
  `preview_url` varchar(1024) NOT NULL DEFAULT '',
  `old_poster` varchar(1024) NOT NULL DEFAULT '',
  `old_poster_s3` varchar(1024) NOT NULL DEFAULT '',
  `poster_s3` varchar(1024) NOT NULL DEFAULT '',
  `workflow_status` varchar(32) NOT NULL DEFAULT 'imported',
  `ai_completed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `tmdb_completed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `duplicate_checked_at` int(10) unsigned NOT NULL DEFAULT '0',
  `merged_into_vod_id` int(10) unsigned NOT NULL DEFAULT '0',
  `published_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`vod_id`),
  UNIQUE KEY `uk_public_id` (`public_id`),
  KEY `idx_workflow` (`workflow_status`,`updated_at`),
  KEY `idx_tmdb` (`tmdb_type`,`tmdb_id`),
  KEY `idx_merged_into` (`merged_into_vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

In the same file add the exact dictionary, relation, and field-state schemas:

```sql
CREATE TABLE IF NOT EXISTS `__PREFIX__meta_term` (
  `term_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `slug` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name_tw` varchar(128) NOT NULL DEFAULT '',
  `name_cn` varchar(128) NOT NULL DEFAULT '',
  `name_en` varchar(128) NOT NULL DEFAULT '',
  `synonyms_json` text,
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `sort` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `uk_kind_slug` (`kind`,`slug`),
  KEY `idx_kind_status_sort` (`kind`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__vod_meta_term` (
  `vod_id` int(10) unsigned NOT NULL,
  `term_id` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  UNIQUE KEY `uk_vod_term` (`vod_id`,`term_id`),
  KEY `idx_term_id` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__vod_field_state` (
  `vod_id` int(10) unsigned NOT NULL,
  `field_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'import',
  `is_locked` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `source_ref` varchar(191) NOT NULL DEFAULT '',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  UNIQUE KEY `uk_vod_field` (`vod_id`,`field_name`),
  KEY `idx_source` (`source`),
  KEY `idx_locked` (`is_locked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Do not add foreign keys because `mac_vod` remains MyISAM in the upstream schema; enforce integrity in services and cleanup tasks.

- [ ] **Step 3: Implement the migration service**

Create `SchemaMigrationService` with these exact public methods:

```php
public function __construct(string $directory, string $prefix);
public function pending(): array;
public function migrate(): array;
```

Implementation rules:

- Accept only filenames matching `/^(\d{14})_([a-z0-9_]+)\.sql$/`.
- Sort by the 14-digit version.
- Replace only the literal token `__PREFIX__`; validate prefix with `/^[a-zA-Z0-9_]+$/`.
- Split statements using a small SQL-file parser that honors quoted strings; do not use raw `explode(';', ...)`.
- Calculate `hash('sha256', $originalSql)` and reject a previously applied version whose checksum changed.
- Execute each migration in a database transaction when the engine supports it; record the version only after every statement succeeds.
- Return `['applied' => [...], 'skipped' => [...]]`.

- [ ] **Step 4: Add the CLI command and registration**

Create `application/command/MaccmsMigrate.php` extending `think\console\Command`, configure name `maccms:migrate`, construct the service with `APP_PATH . 'data/migrations'` and `config('database.prefix')`, then print one line per applied version and a final count.

Append this class to `application/command.php` without removing `SeoAiGenerate`:

```php
return [
    'app\\command\\SeoAiGenerate',
    'app\\command\\MaccmsMigrate',
];
```

- [ ] **Step 5: Run focused checks**

Run:

```bash
php -l application/common/util/SchemaMigrationService.php
php -l application/command/MaccmsMigrate.php
php tests/regression/migration_contract.php
```

Expected: all syntax checks and the contract test pass.

- [ ] **Step 6: Commit**

```bash
git add application/command.php application/command/MaccmsMigrate.php application/common/util/SchemaMigrationService.php application/data/migrations/20260918000100_foundation.sql tests/regression/migration_contract.php
git commit -m "feat: add versioned schema migrations"
```

### Task 3: Add Stable Six-Character Public IDs

**Files:**
- Create: `application/common/util/PublicIdGenerator.php`
- Create: `application/common/model/VodExt.php`
- Create: `tests/regression/public_id_generator.php`

**Interfaces:**
- Consumes: A callable `callable(string $candidate): bool` that reports whether an ID already exists.
- Produces: `PublicIdGenerator::generate(callable $exists): string` and `VodExt::ensureForVod(int $vodId): array`.

- [ ] **Step 1: Write the failing pure unit test**

Create `tests/regression/public_id_generator.php`:

```php
<?php
require dirname(__DIR__, 2) . '/application/common/util/PublicIdGenerator.php';
use app\common\util\PublicIdGenerator;

$seen = [];
$id = PublicIdGenerator::generate(function ($candidate) use (&$seen) {
    $seen[] = $candidate;
    return count($seen) === 1;
});
if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $id)) {
    fwrite(STDERR, "FAIL invalid public ID: {$id}\n"); exit(1);
}
if (count($seen) < 2) {
    fwrite(STDERR, "FAIL collision was not retried.\n"); exit(1);
}
echo "OK: public ID generation passed.\n";
```

Run: `php tests/regression/public_id_generator.php`  
Expected: FAIL because the class is missing.

- [ ] **Step 2: Implement the generator**

Create a final class using alphabet `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`. Generate six characters with `random_int`, retry at most 32 times, and throw `RuntimeException('Unable to allocate unique public ID.')` after exhaustion.

- [ ] **Step 3: Implement `VodExt` persistence**

Create a ThinkPHP model bound to `vod_ext`, primary key `vod_id`, with no automatic timestamps. Implement:

```php
public static function ensureForVod(int $vodId): array;
public static function findByPublicId(string $publicId): ?array;
public static function canonicalVodId(int $vodId): int;
```

`ensureForVod` must return an existing record unchanged or allocate a unique ID and insert default timestamps. Catch a duplicate-key race and re-query before retrying. `canonicalVodId` follows `merged_into_vod_id` for at most ten hops and throws on a cycle.

- [ ] **Step 4: Run the tests**

Run:

```bash
php -l application/common/util/PublicIdGenerator.php
php -l application/common/model/VodExt.php
php tests/regression/public_id_generator.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add application/common/util/PublicIdGenerator.php application/common/model/VodExt.php tests/regression/public_id_generator.php
git commit -m "feat: add stable video public IDs"
```

### Task 4: Define and Enforce the Video Workflow State Machine

**Files:**
- Create: `application/common/util/VodWorkflow.php`
- Create: `tests/regression/vod_workflow.php`

**Interfaces:**
- Consumes: Current and requested workflow status strings.
- Produces: `VodWorkflow::canTransition(string $from, string $to): bool`, `VodWorkflow::assertTransition(string $from, string $to): void`, and `VodWorkflow::nextAfterRetry(string $failedStage): string`.

- [ ] **Step 1: Write failing transition tests**

Create `tests/regression/vod_workflow.php` with table-driven assertions for these allowed edges:

```php
$allowed = [
    ['imported', 'ai_processing'],
    ['ai_processing', 'duplicate_review'],
    ['duplicate_review', 'tmdb_matching'],
    ['duplicate_review', 'merged'],
    ['tmdb_matching', 'manual_review'],
    ['manual_review', 'published'],
    ['manual_review', 'rejected'],
    ['ai_processing', 'failed'],
    ['tmdb_matching', 'failed'],
    ['failed', 'imported'],
];
```

Also assert `published -> ai_processing`, `merged -> published`, and an unknown status are rejected with `InvalidArgumentException` or `DomainException` as appropriate.

Run: `php tests/regression/vod_workflow.php`  
Expected: FAIL because the class is missing.

- [ ] **Step 2: Implement the state machine**

Use class constants for every state and one private transition map. `assertTransition` throws `InvalidArgumentException` for unknown states and `DomainException` for a forbidden edge. `nextAfterRetry` returns `imported` for both supported failed stages (`ai_processing`, `tmdb_matching`) and rejects all other strings.

- [ ] **Step 3: Run and commit**

```bash
php -l application/common/util/VodWorkflow.php
php tests/regression/vod_workflow.php
git add application/common/util/VodWorkflow.php tests/regression/vod_workflow.php
git commit -m "feat: define video processing workflow"
```

### Task 5: Implement the Lossless Native Playback Codec

**Files:**
- Create: `application/common/util/VodPlaybackCodec.php`
- Create: `tests/regression/vod_playback_codec.php`

**Interfaces:**
- Consumes: Four native strings: `$from`, `$url`, `$server`, `$note`.
- Produces: `VodPlaybackCodec::decode(string $from, string $url, string $server = '', string $note = ''): array`, `VodPlaybackCodec::encode(array $sources): array`, and `VodPlaybackCodec::merge(array $primary, array $secondary): array`.

- [ ] **Step 1: Write failing round-trip and merge tests**

Create test fixtures covering two sources, missing server/note fields, empty episode names, dollar signs inside URLs after the first delimiter, duplicate URLs, and Unicode episode names. Also cover delimiter-free URL tokens, interior/trailing empty records, explicit named records with empty URLs, URL control characters, unsafe delimiter-free URLs, and every unrepresentable reserved-delimiter path through both `encode` and `merge`. The principal fixture is:

```php
$native = [
    'from' => 'line_a$$$line_b',
    'url' => '第1集$https://a.test/1.m3u8#第2集$https://a.test/2.m3u8$$$正片$https://b.test/movie.m3u8',
    'server' => 'server_a$$$',
    'note' => '主線$$$備用',
];
$decoded = VodPlaybackCodec::decode($native['from'], $native['url'], $native['server'], $native['note']);
$encoded = VodPlaybackCodec::encode($decoded);
assert_same($native, $encoded, 'decode/encode must preserve native values');
```

For merge, assert sources keep primary order, new sources append, episodes deduplicate by normalized URL, and a duplicate URL retains the primary episode name.

Run: `php tests/regression/vod_playback_codec.php`  
Expected: FAIL because the class is missing.

- [ ] **Step 2: Implement decoding**

Return this exact shape:

```php
[
    [
        'source' => 'line_a',
        'server' => 'server_a',
        'note' => '主線',
        'episodes' => [
            ['name' => '第1集', 'url' => 'https://a.test/1.m3u8', 'format' => 'named'],
        ],
    ],
]
```

Split parallel source fields on `$$$`, episode records on `#`, and named episodes only on the first `$`. Every episode has exact keys `name`, `url`, and `format`: use `named` for native `name$url`, `url_only` for a delimiter-free URL token, and `empty` for an empty segment between or after `#`. Preserve all three forms, empty strings, and ordering byte-for-byte. Reject control characters in source names and URLs, and reject URLs using `javascript:`, `file:`, `data:`, `gopher:`, or `ftp:` with `InvalidArgumentException`; scheme checks include leading ordinary spaces.

- [ ] **Step 3: Implement encoding and merging**

`encode` validates the exact shape and returns keys `from`, `url`, `server`, `note`. Before encoding or merging, reject `#` in episode names or URLs, `$` in episode names, `$$$` in source/server/note/URL values, and `$$` at the start of a named URL because its name separator would complete `$$$`; other ordinary `$` characters remain valid inside named URLs. All-empty server/note columns serialize canonically as `''`, while mixed values retain full parallel alignment. `merge` normalizes URLs by trimming whitespace only; it must not rewrite signed query parameters. Deduplicate exact normalized URLs within each named source and retain the first episode's `format` with its name.

- [ ] **Step 4: Run and commit**

```bash
php -l application/common/util/VodPlaybackCodec.php
php tests/regression/vod_playback_codec.php
git add application/common/util/VodPlaybackCodec.php tests/regression/vod_playback_codec.php
git commit -m "feat: add lossless video playback codec"
```

### Task 6: Add Taxonomy, Field-State, and Compatibility Synchronization

**Files:**
- Create: `application/common/model/MetaTerm.php`
- Create: `application/common/model/VodMetaTerm.php`
- Create: `application/common/model/VodFieldState.php`
- Create: `application/common/util/VodExtensionService.php`
- Create: `tests/regression/vod_extension_contract.php`

**Interfaces:**
- Consumes: `VodExt::ensureForVod`, `meta_term`, `vod_meta_term`, `vod_field_state`, and native `mac_vod`.
- Produces: `VodExtensionService::ensure(int $vodId): array`, `VodExtensionService::replaceTerms(int $vodId, string $kind, array $termIds): void`, `VodExtensionService::lockField(int $vodId, string $field, string $source, string $sourceRef = ''): void`, and `VodExtensionService::syncNativeTaxonomy(int $vodId): void`.

- [ ] **Step 1: Write the failing source contract test**

Create `tests/regression/vod_extension_contract.php`. Read the four source files and assert:

- Models bind to `meta_term`, `vod_meta_term`, and `vod_field_state`.
- Allowed kinds are exactly `region`, `genre`, `tag`.
- Allowed field sources are exactly `import`, `ai`, `tmdb`, `manual`.
- `VodExtensionService` exposes all four public methods listed above.
- Native mappings are exactly `region => vod_area`, `genre => vod_class`, `tag => vod_tag`.
- Compatibility strings use term sort ascending, then term ID ascending, and join names with commas.

Run: `php tests/regression/vod_extension_contract.php`  
Expected: FAIL because the files are missing.

- [ ] **Step 2: Implement the focused models**

Each model extends the existing common `Base`, sets only its `$name` and primary key, and exposes constants for valid kinds or sources. Add validation helpers that throw `InvalidArgumentException` for an unsupported value.

- [ ] **Step 3: Implement `VodExtensionService`**

Rules:

- `ensure` verifies the video exists, then delegates to `VodExt::ensureForVod`.
- `replaceTerms` validates all requested terms exist, are active, and match `$kind`; replace relation rows transactionally.
- `lockField` upserts `source`, `source_ref`, `is_locked = 1`, and `updated_at`.
- `syncNativeTaxonomy` queries active related terms, selects `name_tw` first then `name_cn` then `name_en`, sorts deterministically, and updates only `vod_area`, `vod_class`, and `vod_tag`.
- Never update title, description, playback data, or `vod_status` in this service.

- [ ] **Step 4: Run focused tests**

```bash
php -l application/common/model/MetaTerm.php
php -l application/common/model/VodMetaTerm.php
php -l application/common/model/VodFieldState.php
php -l application/common/util/VodExtensionService.php
php tests/regression/vod_extension_contract.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add application/common/model/MetaTerm.php application/common/model/VodMetaTerm.php application/common/model/VodFieldState.php application/common/util/VodExtensionService.php tests/regression/vod_extension_contract.php
git commit -m "feat: add video metadata extension services"
```

### Task 7: Integrate Extension Creation Without Changing Native Collection Semantics

**Files:**
- Modify: `application/common/model/Vod.php`
- Modify: `application/common/model/Collect.php`
- Create: `tests/regression/vod_extension_hook.php`
- Modify: `tests/regression/run_foundation.php`

**Interfaces:**
- Consumes: `VodExtensionService::ensure(int $vodId): array`.
- Produces: Every successful content save through admin `Vod::saveData()` or collection/API `Collect::vod_data()` has a `vod_ext` row, while normal collection return values and native field writes remain unchanged. Counter/rating/batch-field updates are not content creation boundaries and are excluded.

- [ ] **Step 1: Locate the actual successful content persistence boundaries**

Run:

```bash
rg -n "function saveData|->save\(|insertGetId|allowField" application/common/model/Vod.php
```

Inspection correction (2026-09-18): no existing single shared persistence boundary exists. `Vod::saveData()` serves admin content saves, but `Collect::vod_data()` performs its own native insert/update queries. Add one shared `Vod::ensureExtension()` helper and invoke it at those three successful content-write boundaries. Do not route collection through `saveData()`, intercept global query events, or add hooks to controllers.

- [ ] **Step 2: Write a failing structural regression test**

Create `tests/regression/vod_extension_hook.php` that extracts the shared helper, `Vod::saveData()`, and `Collect::vod_data()` bodies. Assert the helper imports/uses `VodExtensionService`, rejects nonpositive IDs before `ensure()`, and logs/maps failures; assert the three call sites follow their native success checks and known-ID resolution. For example:

```php
assert_true(
    strpos($body, 'VodExtensionService') !== false,
    'The shared Vod persistence boundary must ensure the extension row.'
);
assert_true(
    strpos($body, 'ensure(') !== false,
    'The shared Vod persistence boundary must call ensure after a successful save.'
);
```

Also assert the call occurs after the existing success check by comparing string offsets.

Run: `php tests/regression/vod_extension_hook.php`  
Expected: FAIL because no hook exists.

- [ ] **Step 3: Add the minimal post-save hook**

Import `app\common\util\VodExtensionService`. The shared helper validates a positive ID and calls `$service->ensure((int) $vodId)`. Call it after `Vod::saveData()` native success/ID resolution, after a positive collection insert ID, and after a non-false collection content update with a positive ID (including zero affected rows). On extension failure, log the exception and use the existing native failure shape: save code 1002, or collection red/message routing yielding code 1001 for non-display callers. Keep native write payloads and all existing transaction boundaries unchanged. Native MyISAM writes may already be committed when extension creation fails; the reported failure does not roll them back. Do not enqueue AI work.

- [ ] **Step 4: Register and run the full foundation suite**

Add `vod_extension_hook.php` to `run_foundation.php`, then run:

```bash
php tests/regression/run_foundation.php
php tests/regression/user_register_validate.php
git diff --check
```

Expected: both regression suites pass and `git diff --check` prints nothing.

- [ ] **Step 5: Commit**

```bash
git add application/common/model/Vod.php application/common/model/Collect.php tests/regression/vod_extension_hook.php tests/regression/run_foundation.php docs/superpowers/plans/2026-09-18-maccms-foundation-data.md
git commit -m "feat: ensure video extension records on save"
```

### Task 8: Verify Real Database Installation, Idempotency, and Rollback Documentation

**Files:**
- Create: `docs/deployment/foundation-data.md`
- Modify: `docs/development/upstream-baseline.md`

**Interfaces:**
- Consumes: `php think maccms:migrate` and a disposable MySQL 5.7/8.0 database configured through the normal MACCMS database configuration.
- Produces: A recorded verification procedure and operator-safe backup/rollback instructions.

- [ ] **Step 1: Install a disposable MACCMS database**

Use a dedicated empty database whose name is explicitly confirmed before running the installer. Do not point this test at production or a shared database. Complete the normal MACCMS install, then back up its generated database configuration file.

- [ ] **Step 2: Run the migration twice**

```bash
php think maccms:migrate
php think maccms:migrate
```

Expected first run: version `20260918000100` is applied exactly once.
Expected second run: zero migrations applied and no schema changes.

- [ ] **Step 3: Verify schema invariants with SQL**

Execute:

```sql
SELECT version, checksum FROM mac_schema_migration ORDER BY version;
SHOW CREATE TABLE mac_vod_ext;
SHOW CREATE TABLE mac_meta_term;
SHOW CREATE TABLE mac_vod_meta_term;
SHOW CREATE TABLE mac_vod_field_state;
```

Verify InnoDB, utf8mb4, every unique index, and a single migration row. Repeat on MySQL 5.7 and 8.0 before marking this task complete.

- [ ] **Step 4: Exercise a real video write and codec round trip**

Create one draft video through the existing admin form and one through the existing collection/API path. Confirm each has exactly one `vod_ext` row and distinct six-character IDs. Decode and encode each `vod_play_*` set with `VodPlaybackCodec`; assert the encoded strings equal the stored originals byte-for-byte.

- [ ] **Step 5: Write deployment and rollback instructions**

Create `docs/deployment/foundation-data.md` containing:

- Required PHP extensions and MySQL versions.
- Exact backup commands using an explicitly named database, never a wildcard.
- `php think maccms:migrate` execution and verification queries.
- File rollback by Git release tag.
- Data rollback: restore the pre-deployment database backup. Do not advertise dropping extension tables as a complete rollback after production writes.
- Cron is not enabled in this foundation release because no background jobs are enqueued yet.

- [ ] **Step 6: Record verification results and run final checks**

Append a dated table to `docs/development/upstream-baseline.md` with PHP version, MySQL version, first migration result, second migration result, native admin write result, native collection write result, and playback round-trip result.

Run:

```bash
php tests/regression/run_foundation.php
php tests/regression/user_register_validate.php
find application/common application/command tests/regression -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short
```

Expected: all tests and lint checks pass; diff check is empty; status contains only the documentation updates intended for this task.

- [ ] **Step 7: Commit**

```bash
git add docs/deployment/foundation-data.md docs/development/upstream-baseline.md
git commit -m "docs: add foundation deployment verification"
```

## Completion Gate

Do not begin the AI/TMDB plan until all conditions hold:

- `php tests/regression/run_foundation.php` passes.
- The existing registration regression test passes.
- Migrations are idempotent on both MySQL 5.7 and 8.0.
- Admin and collection writes both create exactly one extension row.
- Playback fields round-trip byte-for-byte.
- No external request was added by this plan.
- The implementation is reviewed against `docs/superpowers/specs/2026-09-18-maccms10-headless-ai-design.md` sections 3, 4, 9, 11, 12, and 14.
