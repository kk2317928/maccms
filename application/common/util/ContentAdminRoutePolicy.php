<?php

namespace app\common\util;

use InvalidArgumentException;

class ContentAdminRoutePolicy
{
    private const ROUTES = [
        'view' => ['content_workspace/view'],
        'review' => ['content_workspace/review'],
        'merge_restore' => ['content_workspace/merge_restore'],
        'tmdb_review' => ['content_workspace/review', 'content_workspace/run_tmdb'],
        'publish' => ['content_workspace/publish'],
        'jobs' => ['content_workspace/view', 'content_workspace/run_ai', 'content_workspace/run_tmdb'],
    ];

    public function supports(string $route): bool
    {
        return isset(self::ROUTES[strtolower(trim($route))]);
    }

    public function allows(string $route, array $grants, int $adminId): bool
    {
        $route = strtolower(trim($route));
        if (!isset(self::ROUTES[$route])) {
            throw new InvalidArgumentException('Unknown intelligent-content admin route.');
        }
        if ($adminId === 1) {
            return true;
        }
        $normalized = [];
        foreach ($grants as $grant) {
            $grant = strtolower(trim((string) $grant));
            if ($grant !== '') { $normalized[$grant] = true; }
        }
        foreach (self::ROUTES[$route] as $permission) {
            if (isset($normalized[$permission])) { return true; }
        }
        return false;
    }
}
