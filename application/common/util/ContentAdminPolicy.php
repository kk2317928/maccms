<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;

class ContentAdminPolicy
{
    private const PERMISSIONS = [
        'view' => 'content_workspace/view',
        'run_ai' => 'content_workspace/run_ai',
        'run_tmdb' => 'content_workspace/run_tmdb',
        'review' => 'content_workspace/review',
        'merge_restore' => 'content_workspace/merge_restore',
        'publish' => 'content_workspace/publish',
        'bulk_overwrite' => 'content_workspace/bulk_overwrite',
        'security' => 'content_workspace/security',
    ];
    private const CONFIRMATION_REQUIRED = ['merge_restore', 'publish', 'bulk_overwrite', 'security'];

    public function assertAllowed(string $action, array $grants, bool $confirmed = false): bool
    {
        $action = strtolower(trim($action));
        if (!isset(self::PERMISSIONS[$action])) {
            throw new InvalidArgumentException('Unknown content-admin action.');
        }
        $normalized = array_map(static function ($grant) { return strtolower(trim((string) $grant)); }, $grants);
        if (!in_array(self::PERMISSIONS[$action], $normalized, true)) {
            throw new RuntimeException('Exact content-admin permission is required.');
        }
        if (in_array($action, self::CONFIRMATION_REQUIRED, true) && !$confirmed) {
            throw new RuntimeException('Explicit confirmation is required.');
        }
        return true;
    }
}
