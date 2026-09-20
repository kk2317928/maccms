<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1EventContract
{
    private const WINDOWS = [
        'play_start'=>300,
        'valid_watch'=>1800,
        'progress'=>30,
        'completion'=>86400,
        'favorite'=>86400,
    ];

    public static function types(): array { return array_keys(self::WINDOWS); }

    public static function dedupeWindow($type): int
    {
        $type=(string)$type;
        if (!isset(self::WINDOWS[$type])) throw new InvalidArgumentException('Unsupported event type.');
        return self::WINDOWS[$type];
    }

    public static function normalize(array $input): array
    {
        $type=isset($input['event_type'])?(string)$input['event_type']:'';
        self::dedupeWindow($type);
        $publicId=strtoupper(trim(isset($input['public_id'])?(string)$input['public_id']:''));
        if (!preg_match('/^[A-Z2-9]{6}$/',$publicId)) throw new InvalidArgumentException('Invalid public_id.');
        $occurred=filter_var($input['occurred_at']??null,FILTER_VALIDATE_INT);
        if ($occurred===false || $occurred<=0) throw new InvalidArgumentException('Invalid occurred_at.');
        $position=self::unsigned($input,'position_seconds',0,4294967295);
        $duration=self::unsigned($input,'duration_seconds',0,4294967295);
        if ($position<0 || $duration<0 || ($duration>0 && $position>$duration)) throw new InvalidArgumentException('Invalid playback position.');
        $episode=trim(isset($input['episode'])?(string)$input['episode']:'');
        if (strlen($episode)>191) throw new InvalidArgumentException('Invalid episode.');
        $eventId=trim(isset($input['event_id'])?(string)$input['event_id']:'');
        if ($eventId!=='' && !preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$eventId)) throw new InvalidArgumentException('Invalid event_id.');
        return [
            'event_type'=>$type,'public_id'=>$publicId,'episode'=>$episode,
            'position_seconds'=>$position,'duration_seconds'=>$duration,
            'occurred_at'=>(int)$occurred,'event_id'=>$eventId,
        ];
    }

    public static function actorKey($memberId,$sessionId,$deviceId): string
    {
        if ((int)$memberId>0) return 'member:'.(int)$memberId;
        $deviceId=trim((string)$deviceId);
        if ($deviceId!=='') return 'device:'.hash('sha256',$deviceId);
        $sessionId=trim((string)$sessionId);
        if ($sessionId!=='') return 'session:'.hash('sha256',$sessionId);
        throw new InvalidArgumentException('An event actor is required.');
    }
    private static function unsigned(array $input,$key,$default,$max): int
    {
        if(!array_key_exists($key,$input))return (int)$default;
        $value=$input[$key];
        if(is_int($value) && $value>=0 && $value<=$max)return $value;
        if(is_string($value) && preg_match('/\\A(?:0|[1-9][0-9]{0,9})\\z/D',$value)===1 && (float)$value<=$max)return (int)$value;
        throw new InvalidArgumentException('Invalid '.$key.'.');
    }
}
