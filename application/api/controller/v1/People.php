<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1Locale;
use app\common\util\ApiV1PeopleService;
use think\Request;

class People extends Base
{
    public function detail(Request $request,$slug)
    {
        $locale=ApiV1Locale::resolve($request->get(),$request->header('Accept-Language'));
        $data=(new ApiV1PeopleService())->detail($slug,$locale);
        return $data===null?$this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request):$this->cacheableResponse($data,$request,['locale'=>$locale->code()]);
    }
}
