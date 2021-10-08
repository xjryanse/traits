<?php
namespace xjryanse\traits;

use xjryanse\user\logic\AuthLogic;
use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\Cachex;
use xjryanse\system\service\SystemFieldsInfoService;
use xjryanse\system\service\SystemFieldsManyService;
use xjryanse\system\service\SystemTableCacheTimeService;
//use xjryanse\system\service\SystemFieldsLogTableService;
use xjryanse\system\service\SystemAsyncTriggerService;
use think\Db;
//use think\facade\Cache;
use Exception;
/**
 * 主模型复用
 */
trait MainModelTrait {
    //复用类需实现
//    protected static $mainModelClass;
    
    protected static $mainModel;
    //是否直接执行后续触发动作
    //protected static $directAfter;
    protected $uuData = [];
    
    public static function mainModel() 
    {
        //实现一下获取主模型
        if(!self::$mainModel){
            self::$mainModel = new self::$mainModelClass();
        }
        return self::$mainModel;             
    }
    
    public function __call($method,$ages){
        //首字母f，且第二个字母大写，表示字段
        
        return $method .'不存在';
    }    
    /**
     * 默认缓存时间
     * @return type
     */
    protected static function defaultCacheTime()
    {
        $tableName = self::mainModel()->getTable();
        return SystemTableCacheTimeService::tableCache( $tableName );
    }
    /**
     * 表名
     */
    public static function getTable(){
        return self::mainModel()->getTable();
    }
    /**
     * 缓存数据更新
     */
    protected static function _cacheUpdate( $id )
    {
        self::getInstance( $id )->get(0);
    }
    /**
     * 获取缓存key
     * @param type $id
     * @return type
     */
    protected static function _cacheKey( $id )
    {
        $table = self::mainModel()->getTable();
        return $table.$id;
    }
    //公共的数据过滤条件
    protected static function commCondition( $withDataAuth = true)
    {
        $con    = session(SESSION_USER_ID) && $withDataAuth 
                ? AuthLogic::dataCon( session(SESSION_USER_ID) , self::mainModel()->getTable())
                : AuthLogic::dataCon( session(SESSION_USER_ID) , self::mainModel()->getTable(),true) ;  //不带数据权限情况下，只取严格模式的权限
        //customerId 的session
        //客户id  有bug20210323
        if( self::mainModel()->hasField('customer_id') && session ( SESSION_CUSTOMER_ID ) ){
//            $con[] = ['customer_id','=',session(SESSION_CUSTOMER_ID)];
        }
        //公司隔离
        if( self::mainModel()->hasField('company_id') && session ( SESSION_COMPANY_ID ) ){
            $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        }
        //应用id
        if( self::mainModel()->hasField('app_id') ){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        //删除标记
        if( self::mainModel()->hasField('is_delete') ){
            $con[] = ['is_delete','=',0];
        }        
        return $con;
    }
    /**
     * 预保存数据
     */
    protected static function preSaveData( &$data )
    {
        Debug::debug('预保存数据$data',$data);
        if(!isset($data['id'])|| !$data['id']){
            $data['id'] = self::mainModel()->newId();
        }
        if( session(SESSION_COMPANY_ID) && !isset($data['company_id'])){
            $data['company_id'] = session(SESSION_COMPANY_ID);
        }
        if( session(SESSION_APP_ID) && !isset($data['app_id'])){
            $data['app_id'] = session(SESSION_APP_ID);
        }
        if( session(SESSION_USER_ID) && !isset($data['creater'])){
            $data['creater'] = session(SESSION_USER_ID);
        }
        //数据来源
        if( session(SESSION_SOURCE) && !isset($data['source'])){
            $data['source'] = session(SESSION_SOURCE);
        }
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        return $data;
    }
    
    /**
     * 条件给字段添加索引
     */
    protected static function condAddColumnIndex( $con = [])
    {
        if(!$con){
            return false;
        } else {
            return true;     //去掉本行后会执行自动添加索引，一般应于项目正式后关闭
        }
        foreach( $con as $conArr ){
            if(is_array($conArr)){
                DbOperate::addColumnIndex(self::mainModel()->getTable(), $conArr[0]);
            }
        }
    }
    
    /*****公共保存【外部有调用】*****/
    protected static function commSave( $data ){
        //预保存数据：id，app_id,company_id,creater,updater,create_time,update_time
        self::preSaveData($data);
        //额外添加详情信息：固定为extraDetail方法
        if(method_exists( __CLASS__, 'extraPreSave')){
            self::extraPreSave( $data, $data['id']);      //注：id在preSaveData方法中生成
        }
        //保存
        $res = self::mainModel()->create( $data );
        $resp = $res ? $res ->toArray() : [];
        //更新完后执行：类似触发器
        if(method_exists( __CLASS__, 'extraAfterSave')){
            //20210821，改异步
            //$resp = $res ? $res ->toArray() : [];
            //self::extraAfterSave( $resp, $data['id']);     
            if(session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)){
                self::extraAfterSave( $resp, $data['id']);                      
            } else {
                $fromTable = self::mainModel()->getTable();
                $addTask = SystemAsyncTriggerService::addTask('save', $fromTable, $data['id']);
                Debug::debug('extraAfterSave',$addTask);
            }
        }
//        //20210311记录更新日志        TODO调整为异步
//        // SystemFieldsLogTableService::tableLog( self::mainModel()->getTable(), [], $data );
//        //清缓存
//        if(SystemTableCacheTimeService::tableHasLog(self::mainModel()->getTable())){
//            Cache::clear();
//            self::_cacheUpdate($res['id']);
//        }
        return $res;        
    }
    /**
     * 公共更新【外部有调用】
     * @param array $data
     * @return type
     * @throws Exception
     */
    protected function commUpdate( array $data )
    {
        $info = $this->get(0);
        if(!$info){
//            return false;
            throw new Exception('记录不存在'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        if(isset($info['is_lock']) && $info['is_lock']){
            throw new Exception('记录已锁定不可修改'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        if(!isset($data['id']) || !$data['id']){
            $data['id'] = $this->uuid;
        }
        $data['updater'] = session(SESSION_USER_ID);
        $data['update_time'] = date('Y-m-d H:i:s');
        //额外添加详情信息：固定为extraDetail方法；更新前执行
        if(method_exists( __CLASS__, 'extraPreUpdate')){
            self::extraPreUpdate( $data, $data['id']);      
        }
        //20210520排除虚拟字段
        $realFieldArr   = DbOperate::realFieldsArr( self::mainModel()->getTable() );
        $data           = Arrays::getByKeys($data, $realFieldArr);        
        $res = self::mainModel()->update( $data );
        if($res){
            // 设定内存中的值
            $this->setUuData($data);
        }

        //更新完后执行：类似触发器
        if(method_exists( __CLASS__, 'extraAfterUpdate')){
            if(session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)){
                $resp = $res ? $res ->toArray() : [];
                // 更新实例值
                self::getInstance($data['id'])->setUuData($data);  
                self::extraAfterUpdate( $resp, $data['id']);                      
            } else {
                //20210821改异步
                $fromTable = self::mainModel()->getTable();
                $addTask = SystemAsyncTriggerService::addTask('update', $fromTable, $data['id']);
                Debug::debug('extraAfterUpdate',$addTask);
            }
        }
//        //20210311记录更新日志
//        SystemFieldsLogTableService::tableLog( self::mainModel()->getTable(), $info, $data );
//        //清缓存
//        if(SystemTableCacheTimeService::tableHasLog(self::mainModel()->getTable())){
//            Cache::clear();
//        }
        
        return $res;
    }
    /**
     * 公共删除【外部有调用】
     * @return type
     * @throws Exception
     */
    protected function commDelete()
    {
        $info = $this->get(0);
        if(!$info){
            throw new Exception('记录不存在'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        if(isset($info['has_used']) && $info['has_used']){
            //软删
            $res = self::mainModel()->where('id',$this->uuid)->update( ['is_delete'=>1] );
            return $res;
//            throw new Exception('记录已使用不可删除'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        if(isset($info['is_lock']) && $info['is_lock']){
            throw new Exception('记录已锁定不可删除'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        //【20210315】判断关联表有记录，则不可删
        $relativeDels   = SystemFieldsInfoService::relativeDelFields( self::mainModel()->getTable() );
        if($relativeDels){
            foreach( $relativeDels as $relativeDel){
                if(DbOperate::isTableExist($relativeDel['table_name']) 
                        && Db::table( $relativeDel['table_name'] )->where( $relativeDel['field_name'] ,$this->uuid )->count()){
                    if($relativeDel['del_fault_msg']){
                        throw new Exception($relativeDel['del_fault_msg']);
                    } else {
    //                    throw new Exception('记录已使用，不可操作');
                        throw new Exception('当前记录'.$this->uuid.'已在数据表'.$relativeDel['table_name'].'的'.$relativeDel['field_name'].'字段使用,不可操作');
                    }
                }
            }
        }        
        
        $res = self::mainModel()->where('id',$this->uuid)->delete( );
//        if($res){
//            self::_cacheUpdate( $this->uuid );
//        }        
        return $res;
    }
    
    /**************************操作方法********************************/
    public static function save( $data)
    {
        return self::commSave($data);
    }
    /*
     * 批量保存
     */
    public static function saveAll ( array &$data ,$preData=[])
    {
        $tmpArr = [];
        foreach( $data as &$v){
            $tmpData        = array_merge($preData,$v) ;
            //预保存数据
            $tmpArr[] = self::preSaveData( $tmpData );
        }
        //saveAll方法新增数据默认会自动识别数据是需要新增还是更新操作，当数据中存在主键的时候会认为是更新操作，如果你需要带主键数据批量新增，可以使用下面的方式
        $res = self::mainModel()->saveAll( $tmpArr ,false );
//        // 是否需要处理缓存数据
//        if($dealCache){
//            foreach( $tmpArr as $v){
//                if(isset($v['id']) && $v['id']){
//                    self::_cacheUpdate($v['id']);
//                }
//            }
//        }
        return $res;
    }
    /**
     * 数据保存取id（自动识别是新增还是更新）
     * @param type $data    
     * @return type
     */
    protected static function commSaveGetId($data) {
        $mainId = '';
        if (isset($data['id']) && self::getInstance( $data['id'])->get()) {
            $mainId = $data['id'];
            //更新
            $res = self::getInstance($data['id'])->update($data);
        } else {
            //新增
            $res = self::save($data);
            $mainId = $res['id'];
        }

        return $mainId;
    }
    /**
     * 数据保存取id（自动识别是新增还是更新）
     * @param type $data    
     * @return type
     */
    public static function saveGetId( $data ){
        return self::commSaveGetId($data);
    }    
    /**
     * 关联表数据保存
     * @param type $mainField   主字段
     * @param type $mainValue   主字段值
     * @param type $arrField    数组字段
     * @param type $arrValues   数组值：一维数据写入数组字段，二维数据直接存储
     */
    public static function midSave( $mainField, $mainValue, $arrField, $arrValues )
    {
        self::checkTransaction();

        $con[]      = [ $mainField ,'=', $mainValue ];
        self::mainModel()->where( $con )->delete();
        $tmpData    = [];
        foreach( $arrValues as $value ){
            if(is_array( $value )){
                $tmp = $value;
            } else {
                $tmp = [];
                $tmp[ $arrField ]   =   $value;
            }
            $tmp[ $mainField ]  =   $mainValue ;
            $tmpData[] = $tmp; 
        }
        return self::saveAll( $tmpData );
    }
    /**
     * 更新
     * @param array $data
     * @return type
     * @throws Exception
     */
    public function update( array $data )
    {
        //预保存数据
        return $this->commUpdate($data);
    }
    /*
     * 设定字段的值
     * @param type $key     键
     * @param type $value   值
     */
    public function setField($key,$value)
    {
        return $this->update([$key=>$value]);
    }
    /**
     * 字段名取值
     * @param type $fieldName   字段名
     * @param type $default     默认值
     * @return type
     */
    public function fieldValue( $fieldName, $default='')
    {
        //如果是定值；有缓存取缓存；无缓存再从数据库取值
        if( (property_exists(__CLASS__, 'fixedFields') && in_array($fieldName, self::$fixedFields)) ){
            $tableName = self::mainModel()->getTable();
            $cacheKey = $tableName.'-'.$this->uuid.'-'.$fieldName;
            return Cachex::funcGet($cacheKey, function() use ($fieldName, $default){
                return $this->fieldValueFromDb($fieldName,$default);
            });
        } else {
            return $this->fieldValueFromDb($fieldName,$default);
        }
    }
    /**
     * 从数据库取新值
     */
    private function fieldValueFromDb($fieldName, $default = ''){
        $info = $this->get();
        return Arrays::value($info, $fieldName, $default);
    }
    /**
     * 获取f开头的驼峰方法名字段信息
     * @param type $functionName  方法名，一般__FUNCTION__即可
     * @param type $prefix          前缀
     * @return type
     */
    public function getFFieldValue( $functionName ,$prefix="f_")
    {
        //驼峰转下划线，再去除前缀
        $pattern = '/^'. $prefix .'/i';
        $fieldName = preg_replace($pattern, '', uncamelize( $functionName ));
        //调用MainModelTrait中的字段值方法
        return $this->fieldValue($fieldName);
    }    

    /*
     * 设定字段的值
     * @param type $key         键
     * @param type $preValue    原值
     * @param type $aftValue    新值
     * @return type
     */
    public function setFieldWithPreValCheck($key,$preValue,$aftValue)
    {
        $info = $this->get(0);
        if($info[$key] != $preValue){
            throw new Exception( self::mainModel()->getTable().'表'. $this->uuid.'记录' 
                    .$key .'的原值不是'. $preValue );
        }
        $con[] = [ $key ,'=',$preValue];
        $con[] = [ 'id' ,'=',$this->uuid ];
        $res = $this->update([$key=>$aftValue]);
//        $res = self::mainModel()->where( $con )->update([$key=>$aftValue]);
//        if($res){
//            self::_cacheUpdate( $this->uuid );
//        }
        //更新缓存
        return $res;
    }
    
    public function delete()
    {
        //删除前
        if(method_exists( __CLASS__, 'extraPreDelete')){
            $this->extraPreDelete();      //注：id在preSaveData方法中生成
        }
        //删除
        $res = $this->commDelete();
        //删除后
        if(method_exists( __CLASS__, 'extraAfterDelete')){
            //20210821改异步
            // $this->extraAfterDelete();      //注：id在preSaveData方法中生成
            $fromTable = self::mainModel()->getTable();
            SystemAsyncTriggerService::addTask('delete', $fromTable, $this->uuid);
        }

        return $res;
    }
    /**************************查询方法********************************/
    protected static function commLists( $con = [],$order='',$field="*" ,$cache=2)
    {        
        $conAll = array_merge( $con ,self::commCondition() );
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        Debug::debug( 'commLists查询表', self::mainModel()->getTable() );
        Debug::debug( 'commLists查询sql', $conAll );
        //字段加索引
        self::condAddColumnIndex( $con );
        $res = self::mainModel()->where( $conAll )->order($order)->field($field)->cache( $cache )->select();
        // 查询出来了直接存
        if($field == "*"){
            foreach($res as &$v){
                self::getInstance($v['id'])->setUuData($v,true);  //强制写入
            }            
        }
        return $res;
    }
    
    public static function lists( $con = [],$order='',$field="*" ,$cache = -1 )
    {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        $cache = $cache <0 ? self::defaultCacheTime() : $cache;
        
        return self::commLists($con, $order, $field,$cache)->each(function($item, $key){
                //额外添加详情信息：固定为extraDetail方法
                if(method_exists( __CLASS__, 'extraDetail')){
                    self::extraDetail($item, $item['id']);
                }
            });
    }
    /**
     * 查询列表，并写入get
     * @param type $con
     */
    public static function listSetUudata($con = [], $master = false){
        if($master) {
            $lists = self::mainModel()->master()->where($con)->select();
        } else {
            $lists = self::mainModel()->where($con)->select();
        }
        //写入内存
        foreach($lists as $v){
            self::getInstance($v['id'])->setUuData($v,true);  //强制写入
        }
        return $lists;
    }
    
    /**
     * 分页的查询
     * @param type $con
     * @param type $order
     * @param type $perPage
     * @return type
     */
    protected static function commPaginate( $con = [],$order='',$perPage=10,$having = '',$field = "")
    {
        $hasExtraDetails = method_exists( __CLASS__, 'extraDetails');
        //默认带数据权限
        $conAll = array_merge( $con ,self::commCondition() );
        //如果数据权限没记录，尝试去除数据权限进行搜索
        $count = self::mainModel()->where( $conAll )->count();
        //有条件才进行搜索：20210326
        if(!$count && $con){
            $conAll = array_merge( $con ,self::commCondition( false ));
        }
        
        //字段加索引
        self::condAddColumnIndex( $conAll );
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        $cache = self::defaultCacheTime();
        $inst = self::mainModel()->where( $conAll )->order($order);
        if($hasExtraDetails){
            $inst->field('id');
        } else if( $field ){
            $inst->field($field);
        }
        $res = $inst->having($having)
            ->cache( $cache )
            ->paginate( intval($perPage) )
            ->each(function($item, $key){
                //额外添加详情信息：固定为extraDetail方法
                if(method_exists( __CLASS__, 'extraDetail')){
                    self::extraDetail($item, $item['id']);
                }
            });
        $result = $res->toArray();
        //20210912,逐步替代 extraDetail方法；额外添加详情信息：固定为extraDetail方法
        if(method_exists( __CLASS__, 'extraDetails')){
            $extraDetails = self::extraDetails(array_column($result['data'], 'id'));
            //id 设为键
            $extraDetailsObj = Arrays2d::fieldSetKey($extraDetails, 'id');
            foreach($result['data'] as &$v){
                $v = $extraDetailsObj[$v['id']];
            }
        }
        
//        $res['con'] = $conAll;
        return $res ? array_merge($result,['con'=>$conAll]) : [] ;                
    }
    
    /**
     * 分页的统计
     * @param type $con
     * @param type $order
     * @param type $perPage
     * @return type
     */
    protected static function commPaginateStatics( $con = [],$order='',$perPage=10,$having = '')
    {
        $conAll = array_merge( $con ,self::commCondition() );
        $table  = self::mainModel()::getTable();
        $fields = self::mainModel()::getConnection()->getFields( $table );
        //计数
        $distinctArr = [];
        foreach( $fields as $key => $value){
            $distinctArr[] = 'count(distinct `'.$key ."`) as `SD".$key.'`';
        }
        $count  = self::mainModel()->where( $conAll )->field( implode(',', $distinctArr) )->select();
        //求和
        $sumArr = [];
        foreach( $fields as $key => $value){
            $sumArr[] = 'sum(`'.$key ."`) as `SS".$key.'`';
        }
        $sum    = self::mainModel()->where( $conAll )->field( implode(',', $sumArr) )->select();

        return ['count'=>$count,'sum'=>$sum];
    }
    /**
     * 分页的统计
     */
    public static function paginateStatics( $con = [] )
    {
        return self::commPaginateStatics($con);
    }
    
    /**
     * 分页的查询
     * @param type $con
     * @param type $order
     * @param type $perPage
     * @return type
     */
    public static function paginate( $con = [],$order='',$perPage=10,$having = '',$field="*")
    {
        return self::commPaginate($con, $order, $perPage, $having, $field);
    }
    /**
     * 自带当前公司的列表查询
     * @param type $con
     * @return type
     */
    public static function listsCompany( $con = [],$order='',$field="*")
    {
        //公司id
        if( self::mainModel()->hasField('company_id') && session( SESSION_COMPANY_ID ) ){
            $con[] = ['company_id','=',session( SESSION_COMPANY_ID )];
        }
        $conAll = array_merge( $con ,self::commCondition() );
        
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        //字段加索引
        self::condAddColumnIndex( $conAll );
        
        return self::mainModel()->where( $conAll )->order($order)->field($field)->cache(2)->select();
    }
    
    /**
     * 带详情的列表
     * @param type $con
     */
    public static function listsInfo( $con = [])
    {
        return self::lists( $con );
    }
    /*
     * 按字段值查询数据
     * @param type $fieldName   字段名
     * @param type $fieldValue  字段值
     * @param type $con         其他条件
     * @return type
     */
    public static function listsByField( $fieldName, $fieldValue, $con = [] )
    {
        $con[] = [ $fieldName , '=',$fieldValue ];
        return self::lists( $con ,'','*',0);    //无缓存取数据
    }
    /**
     * id数组
     * @param type $con
     * @return type
     */
    public static function ids( $con = [])
    {
        $conAll = array_merge( $con ,self::commCondition() );
        //字段加索引
        self::condAddColumnIndex( $con );
        
        return self::mainModel()->where( $conAll )->cache(2)->column('id');
    }
    /**
     * 根据条件返回字段数组
     * @param type $field   字段名
     * @param type $con     查询条件
     * @return type
     */
    public static function column( $field,$con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        //字段加索引
        self::condAddColumnIndex( $con );
        
        return self::mainModel()->where( $con )->cache(2)->column($field);
    }    
    
    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function count( $con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        if(self::mainModel()->hasField('is_delete')){
            $con[] = ['is_delete','=',0];
        }
        //字段加索引
        self::condAddColumnIndex( $con );
        
        return self::mainModel()->where( $con )->count(  );
    }    
    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function sum( $con = [],$field = '')
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        //字段加索引
        self::condAddColumnIndex( $con );
        
        return self::mainModel()->where( $con )->sum( $field );
    }    
    /**
     * 
     * @param type $master  是否从主库获取
     * @return type
     */
    public function commGet( $master = false )
    {
        $con[] = ['id','=',$this->uuid];
        if(self::mainModel()->hasField('is_delete')){
            $con[] = ['is_delete','=',0];
        }
        if($master){
            return self::mainModel()->master()->where($con)->find();
        } else {
            return self::mainModel()->where($con)->find();
        }
    }
    /**
     * 
     * @param type $master   $master  是否从主库获取
     * @return type
     */
    public function get( $master = false )
    {
        if(!$this->uuData){
            if(property_exists(__CLASS__, 'getCache') && self::$getCache){
                // 有缓存的
                $tableName = self::mainModel()->getTable();
                $cacheKey  = 'mainModelGet_'.$tableName.'-'.$this->uuid;
                $this->uuData = Cachex::funcGet($cacheKey, function() use ($master){
                    return $this->commGet( $master );
                });
            } else {
                //没有缓存的
                $this->uuData = $this->commGet( $master );
            }
        }
        return $this->uuData;
    }
    /**
     * 批量获取
     */
    public static function batchGet($ids, $keyField="id", $field = "*"){
        $con[] = ['id','in',$ids];
        $lists = self::mainModel()->where($con)->field($field)->select();
        $listsArr = $lists->toArray();
        $listsArrObj = Arrays2d::fieldSetKey($listsArr, $keyField);
        foreach($listsArrObj as $k=>$v){
            self::getInstance($k)->setUuData($v,true);  //强制写入
        }
        return $listsArrObj;
    }
    /**
     * 修改数据时，同步调整实例内的数据
     * @param type $newData
     * @param type $force       数据不存在时，是否强制写入（用于从其他渠道获取的数据，直接赋值，不走get方法）
     * @return type
     */
    public function setUuData($newData, $force = false){
        //强制写入模式，直接赋值
        if($force){
            $this->uuData = $newData;
        } else if($this->uuData){
            foreach($newData as $key=>$value){
                $this->uuData[$key] = $value;
            }
        }
        return $this->uuData;
    }
    
    protected static function commExtraDetail( &$item ,$id ){
        if(!$item){ return false;}        
        //获取关联字段名：形如：array(2) {
        //      ["rec_user_id"] => string(9) "ydzb_user"
        //      ["busier_id"] => string(9) "ydzb_user"
        //  }
        $tableName = self::mainModel()->getTable() ;
        $infoFields = SystemFieldsInfoService::getInfoFields( $tableName );
        //【1】将关联id转换为信息
        foreach( $infoFields as $key=>$table ){
            $service            = DbOperate::getService($table);
            $infoKey            = preg_replace( '/_id$/','_info',$key );  //将_id结尾的键，替换为Info结尾的键盘：注意标准化
            //转驼峰，驼峰表示附加字段(冗余,虚拟)
            $item[camelize($infoKey) ]   = isset($item[$key]) && $item[$key] ? $service::getInstance($item[$key])->get() : [];
        }
        //【2】一对多中间表信息，如角色等
        $manyFields = SystemFieldsManyService::getManyFields( $tableName );
        foreach( $manyFields as $key=>$fieldInfo ){
            $service            = DbOperate::getService( $fieldInfo['relative_table'] );
            $tmpCon     = [];
            $tmpCon[]   = [ $fieldInfo['main_field'],'=',$item[$fieldInfo['field_name']]];
            //拼接字段
            $item[ $fieldInfo['to_field'] ] = $service::mainModel()->where($tmpCon)->cache(500)->column($fieldInfo['to_field']);
        }
        
        return $item;
    }
    /**
     * 额外信息获取
     * @param type $item
     * @param type $id
     * @return type
     */
    public static function extraDetail(&$item,$id ){
        return self::commExtraDetail($item,$id );
    }
    
    /**
     * 公共详情
     * @param type $cache
     * @return type
     */
    protected function commInfo( $cache = 5  )
    {
        $info = self::mainModel()->where('id',$this->uuid)->cache( $cache )->find();
        //额外添加详情信息：固定为extraDetail方法
        if(method_exists( __CLASS__, 'extraDetail')){
            self::extraDetail($info, $info['id']);
        }

        return $info;
    }
    /**
     * 详情
     * @param type $cache
     * @return type
     */
    public function info( $cache = 2  )
    {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        $cache = $cache <0 ? self::defaultCacheTime() : $cache;        
        return $this->commInfo( $cache );
    }
    /**
     * 按条件查询单条数据
     * @param type $con
     * @param type $cache
     * @return type
     */
    public static function find( $con = [],$cache=5)
    {
        $con = array_merge( $con ,self::commCondition() );        
        //字段加索引
        self::condAddColumnIndex( $con );
        
        $inst = self::mainModel()->where( $con );
        $item = $cache
                ? $inst->cache( $cache )->find()
                : $inst->find();
        //写入内存
        if($item){
            self::getInstance($item['id'])->setUuData($item);
        }

        //额外添加详情信息：固定为extraDetail方法
        if(method_exists( __CLASS__, 'extraDetail')){
            self::extraDetail($item, $item['id']);
        }
        return $item;
    }
    /**
     * 末条记录id
     * @return type
     */
    public static function lastId()
    {
        return self::mainModel()->order('id desc')->value('id');
    }
    
    /**************************校验方法********************************/
    /**
     * 校验事务是否处于开启状态
     * @throws Exception
     */
    public static function checkTransaction(){
        if(!self::mainModel()->inTransaction()){
            throw new Exception('请开启数据库事务');
        }
    }
    /**
     * 校验是否当前公司数据
     * @throws Exception
     */
    public static function checkCurrentCompany( $companyId ){
        //当前无session，或当前session与指定公司id不符
        if( !session(SESSION_COMPANY_ID) || session(SESSION_COMPANY_ID) != $companyId ){
            throw new Exception('未找到数据项~~');
        }
    }
	
    /**
     *	公司是否有记录（适用于SARRS）
     */ 
    public static function companyHasLog( $companyId, $con )
    {
        $con[] = ['company_id','=',$companyId];
        return self::find( $con );
    }
}
