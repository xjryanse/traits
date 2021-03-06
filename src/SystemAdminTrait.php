<?php
namespace xjryanse\traits;

use think\Db;
use think\facade\Request;
use xjryanse\logic\ParamInherit;
use xjryanse\logic\DbOperate;
use xjryanse\system\logic\ConfigLogic;
use xjryanse\system\logic\CompanyLogic;
use xjryanse\system\logic\ColumnLogic;
use xjryanse\system\logic\ExportLogic;

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
        $this->scopeCompanyKey  = Request::param('comKey','') ? : session(SESSION_COMPANY_KEY);
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
        //session用户信息
        $this->admUser          = session('scopeUserInfo');
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
        $this->assign( 'sysConfigs', $this->sysConfigs );
        //字段信息
        $this->assign( 'columnInfo', $this->columnInfo );
        //公司码
        $this->assign(SESSION_COMPANY_ID,$this->scopeCompanyId);
    }
    /**
     * 公共列表页
     */
    protected function commList( $cond = [])
    {
        if(Request::isAjax()){
            $list               = $this->commListData( $cond );
            $list['columnInfo'] = $this->columnInfo;
            return $this->dataReturn('获取数据',$list);
        }
        $this->assign('columnInfo',$this->columnInfo);
        return $this->fetch( $this->template ? : 'common/list');
    }
    /*
     * 【xjl】公共列表数据
     */
    protected function commListData( $cond = [] )
    {
        $param      = Request::param();
        $uparam     = $this->unsetEmpty($param);

        $info       = $this->columnInfo;
        
        $this->debug('xinxi',$info);
        $fields     = ColumnLogic::getSearchFields($this->columnInfo);

        $con        = array_merge( $this->queryCon($uparam, $fields) ,$cond);
        //年月渲染，根据是否设置了字段来判断显示
        if($info['yearmonth_field']){
            $this->yearMonthAssign();
            $yearMonthTimeCon = $this->yearMonthTimeCon( $info['yearmonth_field'] );
            if( $yearMonthTimeCon ){
                $con = array_merge( $con,$yearMonthTimeCon );
            }
        }
        
        $this->debug( '查询条件con',$con );
        //查询条件缓存（用于导出）
        session(Request::module().Request::controller().'_con',$con);
        //获取分页列表
        $perPage        = Request::param( 'per_page', 20 );
        $class          = DbOperate::getService( $info['table_name'] );
        //分页数据
        $list   = $class::paginate( $con , $info['order_by'] ,$perPage);

        //添加额外的数据
        foreach($list['data'] as &$vv){
            //role_id,access_id等类型
            foreach( $this->columnInfo['listInfo'] as $v){
                //复选框
                if($v['type'] == 'check'){
                    $con1   = [];
                    $con1[] = [$v['option']['main_field'],'=',$vv['id']];
                    $rr = ColumnLogic::dynamicColumn($v['option']['to_table'], $v['name'], 'id',$con1);
                    $vv[$v['name']] = array_values($rr);
                }
                //上传图片
                if($v['type'] == 'uplimg'){
                   
                }
            }
        }
        
        return $list;
    }
    
    /**
     * 公共新增页
     */
    protected function commAdd()
    {
        $header = $this->commHeader();
//        dump($header);
        //请求的参数，带入表单
        $data = Request::param();
        $this->assign('row', $data);
        //表单类型：添加
        $this->assign('formType', 'add');
        $this->debug('row', $data );
        
        $url = isset($header['add_ajax_url']) && $header['add_ajax_url'] 
                ? $header['add_ajax_url']
                :'save';
        $this->assign('formUrl', url( $url ,array_merge(Request::param(),['comKey'=>$this->scopeCompanyKey])) );
        $this->assign('btnName', '新增' );
        return $this->fetch( $this->template ? : 'common/add');
    }
    /**
     * 公共编辑页
     */
    protected function commEdit()
    {
        $header = $this->commHeader();
        $id = Request::param('id',0);
        if( !$id ){
            return ;
        }

        $info = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res    = $class::getInstance( $id )->get( );
        
        foreach( $this->columnInfo['listInfo'] as $v){
            if( in_array( $v['type'],[ FR_COL_TYPE_CHECK,FR_COL_TYPE_DYNTREE]) ){
//                dump($v);
                $con1   = [];
                $con1[] = [$v['option']['main_field'],'=',$res['id']];
                $rr = ColumnLogic::dynamicColumn($v['option']['to_table'], $v['name'], 'id',$con1);
                $res[$v['name']] = array_values($rr);
            }
        }

        $this->assign('row', $res );
        //表单类型：添加
        $this->assign('formType', 'edit');
        $this->debug('row', $res );

        $ajaxUrl = isset($header['edit_ajax_url']) && $header['edit_ajax_url'] 
                ? $header['edit_ajax_url']
                :'update';
        $this->assign('formUrl', url( $ajaxUrl ,array_merge(Request::param(),['comKey'=>$this->scopeCompanyKey])) );
        $this->assign('btnName', '编辑' );

        return $this->fetch( $this->template ? : 'common/add');
    }
    /**
     * 公共删除接口
     */
    protected function commDel()
    {
        //取请求字段内容
        $data               = Request::post();
        if( !isset($data['id']) || ! $data['id'] ) {
            return $this->errReturn( 'id必须' );
        }
        $info               = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res    = $class::getInstance( $data['id'] )->delete( );
        return $this->dataReturn('数据删除',$res);
    }
    /**
     * 公共保存接口
     */
    protected function commSave()
    {
        //取请求字段内容
        $postData           = Request::post();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res    = $class::save( $data );
        
        if(isset($res['id'])){
            //中间表数据保存
            $this->midSave( $this->columnInfo , $res['id'] ,$data );
        }
        return $this->dataReturn('数据保存',$res);        
    }

    /**
     * 公共保存接口
     */
    protected function commUpdate()
    {
        //取请求字段内容
        $postData           = Request::post();  
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);

        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );

        $res = $class::getInstance( $data['id'] )->update( $data );
        //中间表数据保存
        $this->midSave( $this->columnInfo , $data['id'] ,$data );

        return $this->dataReturn('数据更新',$res);      
    }
    /*
     * 中间表数据保存
     */
    protected function midSave($columnInfo,$id,$data)
    {
        foreach( $columnInfo['listInfo'] as $v){
            //未设该值则跳过
            if(!isset($data[$v['name']])){
                continue;
            }
            //复选框，动态树
            if( in_array($v['type'],[FR_COL_TYPE_CHECK, FR_COL_TYPE_DYNTREE ])){
                $con1   = [];
                $con1[] = [$v['option']['main_field'],'=', $id ];
                //先删再写
                $class = DbOperate::getService( $v['option']['to_table'] );
                $class::mainModel()->where( $con1 )->delete();
                foreach($data[$v['name']] as $vv){
                    //写资源
                    $tmpData = [];
                    $tmpData[$v['option']['main_field']] = $id;
                    $tmpData[$v['option']['to_field']] = $vv;
                    //TODO优化为批量保存
                    $class::save( $tmpData );
                }
            }
        }
    }
    
    protected function commExport()
    {
        $con = session(Request::module().Request::controller().'_con' );
        //字段
        $info = $this->columnInfo;

        foreach( $info['listInfo'] as $v){
            if(!$v['is_export']){
                continue;
            }
            //动态枚举，项目转换
            if($v['type'] == 'dynenum'){
                $v['option'] = $v['option']['option'];
            }
            //枚举项导出
            if( in_array($v['type'],['enum','dynenum']) ){
                $str = "(CASE ". $v['name'] ;
                foreach( $v['option'] as $kk=>$vv){
                    $str .= " WHEN '". $kk ."' THEN '". $vv  ."'";
                }
                $str .= " ELSE '' END) as `".$v['label'].'`';
                $fields[] = $str;
            } else {
                $fields[] = ' concat('. $v['name'].",'\t') as `".$v['label'].'`';
            }
        }

        $field = implode(',',$fields);
        //请求本地进行导出操作
        $sql = Db::table( $this->columnInfo['table_name'] ) ->where( $con ) ->field( $field ) ->buildSql();
        
        $fileName = ExportLogic::getInstance()->exportToCsv($sql);
        
        return $this->redirect( Request::domain().'/Uploads/Download/CanDelete/'.$fileName );
    }
    /**
     * 数据表头信息
     */
    protected function commHeader()
    {
        $header     = $this->columnInfo;
        $this->assign('header', $header );
        $this->debug('header', $header );
        return $header;
    }
    /**
     * 中间数据添加
     * @param type $mainId
     * @param type $type    0:添加，1更新
     */
    protected function midAdd( $mainId ,$type = 0)
    {
        $key = $type ? "is_edit" : "is_add";
        //写入多对多中间表
        $con[] = ['type','in',[14,15]];
        $list   = QColumnService::getInstance($this->table)->getList( $con );
        foreach( $list as $v){
            if($v[ $key ] == 0){
                continue;
            }
            $data = Request::only( $v['name'] );
            if(!$data){
                continue;
            }
            $this->writeMidData($data, $mainId, Db::table( $v['option']['to_table'] ), $v['option']['to_field'] , $v['option']['main_field'] );
        }
        
        //写入一对多中间表
        $con2[] = ['type','in',[20]];
        $list2   = QColumnService::getInstance($this->table)->getList( $con2 );
        foreach( $list2 as $v){
            if($v[ $key ] == 0){
                continue;
            }
            $data2 = Request::only( $v['name'] );   
            if(!$data2){
                continue;
            }
            if(DbService::getInstance( $v['option']['table_name'] )->dbHasField('company_id') && session(SESSION_COMPANY_ID)){
                $data2[$v['name']]['company_id'] = session(SESSION_COMPANY_ID);
            }
            
            if(isset( $v['option']['label'] )){
                $data2[ $v['name'] ][ $v['option']['label'] ] = $v['label'];
            }
            foreach($data2[ $v['name'] ] as $kk=>&$vvv){
                if(!$vvv && DbService::getInstance( $v['option']['table_name'] )->isDateTime( $kk )){
                    $vvv = null;
                }
            }
            $this->writeHasManyData( $data2 , $mainId, Db::table( $v['option']['table_name'] ), $v['name'] , $v['option']['main_field'] , $v['option']['key'] );
        }
    }
    
    /**
     * 公共保存接口
     */
    protected function commCopy()
    {
        $id = Request::param('id','');
        if( !$id ){
            return $this->errReturn('id必须');
        }
        $info       = $this->columnInfo;
        //校验是否可复制
        $canCopy = false;
        foreach( $info['operateInfo']  as $v){
            if($v['operate_key']  == 'copy' ){
                $canCopy = true;
            }
        }

        if(!$canCopy){
            return $this->errReturn('本表不可复制');
        }
        
        //表名取服务类
        $class      = DbOperate::getService( $info['table_name'] );
        $dataInfo   = $class::getInstance( $id )->get( );
        $res        = $dataInfo->toArray();

        if( isset($res['id'])){ unset($res['id']);}
        if( isset($res['create_time'])){ unset($res['create_time']);}
        if( isset($res['update_time'])){ unset($res['update_time']);}

        Db::startTrans();
        try {
            //保存
            $resp   = $class::save( $res );
            // 提交事务
            Db::commit();
            return $this->succReturn('数据复制',$resp);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return $this->throwMsg($e);
        }
    }
    /**
     * 年月渲染
     */
    protected function yearMonthAssign()
    {
        $yearmonth = Request::param('yearmonth',date('Y-m'));
        if( $yearmonth ){
            $year   = date('Y',strtotime($yearmonth));
            $month  = date('m',strtotime($yearmonth));
        } else {
            $year   = date('Y');
            $month  = date('m');
        }
        //每月多少天
        $monthlyDays = cal_days_in_month(0, $month, $year);
        $this->assign('yearmonth',$yearmonth);
        $this->assign('monthlyDays',$monthlyDays);
        
        $day = Request::param('day','');
        $this->assign('day',$day);        
    }
    
    /**
     * 年月时间条件
     * @param type $key
     */
    protected function yearMonthTimeCon( $key )
    {
        $con = [];
        //年月
        $yearmonth = Request::param('yearmonth',date('Y-m'));
        if( $yearmonth ){
            $startDate  = date('Y-m-01 00:00:00',strtotime($yearmonth));
            $endDate    = date('Y-m-d 23:59:59',strtotime($yearmonth ." +1 month -1 day"));
        
            $con[] = [ $key ,'>=',$startDate];
            $con[] = [ $key ,'<=',$endDate];
        }
        //日
        $day = Request::param('day','');
        if( $day ){
            $day = $yearmonth 
                    ? $yearmonth .'-'.$day 
                    : date('Y-m') .'-'.$day ;
            $startDate  = date('Y-m-d 00:00:00',strtotime( $day ));
            $endDate    = date('Y-m-d 23:59:59',strtotime( $day ));
        
            $con[] = [ $key ,'>=',$startDate];
            $con[] = [ $key ,'<=',$endDate];
        }
        return $con;
    }

    /**
     * 数据转换
     * @param type $data
     * @param type $columnInfo
     * @return type
     */
    protected function commDataCov($data,$columnInfo)
    {
        //数据转换
        foreach( $data as $k=>&$v){
            foreach( $columnInfo['listInfo'] as $field){
                if( $field['type'] == 'uplimage' &&  $k == $field['name'] && isset($v['id'] ) ) {
                    $v = $v['id'];
                }
            }
        }
        return $data;
    }
    
}
