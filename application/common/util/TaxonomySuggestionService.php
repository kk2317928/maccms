<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

class TaxonomySuggestionService
{
    private $clock;

    public function __construct(callable $clock = null) { $this->clock = $clock ?: 'time'; }

    public function stage(int $vodId, string $source, string $sourceRef, array $taxonomy): array
    {
        if ($vodId <= 0 || !in_array($source, ['ai','tmdb','import'], true) || trim($sourceRef) === '') { throw new InvalidArgumentException('Invalid taxonomy suggestion source.'); }
        $map = ['regions'=>'region','genres'=>'genre','tags'=>'tag'];
        $rows = [];
        foreach ($map as $payloadKey => $kind) {
            foreach (array_values(array_unique((array) ($taxonomy[$payloadKey] ?? []))) as $value) {
                $value = trim((string) $value); if ($value === '') { continue; }
                $matches = $this->match($kind, $value);
                $status = count($matches) === 1 ? 'matched' : (count($matches) > 1 ? 'ambiguous' : 'missing');
                $rows[] = $this->persistSuggestion([
                    'vod_id'=>$vodId,'source'=>$source,'source_ref'=>$sourceRef,'kind'=>$kind,
                    'proposed_value'=>$value,'normalized_value'=>$this->normalize($value),
                    'matched_term_id'=>$status === 'matched' ? (int) $matches[0]['term_id'] : 0,
                    'match_status'=>$status,'decision'=>'pending','actor_id'=>0,'actor_name'=>'',
                    'created_at'=>(int) call_user_func($this->clock),'updated_at'=>(int) call_user_func($this->clock),
                ]);
            }
        }
        return $rows;
    }

    public function review(int $suggestionId, string $action, int $actorId, string $actorName): array
    {
        if ($suggestionId <= 0 || !in_array($action, ['accept','reject'], true) || $actorId <= 0 || trim($actorName) === '') { throw new InvalidArgumentException('Invalid taxonomy review.'); }
        return $this->transactional(function () use ($suggestionId,$action,$actorId,$actorName): array {
            $row = $this->loadSuggestionForUpdate($suggestionId); if (!$row) { throw new RuntimeException('Taxonomy suggestion was not found.'); }
            if ($action === 'accept' && ((string) $row['match_status'] !== 'matched' || (int) $row['matched_term_id'] <= 0)) { throw new RuntimeException('Only a unique existing term match may be accepted.'); }
            if ($action === 'accept' && $this->taxonomyLocked((int) $row['vod_id'], (string) $row['kind'])) { throw new RuntimeException('Taxonomy is manually locked.'); }
            $decision = $action === 'accept' ? 'accepted' : 'rejected';
            $this->updateSuggestion($suggestionId, ['decision'=>$decision,'actor_id'=>$actorId,'actor_name'=>trim($actorName),'updated_at'=>(int) call_user_func($this->clock)]);
            if ($decision === 'accepted') {
                $ids = array_values(array_unique(array_filter(array_map('intval', $this->acceptedTermIds((int) $row['vod_id'], (string) $row['kind'])))));
                sort($ids); $this->replaceTerms((int) $row['vod_id'], (string) $row['kind'], $ids); $this->syncNative((int) $row['vod_id']);
            }
            return array_merge($row, ['decision'=>$decision,'actor_id'=>$actorId,'actor_name'=>trim($actorName)]);
        });
    }

    public function pendingForSource(int $vodId, string $sourceRef): array
    {
        if ($vodId <= 0 || trim($sourceRef) === '') { return []; }
        return Db::name('content_taxonomy_suggestion')->where(['vod_id'=>$vodId,'source_ref'=>$sourceRef])->order('kind asc,suggestion_id asc')->select() ?: [];
    }

    protected function match(string $kind, string $value): array
    {
        $needle = $this->normalize($value); $matches = [];
        foreach ($this->loadActiveTerms($kind) as $term) {
            $values = [(string)($term['slug']??''),(string)($term['name_tw']??''),(string)($term['name_cn']??''),(string)($term['name_en']??'')];
            $synonyms = json_decode((string)($term['synonyms_json']??''), true); if (is_array($synonyms)) { $values = array_merge($values, $synonyms); }
            foreach ($values as $candidate) { if ($candidate !== '' && $this->normalize((string)$candidate) === $needle) { $matches[(int)$term['term_id']] = $term; break; } }
        }
        return array_values($matches);
    }

    protected function loadActiveTerms(string $kind): array { return Db::name('meta_term')->where(['kind'=>$kind,'status'=>1])->select() ?: []; }
    protected function persistSuggestion(array $row): array
    {
        $where = array_intersect_key($row, array_flip(['vod_id','source','source_ref','kind','normalized_value']));
        $existing = Db::name('content_taxonomy_suggestion')->where($where)->find();
        if ($existing) { return $existing; }
        try { $row['suggestion_id'] = (int) Db::name('content_taxonomy_suggestion')->insertGetId($row); return $row; }
        catch (Throwable $e) { $existing = Db::name('content_taxonomy_suggestion')->where($where)->find(); if ($existing) { return $existing; } throw $e; }
    }
    protected function loadSuggestionForUpdate(int $id): ?array { $r=Db::name('content_taxonomy_suggestion')->where('suggestion_id',$id)->lock(true)->find(); return $r ?: null; }
    protected function updateSuggestion(int $id,array $changes): void { Db::name('content_taxonomy_suggestion')->where('suggestion_id',$id)->update($changes); }
    protected function taxonomyLocked(int $vodId,string $kind): bool { return Db::name('vod_field_state')->where('vod_id',$vodId)->where('field_name','in',['taxonomy.'.$kind, VodExtensionService::NATIVE_FIELDS[$kind]])->where('is_locked',1)->count()>0; }
    protected function acceptedTermIds(int $vodId,string $kind): array
    {
        $existing=Db::name('vod_meta_term')->alias('v')->join('__META_TERM__ m','m.term_id=v.term_id')->where(['v.vod_id'=>$vodId,'m.kind'=>$kind])->column('v.term_id');
        $accepted=Db::name('content_taxonomy_suggestion')->where(['vod_id'=>$vodId,'kind'=>$kind,'decision'=>'accepted'])->column('matched_term_id'); return array_merge($existing ?: [],$accepted ?: []);
    }
    protected function replaceTerms(int $vodId,string $kind,array $ids): void { VodExtensionService::replaceTerms($vodId,$kind,$ids); }
    protected function syncNative(int $vodId): void { VodExtensionService::syncNativeTaxonomy($vodId); }
    protected function transactional(callable $callback) { return Db::transaction($callback); }
    private function normalize(string $value): string { $value=preg_replace('/\s+/u',' ',trim($value)); return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value); }
}
