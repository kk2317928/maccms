<?php
namespace app\common\util;

final class ApiV1EventDeduplicator
{
    public function fingerprint(array $event,$actorKey): string
    {
        $window=ApiV1EventContract::dedupeWindow($event['event_type']);
        $bucket=intdiv((int)$event['occurred_at'],$window);
        return hash('sha256',implode("\n",[
            (string)$actorKey,(string)$event['event_type'],(string)$event['public_id'],
            (string)($event['episode']??''),(string)$bucket,
        ]));
    }
}
