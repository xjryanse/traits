<?php
namespace xjryanse\traits;

use think\facade\Request;
use xjryanse\user\service\UserService;
use xjryanse\system\logic\ConfigLogic;
use xjryanse\logic\CodeClass;
use xjryanse\logic\DbOperate;
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
        // 拼接开发模式参数
        return json(array_merge($res,self::devModeRes()));
    }
    /**
     * 失败返回
     */
    protected static function errReturn($msg='请求失败',$data = '')
    {
        $res['code']    = 1;
        $res['message'] = $msg;
        $res['data']    = $data;
        return json(array_merge($res,self::devModeRes()));
    }
    
    /**
     * 指定code返回
     */
    protected static function codeReturn($code= 999,$msg = '',$data=[], $trace = [])
    {
        $res['code']    = $code;
        $res['message'] = $msg;
        $res['data']    = $data;
        // 20230727;输出错误信息
        if($trace){
            $res['trace'] = $trace;
        }
        
        return json(array_merge($res,self::devModeRes()));
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
    
    /**
     * 20240419:开发模式参数
     * @return string
     */
    private static function devModeRes(){
        if(!ConfigLogic::config('isDevMode')){
            return [];
        }
        $res                =  [];
        $res['session_id']  = session_id();
        $res['user_id']     = session(SESSION_USER_ID);
        $res['requestIp']   = Request::ip();
        if(session('recUserId')){
            $res['recUserInfo'] = UserService::mainModel()->where('id',session('recUserId'))->field('id,nickname')->cache(86400)->find();
        }
        // 20240419:方便本地调试
        $controller             = Request::controller();
        $admKey                 = Request::param('admKey');
        $tableName              = DbOperate::controllerAdmKeyToTable($controller, $admKey);
        $tableService           = DbOperate::getService($tableName);
        $serviceFilePath        = CodeClass::classGetFilePath($tableService);
        $projectBasePath        = dirname($_SERVER['DOCUMENT_ROOT']);     
        $localPath              = str_replace($projectBasePath, '', $serviceFilePath);
        $codePath               = 'http://localhost:9633/cmd.php?filePath='.$localPath.'&startLine=1&host='.Request::host();
        $res['NBCodePath']      = $codePath;
        return $res;
    }
}
