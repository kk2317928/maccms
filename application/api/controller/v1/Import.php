<?php
namespace app\api\controller\v1;

use app\common\util\ContentImportAuthenticator;
use app\common\util\ContentImportService;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\Request;
use Throwable;

class Import extends Base
{
    public function videos(Request $request)
    {
        try {
            $body=(string)$request->getInput();$path=(string)parse_url($request->url(),PHP_URL_PATH);
            $auth=new ContentImportAuthenticator((string)getenv('MACCMS_CONTENT_IMPORT_SECRET'),null,function($nonce,$timestamp){
                try{return Db::name('video_import_nonce')->insert(['nonce'=>$nonce,'request_timestamp'=>$timestamp,'created_at'=>time()])===1;}catch(Throwable $e){return false;}
            });
            $auth->authenticate('POST',$path,$body,$request->header('X-Import-Timestamp'),$request->header('X-Import-Nonce'),$request->header('X-Import-Signature'));
            $payload=json_decode($body,true);if(!is_array($payload))throw new InvalidArgumentException('Invalid JSON payload.');
            $result=(new ContentImportService())->import($payload,$request->header('Idempotency-Key'));
            unset($result['vod_id']);
            return $this->successResponse($result,$request,[],201);
        } catch(InvalidArgumentException $e){return $this->errorResponse('IMPORT_REJECTED',$e->getMessage(),422,$request);}
        catch(RuntimeException $e){return $this->errorResponse('IMPORT_FAILED','The import could not be completed.',409,$request);}
        catch(Throwable $e){return $this->internalError($request);}
    }
}
