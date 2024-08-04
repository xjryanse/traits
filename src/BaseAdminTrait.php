<?php
namespace xjryanse\traits;

use xjryanse\generate\service\GenerateTemplateLogService;
use Exception;
use think\Db;
use think\facade\Request as TpRequest;
use xjryanse\logic\Request;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\Sql;
use xjryanse\logic\ModelQueryCon;
use xjryanse\logic\Arrays;
use xjryanse\logic\Strings;
use xjryanse\logic\Datetime;
use xjryanse\system\logic\ColumnLogic;
use xjryanse\system\logic\ExportLogic;
use xjryanse\system\logic\ImportLogic;
use xjryanse\system\service\SystemColumnListService;
use xjryanse\system\service\SystemImportAsyncService;
use xjryanse\system\service\SystemColumnWhereCovService;
use xjryanse\universal\service\UniversalItemBtnService;
use xjryanse\universal\service\UniversalItemTableService;
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
    
    protected $methodKey;
    // 20230526：表名对应的服务类库
    protected $service;

    /**
     * 【1】初始化参数设定
     */
    protected function initAdminParamSet()
    {
        //defaultColumn 方法中，会提取cateFieldName的key，来进行过滤字段
        //方法id
        $this->methodKey = Request::param('methodKey','') ? : '';
        //方法id作数据隔离
        $cateFieldValues        = Request::param();
        $this->columnInfo       = $this->getColumnInfo( $cateFieldValues ,$this->methodKey );
        // 20230526:表名对应的服务类库
        $this->service          = $this->columnInfo ? DbOperate::getService( $this->columnInfo['table_name'] ) : null;

        // self::debug('$this->columnInfo',$this->columnInfo);
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
    protected function getColumnInfo( $cateFieldValues = [],$methodKey ='',$data = [])
    {
        $controller             = strtolower( Request::controller() );
        //优先取路由，路由没有再取参数
        $admKey                 = Request::route('admKey','') ? : Request::param('admKey','');
        Debug::debug('getColumnInfo的param信息',Request::route());
        Debug::debug('getColumnInfo的信息',$controller.'-'.$admKey);
        return ColumnLogic::defaultColumn( $controller, $admKey ,'', $cateFieldValues, $methodKey, $data);
    }

    /**
     * 查询条件缓存key：数据查询时存，数据导出时用
     */
    private function conditionCacheKey()
    {
        return TpRequest::module().TpRequest::controller().'_con';
    }
    /**
     * 公共列表页
     */
    protected function commList( $cond = [], $paginateMethod)
    {
        $list               = $this->commListData( $cond, $paginateMethod );
        return $this->dataReturn('获取数据',$list);
    }
    /**
     * 获取分页查询条件
     */
    protected function getCondition($cond = []){
        //20211207;兼容vue前端的写法则
        $dataParam         = Request::param('table_data',[] );
        if(is_string($dataParam)){
            $dataParam = json_decode($dataParam,JSON_UNESCAPED_UNICODE);
        }
        $paramRaw   = Request::post();
        $param      = array_merge($paramRaw, $dataParam);
        $uparam     = $this->unsetEmpty($param);
        // $this->debug( '$uparam',$uparam );

        $info       = $this->columnInfo;
        //运行到此处 1.08s
        // $this->debug('xinxi',$info);
        //【1】数据表配置
        $whereFields     = ColumnLogic::getSearchFields($this->columnInfo);
        //【2】20230609: 形如isUserExist，默认写入equal查询条件
        $existFields = $this->service::uniExistFields();
        $whereFields['equal'] = array_merge(Arrays::value($whereFields, 'equal',[]), $existFields);
        //【3】20231113: 关联映射字段，默认写入equal查询条件
        $reflectFields = $this->service::uniReflectFields();
        $whereFields['equal'] = array_merge(Arrays::value($whereFields, 'equal',[]), $reflectFields);
        //【4】20231124:入参指定字段
        $pEqualFields = explode(',',Request::param('equalFields',''));
        if($pEqualFields){
            $whereFields['equal'] = array_merge(Arrays::value($whereFields, 'equal',[]), $pEqualFields);
        }
        // 20230609：添加关联
        // $this->debug( '$whereFields',$whereFields );
        //【通用查询】
        $con        = array_merge( ModelQueryCon::queryCon($uparam, $whereFields) ,$cond);
        //【特殊查询】：20210513
        $specialSearchArr   = Request::param('specialSearchArr');
        if($specialSearchArr){
            $specialSearchCon   = ModelQueryCon::specialSearchToCon($specialSearchArr);
            if($specialSearchCon){
                $con = array_merge($con,$specialSearchCon);
            }
        }
        // 20221030：增加特殊转换条件
        $whereCon = SystemColumnWhereCovService::getWhere($info['id'],$uparam);
        if($whereCon){
            $con = array_merge($con,$whereCon);
        }
        //年月渲染，根据是否设置了字段来判断显示
        //20220407,增加判断yearmonth有值
        $timeArr    = Datetime::paramScopeTime($uparam);
        if($timeArr && $info['yearmonth_field']){
            $fKey   = $info['yearmonth_field'];
            $con    = array_merge($con, Datetime::scopeTimeCon($fKey, $timeArr));
        }

        // 20231019:日期
        if($this->service::getTimeField() && Arrays::value($this->data, 'yearmonthDate')){
            $timeField = $this->service::getTimeField();
            $con[] = [$timeField,'>=',Datetime::dateStartTime($this->data['yearmonthDate'])];
            $con[] = [$timeField,'<=',Datetime::dateEndTime($this->data['yearmonthDate'])];
        }
        
        // dump($this->service::getTimeField());
        
        // $this->debug( '查询条件con',$con );
        //查询条件缓存（用于导出）
        session($this->conditionCacheKey(),$con);
        return $con;
    }
    
    /*
     * 【xjl】公共列表数据
     */
    protected function commListData( $cond = [], $method = 'paginate' )
    {
        $con = $this->getCondition($cond);
        // 20230725：有传此字段，则只提取字段用户是"我"的记录
        $meField    = Request::param( 'meField' );
        if($meField){
            $con[] = [$meField,'=',session(SESSION_USER_ID)];
        }
        //运行到此处1.56s
        //获取分页列表
        // 20220924:限制查询几条？？
        $info           = $this->columnInfo;
        $limit          = Request::param( 'limit' );
        $perPage        = $limit ? : Request::param( 'per_page', 20 );
        $class          = DbOperate::getService( $info['table_name'] );
        $fields         = ColumnLogic::listFields($this->columnInfo['id']);
        //分页数据：排序
        $orderBy = Request::param('orderBy') ? : $info['order_by'];
        $withSum        = Request::param('withSum') ? true :false;
        $list   = $class::$method( $con , $orderBy ,$perPage,"", implode(',', $fields), $withSum);
        //运行到此处 3.55s
        //添加额外的数据
        if($list['data']){
            foreach($list['data'] as &$vv){
                //根据不同字段类型，映射不同类库进行数据转换
                $vv = $this->commDataInfo( $vv , $this->columnInfo['listInfo'] );
            }
        }
        // 20220924控制分页不向下查询更多
        if($limit){
            $list['last_page'] = 1;
        }
        // 20230726：性能更佳？替代方案？？
        $uTableId = Request::param('uTableId');
        if($list && $uTableId){
            $list['dynDataList'] = $uTableId && $list['data'] 
                    ? UniversalItemTableService::getDynDataListByPageItemIdAndData($uTableId, $list['data']) 
                    : [];        
        }
        //数据统计
//        $list['statics']    = $class::paginateStatics( $con );

        return $list;
    }
    
    /**
     * 取信息
     * @return type
     */
    protected function commGet()
    {
        Debug::debug('commGet请求参数',Request::param());
        Debug::debug('commGet路由参数',Request::route());
        $dataParam         = Request::param('table_data',[] );
        $id = Arrays::value($dataParam, 'id') ? :Request::param('id','');
        // 20230313:前端奇怪的传参
        if(is_array($id)){
            return [];
        }
        Debug::debug('$id',$id);
        if($id){
            $res    = $this->service::getInstance( $id )->info( );
        }
        $resp = $id && $res 
                ? (is_array($res) ? $res : $res->toArray() )
                : [];
        // TODO:临时控制前端切换
        if(TpRequest::header('source') == 'admin'){
            $resp['valTab'] = 'base';
        }
        return $resp;
    }
    /**
     * 20230222：跳转的导出，准备弃用了，使用download方法替代
     * @param type $key
     */
    protected function commGetGenerate($key){
        $dataParam         = Request::param('table_data',[] );
        $id = Arrays::value($dataParam, 'id') ? :Request::param('id','');

        Debug::debug('$id',$id);
        $info = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res = $class::getInstance( $id )->infoGenerate( $key );
        return $res;
    }
    /**
     * 20230321：数据导出，带了文件名处理
     * @param type $key
     * @return type
     */
    protected function commGetGenerateDownload($key){
        $dataParam         = Request::param('table_data',[] );
        $id = Arrays::value($dataParam, 'id') ? :Request::param('id','');

        Debug::debug('$id',$id);
        $info = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res = $class::getInstance( $id )->infoGenerateDownload( $key );
        //格式：
//        $respData['url']        = 'http://sdsssss.doc';
//        $respData['fileName']   = '申请表.doc';
        
        return $res;
    }

    /**
     * 公共删除接口
     */
    protected function commDel()
    {
        $dataParam  = Request::param('table_data',[] );
        $ids         = Arrays::value($dataParam, 'id') ? :Request::param('id','');
        if( !$ids ) {
            return $this->errReturn( 'id必须' );
        }
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        $info               = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        
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
        return $this->dataReturn('数据删除',$res);
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
     * 20220619公共保存接口(先内存，再搬数据库)
     */
    protected function commSaveRam()
    {
        //取请求字段内容
        $postData           = $this->data ? : Request::param();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $res    = $class::saveRam( $data );
        DbOperate::dealGlobal();
        return $this->dataReturn('数据保存',$res);
    }
    
    /**
     * 20220619 公共保存接口(先内存，再搬数据库)
     */
    protected function commUpdateRam()
    {
        //取请求字段内容
        $postData           = $this->data ? :Request::param();
        // dump($postData);
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        //批量
        $ids                = Arrays::value($postData,'id');        
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        if(!$ids){
            return $this->errReturn('请先选择记录');
        }
        foreach($ids as $id){
            $data['id']     = $id;
            $realFieldArr   = DbOperate::realFieldsArr($this->columnInfo['table_name']);
            $data           = Arrays::getByKeys($data, $realFieldArr);
            $res            = $class::getInstance( $data['id'] )->updateRam( $data );            
        }
        DbOperate::dealGlobal();

        return $this->dataReturn('数据更新',$res);      
    }
    
    /**
     * 20220619公共删除接口
     */
    protected function commDelRam()
    {
        //取请求字段内容
        $data           = $this->data ? :Request::param();        
        if( !isset($data['id']) || ! $data['id'] ) {
            return $this->errReturn( 'id必须' );
        }
        $info               = $this->columnInfo;
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        
        $ids                = Arrays::value($data,'id');        
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        
        foreach($ids as $id){
            $res    = $class::getInstance( $id )->deleteRam( );
        }
        
        DbOperate::dealGlobal();
        return $this->dataReturn('数据删除',$res);
    }

    protected function commSaveAll(){
        $postData           = Request::param('table_data',[]);
        if(!$postData){
            return $this->errReturn('数据table_data必须');
        }
        $info               = $this->columnInfo;
        //数据转换
        foreach($postData as &$value){
            $value = $this->commDataCov( $value , $info);
        }
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        Db::startTrans();
        $res = $class::saveAll($postData);
        Db::commit();
        return $this->dataReturn('批量保存',$res);
    }

    /**
     * 公共保存接口
     */
    protected function commUpdate()
    {
        //取请求字段内容
        $postData           = $this->data ? :Request::post();
        // dump($postData);
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        //批量
        $ids                = Arrays::value($postData,'id');
        if(!is_array($ids)){
            //兼容逗号传值，处理成数组
            $ids = explode(',',$ids);
        }
        if(!$ids){
            return $this->errReturn('请先选择记录');
        }
        
        foreach($ids as $id){
            $data['id'] = $id;
            Db::startTrans();
            $realFieldArr = DbOperate::realFieldsArr($this->columnInfo['table_name']);
            $data = Arrays::getByKeys($data, $realFieldArr);
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
    /**
     * 兼容新增和更新，只支持单表
     */
    public function commSaveGetInfo(){
        //取请求字段内容
        $postData           = $this->data ? : Request::param();
        $info               = $this->columnInfo;
        // throw new \Exception('测试中');
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        Db::startTrans();
        $id     = $class::saveGetId( $data );
        Db::commit();
        $res    = $class::getInstance($id)->get(MASTER_DATA);
        return $this->dataReturn('数据保存',$res);
    }
    /**
     * 20220623
     * @return type
     */
    public function commSaveGetInfoRam(){
        //取请求字段内容
        $postData           = $this->data ? : Request::param();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $id     = $class::saveGetIdRam( $data );
        $res    = $class::getInstance($id)->get(MASTER_DATA);
        DbOperate::dealGlobal();
        return $this->dataReturn('数据保存',$res);
    }
    
    public function commSaveGetIdRam(){
        //取请求字段内容
        $postData           = $this->data ? : Request::param();
        $info               = $this->columnInfo;
        //数据转换
        $data = $this->commDataCov( $postData , $info);
        //表名取服务类
        $class  = DbOperate::getService( $info['table_name'] );
        $id     = $class::saveGetIdRam( $data );
        DbOperate::dealGlobal();
        return $this->dataReturn('数据保存',$id);
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
     * 20220816，paginate方法处理数据后导出逻辑
     * （性能不佳）
     * @return type
     */
    protected function commExportPaginate(){
        $btnId      = Request::param('uniBtnId','');
        $btnInfo    = UniversalItemBtnService::getInstance($btnId)->get();
        $uniFieldArr    = UniversalItemBtnService::getInstance($btnId)->getExportFieldArr();

        $con            = session( $this->conditionCacheKey() );
        $info           = $this->columnInfo;
        $class          = DbOperate::getService( $info['table_name'] );
        $fields         = ColumnLogic::listFields($info['id']);
        //分页数据：排序
        $orderBy        = Request::param('orderBy') ? : $info['order_by'];
        $withSum        = Request::param('withSum') ? true :false;
        $list           = $class::paginate( $con , $orderBy ,9999,"", implode(',', $fields), $withSum);
        //20220927
        $sumDataArr     = Arrays::value($list, 'sumData',[]);
        //导出数据转换：拼上动态枚举的值
        $exportData     = UniversalItemTableService::exportDataDeal($uniFieldArr, $list['data'], $list['dynDataList'], $sumDataArr);
        if($btnInfo['tpl_id']){
            //有模板，使用模板导出
            $replace                = [];
            $resp                   = GenerateTemplateLogService::export($btnInfo['tpl_id'], $exportData,$replace);
            $res                    = $resp['file_path'];
        } else {
            $dataTitle  = array_column($uniFieldArr,'label');
            // $keys       = array_column($v['optionArr'],'name');
            //没有模板，使用简单的导出
            $fileName   = ExportLogic::getInstance()->putIntoCsv($exportData,$dataTitle);
            $res        = Request::domain().'/Uploads/Download/CanDelete/'.$fileName;
        }
        // TODO；准备替换成如下方法。
        // $res        = UniversalItemBtnService::getInstance($btnId)->exportPack($pgLists['data'],$pgLists['dynDataList'],$pgLists['sumData']);

        return $this->dataReturn('数据导出',$res);
    }
    /**
     * 公共导出逻辑
     * @return type
     */
    protected function commExport()
    {
        // 2023-01-09
        ini_set('memory_limit','3072M');    // 临时设置最大内存占用为3G  
        set_time_limit(300);                  // 设置脚本最大执行时间 为0 永不过期

        $btnId          = Request::param('uniBtnId','');
        //按钮信息
        $btnInfo        = UniversalItemBtnService::getInstance($btnId)->get();        
        $uniFieldArr    = UniversalItemBtnService::getInstance($btnId)->getExportFieldArr();

        $con = session( $this->conditionCacheKey() );
        // 20240324：可以选择部分项目导出
        if(Request::param('id')){
            $ids = is_array(Request::param('id')) ? Request::param('id') : explode(',',Request::param('id'));
            $con[] = ['id','in',$ids];
        }
        
        //字段
        $info = $this->columnInfo;
        $orderBy        = Request::param('orderBy') ? : $info['order_by'];

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
                //20211220,探索更优
                if(!$btnInfo['tpl_id'] && (Strings::isEndWith($v['name'],'id') || Strings::isEndWith($v['name'],'_no')) ){
                    $str = ' concat(`'. $v['name']."`,'\t') ";
                } else {
                    $str = '`' . $v['name'] . '`';
                }
                //$str = ' concat(`'. $v['name']."`,'\t') ";
            }
            //$fields[] = $str . " as `".$v['label']."`";
            //20220506
            $fields[] = $str . " as `".$v['name']."`";
        }
        $service = DbOperate::getService( $this->columnInfo['table_name'] );
        if($service::mainModel()->hasField('company_id')){
            $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        }
        //20220511加
        if($service::mainModel()->hasField('is_delete')){
            $con[] = ['is_delete','=',0];
        }

        $field = implode(',',$fields);
        //请求本地进行导出操作
        $sql = Db::table( $this->columnInfo['table_name'] ) ->where( $con )->order($orderBy) ->field( $field ) ->buildSql();
        Debug::debug('$sql',$sql);
        //$fileName = ExportLogic::getInstance()->exportToCsv($sql);
        //pdo高效率查询
        $rows = DbOperate::pdoQuery($sql);
        //取动态枚举数据
        // $dynDataList    = SystemColumnListService::getDynDataListByColumnIdAndData($info['id'], $rows);
        $pageItemId     = UniversalItemBtnService::getInstance($btnId)->getExportTablePageItemId();
        $dynDataList    = UniversalItemTableService::getDynDataListByPageItemIdAndData($pageItemId, $rows);
        if($btnInfo['tpl_id']){
            //导出结果加上动态枚举数据进行拼接转换
            $exportData             = UniversalItemTableService::exportDataDeal($uniFieldArr, $rows, $dynDataList);
            foreach($exportData as $k=>&$r){
                $r['i'] = $k+1;
            }
            //有模板，使用模板导出
            $replace                = [];
            $resp                    = GenerateTemplateLogService::export($btnInfo['tpl_id'], $exportData,$replace);
            $res                    = $resp['file_path'];
        } else {
            $exportData     = UniversalItemTableService::exportDataDeal($uniFieldArr, $rows, $dynDataList);
            // 20240108:反馈无法导出，加name
            $dataTitle  = array_column($uniFieldArr,'label','name');
            $keys       = array_column($uniFieldArr,'name');
            Debug::debug('$dataTitle',$dataTitle);
            Debug::debug('$keys',$keys);
            //没有模板，使用简单的导出
            //$fileName   = ExportLogic::getInstance()->putIntoCsv($exportData,$dataTitle,'',$keys);
            //20220816:因调整exportDataDeal，同步调
            if(count($exportData) > 500 ){
                $fileName   = ExportLogic::getInstance()->putIntoCsv($exportData,$dataTitle);
                //20220816:兼容前端
                $res['url'] = Request::domain().'/Uploads/Download/CanDelete/'.$fileName;
            } else {
                $exportPathRaw  = ExportLogic::getInstance()->dataExportExcel($exportData,$dataTitle);
                $exportPath     = str_replace('./', '/', $exportPathRaw);
                $res['url']     = Request::domain().$exportPath;
                $fileName       = date('YmdHis').'.xlsx';
            }
            $res['fileName'] = $fileName;
        }

        return $this->dataReturn('数据导出',$res);
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
        // $this->assign('header', $header );
        // $this->debug('header', $header );
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
        //表名取服务类
        $class      = DbOperate::getService( $info['table_name'] );
        Db::startTrans();
        $res = $class::getInstance($id)->copy();
        Db::commit();
        return $this->dataReturn('数据复制',$res);
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
     * 年月时间条件
     * @param type $key
     */
    protected function yearMonthTimeCon( $key )
    {
        $yearmonth = Arrays::value($this->data, 'yearmonth');
        $day = Arrays::value($this->data, 'date');

        return Datetime::yearMonthTimeCon($key, $yearmonth, $day);
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
            //非新增且非编辑的字段不处理；20210908字段不存在的也不处理
            if((!$v['is_add'] && !$v['is_edit']) || !isset($data[$v['name']]) ){
                continue;
            }
            //根据不同字段类型，映射不同类库进行数据转换
            $data[$v['name']] = SystemColumnListService::getData( $v['type'] , $data, $v );
        }
        return $data;
    }
}
