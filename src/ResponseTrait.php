<?php
namespace xjryanse\traits;

use think\facade\Request;
use xjryanse\user\service\UserService;
/**
 * 返回码复用
 */
trait ResponseTrait
{
    /**
     * 成功返回
     */
    protected static function succReturn($msg='请求成功',$data = '',$res = [])
    {
        $res['code']        = 0;     //20191205 数据返回的基本结构   三个字段   code=0 ,message='提示', data=>{}
        $res['message']     = $msg;
        $res['data']        = $data;
        $res['session_id']  = session_id();
        $res['user_id']     = session(SESSION_USER_ID);
        if(session('recUserId')){
            $res['recUserInfo'] = UserService::mainModel()->where('id',session('recUserId'))->field('id,nickname')->cache(86400)->find();
        }
        return json($res);
    }
    /**
     * 失败返回
     */
    protected static function errReturn($msg='请求失败',$data = '')
    {
        $res['code']    = 1;
        $res['message'] = $msg;
        $res['data']    = $data;
        return json($res);        
    }
    
    /**
     * 指定code返回
     */
    protected static function codeReturn($code= 999,$msg = '',$data=[])
    {
        $res['code']    = $code;
        $res['message'] = $msg;
        $res['data']    = $data;
        
        return json($res);
    }

    /**
     * 失败返回
     */
    protected static function dataReturn($msg='请求',$data = '')
    {
        if( $data ){
            return self::succReturn( $msg.'成功', $data );
        } else {
            return self::errReturn( $msg.'失败', $data );
        }
    }
    
    /**
     * 异常信息返回
     */
    protected function throwMsg(\Throwable $e)
    {
        $debug = Request::param('debug');
        if($debug == 'xjryanse'){
            $res['msg']     = $e->getMessage();
            $res['file']    = $e->getFile();
            $res['line']    = $e->getLine();
            $res['trace']   = $e->getTrace();

            return json($res);
        }
        return $this->errReturn( $e->getMessage() );
    }    
    
    /**
     * 分页兼容
     * @param type $res
     * @param type $msg
     * @return type
     */
    protected function paginateReturn($res)
    {
        if($res){
            $res = $res->toArray();
            return [
                    'total_result'  => $res['total'],
                    'page_size'     => $res['per_page'],
                    'page_no'       => $res['current_page'],
                    'last_page'     => $res['last_page'],
                    'data'          => $res['data'],
                ];
        }
    }
    
}
