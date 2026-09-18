<?php
namespace app\common\model;

use InvalidArgumentException;

class MetaTerm extends Base
{
    public const KINDS = ['region', 'genre', 'tag'];
    protected $name = 'meta_term';
    protected $primaryId = 'term_id';
    protected $pk = 'term_id';
    protected $autoWriteTimestamp = false;

    public static function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unsupported metadata term kind: {$kind}.");
        }
    }
}
