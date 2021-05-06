<?php
namespace xjryanse\traits;

use think\Db;
use think\facade\Request;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\Sql;
use xjryanse\logic\ModelQueryCon;
use xjryanse\system\logic\ColumnLogic;
use xjryanse\system\logic\ExportLogic;
use xjryanse\system\logic\ImportLogic;
use xjryanse\system\service\SystemColumnListService;
use xjryanse\system\service\SystemImportAsyncService;
use Exception;
/**
 * 后台系统管理复用，一般需依赖一堆类库
 */
trait BaseAdminTrait
{
    //字段信息
    protected $columnInfo;
    //表名
    protected $admTable;
    //表id
    protected $admTableId;
    
    /**
     * 【1】初始化参数设定
     */
    protected function initAdminParamSet()
    {
        //defaultColumn 方法中，会提取cateFieldName的key，来进行过滤字段
        //方法id
        $methodKey = Request::param('methodKey','') ? : '';
        //方法id作数据隔离
        $cateFieldValues        = Request::param();
        $this->columnInfo       = $this->getColumnInfo( $cateFieldValues ,$methodKey );
        self::debug('$this->columnInfo',$this->columnInfo);
        //编辑方法才去取
        if(Request::action() == 'edit'){
            $idGetInfo  = $this->commGet();
            if($idGetInfo){
                $this->columnInfo       = $this->getColumnInfo( $idGetInfo );
            }
        }
        //对应表名
        $this->admTable         = isset($this->columnInfo['table_name']) ? $this->columnInfo['table_name'] : '';
        //对应表id
        $this->admTableId       = isset($this->columnInfo['id']) ? $this->columnInfo['id'] : '';
    }
    /**
     * 
     * @param type $cateFieldValues defaultColumn 方法中，会提取cateFieldName的key，来进行过滤字段
     * @return type
     */
    protected function getColumnInfo( $cateFieldValues = [],$methodKey ='')
    {
        $controller             = strtolower( Request::controller() );
        $admKey                 = Request::param('admKey','');

        return ColumnLogic::defaultColumn( $controller, $admKey ,'', $cateFieldValues, $methodKey);
    }
    
    /**
     * 【2】初始化参数渲染
     */
    protected function initAdminAssign()
    {
        //字段信息
        $this->assign( 'columnInfo', $this->columnInfo );
        //对应表名
        $this->assign( 'admTable', $this->admTable );
        //对应表的id
        $this->assign( 'admTableId', $this->admTableId );
    }
    /**
     * 查询条件缓存key：数据查询时存，数据导出时用
     */
    private function conditionCacheKey()
    {
        return Request::module().Request::controller().'_con';
    }
    /**
     * 公共列表页
     */
    protected function commList( $cond = [])
    {
        if(Request::isAjax()){
            $list               = $this->commListData( $cond );
//            $list['columnInfo'] = $this->columnInfo;            
            return $this->dataReturn('获取数据',$list);
        }
//        $this->assign('columnInfo',$this->columnInfo);
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
        //运行到此处 1.08s
        $this->debug('xinxi',$info);
        $whereFields     = ColumnLogic::getSearchFields($this->columnInfo);
        $this->debug( '$whereFields',$whereFields );

        $con        = array_merge( ModelQueryCon::queryCon($uparam, $whereFields) ,$cond);
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
        session($this->conditionCacheKey(),$con);
        //运行到此处1.56s
        //获取分页列表
        $perPage        = Request::param( 'per_page', 20 );
        $class          = DbOperate::getService( $info['table_name'] );
        $fields         = ColumnLogic::listFields($this->columnInfo['id']);
        //分页数据
        $list   = $class::paginate( $con , $info['order_by'] ,$perPage,"", implode(',', $fields));
        //运行到此处 3.55s
        //添加额外的数据
        foreach($list['data'] as &$vv){
            //根据不同字段类型，映射不同类库进行数据转换
            $vv = $this->commDataInfo( $vv , $this->columnInfo['listInfo'] );
        }
        //数据统计
//        $list['statics']    = $class::paginateStatics( $con );

        return $list;
    }
    
