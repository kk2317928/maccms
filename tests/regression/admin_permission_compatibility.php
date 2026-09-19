<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routePolicyPath = $root . '/application/common/util/ContentAdminRoutePolicy.php';
if (!is_file($routePolicyPath)) {
    fwrite(STDERR, "FAIL: ContentAdminRoutePolicy.php is missing.\n");
    exit(1);
}
require_once $routePolicyPath;
require_once $root . '/application/common/util/ContentAdminPolicy.php';

use app\common\util\ContentAdminPolicy;
use app\common\util\ContentAdminRoutePolicy;

function adminCompatibilityAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$routePolicy = new ContentAdminRoutePolicy();
$routes = [
    'view' => [['content_workspace/view']],
    'review' => [['content_workspace/review']],
    'merge_restore' => [['content_workspace/merge_restore']],
    'tmdb_review' => [['content_workspace/review'], ['content_workspace/run_tmdb']],
    'publish' => [['content_workspace/publish']],
    'jobs' => [['content_workspace/view'], ['content_workspace/run_ai'], ['content_workspace/run_tmdb']],
];
$allPermissions = [
    'content_workspace/view', 'content_workspace/run_ai', 'content_workspace/run_tmdb',
    'content_workspace/review', 'content_workspace/merge_restore', 'content_workspace/publish',
    'content_workspace/bulk_overwrite', 'content_workspace/security',
];
foreach ($routes as $route => $grantSets) {
    adminCompatibilityAssert($routePolicy->allows($route, [], 9) === false, "{$route} must default deny delegated admins.");
    foreach ($grantSets as $grants) {
        adminCompatibilityAssert($routePolicy->allows($route, $grants, 9) === true, "{$route} rejected an exact route permission.");
    }
    $allowed = array_unique(array_merge(...$grantSets));
    foreach ($allPermissions as $permission) {
        if (!in_array($permission, $allowed, true)) {
            adminCompatibilityAssert($routePolicy->allows($route, [$permission], 9) === false, "{$route} accepted unrelated permission {$permission}.");
        }
    }
    adminCompatibilityAssert($routePolicy->allows($route, ['CONTENT_WORKSPACE/' . strtoupper(substr(strrchr($grantSets[0][0], '/'), 1))], 9) === true, "{$route} permission normalization must be case-insensitive.");
    adminCompatibilityAssert($routePolicy->allows($route, ['x' . $grantSets[0][0]], 9) === false, "{$route} accepted a permission substring.");
    adminCompatibilityAssert($routePolicy->allows($route, [], 1) === true, "{$route} must remain available to the super administrator.");
}
try {
    $routePolicy->allows('unknown', ['content_workspace/view'], 9);
    adminCompatibilityAssert(false, 'unknown intelligent-content route was accepted.');
} catch (InvalidArgumentException $exception) {}

$actionPolicy = new ContentAdminPolicy();
$actions = [
    'view' => 'content_workspace/view', 'run_ai' => 'content_workspace/run_ai',
    'run_tmdb' => 'content_workspace/run_tmdb', 'review' => 'content_workspace/review',
    'merge_restore' => 'content_workspace/merge_restore', 'publish' => 'content_workspace/publish',
    'bulk_overwrite' => 'content_workspace/bulk_overwrite', 'security' => 'content_workspace/security',
];
foreach ($actions as $action => $permission) {
    foreach ($allPermissions as $candidate) {
        $confirmed = in_array($action, ['merge_restore', 'publish', 'bulk_overwrite', 'security'], true);
        if ($candidate === $permission) {
            adminCompatibilityAssert($actionPolicy->assertAllowed($action, [$candidate], $confirmed), "{$action} rejected its exact action permission.");
            continue;
        }
        try {
            $actionPolicy->assertAllowed($action, [$candidate], true);
            adminCompatibilityAssert(false, "{$action} accepted unrelated action permission {$candidate}.");
        } catch (RuntimeException $exception) {}
    }
}

