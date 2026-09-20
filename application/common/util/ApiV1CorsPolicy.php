<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1CorsPolicy
{
    private $origins;

    public function __construct(array $origins)
    {
        $this->origins=array();
        foreach($origins as $origin) {
            $origin=rtrim(trim((string)$origin),'/');
            if (!$this->validOrigin($origin)) throw new InvalidArgumentException('cors_origin');
            $this->origins[$origin]=true;
        }
    }

    public function headers($origin,$method,array $methods)
    {
        $origin=rtrim(trim((string)$origin),'/');
        if (!$this->validOrigin($origin) || !isset($this->origins[$origin])) throw new InvalidArgumentException('cors_origin');
        $method=strtoupper((string)$method);
        $methods=array_values(array_unique(array_map('strtoupper',$methods)));
        if (!in_array($method,$methods,true)) throw new InvalidArgumentException('cors_method');
        return array(
            'Access-Control-Allow-Origin'=>$origin,
            'Vary'=>'Origin',
            'Access-Control-Expose-Headers'=>'ETag, X-Request-ID, Retry-After',
        );
    }

    public function preflight($origin,$requestedMethod,array $methods)
    {
        $headers=$this->headers($origin,$requestedMethod,$methods);
        $allowed=array_values(array_unique(array_merge(array_map('strtoupper',$methods),array('OPTIONS'))));
        $headers['Access-Control-Allow-Methods']=implode(', ',$allowed);
        $headers['Access-Control-Allow-Headers']='Authorization, Content-Type, X-Request-ID';
        $headers['Access-Control-Max-Age']='600';
        return $headers;
    }

    private function validOrigin($origin)
    {
        if ($origin==='' || $origin==='*') return false;
        $parts=parse_url($origin);
        return is_array($parts)
            && isset($parts['scheme'],$parts['host'])
            && strtolower((string)$parts['scheme'])==='https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('/\\A[a-z0-9.-]+\\z/iD',(string)$parts['host'])===1;
    }
}