    /**
     * 公共新增页
     */
    protected function commAdd()
    {
        $header = $this->commHeader();
        //请求的参数，带入表单
        $data   = Request::param();
        //默认填写当前用户的字段
        if(isset( $data['currentUserField'])){
            $currentUserField = explode(',','currentUserField');
            foreach( $currentUserField as $currentUserField){
                $data[ $currentUserField ] = session(SESSION_USER_ID);
            }
        }        
        $row    = $this->commDataInfo( $data , $this->columnInfo['listInfo'] );        
        $this->assign('row', $row);
        //表单类型：添加
        $this->assign('formType', 'add');
        $this->debug('row', $data );
        
        $url = isset($header['add_ajax_url']) && $header['add_ajax_url'] 
                ? $header['add_ajax_url']
                :'save';
        $this->assign('formUrl', url( $url , $this->paramInherit ) );
        $this->assign('btnName', '新增' );
        
        $isLayer = Request::param('isLayer','');                        //弹窗不是新页面
        if($isLayer){
            return $this->fetch( $this->template ? : 'common/add2');    //加载静态资源
        } else {
            return $this->fetch( $this->template ? : 'common/add');
        }
    }
    /**
     * 取信息
     * @return type
     */
    protected function commGet()
    {
        $id = Request::param('id',0);
        if($id){
            $info = $this->columnInfo;
            //表名取服务类
            $class  = DbOperate::getService( $info['table_name'] );
            $res    = $class::getInstance( $id )->info( );
        }
        return $id && $res ? $res->toArray() : [];
    }
    /**
     * 公共编辑页
     */
    protected function commEdit()
    {
        $header = $this->commHeader();
        $res = $this->commGet();
        if( !$res ){
            return ;
        }
        
        $resp = $this->commDataInfo( $res , $this->columnInfo['listInfo'] );

        $this->assign('row', $resp );
        //表单类型：添加
        $this->assign('formType', 'edit');

        $ajaxUrl = isset($header['edit_ajax_url']) && $header['edit_ajax_url'] 
                ? $header['edit_ajax_url']
                :'update';
        //覆盖initAdminAssign中的方法，重新渲染字段信息：因有些字段会根据实际数据的类型进行过滤
        $this->assign('formUrl', url( $ajaxUrl ,$this->paramInherit) );
        $this->assign('btnName', '编辑' );

        $isLayer = Request::param('isLayer','');                        //弹窗不是新页面
        if($isLayer){
            return $this->fetch( $this->template ? : 'common/add2');    //加载静态资源
        } else {
            return $this->fetch( $this->template ? : 'common/add');
        }
    }
    /**
     * 公共删除接口
     */
    protected function commDel()
    {
        //取请求字段内容
        $data               = Request::param();
        if( !isset($data['id']) || ! $data['id'] ) {
            return $this->errReturn( 'id必须' );
        }
        $info               = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        
        $ids = Request::param('id','');
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        
        Db::startTrans();
        foreach($ids as $id){
            $res    = $class::getInstance( $id )->delete( );
        }
        Db::commit();
        return $this->dataReturn('数据删除',$res);
    }
    /**
     * TODO：待完善20201112：公共联表删除接口
     */
    protected function commUniDel()
    {
        //取请求字段内容
        $id               = Request::post('id','');
        if( !$id ) {
            return $this->errReturn( 'id必须' );
        }
        $info               = $this->columnInfo;
        if( !$info['uni_del'] ) {
            return $this->errReturn( $this->columnInfo['table_name'].'不允许联表删除');
        }

        Db::startTrans();
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res    = $class::getInstance( $id )->delete( );
        //取联表的信息
        Db::commit();
        return $this->dataReturn('数据删除');
    }
    /**
     * 公共保存接口
     */
    protected function commSave()
    {
        //取请求字段内容
        $postData           = Request::param();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        Db::startTrans();
        $res    = $class::save( $data );
        if(isset($res['id'])){
            //中间表数据保存
            $this->midSave( $this->columnInfo , $res['id'] ,$res );
        }
        Db::commit();
        return $this->dataReturn('数据保存',$res);
    }