$base = @file_get_contents($root . '/application/admin/controller/Base.php') ?: '';
$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
adminCompatibilityAssert(strpos($base, 'ContentAdminRoutePolicy') !== false, 'native admin authorization must delegate intelligent-content route decisions to the tested route policy.');
foreach (array_keys($routes) as $route) {
    adminCompatibilityAssert(strpos($controller, 'function ' . $route . '(') !== false, "content workspace route {$route} is missing.");
}
foreach (['merge_restore' => 'confirmed', 'publish' => 'confirmed', 'jobs' => 'confirmed'] as $route => $needle) {
    $offset = strpos($controller, 'function ' . $route . '(');
    $next = strpos($controller, "\n    public function ", $offset + 10);
    $body = substr($controller, $offset, $next === false ? null : $next - $offset);
    adminCompatibilityAssert(strpos($body, $needle) !== false && strpos($body, 'mac_admin_csrf_token') !== false, "{$route} lost confirmation or stable CSRF handling.");
}
adminCompatibilityAssert(strpos($controller, 'TmdbExternalSourceProvider') === false && strpos($controller, 'AiNormalizationJobHandler') === false, 'admin HTTP controller must not invoke long-running external handlers.');

adminCompatibilityAssert(is_dir($root . '/application/admin/view_new/content_workspace'), 'intelligent-content templates must remain in active view_new.');
adminCompatibilityAssert(!is_dir($root . '/application/admin/view/content_workspace'), 'intelligent-content templates must not create an obsolete parallel view tree.');
foreach (['index', 'review', 'merge_restore', 'tmdb_review', 'publish', 'jobs'] as $template) {
    adminCompatibilityAssert(is_file($root . '/application/admin/view_new/content_workspace/' . $template . '.html'), "active admin template {$template} is missing.");
}

$vod = @file_get_contents($root . '/application/common/model/Vod.php') ?: '';
$collect = @file_get_contents($root . '/application/common/model/Collect.php') ?: '';
$codec = @file_get_contents($root . '/application/common/util/VodPlaybackCodec.php') ?: '';
adminCompatibilityAssert(strpos($vod, 'function saveData(') !== false && strpos($vod, 'ensureExtension(') !== false, 'native Vod::saveData extension boundary is missing.');
adminCompatibilityAssert(strpos($collect, 'function vod_data(') !== false && substr_count($collect, 'ensureExtension(') >= 2, 'native collection insert/update extension boundaries are missing.');
foreach (['vod_play_from', 'vod_play_url', 'vod_play_server', 'vod_play_note'] as $field) {
    adminCompatibilityAssert(strpos($codec, $field) !== false || strpos($codec, str_replace('vod_play_', '', $field)) !== false, "native playback compatibility field {$field} is not represented.");
}

$compatibilityWorkflows = [
    '.github/workflows/mysql57-foundation.yml',
    '.github/workflows/mysql80-foundation.yml',
    '.github/workflows/native-vod-foundation.yml',
];
$authorizationDependencies = [
    'application/admin/controller/Base.php',
    'application/admin/controller/ContentWorkspace.php',
    'application/common/util/ContentAdminPolicy.php',
    'application/common/util/ContentAdminRoutePolicy.php',
    'application/common/util/ContentJobAdminService.php',
    'tests/regression/admin_permission_compatibility.php',
];
foreach ($compatibilityWorkflows as $workflowPath) {
    $workflow = @file_get_contents($root . '/' . $workflowPath) ?: '';
    foreach ($authorizationDependencies as $dependency) {
        adminCompatibilityAssert(strpos($workflow, "'{$dependency}'") !== false, "{$workflowPath} does not trigger for authorization dependency {$dependency}.");
    }
}

$nativeWorkflow = @file_get_contents($root . '/.github/workflows/native-vod-foundation.yml') ?: '';
adminCompatibilityAssert(strpos($nativeWorkflow, 'tests/integration/native_vod_paths.php') !== false && strpos($nativeWorkflow, 'Verify native video paths') !== false, 'native admin/collection/playback compatibility must remain executable in CI.');

fwrite(STDOUT, "OK: intelligent-content permission matrix and native compatibility contract passed.\n");
