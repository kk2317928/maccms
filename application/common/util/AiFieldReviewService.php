<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;

class AiFieldReviewService
{
    private const FIELD_MAP = [
        'normalized_title' => 'vod_name', 'original_title' => 'original_title',
        'title_tw' => 'title_tw', 'title_cn' => 'title_cn', 'title_en' => 'title_en',
        'year' => 'vod_year', 'media_type' => 'type2',
        'taxonomy.regions' => 'vod_area', 'taxonomy.genres' => 'vod_class', 'taxonomy.tags' => 'vod_tag',
    ];

    protected $governance;
    protected $audit;
    private $clock;

    public function __construct($governance, $audit, callable $clock = null)
    {
        $this->governance = $governance;
        $this->audit = $audit;
        $this->clock = $clock ?: 'time';
    }

    public function preview(int $runId): array
    {
        if ($runId < 1) { throw new InvalidArgumentException('AI run ID must be positive.'); }
        $run = $this->loadRun($runId);
        if ($run === null || (string) ($run['validation_status'] ?? '') !== 'valid') {
            throw new RuntimeException('Valid AI run was not found.');
        }
        if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', (string) ($run['public_id'] ?? ''))) {
            throw new RuntimeException('AI run video identity is unavailable.');
        }
        $payload = json_decode((string) $run['raw_response_json'], true);
        if (!is_array($payload)) { throw new RuntimeException('AI run response is not reviewable.'); }
        $fields = [];
        foreach (self::FIELD_MAP as $candidateKey => $field) {
            $candidate = $this->candidateFromPayload($payload, $candidateKey);
            if ($candidate === null) { continue; }
            $current = $this->governance->inspect((int) $run['vod_id'], $field);
            $state = is_array($current['state'] ?? null) ? $current['state'] : [];
            $stored = $this->loadDecision($runId, $field);
            $candidate = $stored ? $this->decode((string) $stored['candidate_value_json']) : $candidate;
            $baselineHash = (string) ($stored['baseline_hash'] ?? '');
            $fields[$field] = [
                'current' => $current['value'] ?? null, 'candidate' => $candidate,
                'source' => (string) ($state['source'] ?? 'import'), 'source_ref' => (string) ($state['source_ref'] ?? ''),
                'locked' => !empty($state['is_locked']),
                'stale' => $baselineHash === '' || !hash_equals($baselineHash, $this->valueHash($current['value'] ?? null)),
                'decision' => $stored,
            ];
        }
        return ['run' => $run, 'fields' => $fields];
    }

    public function review(int $runId, string $field, string $action, $editedValue, int $actorId, string $actorName): array
    {
        if (!in_array($action, ['accept', 'edit', 'reject', 'lock'], true)) { throw new InvalidArgumentException('Unsupported AI field-review action.'); }
        if ($actorId < 1 || trim($actorName) === '' || strlen($actorName) > 100) { throw new InvalidArgumentException('Invalid AI field reviewer.'); }
        $preview = $this->preview($runId);
        if (!isset($preview['fields'][$field])) { throw new InvalidArgumentException('Field is not present in this AI run.'); }
        $item = $preview['fields'][$field];
        if ($action === 'accept' && $item['locked']) { throw new RuntimeException('AI field is locked and cannot be accepted.'); }
        if ($action === 'accept' && $item['stale']) { throw new RuntimeException('AI field is stale and must be reviewed again.'); }
        if ($action === 'accept') { $item['candidate'] = $this->validateFieldValue($field, $item['candidate']); }
        if ($action === 'edit') { $editedValue = $this->validateFieldValue($field, $editedValue); }
        $run = $preview['run'];
        $decision = ['accept' => 'accepted', 'edit' => 'edited', 'reject' => 'rejected', 'lock' => 'locked'][$action];
        $reviewedValue = $action === 'accept' ? $item['candidate'] : ($action === 'edit' ? $editedValue : ($action === 'lock' ? $item['current'] : null));
        $now = (int) call_user_func($this->clock);
        $proposedCount = count($preview['fields']);
        return $this->transactional(function () use ($runId, $field, $action, $actorId, $actorName, $item, $run, $decision, $reviewedValue, $now, $proposedCount) {
            $this->lockVideoRows((int) $run['vod_id']);
            $fresh = $this->governance->inspect((int) $run['vod_id'], $field);
            $stored = $this->loadDecision($runId, $field);
            if (!$stored) { throw new RuntimeException('AI field baseline is unavailable.'); }
            if ($action === 'accept' && !hash_equals((string) $stored['baseline_hash'], $this->valueHash($fresh['value'] ?? null))) {
                throw new RuntimeException('AI field is stale and must be reviewed again.');
            }
            $this->audit->append($actorId, $actorName, 'content.ai_field.' . $decision, 'vod', (string) ($run['public_id'] ?? ''),
                ['field' => $field, 'value' => $item['current'], 'source' => $item['source'], 'locked' => $item['locked']],
                ['field' => $field, 'value' => $reviewedValue, 'decision' => $decision],
                ['ai_run_id' => $runId, 'provider' => (string) $run['provider'], 'model' => (string) $run['model'], 'prompt_version' => (string) $run['prompt_version']]
            );
            if ($action === 'accept') {
                if (!$this->governance->apply((int) $run['vod_id'], $field, $item['candidate'], 'ai', 'ai_run:' . $runId . ':admin:' . $actorId)) { throw new RuntimeException('Field governance blocked the AI value.'); }
            } elseif ($action === 'edit' || $action === 'lock') {
                if (!$this->governance->apply((int) $run['vod_id'], $field, $reviewedValue, 'manual', 'ai_run:' . $runId . ':admin:' . $actorId)) { throw new RuntimeException('Field governance blocked the manual value.'); }
            }
            $this->persistDecision([
                'ai_run_id' => $runId, 'vod_id' => (int) $run['vod_id'], 'field_name' => $field,
                'candidate_value_json' => $this->encode($item['candidate']),
                'baseline_value_json' => (string) $stored['baseline_value_json'],
                'baseline_hash' => (string) $stored['baseline_hash'],
                'reviewed_value_json' => $reviewedValue === null ? null : $this->encode($reviewedValue),
                'decision' => $decision, 'actor_id' => $actorId, 'actor_name' => trim($actorName),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->updateRunDecision($runId, $this->countDecisions($runId) >= $proposedCount ? 'reviewed' : 'reviewing');
            return ['decision' => $decision, 'field' => $field, 'value' => $reviewedValue];
        });
    }

    public function pendingRuns(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        $query = Db::name('content_ai_run')->alias('r')->join($this->tablePrefix() . 'vod v', 'v.vod_id=r.vod_id', 'left')
            ->where('r.validation_status', 'valid')->where('r.decision_status', 'in', ['pending', 'reviewing']);
        $total = (int) (clone $query)->count();
        $rows = $query->field('r.ai_run_id,r.vod_id,r.provider,r.model,r.prompt_version,r.decision_status,r.created_at,v.vod_name')
            ->order('r.created_at asc,r.ai_run_id asc')->page($page, $limit)->select();
        return ['total' => $total, 'rows' => $rows ?: [], 'page' => $page, 'limit' => $limit];
    }

    protected function loadRun(int $runId): ?array
    {
        $row = Db::name('content_ai_run')->alias('r')->join($this->tablePrefix() . 'vod_ext e', 'e.vod_id=r.vod_id', 'left')
            ->where('r.ai_run_id', $runId)->field('r.*,e.public_id')->find();
        return $row ?: null;
    }
    protected function loadDecision(int $runId, string $field): ?array { $row = Db::name('content_ai_field_review')->where(['ai_run_id' => $runId, 'field_name' => $field])->find(); return $row ?: null; }
    protected function countDecisions(int $runId): int { return (int) Db::name('content_ai_field_review')->where('ai_run_id', $runId)->where('decision', '<>', 'pending')->count(); }
    protected function lockVideoRows(int $vodId): void
    {
        if (!Db::name('vod')->where('vod_id', $vodId)->lock(true)->find() || !Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find()) {
            throw new RuntimeException('AI run video record is unavailable.');
        }
    }
    protected function persistDecision(array $row): void
    {
        $existing = $this->loadDecision((int) $row['ai_run_id'], (string) $row['field_name']);
        if ($existing) {
            $row['created_at'] = (int) $existing['created_at'];
            Db::name('content_ai_field_review')->where(['ai_run_id' => $row['ai_run_id'], 'field_name' => $row['field_name']])->update($row); return;
        }
        Db::name('content_ai_field_review')->insert($row);
    }
    protected function updateRunDecision(int $runId, string $status): void { Db::name('content_ai_run')->where('ai_run_id', $runId)->update(['decision_status' => $status]); }
    protected function transactional(callable $callback) { return Db::transaction($callback); }
    private function encode($value): string
    {
        try { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new InvalidArgumentException('AI field value is not JSON encodable.', 0, $exception); }
    }
    private function decode(string $json)
    {
        try { return json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Stored AI field value is invalid.', 0, $exception); }
    }
    private function valueHash($value): string { return hash('sha256', $this->encode($value)); }
    private function validateFieldValue(string $field, $value)
    {
        if ($field === 'vod_year') {
            if (is_string($value) && preg_match('/^[0-9]{4}$/', $value)) { $value = (int) $value; }
            if (!is_int($value) || $value < 1870 || $value > (int) date('Y') + 2) { throw new InvalidArgumentException('Edited year is invalid.'); }
            return $value;
        }
        if ($field === 'type2') {
            if (!is_string($value) || !in_array(trim($value), ['movie', 'tv', 'anime', 'short'], true)) { throw new InvalidArgumentException('Edited media type is invalid.'); }
            return trim($value);
        }
        if (!is_string($value)) { throw new InvalidArgumentException('Edited title must be text.'); }
        $value = trim($value);
        if (preg_match('/[<>]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) { throw new InvalidArgumentException('Edited title is invalid.'); }
        if ($field === 'vod_name' && $value === '') { throw new InvalidArgumentException('Edited video name is required.'); }
        $value = function_exists('mac_filter_xss') ? mac_filter_xss($value) : trim(htmlspecialchars(strip_tags($value), ENT_QUOTES));
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > 255) { throw new InvalidArgumentException('Edited title is too long after canonicalization.'); }
        return $value;
    }
    private function candidateFromPayload(array $payload, string $key)
    {
        if (strpos($key, 'taxonomy.') !== 0) {
            return array_key_exists($key, $payload) ? $payload[$key] : null;
        }
        $part = substr($key, strlen('taxonomy.'));
        $values = $payload['taxonomy'][$part] ?? null;
        return is_array($values) ? implode(',', $values) : null;
    }

    private function tablePrefix(): string
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) { throw new RuntimeException('Invalid database table prefix.'); }
        return $prefix;
    }
}