    /**
     * 公共保存接口
     */
    protected function commUpdate()
    {
        //取请求字段内容
        $postData           = Request::param();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        //批量
        $ids = Request::param('id','');
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        foreach($ids as $id){
            $data['id'] = $id;
            Db::startTrans();
            $res = $class::getInstance( $data['id'] )->update( $data );
            //中间表数据保存
            $this->midSave( $this->columnInfo , $data['id'] ,$data );
            if(Debug::isDebug()){
                throw new Exception('测试中');
            }
            Db::commit();
        }

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
            //中间表数据保存
            SystemColumnListService::saveData( $v['type'] , $data, $v );
        }
    }
    /**
     * 公共导出逻辑
     * @return type
     */
    protected function commExport()
    {
        $con = session( $this->conditionCacheKey() );
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
            if( in_array($v['type'],[ FR_COL_TYPE_CHECK ]) ){
                $option = $v['option'];
                $tmpOption = is_array($option['option']) ? $option['option'] : $option['option']->toArray(); 
                $keyValue = array_column($tmpOption, $option['value'],$option['key']);
                //组建groupConcat条件
                $str = Sql::buildGroupConcat(  $option['to_table']
                        , " user_id = `". $info['table_name']."`.id "
                        , Sql::buildCaseWhen($option['to_field'], $keyValue, $v['label'])
                        , $v['label']);
            } else if( in_array($v['type'],['enum','dynenum']) ){
                //枚举项导出                
                $str = Sql::buildCaseWhen($v['name'], $v['option'], $v['label']) ;
            } else if( $v['name'] ){
                $str = ' concat('. $v['name'].",'\t') ";
            }
            $fields[] = $str . "as `".$v['label']."`";
        }

        $field = implode(',',$fields);
        //请求本地进行导出操作
        $sql = Db::table( $this->columnInfo['table_name'] ) ->where( $con ) ->field( $field ) ->buildSql();
        if($this->isDebug()){
            $this->debug('$con',$con);
            $this->debug('$field',$field);
            $this->debug('$sql',$sql);
            exit;
        }

        $fileName = ExportLogic::getInstance()->exportToCsv($sql);
        
        return $this->redirect( Request::domain().'/Uploads/Download/CanDelete/'.$fileName );
    }
    /**
     * 公共导入逻辑
     * @return type
     */
    protected function commImport()
    {
        $headers = ColumnLogic::getImportFields($this->columnInfo);
        //表单提交的字段
        $fileId = Request::param('importFileId',0);
        if(!$fileId){
            return $this->errReturn( '未指定文件' );
        }
        $preInputData = Request::param();
        
        //使用实时导入20210127
        $impData = ImportLogic::fileGetArray( $fileId, $headers );
        $covData = ColumnLogic::getCovData( $this->columnInfo );
        //批量新增
        $class  = DbOperate::getService( $this->columnInfo['table_name'] );
        //数据导入的
        $impData2 = ImportLogic::importDataCov($impData, $covData);        
        $res = $class::saveAll( $impData2 ,$preInputData);
        //添加到导入任务【已完成】
        $data['cov_data'] = json_encode($covData,JSON_UNESCAPED_UNICODE);
        $data['op_status'] = XJRYANSE_OP_FINISH;
        SystemImportAsyncService::addTask($this->columnInfo['table_name'], $fileId, $headers, $preInputData ,$data);

        return $this->succReturn( '数据导入成功'.count($res).'条',$res );        
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
        $list   = QColumnService::getInstance($this->admTable)->getList( $con );
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
        $list2   = QColumnService::getInstance($this->admTable)->getList( $con2 );
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
            return $this->dataReturn('数据复制',$resp);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return $this->throwMsg($e);
        }
    }
    /**
     * 公共保存接口
     */
    protected function commTemplate()
    {
        $info       = $this->columnInfo;
        return $info['import_tpl_id'] 
                ? $this->redirect($info['import_tpl_id']['file_path']) 
                : '';
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
                if( in_array( $field['type'],[FR_COL_TYPE_UPLIMAGE,FR_COL_TYPE_UPLFILE]) &&  $k == $field['name'] && isset($v['id'] ) ) {
                    $v = $v['id'];
                }
            }
        }
        return $data;
    }
    /**
     * 将查询出来的数据，根据各字段类型提取详情
     * @param type $data
     * @param type $listInfo    columnInfo 的 listInfo 字段
     * @return type
     */
    protected function commDataInfo( &$data, $listInfo )
    {
        //role_id,access_id等类型
        foreach( $listInfo as $v){
            //非新增且非编辑的字段不处理
            if(!$v['is_add'] && !$v['is_edit']){
                continue;
            }
            //根据不同字段类型，映射不同类库进行数据转换
            $data[$v['name']] = SystemColumnListService::getData( $v['type'] , $data, $v );
        }
        return $data;
    }
}
