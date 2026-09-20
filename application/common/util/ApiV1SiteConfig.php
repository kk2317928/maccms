<?php
namespace app\common\util;

final class ApiV1SiteConfig
{
    private $config;
    public function __construct(array $config=null)
    {
        if($config!==null){$this->config=$config;return;}
        $this->config=isset($GLOBALS['config'])&&is_array($GLOBALS['config'])?$GLOBALS['config']:(array)config('maccms');
    }
    public function toArray()
    {
        $site=(array)($this->config['site']??[]);$app=(array)($this->config['app']??[]);
        return [
            'name'=>(string)($site['site_name']??''),'base_url'=>(string)($site['site_url']??''),
            'description'=>(string)($site['site_description']??''),'logo'=>(string)($site['site_logo']??''),
            'mobile_logo'=>(string)($site['site_waplogo']??''),'language'=>(string)($app['lang']??'zh-cn'),
            'search_enabled'=>(string)($app['search']??'0')==='1',
        ];
    }
}
