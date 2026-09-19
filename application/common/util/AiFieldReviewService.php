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
        $payload = json_decode((string) $run['raw_response_json'], true);
        if (!is_array($payload)) { throw new RuntimeException('AI run response is not reviewable.'); }
        $fields = [];
        foreach (self::FIELD_MAP as $candidateKey => $field) {
            if (!array_key_exists($candidateKey, $payload)) { continue; }
            $current = $this->governance->inspect((int) $run['vod_id'], $field);
            $state = is_array($current['state'] ?? null) ? $current['state'] : [];
            $fields[$field] = [
                'current' => $current['value'] ?? null, 'candidate' => $payload[$candidateKey],
                'source' => (string) ($state['source'] ?? 'import'), 'source_ref' => (string) ($state['source_ref'] ?? ''),
                'locked' => !empty($state['is_locked']),
                'stale' => (int) ($state['updated_at'] ?? 0) > (int) $run['created_at'],
                'decision' => $this->loadDecision($runId, $field),
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
        if ($action === 'edit' && !(is_scalar($editedValue) || $editedValue === null)) { throw new InvalidArgumentException('Edited field value must be scalar.'); }
        $run = $preview['run'];
        $decision = ['accept' => 'accepted', 'edit' => 'edited', 'reject' => 'rejected', 'lock' => 'locked'][$action];
        $reviewedValue = $action === 'accept' ? $item['candidate'] : ($action === 'edit' ? $editedValue : ($action === 'lock' ? $item['current'] : null));
        $now = (int) call_user_func($this->clock);
        return $this->transactional(function () use ($runId, $field, $action, $actorId, $actorName, $item, $run, $decision, $reviewedValue, $now) {
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
                'reviewed_value_json' => $reviewedValue === null ? null : $this->encode($reviewedValue),
                'decision' => $decision, 'actor_id' => $actorId, 'actor_name' => trim($actorName),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->updateRunDecision($runId, 'reviewing');
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
    private function tablePrefix(): string
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) { throw new RuntimeException('Invalid database table prefix.'); }
        return $prefix;
    }
}
