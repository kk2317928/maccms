<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/application/common/util/TaxonomyDictionaryService.php';

use app\common\util\TaxonomyDictionaryService;

final class MemoryTaxonomyDictionary extends TaxonomyDictionaryService
{
    public $terms = [];
    public $references = [];
    public $events = [];

    protected function listRows(array $filters): array
    {
        return array_values(array_filter($this->terms, static function (array $row) use ($filters): bool {
            if (!empty($filters['kind']) && $row['kind'] !== $filters['kind']) { return false; }
            if (isset($filters['status']) && $filters['status'] !== '' && (int) $row['status'] !== (int) $filters['status']) { return false; }
            return true;
        }));
    }

    protected function findRow(int $termId): ?array { return $this->terms[$termId] ?? null; }

    protected function conflictingRows(string $kind, int $excludeTermId): array
    {
        return array_values(array_filter($this->terms, static fn(array $row): bool =>
            $row['kind'] === $kind && (int) $row['status'] === 1 && (int) $row['term_id'] !== $excludeTermId
        ));
    }

    protected function persistRow(array $row): array
    {
        $termId = (int) ($row['term_id'] ?? 0);
        if ($termId <= 0) { $termId = count($this->terms) + 1; }
        $row['term_id'] = $termId;
        $this->terms[$termId] = $row;
        return $row;
    }

    protected function referenceCount(int $termId): int { return (int) ($this->references[$termId] ?? 0); }
    protected function removeRow(int $termId): void { unset($this->terms[$termId]); }
    protected function transactional(callable $callback) { return $callback(); }
    protected function audit(string $event, int $actorId, array $before, array $after): void
    {
        $this->events[] = compact('event', 'actorId', 'before', 'after');
    }
}

$service = new MemoryTaxonomyDictionary(static fn(): int => 1000);
$genre = $service->save([
    'kind' => 'genre',
    'slug' => 'science-fiction',
    'name_tw' => '科幻',
    'name_cn' => ' 科幻 ',
    'name_en' => 'Science Fiction',
    'synonyms' => ['Sci-Fi', ' sci-fi ', '科 幻'],
    'status' => 1,
    'sort' => 10,
], 7);

assertDictionary($genre['term_id'] === 1, 'new term receives identity');
assertDictionary($genre['synonyms_json'] === '["Sci-Fi","科 幻"]', 'synonyms are trimmed and deduplicated');
assertDictionary($genre['created_at'] === 1000 && $genre['updated_at'] === 1000, 'timestamps use injected clock');
assertDictionary(count($service->events) === 1 && $service->events[0]['event'] === 'content.taxonomy.create', 'create is audited');

try {
    $service->save([
        'kind' => 'genre', 'slug' => 'sf-alt', 'name_tw' => '未來',
        'name_cn' => '', 'name_en' => 'Future', 'synonyms' => ['SCI-FI'],
        'status' => 1, 'sort' => 20,
    ], 7);
    assertDictionary(false, 'active same-kind synonym collision was accepted');
} catch (InvalidArgumentException $exception) {
    assertDictionary(strpos($exception->getMessage(), 'conflicts') !== false, 'collision gives a safe validation error');
}

$region = $service->save([
    'kind' => 'region', 'slug' => 'science-fiction', 'name_tw' => '科幻地區',
    'name_cn' => '', 'name_en' => '', 'synonyms' => ['Sci-Fi'],
    'status' => 1, 'sort' => 1,
], 7);
assertDictionary($region['term_id'] === 2, 'same token in a different kind is allowed');

$service->references[1] = 3;
$result = $service->delete(1, 7);
assertDictionary($result['action'] === 'deactivated' && (int) $service->terms[1]['status'] === 0, 'referenced term is deactivated');
assertDictionary(isset($service->terms[1]), 'referenced term is preserved');

$result = $service->delete(2, 7);
assertDictionary($result['action'] === 'deleted' && !isset($service->terms[2]), 'unreferenced term may be deleted');

foreach ([
    ['kind' => 'unknown', 'slug' => 'valid-slug'],
    ['kind' => 'genre', 'slug' => 'Invalid Slug'],
] as $invalid) {
    try {
        $service->save(array_merge([
            'name_tw' => '測試', 'name_cn' => '', 'name_en' => '',
            'synonyms' => [], 'status' => 1, 'sort' => 0,
        ], $invalid), 7);
        assertDictionary(false, 'invalid dictionary identity was accepted');
    } catch (InvalidArgumentException $exception) {
    }
}

$activeGenres = $service->list(['kind' => 'genre', 'status' => 1]);
assertDictionary($activeGenres === [], 'list filters inactive rows');

fwrite(STDOUT, "PASS: taxonomy dictionary domain contract\n");

function assertDictionary(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
