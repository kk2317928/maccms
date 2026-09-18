<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/application/common/util/FieldGovernance.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: FieldGovernance is missing.\n"); exit(1); }
require_once $path;

use app\common\util\FieldGovernance;

final class MemoryFieldGovernance extends FieldGovernance
{
    public $states = [];
    public $values = [];
    protected function loadState(int $vodId, string $field): ?array { return $this->states[$vodId][$field] ?? null; }
    protected function persistValue(int $vodId, string $field, $value): void { $this->values[$vodId][$field] = $value; }
    protected function persistState(array $state): void { $this->states[$state['vod_id']][$state['field_name']] = $state; }
}

$governance = new MemoryFieldGovernance(static fn (): int => 1726704000);
if (!$governance->apply(42, 'title_tw', 'AI title', 'ai', 'run:1')) { fwrite(STDERR, "FAIL: AI must replace an unset/import field.\n"); exit(1); }
if ($governance->apply(42, 'title_tw', 'Unconfirmed TMDB', 'tmdb', 'tmdb:1')) { fwrite(STDERR, "FAIL: unconfirmed TMDB must not outrank AI.\n"); exit(1); }
if (!$governance->apply(42, 'title_tw', 'Confirmed TMDB', 'tmdb', 'tmdb:1', true)) { fwrite(STDERR, "FAIL: confirmed TMDB must replace AI.\n"); exit(1); }
if ($governance->apply(42, 'title_tw', 'Later AI', 'ai', 'run:2')) { fwrite(STDERR, "FAIL: AI must not replace confirmed TMDB.\n"); exit(1); }
if (!$governance->apply(42, 'title_tw', 'Editor title', 'manual', 'admin:7')) { fwrite(STDERR, "FAIL: manual input must replace background sources.\n"); exit(1); }
$state = $governance->states[42]['title_tw'];
if ($state['source'] !== 'manual' || $state['is_locked'] !== 1) { fwrite(STDERR, "FAIL: manual writes must automatically lock the field.\n"); exit(1); }
if ($governance->apply(42, 'title_tw', 'Blocked TMDB', 'tmdb', 'tmdb:2', true)) { fwrite(STDERR, "FAIL: background writes must not cross a manual lock.\n"); exit(1); }
if (!$governance->apply(42, 'title_tw', 'Approved TMDB', 'tmdb', 'tmdb:2', true, true)) { fwrite(STDERR, "FAIL: an explicit reviewed override must cross the lock.\n"); exit(1); }
if ($governance->values[42]['title_tw'] !== 'Approved TMDB' || $governance->states[42]['title_tw']['is_locked'] !== 0) { fwrite(STDERR, "FAIL: approved background override must persist and unlock the new source.\n"); exit(1); }

try { $governance->apply(0, 'bad-field', 'x', 'other'); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: field precedence and manual-lock contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: invalid field governance input must be rejected.\n"); exit(1);
