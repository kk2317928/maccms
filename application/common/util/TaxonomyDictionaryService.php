<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class TaxonomyDictionaryService
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function list(array $filters = []): array
    {
        $normalized = [];
        if (isset($filters['kind']) && trim((string) $filters['kind']) !== '') {
            $normalized['kind'] = $this->kind((string) $filters['kind']);
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $normalized['status'] = $this->status($filters['status']);
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $normalized['q'] = trim((string) $filters['q']);
        }
        return $this->listRows($normalized);
    }

    public function save(array $input, int $actorId): array
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Taxonomy dictionary actor is invalid.');
        }

        return $this->transactional(function () use ($input, $actorId): array {
            $termId = max(0, (int) ($input['term_id'] ?? 0));
            $before = $termId > 0 ? $this->findRow($termId) : null;
            if ($termId > 0 && !$before) {
                throw new RuntimeException('Taxonomy term was not found.');
            }

            $now = (int) call_user_func($this->clock);
            $row = [
                'term_id' => $termId,
                'kind' => $this->kind((string) ($input['kind'] ?? '')),
                'slug' => $this->slug((string) ($input['slug'] ?? '')),
                'name_tw' => $this->text($input['name_tw'] ?? ''),
                'name_cn' => $this->text($input['name_cn'] ?? ''),
                'name_en' => $this->text($input['name_en'] ?? ''),
                'synonyms_json' => $this->synonymsJson($input['synonyms'] ?? []),
                'status' => $this->status($input['status'] ?? 1),
                'sort' => $this->sort($input['sort'] ?? 0),
                'created_at' => $before ? (int) $before['created_at'] : $now,
                'updated_at' => $now,
            ];
            if ($row['name_tw'] === '' && $row['name_cn'] === '' && $row['name_en'] === '') {
                throw new InvalidArgumentException('At least one taxonomy term name is required.');
            }
            if ($row['status'] === 1) {
                $this->assertNoConflicts($row, $termId);
            }

            $saved = $this->persistRow($row);
            $this->audit(
                $before ? 'content.taxonomy.update' : 'content.taxonomy.create',
                $actorId,
                $before ?: [],
                $saved
            );
            return $saved;
        });
    }

    public function deactivate(int $termId, int $actorId): array
    {
        if ($termId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('Taxonomy deactivation identity is invalid.');
        }

        return $this->transactional(function () use ($termId, $actorId): array {
            $before = $this->findRow($termId);
            if (!$before) {
                throw new RuntimeException('Taxonomy term was not found.');
            }
            $after = $before;
            $after['status'] = 0;
            $after['updated_at'] = (int) call_user_func($this->clock);
            $saved = $this->persistRow($after);
            $this->audit('content.taxonomy.deactivate', $actorId, $before, $saved);
            return $saved;
        });
    }

    public function delete(int $termId, int $actorId): array
    {
        if ($termId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('Taxonomy deletion identity is invalid.');
        }

        return $this->transactional(function () use ($termId, $actorId): array {
            $before = $this->findRow($termId);
            if (!$before) {
                throw new RuntimeException('Taxonomy term was not found.');
            }
            $references = $this->referenceCount($termId);
            if ($references > 0) {
                $after = $before;
                $after['status'] = 0;
                $after['updated_at'] = (int) call_user_func($this->clock);
                $saved = $this->persistRow($after);
                $this->audit('content.taxonomy.deactivate', $actorId, $before, $saved);
                return ['action' => 'deactivated', 'term' => $saved, 'references' => $references];
            }

            $this->removeRow($termId);
            $this->audit('content.taxonomy.delete', $actorId, $before, []);
            return ['action' => 'deleted', 'term_id' => $termId, 'references' => 0];
        });
    }

    protected function listRows(array $filters): array
    {
        $query = Db::name('meta_term');
        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (isset($filters['status'])) {
            $query->where('status', (int) $filters['status']);
        }
        if (isset($filters['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
            $query->where(function ($builder) use ($q) {
                $builder->where('slug', 'like', $q)
                    ->whereOr('name_tw', 'like', $q)
                    ->whereOr('name_cn', 'like', $q)
                    ->whereOr('name_en', 'like', $q)
                    ->whereOr('synonyms_json', 'like', $q);
            });
        }
        $rows = $query->order('kind asc,sort asc,term_id asc')->select();
        return is_array($rows) ? $rows : [];
    }

    protected function findRow(int $termId): ?array
    {
        $row = Db::name('meta_term')->where('term_id', $termId)->lock(true)->find();
        return $row ?: null;
    }

    protected function conflictingRows(string $kind, int $excludeTermId): array
    {
        $query = Db::name('meta_term')->where(['kind' => $kind, 'status' => 1]);
        if ($excludeTermId > 0) {
            $query->where('term_id', '<>', $excludeTermId);
        }
        $rows = $query->select();
        return is_array($rows) ? $rows : [];
    }

    protected function persistRow(array $row): array
    {
        $termId = (int) ($row['term_id'] ?? 0);
        if ($termId > 0) {
            Db::name('meta_term')->where('term_id', $termId)->update(array_diff_key($row, ['term_id' => true]));
        } else {
            unset($row['term_id']);
            $termId = (int) Db::name('meta_term')->insertGetId($row);
            if ($termId <= 0) {
                throw new RuntimeException('Taxonomy term could not be created.');
            }
            $row['term_id'] = $termId;
        }
        return $row;
    }

    protected function referenceCount(int $termId): int
    {
        return (int) Db::name('vod_meta_term')->where('term_id', $termId)->count();
    }

    protected function removeRow(int $termId): void
    {
        if (Db::name('meta_term')->where('term_id', $termId)->delete() !== 1) {
            throw new RuntimeException('Taxonomy term could not be deleted.');
        }
    }

    protected function transactional(callable $callback)
    {
        return Db::transaction($callback);
    }

    protected function audit(string $event, int $actorId, array $before, array $after): void
    {
        $subject = (string) ($after['term_id'] ?? $before['term_id'] ?? $after['slug'] ?? $before['slug'] ?? '');
        (new ContentAdminAudit())->append(
            $actorId,
            'admin#' . $actorId,
            $event,
            'taxonomy_term',
            $subject,
            $this->auditProjection($before),
            $this->auditProjection($after),
            []
        );
    }

    private function assertNoConflicts(array $row, int $excludeTermId): void
    {
        $wanted = $this->identityTokens($row);
        foreach ($this->conflictingRows($row['kind'], $excludeTermId) as $candidate) {
            if (array_intersect_key($wanted, $this->identityTokens($candidate))) {
                throw new InvalidArgumentException('Taxonomy term conflicts with an active term of the same kind.');
            }
        }
    }

    private function identityTokens(array $row): array
    {
        $values = [
            (string) ($row['slug'] ?? ''),
            (string) ($row['name_tw'] ?? ''),
            (string) ($row['name_cn'] ?? ''),
            (string) ($row['name_en'] ?? ''),
        ];
        $synonyms = json_decode((string) ($row['synonyms_json'] ?? '[]'), true);
        if (is_array($synonyms)) {
            $values = array_merge($values, $synonyms);
        }
        $tokens = [];
        foreach ($values as $value) {
            $normalized = $this->normalize((string) $value);
            if ($normalized !== '') {
                $tokens[$normalized] = true;
            }
        }
        return $tokens;
    }

    private function synonymsJson($input): string
    {
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : preg_split('/[,，\r\n]+/u', $input);
        }
        if (!is_array($input)) {
            throw new InvalidArgumentException('Taxonomy synonyms must be a list.');
        }
        $values = [];
        $seen = [];
        foreach ($input as $value) {
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Taxonomy synonym must be text.');
            }
            $text = $this->text($value);
            $key = $this->normalize($text);
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $values[] = $text;
            }
        }
        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['region', 'genre', 'tag'], true)) {
            throw new InvalidArgumentException('Unsupported taxonomy term kind.');
        }
        return $kind;
    }

    private function slug(string $slug): string
    {
        $slug = trim($slug);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 128) {
            throw new InvalidArgumentException('Taxonomy slug is invalid.');
        }
        return $slug;
    }

    private function text($value): string
    {
        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('Taxonomy text value is invalid.');
        }
        $value = trim((string) $value);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || strlen($value) > 128) {
            throw new InvalidArgumentException('Taxonomy text value is invalid.');
        }
        return $value;
    }

    private function status($status): int
    {
        if (!in_array((string) $status, ['0', '1'], true)) {
            throw new InvalidArgumentException('Taxonomy status must be zero or one.');
        }
        return (int) $status;
    }

    private function sort($sort): int
    {
        if (!preg_match('/^\d+$/', (string) $sort) || (int) $sort > 4294967295) {
            throw new InvalidArgumentException('Taxonomy sort value is invalid.');
        }
        return (int) $sort;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function auditProjection(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'term_id', 'kind', 'slug', 'name_tw', 'name_cn', 'name_en',
            'synonyms_json', 'status', 'sort',
        ]));
    }
}
