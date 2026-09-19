<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1Locale
{
    private $code;
    private function __construct($code) { $this->code=$code; }

    public static function resolve(array $query, $acceptLanguage)
    {
        if (array_key_exists('locale',$query)) {
            if (!is_string($query['locale']) || trim($query['locale'])==='') { throw new InvalidArgumentException('locale'); }
            $code=self::normalize($query['locale'],false);
            if ($code===null) { throw new InvalidArgumentException('locale'); }
            return new self($code);
        }
        if (is_string($acceptLanguage)) {
            foreach (explode(',',$acceptLanguage) as $range) {
                $parts=explode(';',trim($range));
                $q=1.0;
                foreach(array_slice($parts,1) as $parameter) {
                    if (preg_match('/\A\s*q=([0-9.]+)\s*\z/i',$parameter,$matches)) { $q=(float)$matches[1]; }
                }
                if ($q<=0) continue;
                $code=self::normalize(trim($parts[0]),true);
                if ($code!==null) return new self($code);
            }
        }
        return new self('zh-TW');
    }

    public static function fromCode($code)
    {
        if (!is_string($code)) throw new InvalidArgumentException('locale');
        $normalized=self::normalize($code,false);
        if ($normalized===null) throw new InvalidArgumentException('locale');
        return new self($normalized);
    }

    public function code() { return $this->code; }

    public function selectTitle(array $values)
    {
        $orders=array(
            'zh-TW'=>array('tw','native','original','cn','en'),
            'zh-CN'=>array('cn','tw','native','original','en'),
            'en'=>array('en','original','tw','native','cn'),
        );
        return $this->first($values,$orders[$this->code],'');
    }

    public function selectName(array $values,$fallback='')
    {
        $primary=$this->code==='zh-CN'?'cn':($this->code==='en'?'en':'tw');
        return $this->first($values,array_values(array_unique(array($primary,'tw','cn','en'))),$fallback);
    }

    private function first(array $values,array $keys,$fallback)
    {
        foreach($keys as $key) {
            if (isset($values[$key]) && is_scalar($values[$key]) && trim((string)$values[$key])!=='') return (string)$values[$key];
        }
        return (string)$fallback;
    }

    private static function normalize($value,$header)
    {
        $value=strtolower(str_replace('_','-',trim((string)$value)));
        if ($value==='zh-tw' || ($header && in_array($value,array('zh-hant','zh-hk','zh-mo'),true))) return 'zh-TW';
        if ($value==='zh-cn' || ($header && in_array($value,array('zh-hans','zh-sg'),true))) return 'zh-CN';
        if ($value==='en' || ($header && strpos($value,'en-')===0)) return 'en';
        return null;
    }
}
