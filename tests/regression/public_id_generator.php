<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$generatorPath = $root . '/application/common/util/PublicIdGenerator.php';
if (!is_file($generatorPath)) {
    fwrite(STDERR, "FAIL: PublicIdGenerator is missing.\n");
    exit(1);
}
require $generatorPath;

use app\common\util\PublicIdGenerator;

function public_id_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$attempts = [];
$id = PublicIdGenerator::generate(function ($candidate) use (&$attempts) {
    $attempts[] = $candidate;
    return count($attempts) === 1;
});

public_id_assert((bool) preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $id), 'public ID format/alphabet is invalid.');
public_id_assert(count($attempts) === 2, 'one collision must cause exactly one retry.');
public_id_assert($id === $attempts[1], 'returned ID must be the first non-colliding candidate.');

$exhausted = false;
$exhaustionAttempts = 0;
try {
    PublicIdGenerator::generate(function () use (&$exhaustionAttempts) {
        $exhaustionAttempts++;
        return true;
    });
} catch (RuntimeException $exception) {
    $exhausted = $exception->getMessage() === 'Unable to allocate unique public ID.';
}
public_id_assert($exhausted, 'generator must throw the documented exhaustion error.');
public_id_assert($exhaustionAttempts === 32, 'generator must stop after 32 attempts.');

fwrite(STDOUT, "OK: public ID generation contract passed.\n");
