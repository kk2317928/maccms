<?php
namespace app\api\controller\v1;

use think\Request;

class Index extends Base
{
    public function index(Request $request)
    {
        return $this->successResponse(array('version' => 'v1'), $request);
    }

    public function methodNotAllowed(Request $request)
    {
        return $this->errorResponse('METHOD_NOT_ALLOWED', 'The request method is not allowed.', 405, $request);
    }

    public function notFound(Request $request)
    {
        return $this->errorResponse('NOT_FOUND', 'The requested resource was not found.', 404, $request);
    }
}
