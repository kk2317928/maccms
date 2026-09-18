<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$vodSource = file_get_contents($root . '/application/common/model/Vod.php');
$collectSource = file_get_contents($root . '/application/common/model/Collect.php');

function hook_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function method_body($source, $method)
{
    $start = strpos($source, 'function ' . $method . '(');
    hook_assert($start !== false, "missing {$method} method.");
    $open = strpos($source, '{', $start);
    hook_assert($open !== false, "missing {$method} method body.");
    $depth = 0;
    $length = strlen($source);
    for ($index = $open; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open + 1, $index - $open - 1);
            }
        }
    }
    hook_assert(false, "unclosed {$method} method body.");
}

hook_assert(strpos($vodSource, 'use app\\common\\util\\VodExtensionService;') !== false, 'Vod must import VodExtensionService.');
$helper = method_body($vodSource, 'ensureExtension');
$guard = strpos($helper, '$vodId <= 0');
$ensure = strpos($helper, 'VodExtensionService::ensure');
hook_assert($guard !== false && $ensure !== false && $guard < $ensure, 'ensureExtension must reject nonpositive IDs before ensuring.');
hook_assert(strpos($helper, 'catch') !== false && strpos($helper, 'Log::error') !== false, 'ensureExtension must log mapped failures.');
hook_assert(strpos($helper, "'code'=>1002") !== false || strpos($helper, "'code' => 1002") !== false, 'ensureExtension must use native save failure code 1002.');

$save = method_body($vodSource, 'saveData');
$nativeSuccess = strpos($save, 'if(false === $res)');
$resolvedId = strpos($save, '$ixVodId =');
$saveEnsure = strpos($save, '$this->ensureExtension($ixVodId)');
$saveReturn = strrpos($save, "'code'=>1");
hook_assert($nativeSuccess !== false && $resolvedId !== false && $saveEnsure !== false, 'saveData must ensure after native success and ID resolution.');
hook_assert($nativeSuccess < $resolvedId && $resolvedId < $saveEnsure && $saveEnsure < $saveReturn, 'saveData extension hook is in the wrong order.');

$collect = method_body($collectSource, 'vod_data');
hook_assert(substr_count($collect, 'ensureExtension(') === 2, 'collection must hook exactly its insert and update boundaries.');
$insert = strpos($collect, "model('Vod')->insert");
$insertSuccess = strpos($collect, 'if ($vod_id > 0)', $insert);
$insertEnsure = strpos($collect, 'ensureExtension($vod_id)', $insert);
hook_assert($insert < $insertSuccess && $insertSuccess < $insertEnsure, 'collection insert must ensure only after a positive insert ID.');
$update = strpos($collect, "model('Vod')->where($where)->update($update)");
$updateSuccess = strpos($collect, '$res !== false', $update);
$updateEnsure = strpos($collect, "ensureExtension((int) $info['vod_id'])", $update);
hook_assert($update < $updateSuccess && $updateSuccess < $updateEnsure, 'collection update must ensure after every non-false update, including zero affected rows.');
hook_assert(strpos($collect, "\$color = 'red'", $insertEnsure) !== false, 'collection extension failures must use existing red/error routing.');
hook_assert(strpos($collect, 'content_job') === false, 'native persistence hooks must not enqueue AI work.');

fwrite(STDOUT, "OK: native video extension hook contract passed.\n");
