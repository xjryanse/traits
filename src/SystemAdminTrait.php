<?php
namespace xjryanse\traits;

use think\facade\Request;
use xjryanse\logic\ParamInherit;
use xjryanse\system\logic\ConfigLogic;
use xjryanse\system\logic\CompanyLogic;
use xjryanse\system\logic\ColumnLogic;

/**
 * 后台系统管理复用，一般需依赖一堆类库
 */
trait SystemAdminTrait
{    
    //参数继承
    protected $paramInherit;
    //公司信息
    protected $companyInfo;
    //全局公司key
    protected $scopeCompanyKey;
    //全局公司id
    protected $scopeCompanyId;
    //字段信息
    protected $columnInfo;
    //表名
    protected $table;
    //表id
    protected $tableId;
    //我的请求地址
    protected $myRequestUrl;
    //系统配置项
    protected $sysConfigs;
    
    /**
     * 【1】初始化参数设定
     */
    protected function initParamSet()
    {
        //参数继承
        $this->paramInherit     = ParamInherit::get();
        //全局公司key
        $this->scopeCompanyKey  = Request::param('comKey','') ? : session('scopeCompanyKey');
        //公司信息
        $this->companyInfo      = CompanyLogic::hasCompany();
        //全局公司id
        $this->scopeCompanyId   = $this->companyInfo['id'];
        //表的字段信息
        $controller             = strtolower( Request::controller() );
        $comKey                 = Request::param('admKey','');
        $this->columnInfo       = ColumnLogic::defaultColumn( $controller, $comKey );
        //对应表名
        $this->table            = $this->columnInfo['table_name'];
        //对应表id
        $this->tableId          = $this->columnInfo['id'];        
        //我的请求地址
        $this->myRequestUrl     = url( Request::action(), array_merge($this->paramInherit,['comKey'=>$this->scopeCompanyKey]));
        //配置项获取
        $this->sysConfigs       = ConfigLogic::getConfigs();
    }
    
    /**
     * 【2】初始化参数渲染
     */
    protected function initAssign()
    {
        //继承参数渲染
        $this->assign( 'paramInherit', $this->paramInherit );
        //公司信息
        $this->assign( 'companyInfo', $this->companyInfo );
        //comKey：公司key
        $this->assign( 'comKey', $this->scopeCompanyKey );
        //当前请求地址
        $this->assign( 'myRequestUrl', $this->myRequestUrl );
        //用户信息
        $this->assign( 'admUser', $this->admUser );
        //系统配置项数组
        $this->assign( 'sysConfigs', $this->sysConfigs);        
    }
}
