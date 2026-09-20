<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1SiteConfig;
use think\Request;

class SiteConfig extends Base
{
    public function index(Request $request){return $this->cacheableResponse((new ApiV1SiteConfig())->toArray(),$request);}
}
