<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1OpenApi;
use think\Request;

final class Docs extends Base
{
    public function openapi(Request $request)
    {
        return $this->cacheableRawDocumentResponse(ApiV1OpenApi::raw(),$request);
    }
}
