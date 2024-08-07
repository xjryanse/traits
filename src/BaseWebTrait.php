<?php
namespace xjryanse\traits;

use think\facade\Request;
use xjryanse\logic\ParamInherit;
use xjryanse\user\service\UserService;
use xjryanse\system\logic\ConfigLogic;
use xjryanse\system\logic\CompanyLogic;

/**
 * 后台系统管理复用，一般需依赖一堆类库
 */
trait BaseWebTrait
{
    //参数继承
    protected $paramInherit;
    //全局公司key
    protected $scopeCompanyKey;
    //公司信息
    protected $companyInfo;
    //全局公司id
    protected $scopeCompanyId;
    //我的请求地址
    protected $myRequestUrl;
    //系统配置项
    protected $sysConfigs;
    //推荐人id
    protected $recUserId;
    //推荐人id
    protected $recUserInfo;

    /**
     * 【1】初始化参数设定
     */
    protected function initWebParamSet()
    {
        //参数继承
        $this->paramInherit     = ParamInherit::get();
        //全局公司key
        $this->scopeCompanyKey  = Request::param('comKey','') ? : session(SESSION_COMPANY_KEY);
        //公司信息
        $this->companyInfo      = CompanyLogic::hasCompany();
        //全局公司id
        $this->scopeCompanyId   = $this->companyInfo['id'];
        //我的请求地址
        $this->myRequestUrl     = url( Request::action(), array_merge($this->paramInherit,['comKey'=>$this->scopeCompanyKey]));
        //配置项获取
        $this->sysConfigs       = ConfigLogic::getConfigs();
        //推荐人Id
        $this->recUserId        = Request::param('recUserId','') ? : (session('recUserId') ? : '');
        //推荐人信息存session
        session('recUserId',$this->recUserId);
        //推荐人信息
        $this->recUserInfo      = $this->recUserId  ? UserService::getInstance($this->recUserId)->get( 60 ) : [] ; 
    }

}
