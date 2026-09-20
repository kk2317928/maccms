<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1PlaybackPolicy
{
    private $sources;
    private $http;

    public function __construct(array $sources, callable $resolver = null)
    {
        $this->sources=$sources;
        $this->http=new ExternalHttpPolicy($resolver);
    }

    public function authorize($source,$url = null)
    {
        if ($url===null) { $url=$source[1]??''; $source=$source[0]??''; }
        $source=(string)$source;
        $settings=isset($this->sources[$source])&&is_array($this->sources[$source])?$this->sources[$source]:array();
        if (empty($settings['enabled']) || empty($settings['allowed_hosts']) || !is_array($settings['allowed_hosts'])) {
            throw new InvalidArgumentException('playback_source');
        }
        return $this->http->validate((string)$url,$settings['allowed_hosts']);
    }
}
