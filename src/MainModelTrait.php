<?php

namespace xjryanse\traits;

use xjryanse\user\logic\AuthLogic;
use xjryanse\logic\Arrays;
use xjryanse\logic\Strings;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\Cachex;
use xjryanse\system\service\SystemColumnService;
use xjryanse\system\service\SystemColumnListService;
// use xjryanse\system\service\SystemFieldsInfoService;
// use xjryanse\system\service\SystemFieldsManyService;
use xjryanse\system\service\SystemTableCacheTimeService;
use xjryanse\system\service\SystemAsyncTriggerService;
use xjryanse\system\service\SystemColumnListForeignService;
use xjryanse\finance\service\FinanceTimeService;
use app\system\AsyncOperate\SendTemplateMsg;
use think\facade\Request;
use think\Db;
use think\facade\Cache;
use Exception;

/**
 * 主模型复用
 */
trait MainModelTrait {

    //复用类需实现
//    protected static $mainModelClass;
    /**
     * 20220620:用于调试死循环
    self::$queryCount = self::$queryCount +1;
    // 20220312;因为检票，从20调到200；TODO检票的更优方案呢？？
    $limitTimes = 20;
    if(self::$queryCount > $limitTimes){
        throw new Exception('$queryCount 次数超限'.$limitTimes);
    }
     */
    public static $queryCount       = 0 ;   //末个节点执行次数
    //20220803优化
    public static $queryCountArr    = [] ;   //末个节点执行次数

    protected static $mainModel;
    //是否直接执行后续触发动作
    //protected static $directAfter;
    //20220617:考虑get没取到值的情况，可以不用重复查询
    protected $hasUuDataQuery = false;
    protected $uuData = [];

    public static function mainModel() {
        //实现一下获取主模型
        if (!self::$mainModel) {
            self::$mainModel = new self::$mainModelClass();
        }
        return self::$mainModel;
    }
    /**
     * 20230601
     * @return type
     */
    public static function mainModelClass(){
        return self::$mainModelClass;
    }

    public function __call($method, $ages) {
        //首字母f，且第二个字母大写，表示字段

        return $method . '不存在';
    }
    /**
     * 20220921 主模型带where参数
     */
    public static function where($con = []){
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con);
    }

    /**
     * 默认缓存时间
     * @return type
     */
    protected static function defaultCacheTime() {
        $tableName = self::mainModel()->getTable();
        return SystemTableCacheTimeService::tableCache($tableName);
    }

    /**
     * 表名
     */
    public static function getTable() {
        return self::mainModel()->getTable();
    }

    //公共的数据过滤条件
    protected static function commCondition($withDataAuth = true) {
        $con = session(SESSION_USER_ID) && $withDataAuth ? AuthLogic::dataCon(session(SESSION_USER_ID), self::mainModel()->getTable()) : AuthLogic::dataCon(session(SESSION_USER_ID), self::mainModel()->getTable(), true);  //不带数据权限情况下，只取严格模式的权限
        //customerId 的session
        //客户id  有bug20210323
        if (self::mainModel()->hasField('customer_id') && session(SESSION_CUSTOMER_ID)) {
//            $con[] = ['customer_id','=',session(SESSION_CUSTOMER_ID)];
        }
        //公司隔离
        if (self::mainModel()->hasField('company_id') && session(SESSION_COMPANY_ID)) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        //应用id
        if (self::mainModel()->hasField('app_id')) {
            $con[] = ['app_id', '=', session(SESSION_APP_ID)];
        }
        //删除标记
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        return $con;
    }

    /**
     * 预保存数据
     */
    protected static function preSaveData(&$data) {
        Debug::debug('预保存数据$data', $data);
        if (!isset($data['id']) || !$data['id']) {
            $data['id'] = self::mainModel()->newId();
        }
        if (session(SESSION_COMPANY_ID) && !isset($data['company_id']) && self::mainModel()->hasField('company_id')) {
            $data['company_id'] = session(SESSION_COMPANY_ID);
        }
        if (session(SESSION_APP_ID) && !isset($data['app_id']) && self::mainModel()->hasField('app_id')) {
            $data['app_id'] = session(SESSION_APP_ID);
        }
        if (session(SESSION_USER_ID) && !isset($data['creater']) && self::mainModel()->hasField('creater')) {
            $data['creater'] = session(SESSION_USER_ID);
        }
        //数据来源
        if (session(SESSION_SOURCE) && !isset($data['source']) && self::mainModel()->hasField('source')) {
            $data['source'] = session(SESSION_SOURCE);
        }
        //20220324:部门id(恒兴)
        if (session(SESSION_DEPT_ID) && !isset($data['dept_id']) && self::mainModel()->hasField('dept_id')) {
            $data['dept_id'] = session(SESSION_DEPT_ID);
        }
        // 20221026：create_time????
        if(!isset($data['create_time']) || !$data['create_time']){
            $data['create_time'] = date('Y-m-d H:i:s');
        }
        $data['update_time'] = date('Y-m-d H:i:s');
        return $data;
    }

    /**
     * 条件给字段添加索引
     */
    protected static function condAddColumnIndex($con = []) {
        if (!$con) {
            return false;
        } else {
            return true;     //去掉本行后会执行自动添加索引，一般应于项目正式后关闭
        }
        foreach ($con as $conArr) {
            if (is_array($conArr)) {
                DbOperate::addColumnIndex(self::mainModel()->getTable(), $conArr[0]);
            }
        }
    }

    /*     * ***公共保存【外部有调用】**** */

    protected static function commSave($data) {
        //预保存数据：id，app_id,company_id,creater,updater,create_time,update_time
        self::preSaveData($data);
        //额外添加详情信息：固定为extraDetail方法
        if (method_exists(__CLASS__, 'extraPreSave')) {
            self::extraPreSave($data, $data['id']);      //注：id在preSaveData方法中生成
        }
        
        // $realFieldArr = DbOperate::realFieldsArr(self::mainModel()->getTable());
        // $dataSave = Arrays::getByKeys($data, $realFieldArr);
        //保存
        $res = self::mainModel()->create($data);
        $resp = $res ? $res->toArray() : [];
        //20220617:写入内存
        $uuData = self::getInstance($res['id'])->commGet(true);
        self::getInstance($res['id'])->setUuData($uuData, true);
//        $resp = self::mainModel()->insert( $data );
        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'extraAfterSave')) {
            //20210821，改异步
            //$resp = $res ? $res ->toArray() : [];
            //self::extraAfterSave( $resp, $data['id']);     
            if (session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)) {
                self::extraAfterSave($resp, $data['id']);
            } else {
                $fromTable = self::mainModel()->getTable();
                $addTask = SystemAsyncTriggerService::addTask('save', $fromTable, $data['id']);
                Debug::debug('extraAfterSave', $addTask);
            }
        }
        /**
         * 2022-12-15:增加静态配置清缓存
         */
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
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
    protected function commUpdate(array $data) {
        $info = $this->get(0);
        if (!$info) {
//            return false;
            throw new Exception('记录不存在' . self::mainModel()->getTable() . '表' . $this->uuid);
        }
        if (isset($info['is_lock']) && $info['is_lock']) {
            throw new Exception('记录已锁定不可修改' . self::mainModel()->getTable() . '表' . $this->uuid);
        }
        if (!isset($data['id']) || !$data['id']) {
            $data['id'] = $this->uuid;
        }
        $data['updater'] = session(SESSION_USER_ID);
        $data['update_time'] = date('Y-m-d H:i:s');
        //额外添加详情信息：固定为extraDetail方法；更新前执行
        if (method_exists(__CLASS__, 'extraPreUpdate')) {
            self::extraPreUpdate($data, $data['id']);
        }
        //20210520排除虚拟字段
        $realFieldArr = DbOperate::realFieldsArr(self::mainModel()->getTable());
        $dataSave = Arrays::getByKeys($data, $realFieldArr);
        $res = self::mainModel()->update($dataSave);
        //20220518,调试内容
        Debug::debug(__CLASS__ . __FUNCTION__ . '的updateSql', self::mainModel()->getLastSql());
        if ($res) {
            // 设定内存中的值
            $this->setUuData($dataSave);
        }

        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'extraAfterUpdate')) {
            if (session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)) {
                //$resp = $res ? $res ->toArray() : [];
                // 更新实例值
                // self::getInstance($data['id'])->setUuData($dataSave);  
                // self::extraAfterUpdate( $resp, $data['id']);
                // 20220609:尝试替换：影响较大，请跟踪
                self::extraAfterUpdate($data, $data['id']);
            } else {
                //20210821改异步
                $fromTable = self::mainModel()->getTable();
                $addTask = SystemAsyncTriggerService::addTask('update', $fromTable, $data['id']);
                Debug::debug('extraAfterUpdate', $addTask);
            }
        }

        /**
         * 2022-12-15:增加静态配置清缓存
         */
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
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
    protected function commDelete() {
        $info = $this->get(0);
        if (!$info) {
            throw new Exception('记录不存在' . self::mainModel()->getTable() . '表' . $this->uuid);
        }
        if (isset($info['has_used']) && $info['has_used']) {
            //软删
            $res = self::mainModel()->where('id', $this->uuid)->update(['is_delete' => 1]);
            return $res;
//            throw new Exception('记录已使用不可删除'.self::mainModel()->getTable().'表'.$this->uuid);
        }
        if (isset($info['is_lock']) && $info['is_lock']) {
            throw new Exception('记录已锁定不可删除' . self::mainModel()->getTable() . '表' . $this->uuid);
        }
        //【20210315】判断关联表有记录，则不可删
        /*
        $relativeDels = SystemFieldsInfoService::relativeDelFields(self::mainModel()->getTable());
        if ($relativeDels) {
            foreach ($relativeDels as $relativeDel) {
                if (DbOperate::isTableExist($relativeDel['table_name']) && Db::table($relativeDel['table_name'])->where($relativeDel['field_name'], $this->uuid)->count()) {
                    if ($relativeDel['del_fault_msg']) {
                        throw new Exception($relativeDel['del_fault_msg']);
                    } else {
                        //                    throw new Exception('记录已使用，不可操作');
                        throw new Exception('当前记录' . $this->uuid . '已在数据表' . $relativeDel['table_name'] . '的' . $relativeDel['field_name'] . '字段使用,不可操作');
                    }
                }
            }
        }
         */

        $res = self::mainModel()->where('id', $this->uuid)->delete();
//        if($res){
//            self::_cacheUpdate( $this->uuid );
//        }        
        
        /**
         * 2022-12-15:增加静态配置清缓存
         */
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }

        return $res;
    }

    /*     * ************************操作方法******************************* */

    public static function save($data) {
        return self::commSave($data);
    }

    /**
     * 20220305
     * 替代TP框架的select方法，在查询带图片数据上效率更高
     * @param type $inst    组装好的db查询类
     */
    public static function selectX($con = [], $order = "", $field = "", $hidden = []) {
        $tableName = self::mainModel()->getTable();
        $inst = Db::table($tableName);
        if ($con) {
            $inst->where($con);
        }
        if ($order) {
            $inst->order($order);
        }
        if ($field) {
            $inst->field($field);
        }
        if ($hidden) {
            $inst->hidden($hidden);
        }
        $data = $inst->select();
        Debug::debug($tableName.'的selectX的sql',Db::table($tableName)->getLastSql());
        /* 图片字段提取 */
        if (property_exists(self::mainModel(), 'picFields')) {
            $picFields = self::mainModel()::$picFields;
            $data = Arrays2d::picFieldCov($data, $picFields);
        }
        /**多图**/
        if (property_exists(self::mainModel(), 'multiPicFields')) {
            $multiPicFields = self::mainModel()::$multiPicFields;
            $data = Arrays2d::multiPicFieldCov($data, $multiPicFields);
        }
        /* 2022-12-18 混合图片的字段提取，如:配置表 */
        if (property_exists(self::mainModel(), 'mixPicFields')) {
            $mixPicFields = self::mainModel()::$mixPicFields;
            $data = Arrays2d::mixPicFieldCov($data, $mixPicFields);
        }
        // 2022-12-18:增加获取器转换（不应有查询数据库逻辑，以确保性能）
        foreach($data as &$dVal){
            foreach($dVal as $kk=>&$vv){
                $attrClass = 'get'.ucfirst(Strings::camelize($kk)).'Attr';
                if(method_exists(self::mainModel(),$attrClass)){
                    $vv = self::mainModel()->$attrClass($vv,$dVal);
                }
            }
        }

        return $data;
    }
    /**
     * 20230429：增强的筛选，自动判断是否有静态。
     */
    public static function selectXS($con = [], $order = "", $field = "", $hidden = []){
        if (method_exists(__CLASS__, 'staticConList')) {
            $lists = self::staticConList($con);
        } else {
            $lists = self::selectX($con, $order, $field, $hidden);
        }
        return $lists;
    }
    
    /*
     * 批量保存
     */

    public static function saveAll(array &$data, $preData = []) {
        // 2023-02-28：用于批量导入过滤数据
        if (method_exists(__CLASS__, 'saveAllFilter')) {
            self::saveAllFilter($data);      //注：id在preSaveData方法中生成
        }
        $tmpArr = [];
        foreach ($data as &$v) {
            $tmpData = array_merge($preData, $v);
            //预保存数据
            self::preSaveData($tmpData);
            //额外添加详情信息：固定为extraDetail方法
            if (method_exists(__CLASS__, 'extraPreSave')) {
                self::extraPreSave($tmpData, $tmpData['id']);      //注：id在preSaveData方法中生成
            }
            $tmpArr[] = $tmpData;
        }
        //saveAll方法新增数据默认会自动识别数据是需要新增还是更新操作，当数据中存在主键的时候会认为是更新操作，如果你需要带主键数据批量新增，可以使用下面的方式
        // $res = self::mainModel()->saveAll( $tmpArr ,false );
        // Debug::dump($tmpArr);
        $res = self::saveAllX($tmpArr);
        // 2022-12-16
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }
        // 20230403:批量保存后处理逻辑
        if (method_exists(__CLASS__, 'extraAfterSaveAll')) {
            self::extraAfterSaveAll($tmpArr);      //注：id在preSaveData方法中生成
        }
        
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
     * 20220621优化性能
     * @param array $data
     * @param type $preData
     * @return type
     */
    public static function saveAllRam(array &$data, $preData = []) {
        foreach ($data as &$v) {
            $tmpData = array_merge($preData, $v);
            self::saveRam($tmpData);
        }
        return true;
    }

    /**
     * 【增强版】批量保存数据
     * @param type $dataArr
     */
    public static function saveAllX($dataArr) {
        if (!$dataArr) {
            return false;
        }

        //20220621;解决批量字段不同步bug                
        $tableName = self::getTable();
        $saveArr = [];
        foreach($dataArr as $data){
            $keys = array_keys($data);
            sort($keys);
            ksort($data);
            $data = DbOperate::dataFilter($tableName, $data);
            $keyStr = md5(implode(',', $keys));
            $saveArr[$keyStr][] = $data;
        }
        // 20220621
        foreach($saveArr as $arr){
            $sql = DbOperate::saveAllSql($tableName, array_values($arr));
            Db::query($sql);
        }
        return true;
    }

    /**
     * 数据保存取id（自动识别是新增还是更新）
     * @param type $data    
     * @return type
     */
    protected static function commSaveGetId($data) {
        $mainId = '';
        if (isset($data['id']) && self::getInstance($data['id'])->get()) {
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
     * 优化性能
     */
    protected static function commSaveGetIdRam($data){
        $mainId = '';
        if (isset($data['id']) && self::getInstance($data['id'])->get()) {
            $mainId = $data['id'];
            //更新
            $res = self::getInstance($data['id'])->updateRam($data);
        } else {
            //新增
            $res = self::saveRam($data);
            $mainId = $res['id'];
        }

        return $mainId;
    }

    /**
     * 数据保存取id（自动识别是新增还是更新）
     * @param type $data    
     * @return type
     */
    public static function saveGetId($data) {
        return self::commSaveGetId($data);
    }
    
    public static function saveGetIdRam($data) {
        return self::commSaveGetIdRam($data);
    }

    /**
     * 关联表数据保存
     * @param type $mainField   主字段
     * @param type $mainValue   主字段值
     * @param type $arrField    数组字段
     * @param type $arrValues   数组值：一维数据写入数组字段，二维数据直接存储
     */
    public static function midSave($mainField, $mainValue, $arrField, $arrValues) {
        self::checkTransaction();

        $con[] = [$mainField, '=', $mainValue];
        self::mainModel()->where($con)->delete();
        $tmpData = [];
        foreach ($arrValues as $value) {
            if (is_array($value)) {
                $tmp = $value;
            } else {
                $tmp = [];
                $tmp[$arrField] = $value;
            }
            $tmp[$mainField] = $mainValue;
            $tmpData[] = $tmp;
        }
        return self::saveAll($tmpData);
    }

    /**
     * 更新
     * @param array $data
     * @return type
     * @throws Exception
     */
    public function update(array $data) {
        //预保存数据
        return $this->commUpdate($data);
    }

    /*
     * 设定字段的值
     * @param type $key     键
     * @param type $value   值
     */

    public function setField($key, $value) {
        return $this->update([$key => $value]);
    }

    /**
     * 字段名取值
     * @param type $fieldName   字段名
     * @param type $default     默认值
     * @return type
     */
    public function fieldValue($fieldName, $default = '') {
        //如果是定值；有缓存取缓存；无缓存再从数据库取值
        if ((property_exists(__CLASS__, 'fixedFields') && in_array($fieldName, self::$fixedFields))) {
            $tableName = self::mainModel()->getTable();
            $cacheKey = $tableName . '-' . $this->uuid . '-' . $fieldName;
            return Cachex::funcGet($cacheKey, function() use ($fieldName, $default) {
                        return $this->fieldValueFromDb($fieldName, $default);
                    });
        } else {
            return $this->fieldValueFromDb($fieldName, $default);
        }
    }

    /**
     * 从数据库取新值
     */
    private function fieldValueFromDb($fieldName, $default = '') {
        //20220306；配置式缓存
        if (method_exists(__CLASS__, 'staticGet')) {
            $info = $this->staticGet();
        } else {
            $info = $this->get();
        }
        return Arrays::value($info, $fieldName, $default);
    }

    /**
     * 获取f开头的驼峰方法名字段信息
     * @param type $functionName  方法名，一般__FUNCTION__即可
     * @param type $prefix          前缀
     * @return type
     */
    public function getFFieldValue($functionName, $prefix = "f_") {
        //驼峰转下划线，再去除前缀
        $pattern = '/^' . $prefix . '/i';
        $fieldName = preg_replace($pattern, '', uncamelize($functionName));
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

    public function setFieldWithPreValCheck($key, $preValue, $aftValue) {
        $info = $this->get(0);
        if ($info[$key] != $preValue) {
            throw new Exception(self::mainModel()->getTable() . '表' . $this->uuid . '记录'
                    . $key . '的原值不是' . $preValue);
        }
        $con[] = [$key, '=', $preValue];
        $con[] = ['id', '=', $this->uuid];
        $res = $this->update([$key => $aftValue]);
//        $res = self::mainModel()->where( $con )->update([$key=>$aftValue]);
//        if($res){
//            self::_cacheUpdate( $this->uuid );
//        }
        //更新缓存
        return $res;
    }

    public function delete() {
        // 20230516：通用的删除前校验数据
        if (method_exists(__CLASS__, 'delPreCheck')) {
            $info = $this->info();
            self::delPreCheck($info);
        }
        //删除前
        if (method_exists(__CLASS__, 'extraPreDelete')) {
            $this->extraPreDelete();      //注：id在preSaveData方法中生成
        }
        // 20230601:最后一道防线
        $tableName = self::getTable();
        DbOperate::checkCanDelete($tableName, $this->uuid);
        //20220515;优化跳转
        $rawData = $this->get();
        //删除
        $res = $this->commDelete();
        //删除后
        if (method_exists(__CLASS__, 'extraAfterDelete')) {
            if (session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)) {
                $this->extraAfterDelete($rawData);
            } else {
                //20210821改异步
                // $this->extraAfterDelete();      //注：id在preSaveData方法中生成
                $fromTable = self::mainModel()->getTable();
                SystemAsyncTriggerService::addTask('delete', $fromTable, $this->uuid);
            }
        }

        return $res;
    }

    /*     * ************************查询方法******************************* */

    protected static function commLists($con = [], $order = '', $field = "*", $cache = 2) {
        $conAll = array_merge($con, self::commCondition());
        if (!$order && self::mainModel()->hasField('sort')) {
            $order = "sort";
        }
        Debug::debug('commLists查询表', self::mainModel()->getTable());
        Debug::debug('commLists查询sql', $conAll);
        //字段加索引
        self::condAddColumnIndex($con);
        $res = self::mainModel()->where($conAll)->order($order)->field($field)->cache($cache)->select();
        // 查询出来了直接存
        if ($field == "*") {
            foreach ($res as &$v) {
                self::getInstance($v['id'])->setUuData($v, true);  //强制写入
            }
        }
        return $res;
    }

    public static function lists($con = [], $order = '', $field = "*", $cache = -1) {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        $cache = $cache < 0 ? self::defaultCacheTime() : $cache;

        return self::commLists($con, $order, $field, $cache)->each(function($item, $key) {
                    //额外添加详情信息：固定为extraDetail方法
                    if (method_exists(__CLASS__, 'extraDetail')) {
                        self::extraDetail($item, $item['id']);
                    }
                });
    }
    /**
     * 20221104：查询结果即数组
     * @param type $con
     * @param type $order
     * @param type $field
     * @param type $cache
     * @return type
     */
    public static function listsArr($con = [], $order = '', $field = "*", $cache = -1) {
        $lists = self::lists($con, $order, $field, $cache);
        return $lists ? $lists->toArray() : [];
    }

    /**
     * 查询列表，并写入get
     * @param type $con
     */
    public static function listSetUudata($con = [], $master = false) {
        if ($master) {
            $lists = self::mainModel()->master()->where($con)->select();
        } else {
            // $lists = self::mainModel()->where($con)->select();
            // 20230501：优化性能
            $lists = self::selectXS($con);
        }
        //写入内存
        foreach ($lists as $v) {
            self::getInstance($v['id'])->setUuData($v, true);  //强制写入
        }
        return $lists;
    }

    /**
     * 20220919动态数组列表
     */
    public static function dynDataList($dataArr) {
        $columnId   = SystemColumnService::tableNameGetId(self::getTable());
        $dynFields  = SystemColumnListService::columnTypeFields($columnId, 'dynenum');
        $dynDatas   = [];
        foreach ($dynFields as $key) {
            $dynDatas[$key] = array_unique(array_column($dataArr, $key));
        }
        Debug::debug('commPaginate 的 dynDataList 的 $columnId', $columnId);
        Debug::debug('commPaginate 的 dynDataList 的 $dynDatas', $dynDatas);
        $dynDataList = SystemColumnListService::sDynDataList($columnId, $dynDatas);
        return $dynDataList;
    }

    /**
     * 分页的查询
     * @param type $con
     * @param type $order
     * @param type $perPage
     * @return type
     */
    public static function paginate($con = [], $order = '', $perPage = 10, $having = '', $field = "*", $withSum = false) {
        return self::paginateX($con, $order, $perPage, $having, $field, $withSum);
        // return self::commPaginate($con, $order, $perPage, $having, $field);
    }

    /**
     * 使用自己封装的分页查询方法（框架自带方法有性能问题）
     * @param type $con
     * @param string $order
     * @param type $perPage
     * @param type $having
     * @param type $field
     * @return type
     */
    public static function paginateX($con = [], $order = '', $perPage = 10, $having = '', $field = "*", $withSum = false) {
        //默认带数据权限
        $conAll = array_merge($con, self::commCondition());
        //如果有额外的数据过滤条件限定方法
        if (method_exists(__CLASS__, 'extraDataAuthCond')) {
            $conAll = array_merge($conAll, self::extraDataAuthCond());
        }
        // 查询条件单拎；适用于后台管理（客户权限，业务员权限）
        return self::paginateRaw($conAll, $order, $perPage, $having, $field, $withSum);
    }
    /**
     * 20230323：raw方法，解决有些不需要数据权限的场景：比如web端
     * @param type $conAll
     * @param type $order
     * @param type $perPage
     * @param type $having
     * @param string $field
     * @param type $withSum
     * @return type
     */
    public static function paginateRaw($conAll = [], $order = '', $perPage = 10, $having = '', $field = "*", $withSum = false) {
        if (method_exists(__CLASS__, 'extraDetails')) {
            $field = 'id';
        }
        // 一定要放在setCustTable前面
        $columnId = SystemColumnService::tableNameGetId(self::getTable());
        $page = Request::param('page', 1);
        $start = ($page - 1) * intval($perPage);
        // 定制数据表查询视图的方法
        if (method_exists(__CLASS__, 'setCustTable')) {
            //20211015这种一般都是业务比较复杂的，从主库进行查询
            //TODO是否可以优化？？
            if (method_exists(__CLASS__, 'setCustIdTable')) {
                $resp['table'] = self::setCustIdTable($conAll);
                $res = self::mainModel()->master()->order($order)->field($field)->limit($start, intval($perPage))->select();
                // 定制数据统计方法
                $total = method_exists(__CLASS__, 'custCount') ? self::custCount($conAll) : self::mainModel()->count(1);
            } else {
                $resp['table'] = self::setCustTable($conAll);
                $res = self::mainModel()->master()->order($order)->field($field)->limit($start, intval($perPage))->select();
                // 定制数据统计方法
                $total = method_exists(__CLASS__, 'custCount') ? self::custCount($conAll) : self::mainModel()->count(1);
            }
        } else {
            $res = self::mainModel()->where($conAll)->order($order)->field($field)->limit($start, intval($perPage))->select();
            //20220619：如果查询结果数小于分页条数，则结果数即总数
            $total = $page == 1 && count($res) < $perPage 
                    ? count($res) 
                    : self::mainModel()->where($conAll)->count(1);
        }
        // 采用跟TP框架一样的数据格式
        $resp['data'] = $res ? $res->toArray() : [];
        //额外数据信息；上方取了id后，再此方法内部根据id进行第二次查询
        //（逻辑比较复杂，但大表数据效率较高）
        if (method_exists(__CLASS__, 'extraDetails')) {
            $extraDetails = self::extraDetails(array_column($resp['data'], 'id'));
            //id 设为键
            $extraDetailsObj = Arrays2d::fieldSetKey($extraDetails, 'id');
            foreach ($resp['data'] as &$v) {
                $v = $extraDetailsObj[$v['id']];
            }
        } else {
            // 2022-12-15:无额外逻辑，也需要查一下配置的统计数据
            $resp['data'] = SystemColumnListForeignService::listAddStatics(self::getTable(), $resp['data']);
        }
        // 关联字段的键值对封装（）
        /*         * *********** 动态枚举 ************ */
        $resp['dynDataList'] = SystemColumnListService::getDynDataListByColumnIdAndData($columnId, $resp['data']);
//
//        $resp['$dynFields']     = $dynFields;
//        $resp['$columnId']      = $columnId;
//        $resp['$dynDatas']      = $dynDatas;
        // 采用跟TP框架一样的数据格式
        $resp['current_page'] = $page;
        $resp['total'] = $total;
        $resp['per_page'] = intval($perPage);
        $resp['last_page'] = ceil($resp['total'] / intval($perPage));
        // 是否展示统计数据
        $resp['withSum'] = $withSum ? 1 : 0;
        /**
         * 2020303,新增带求和字段
         */
        if ($withSum && $resp['data']) {
            $sumFields = SystemColumnListService::sumFields($columnId);
            if ($sumFields) {
                $fieldStr = DbOperate::sumFieldStr($sumFields);
                $data = self::mainModel()->where($conAll)->field($fieldStr)->find();
                $resp['sumData'] = $data ? $data->toArray() : [];
            } else {
                // 20220610:增加空数据时输出，避免前端报错
                $resp['sumData'] = [];
            }
        }
        $resp['con'] = $conAll;
        return $resp;
    }

    /**
     * 自带当前公司的列表查询
     * @param type $con
     * @return type
     */
    public static function listsCompany($con = [], $order = '', $field = "*") {
        //公司id
        if (self::mainModel()->hasField('company_id') && session(SESSION_COMPANY_ID)) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        $conAll = array_merge($con, self::commCondition());

        if (!$order && self::mainModel()->hasField('sort')) {
            $order = "sort";
        }
        //字段加索引
        self::condAddColumnIndex($conAll);

        return self::mainModel()->where($conAll)->order($order)->field($field)->cache(2)->select();
    }

    /**
     * 带详情的列表
     * @param type $con
     */
    public static function listsInfo($con = []) {
        return self::lists($con);
    }

    /*
     * 按字段值查询数据
     * @param type $fieldName   字段名
     * @param type $fieldValue  字段值
     * @param type $con         其他条件
     * @return type
     */

    public static function listsByField($fieldName, $fieldValue, $con = []) {
        $con[] = [$fieldName, '=', $fieldValue];
        return self::lists($con, '', '*', 0);    //无缓存取数据
    }

    /**
     * id数组
     * @param type $con
     * @return type
     */
    public static function ids($con = [], $order = '') {
        $conAll = array_merge($con, self::commCondition());
        //字段加索引
        self::condAddColumnIndex($con);
        
        $inst = self::mainModel()->where($conAll);
        if ($order) {
            $inst->order($order);
        }
        return $inst->cache(1)->column('id');
    }
    /**
     * 根据字段的值，提取id；
     * 如单号，提取明细号
     * @param type $fieldName
     * @param type $value
     */
    public static function fieldGetIds($fieldName,$value){
        $con[] = [$fieldName, '=', $value];
        return self::where($con)->cache(1)->column('id');
    }

    /**
     * 根据条件返回字段数组
     * @param type $field   字段名
     * @param type $con     查询条件
     * @return type
     */
    public static function column($field, $con = []) {
        if (self::mainModel()->hasField('app_id')) {
            $con[] = ['app_id', '=', session(SESSION_APP_ID)];
        }
        //TODO会有bug
        if (self::mainModel()->hasField('company_id') && session(SESSION_COMPANY_ID)) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        //字段加索引
        self::condAddColumnIndex($con);

        return self::mainModel()->where($con)->cache(2)->column($field);
    }

    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function count($con = []) {
        if (self::mainModel()->hasField('app_id')) {
            $con[] = ['app_id', '=', session(SESSION_APP_ID)];
        }
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        //20220120增加公司隔离，是否有bug？？
        if (self::mainModel()->hasField('company_id') && session(SESSION_COMPANY_ID)) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }

        //字段加索引
        self::condAddColumnIndex($con);

        return self::mainModel()->where($con)->count();
    }

    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function sum($con = [], $field = '') {
        if (self::mainModel()->hasField('app_id')) {
            $con[] = ['app_id', '=', session(SESSION_APP_ID)];
        }
        //字段加索引
        self::condAddColumnIndex($con);

        return self::mainModel()->where($con)->sum($field);
    }

    /**
     * 
     * @param type $master  是否从主库获取
     * @return type
     */
    public function commGet($master = false) {
        if(!$this->uuid){
            return [];
        }
        $con[] = ['id', '=', $this->uuid];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        /*         * 20220303静态表全量缓存，从缓存获取* */
        /*         * 有bug* */
//        if(method_exists( __CLASS__, 'staticConFind')){
//            return self::staticConFind($con);
//        }
        /*         * 其他从数据库获取* */
        if ($master) {
            return self::mainModel()->master()->where($con)->find();
        } else {
            return self::mainModel()->where($con)->find();
        }
    }
    /**
     * 缓存get数据的key值
     */
    protected function cacheGetKey(){
        $tableName = self::mainModel()->getTable();
        return 'mainModelGet_' . $tableName . '-' . $this->uuid;
    }
    
    /**
     * 
     * @param type $master   $master  是否从主库获取
     * @return type
     */
    public function get($master = false) {
        // 2022-11-20:增加静态数据提取方法
        if (!$this->uuData && method_exists(__CLASS__, 'staticGet')) {
            $this->uuData = $this->staticGet();
        }

        if (!$this->uuData && !$this->hasUuDataQuery) {
            if (property_exists(__CLASS__, 'getCache') && self::$getCache) {
                // 有缓存的
                // $tableName = self::mainModel()->getTable();
                // $cacheKey = 'mainModelGet_' . $tableName . '-' . $this->uuid;
                $cacheKey = $this->cacheGetKey();
                $this->uuData = Cachex::funcGet($cacheKey, function() use ($master) {
                            return $this->commGet($master);
                        });
            } else {
                //没有缓存的
                $this->uuData = $this->commGet($master);
            }
            //20220617:增加已查询判断，查空可以不用重复查
            $this->hasUuDataQuery = true;
        }
        return $this->uuData;
    }
    /**
     * 20230516:仅从缓存中提取get
     */
    protected function getFromCache(){
        $cacheKey = $this->cacheGetKey();
        return Cachex::get($cacheKey);
    }

    /**
     * 批量获取
     */
    public static function batchGet($ids, $keyField = "id", $field = "*") {
        //20220617
        if (!$ids) {
            return [];
        }
        // 20220617只有一条的，通过get取（存内存性能得到提升）
        if (count($ids) == 1 && $ids[0]) {
            $info = self::getInstance($ids[0])->get();
            $infoArr = $info ? $info->toArray() : [];
            return [$ids[0] => $infoArr];
        } else {
            $con[] = ['id', 'in', $ids];
            $lists = self::mainModel()->where($con)->field($field)->select();
            $listsArr = $lists->toArray();
            $listsArrObj = Arrays2d::fieldSetKey($listsArr, $keyField);
            foreach ($listsArrObj as $k => $v) {
                self::getInstance($k)->setUuData($v, true);  //强制写入
            }
            return $listsArrObj;
        }
    }

    /**
     * 分组批量筛选
     */
    public static function groupBatchSelect($key, $keyIds, $field = "*", $con = []) {
        $con[] = [$key, 'in', $keyIds];
//        $listsRaw = self::mainModel()->where($con)->field($field)->select();
//        $lists = $listsRaw ? $listsRaw->toArray() : [];
        $lists = self::selectXS($con,'',$field);

        //拼接
        $data = [];
        foreach ($lists as &$v) {
            $data[$v[$key]][] = $v;
        }
        return $data;
    }
    /**
     * 批量find,适用于key为id的情况
     * @param type $key
     * @param type $keyIds
     * @param type $field
     * @return type
     */
    public static function groupBatchFind($ids, $field = "*") {
        $con[] = ['id', 'in', $ids];
        $listsRaw = self::mainModel()->where($con)->field($field)->select();
        $lists = $listsRaw ? $listsRaw->toArray() : [];
        //拼接
        $data = [];
        foreach ($lists as &$v) {
            // $data[$v[$key]][] = $v;
            $data[$v['id']] = $v;
        }
        return $data;
    }

    /**
     * 分组批量统计
     * @param type $key
     * @param type $keyIds
     * @param type $con
     */
    public static function groupBatchCount($key, $keyIds, $con = []) {
        if (method_exists(__CLASS__, 'staticGroupBatchCount')) {
            $arr = self::staticGroupBatchCount($key, $keyIds, $con);
        } else {
            $arr = self::dbGroupBatchCount($key, $keyIds, $con);
        }
        return $arr;
    }
    /**
     * 20230429：数据库中做分组统计
     * @param type $key
     * @param type $keyIds
     * @param type $con
     * @return type
     */
    public static function dbGroupBatchCount($key, $keyIds, $con = []) {
        $con[] = [$key, 'in', $keyIds];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        //20221005:增加公司端口过滤
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con)->group($key)->column('count(1)', $key);
    }


    /**
     * 分组批量求和
     * @param type $key
     * @param type $keyIds
     * @param type $sumField
     * @param type $con
     * @return type
     */
    public static function groupBatchSum($key, $keyIds, $sumField, $con = []) {
        $con[] = [$key, 'in', $keyIds];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }

        return self::mainModel()->where($con)->group($key)->column('sum(' . $sumField . ')', $key);
    }
    /**
     * 20230403 分组取最新的记录
     */
    public static function groupLastRecord($timeField,$groupField ,$dataField='*', $conLast = []){
        $times          = self::where($conLast)->group($groupField)->column('max('.$timeField.')');
        if(!$times){
            return [];
        }
        //根据id，提取数据
        $conL[]         = [$timeField,'in',$times];
        $listArr        = self::where($conL)->order($timeField)->select();
        $dataArr        = [];
        foreach($listArr as $v){
            $dataArr[$v[$groupField]] = $v;
        }

        return $dataArr;
    }
    /**
     * 修改数据时，同步调整实例内的数据
     * @param type $newData
     * @param type $force       数据不存在时，是否强制写入（用于从其他渠道获取的数据，直接赋值，不走get方法）
     * @return type
     */
    public function setUuData($newData, $force = false) {
        //强制写入模式，直接赋值
        Debug::debug(self::mainModel()->getTable().'的setUuData',$newData);
        if ($force) {
            $this->uuData = $newData;
        } else if ($this->uuData) {
            foreach ($newData as $key => $value) {
                $this->uuData[$key] = $value;
            }
        }
        return $this->uuData;
    }

    /**
     * 逐步废弃：20220606
     * @param type $item
     * @param type $id
     * @return boolean
     */
    protected static function commExtraDetail(&$item, $id) {
        if (!$item) {
            return false;
        }
        return $item;
    }
    /**
     * 2023-01-08：删除公共详情的缓存
     * @param type $ids
     */
    public static function clearCommExtraDetailsCache($ids){
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        foreach($ids as $id){
            $cacheKey = self::commExtraDetailsCacheKey($id);
            Cache::rm($cacheKey);
        }
    }
    
    /**
     * 2023-01-08：获取数据缓存key
     */
    protected static function commExtraDetailsCacheKey($id){
        $tableName      = self::mainModel()->getTable();
        $baseCacheKey   = $tableName.'commExtraDetails';
        return $baseCacheKey.$id;
    }

    /**
     * 2023-01-08:带缓存查询详情数据
     */
    protected static function commExtraDetailsWithCache($ids, $func = null, $expire = 0){
        //数组返回多个，非数组返回一个
        $isMulti = is_array($ids);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        // ====
        $needDbQuery    = false;
        $cacheRes       = [];
        // 先从缓存数据中提取；
        foreach($ids as $id){
            $cacheKey   = self::commExtraDetailsCacheKey($id);
            $cacheInfo  = Cache::get($cacheKey);
            if(!$cacheInfo){
                $needDbQuery = true;
            }
            $cacheRes[] = $cacheInfo;
        }
        // 进行数据库查询
        if($needDbQuery){
            $lists      = self::commExtraDetails($ids, $func);
            foreach($lists as $v){
                $cacheKey   = self::commExtraDetailsCacheKey($v['id']);
                Cache::set($cacheKey, $v, $expire);
            }

            $cacheRes   = $lists;
        }

        return $isMulti ? $cacheRes : $cacheRes[0];
    }

    /**
     * 20220606，闭包公共
     * @param type $ids
     * @param type $func            闭包方法
     * @param type $withUniStatics  是否带关联统计？过渡
     * @return type
     */
    protected static function commExtraDetails($ids, $func = null, $withUniStatics = false) {
        //数组返回多个，非数组返回一个
        $isMulti = is_array($ids);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        //20220619:优化性能
        if(!$ids){
            return [];
        }
        $con[] = ['id', 'in', $ids];
        //20220706:增加数据隔离
        if(self::mainModel()->hasField('company_id')){
            $con[] = ['company_id', 'in', session(SESSION_COMPANY_ID)];
        }        
        // $listsRaw = self::selectX($con);      
        if(method_exists(__CLASS__, 'staticConList')){
            $listsRaw = self::staticConList($con);
        } else {
            $listsRaw = self::selectX($con);      
        }
        // 20221104:增？？写入内存
        foreach($listsRaw as &$dataItem){
            self::getInstance($dataItem['id'])->setUuData($dataItem,true);  //强制写入
            // 20230516：增加写入缓存
            if (property_exists(__CLASS__, 'getCache') && self::$getCache) {
                // 有缓存的
                // $tableName = self::mainModel()->getTable();
                // $cacheKey = 'mainModelGet_' . $tableName . '-' . $this->uuid;
                $cacheKey = self::getInstance($dataItem['id'])->cacheGetKey();
                self::getInstance($dataItem['id'])->hasUuDataQuery = true;
                Cachex::setVal($cacheKey, $dataItem);
            }
        }
        // 20220919:返回结果按原顺序输出
        $listsObj = Arrays2d::fieldSetKey($listsRaw, 'id');
        $listsA = [];
        foreach ($ids as &$id) {
            // 20230516：增加isset判断
            if(isset($listsObj[$id])){
                $listsA[] = $listsObj[$id];
            }
        }
        // 20230528：添加框架的关联统计
        if($withUniStatics){
            $listsA = self::listAddUniStatics($listsA);
        }
        // 2022-12-14:【公共的配置式拼接统计数据】
        $lists = SystemColumnListForeignService::listAddStatics(self::getTable(), $listsA);
        //自定义方法：
        $listsNew = $lists 
                ? ($func ? $func($lists) : $lists)
                : [];

        return $isMulti ? $listsNew : $listsNew[0];
    }
    /**
     * 20230528：列表添加框架的关联统计
     */
    protected static function listAddUniStatics($lists){
        if(!$lists){
            return [];
        }
        $res = self::objAttrConfList();
        $ids = $lists ? array_column($lists, 'id') : [];
        //【1】批量查询属性列表
        foreach($res as $key=>$val){
            self::objAttrsListBatch($key, $ids); 
        }
        //【2】拼接属性列表
        foreach($lists as &$v){
            // $key即objAttrs的key
            foreach($res as $key=>$val){
                $v['uni'.ucfirst($key).'Count'] = self::getInstance($v['id'])->objAttrsCount($key);
            }
        }
        return $lists;
    }

    /**
     * 额外信息获取
     * @param type $item
     * @param type $id
     * @return type
     */
    public static function extraDetail(&$item, $id) {
        return self::commExtraDetail($item, $id);
    }

    /**
     * 公共详情
     * @param type $cache
     * @return type
     */
    protected function commInfo($cache = 5) {
        //额外添加详情信息：固定为extraDetails方法
        if (method_exists(__CLASS__, 'extraDetails')) {
            $info = self::extraDetails($this->uuid);
        } else {
            $infoRaw = $this->get();
            // 2022-11-20???
            if(is_object($infoRaw)){
                $info = $infoRaw ? $infoRaw->toArray() : [];
            } else {
                $info = $infoRaw ? : [];
            }
        }
        /** 20220514；增加动态枚举数据返回 ************ */
        $columnId = SystemColumnService::tableNameGetId(self::getTable());
        $dynFields = SystemColumnListService::columnTypeFields($columnId, 'dynenum');
        $dynDatas = [];
        foreach ($dynFields as $key) {
            $dynDatas[$key] = Arrays::value($info, $key);    // array_unique(array_column($info,$key));
        }
        // 固定dynDataList
        if($info){
            $info['dynDataList'] = SystemColumnListService::sDynDataList($columnId, $dynDatas);
        }

        return $info;
    }

    /**
     * 详情
     * @param type $cache
     * @return type
     */
    public function info($cache = 2) {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        $cache = $cache < 0 ? self::defaultCacheTime() : $cache;
        return $this->commInfo($cache);
    }

    /**
     * 按条件查询单条数据
     * @param type $con
     * @param type $cache
     * @return type
     */
    public static function find($con = [], $cache = 5) {
        $conN = array_merge($con, self::commCondition());
        //字段加索引
        self::condAddColumnIndex($conN);

        $inst = self::mainModel()->where($conN);
        $item = $cache ? $inst->cache($cache)->find() : $inst->find();
        //写入内存
        if ($item) {
            self::getInstance($item['id'])->setUuData($item);
        }

        //额外添加详情信息：固定为extraDetail方法
        /* 2022-12-14精简剔除
        if (method_exists(__CLASS__, 'extraDetail')) {
            self::extraDetail($item, $item['id']);
        }
         */
        return $item;
    }
    
    /**
     * 末条记录id
     * @return type
     */
    public static function lastId() {
        return self::mainModel()->order('id desc')->value('id');
    }
    
    /*
     * 上一次的值
     */
    public static function lastVal($field, $con = []){
        return self::mainModel()->where($con)->order('id desc')->value($field);
    }

    /*     * ************************校验方法******************************* */

    /**
     * 校验事务是否处于开启状态
     * @throws Exception
     */
    public static function checkTransaction() {
        if (!self::mainModel()->inTransaction()) {
            throw new Exception('请开启数据库事务');
        }
    }

    /**
     * 校验事务是否处于关闭状态
     * @throws Exception
     */
    public static function checkNoTransaction() {
        if (self::mainModel()->inTransaction()) {
            throw new Exception('请关闭事务');
        }
    }

    /**
     * 校验是否当前公司数据
     * @throws Exception
     */
    public static function checkCurrentCompany($companyId) {
        //当前无session，或当前session与指定公司id不符
        if (!session(SESSION_COMPANY_ID) || session(SESSION_COMPANY_ID) != $companyId) {
            throw new Exception('未找到数据项~~');
        }
    }

    /**
     * 	公司是否有记录（适用于SARRS）
     */
    public static function companyHasLog($companyId, $con) {
        $con[] = ['company_id', '=', $companyId];
        return self::find($con);
    }

    /**
     * 判断表中某个字段是否有值
     * @param type $field
     * @param type $value
     */
    public static function fieldHasValue($field, $value) {
        $con[] = [$field, '=', $value];
        return self::mainModel()->where($con)->count();
    }

    /**
     * 校验系统账期
     * 写在这里方便不需要每个类都引入FinanceTimeService 包
     */
    public static function checkFinanceTimeLock($time) {
        FinanceTimeService::checkLock($time);
    }

    /*
     * 20220619 只保存到内存中
     */
    public static function saveRam($data) {
        self::queryCountCheck(__METHOD__);
        global $glSaveData;
        self::preSaveData($data);
        if (method_exists(__CLASS__, 'ramPreSave')) {
            self::ramPreSave($data, $data['id']);      //注：id在preSaveData方法中生成
        }

        self::getInstance($data['id'])->setUuData($data, true);
        //20220619 新增核心
        $tableName = self::mainModel()->getTable();
        //20220624:获取器；

        $columns = DbOperate::columns($tableName);
        foreach($columns as $column){
            $fieldName = $column['Field'];
            $setAttrKey = 'set'.ucfirst(Strings::camelize($fieldName)).'Attr';            
            if(isset($data[$fieldName]) && method_exists(self::mainModel(), $setAttrKey)){
                $data[$fieldName] = self::mainModel()->$setAttrKey($data[$fieldName]);
                //dump('获取器'.$setAttrKey);
            }
        }

        $glSaveData[$tableName][$data['id']] = $data;
        // 20230519
        self::pushObjAttrs($data);
        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'ramAfterSave')) {
            self::ramAfterSave($data, $data['id']);
        }
        // 20230519静态的，清一下缓存
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }
        return $data;
    }
    /**
     * 20230519:写入关联类库内存
     * @param type $data
     */
    protected static function pushObjAttrs($data){
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property) use ($data){
            $baseClass::getInstance($keyId)->objAttrsPush($property,$data);            
        });
    }
    /**
     * 20220619
     * @global array $glUpdateData
     * @param array $data
     * @return type
     * @throws Exception
     */
    public function updateRam(array $data) {
        self::queryCountCheck(__METHOD__);        
        $tableName = self::mainModel()->getTable();
        $info = $this->get(0);
        if (!$info) {
//            return false;
            throw new Exception('记录不存在' . $tableName . '表' . $this->uuid);
        }
        if (isset($info['is_lock']) && $info['is_lock']) {
            throw new Exception('记录已锁定不可修改' . $tableName . '表' . $this->uuid);
        }
        //20220624:剔除id，防止误更新。
        if(isset($data['id'])){
            unset($data['id']);
        }
//        if (!isset($data['id']) || !$data['id']) {
//            $data['id'] = $this->uuid;
//        }

        $data['updater'] = session(SESSION_USER_ID);
        $data['update_time'] = date('Y-m-d H:i:s');
        //额外添加详情信息：固定为extraDetail方法；更新前执行
        if (method_exists(__CLASS__, 'ramPreUpdate')) {
            self::ramPreUpdate($data, $this->uuid);
        }
        //20220620:封装
        $dataSave = $this->doUpdateRam($data);
        // 20230519:更新
        self::updateObjAttrs($this->get(), $this->uuid);

        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'ramAfterUpdate')) {
            self::ramAfterUpdate($data, $this->uuid);
        }
        // 20230519静态的，清一下缓存
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }
        return $dataSave;
    }
    /**
     * 20230519:更新关联
     * @param type $data    更新数组内容
     * @param type $uuid    id单传
     */
    protected static function updateObjAttrs($data, $uuid){
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property) use ($data,$uuid){
            $baseClass::getInstance($keyId)->objAttrsUpdate($property,$uuid,$data);            
        });
    }
    /**
     * 处理关联属性的闭包函数
     * @param type $data
     * @param type $func
     */
    private static function dealObjAttrsFunc($data,$func){
        $class = '\\'.__CLASS__;
        $con[] = ['class', '=', $class];
        $lists = DbOperate::objAttrConfArr($con);
        foreach($lists as $v){
            // project_id
            $keyField   = Arrays::value($v,'keyField');
            // DevProjectService
            $baseClass  = Arrays::value($v,'baseClass');
            // devProjectExt
            $property   = Arrays::value($v,'property');
            $keyId      = $keyField ? Arrays::value($data,$keyField) : '';
            if($keyId && $baseClass && $property){
                // 20230519:调用闭包函数
                $func($baseClass, $keyId, $property);
//                $baseClass::getInstance($keyId)->objAttrsUpdate($property,$uuid,$data);            
            }
        }
    }
    /**
     * 不关联执行前后触发的更新
     */
    public function doUpdateRam($data){
        global $glUpdateData;
        $tableName = self::mainModel()->getTable();
        // 设定内存中的值
        $this->setUuData($data);
        Debug::debug($tableName.'的doUpdateRam',$data);
        $columns = DbOperate::columns($tableName);
        foreach($columns as $column){
            $fieldName = $column['Field'];
            $setAttrKey = 'set'.ucfirst(Strings::camelize($fieldName)).'Attr';
            //Debug::debug($tableName.'的$setAttrKey之'.$fieldName,$setAttrKey);
            if(isset($data[$fieldName]) && method_exists(self::mainModel(), $setAttrKey)){
                $data[$fieldName] = self::mainModel()->$setAttrKey($data[$fieldName]);
                //dump('获取器'.$setAttrKey);
            }
        }
        
        $realFieldArr   = DbOperate::realFieldsArr($tableName);
        $dataSave       = Arrays::getByKeys($data, $realFieldArr);
        Debug::debug($tableName.'的doUpdateRam的$dataSave',$dataSave);
        //20220620:对多次的数据进行合并
        if(!isset($glUpdateData[$tableName])){
            $glUpdateData[$tableName] = [];
        }
        $glUpdateData[$tableName][$this->uuid] = isset($glUpdateData[$tableName][$this->uuid]) 
                ? array_merge($glUpdateData[$tableName][$this->uuid], $dataSave) 
                : $dataSave;
        // 设定内存中的值
        // return $dataSave;
        // $dataSave经获取器处理，对图片兼容不好
        return $data; 
    }
    
    /**
     * 20220619
     * @global array $glDeleteData
     * @return type
     */
    public function deleteRam() {
        self::queryCountCheck(__METHOD__);           
        $rawData = $this->get();
        //删除前
        if (method_exists(__CLASS__, 'ramPreDelete')) {
            $this->ramPreDelete();      //注：id在preSaveData方法中生成
        }
        $this->doDeleteRam();
        // 20230519:更新
        self::delObjAttrs($rawData, $this->uuid);
        //删除后
        if (method_exists(__CLASS__, 'ramAfterDelete')) {
            $this->ramAfterDelete($rawData);
        }
        // 20230519静态的，清一下缓存
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }
        return $this->uuid;
    }
    /**
     * 
     * @param type $data    原始数据，get 提取
     * @param type $uuid
     */
    protected static function delObjAttrs($data, $uuid){
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property) use ($uuid){
            $baseClass::getInstance($keyId)->objAttrsUnSet($property,$uuid);            
        });
    }
    /**
     * 20220703;?仅执行删除动作
     * @global type $glSaveData
     * @global array $glDeleteData
     * @return boolean
     */
    public function doDeleteRam(){
        global $glSaveData,$glDeleteData;
        $tableName = self::mainModel()->getTable();
        //20220625:还未写入数据库的，直接在内存中删了就行
        if(isset($glSaveData[$tableName]) && isset($glSaveData[$tableName][$this->uuid])){
            unset($glSaveData[$tableName][$this->uuid]);
        } else {
            $glDeleteData[$tableName][] = $this->uuid;
        }
        return true;
    }
    /**
     * 20220620：死循环调试专用
     * @throws Exception
     */
    protected static function queryCountCheck($method, $limitTimes = 2000){
        // self::$queryCount               = self::$queryCount + 1;
        self::$queryCountArr[$method]   = Arrays::value(self::$queryCountArr, $method, 0) + 1;
        // 20220312;因为检票，从20调到200；TODO检票的更优方案呢？？
        if(self::$queryCountArr[$method] > $limitTimes){
            throw new Exception(__CLASS__.'中'.$method.'$queryCount 次数超限'.$limitTimes);
        }
    }
    /**
     * 20220620：是否有前序数据（单条）
     * 前序订单，前序账单
     * @param type $fieldName
     */
    public function getPreData($fieldName){
        $info = $this->get();
        $preId = Arrays::value($info, $fieldName);
        if(!$preId){
            return false;
        }
        return self::getInstance($preId)->get();
    }
    /**
     * 20220620 获取后续数据清单
     * 后序订单，后序账单……
     * 20220622 未入库的取不到……
     */
    public function getAfterDataArr($fieldName){
        global $glSaveData;
        $tableName = self::mainModel()->getTable();

        $con[]  = [$fieldName,'=',$this->uuid];
        //提取未入库数据
        $noSaveArrs = array_values(Arrays::value($glSaveData, $tableName, []));
        $idsNoSave = array_column(Arrays2d::listFilter($noSaveArrs, $con),'id');
        //提取已入库数据
        if (self::mainModel()->hasField('is_delete')) {
            $con[]  = ['is_delete','=',0];
        }
        // 2022-11-20: 增加cache(1)缓存
        $idsSaved    = self::mainModel()->where($con)->cache(1)->column('id');
        //合并未入库和已入库数据
        $ids    = array_merge($idsNoSave,$idsSaved);
        $info   = $this->get();
        if(Arrays::value($info, 'afterIds',[])){
            $ids = array_merge($ids, $info['afterIds']);
        }
        $dataArr = [];
        foreach($ids as $id){
            $dataArr[$id] = self::getInstance($id)->get();
        }
        return $dataArr;
    }
    /**
     * 20220624:方法停用：用于过渡
     */
    public static function stopUse($method){
        throw new Exception($method.'方法停用');
    }    
    /**
     * 校验是否有来源数据。
     * @param type $sourceId
     * @return boolean
     */
    public static function hasSource($sourceId){
        if (!$sourceId || !self::mainModel()->hasField('source_id')) {
            return false;
        }
        $con[] = ['source_id','=',$sourceId];
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        }
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete','=',0];
        }
        return self::mainModel()->where($con)->count();
    }
    /**
     * 20220711:用于跨系统迁移数据
     * @param type $sourceId
     * @return boolean
     */
    public static function sourceIdToId($sourceId){
        if(!$sourceId && $sourceId !== 0){
            return false;
        }
        $con[] = ['source_id','=',$sourceId];
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con)->value('id');
    }
    /**
     * 20221116,从逗号分隔中查询数据
     */
    public static function sourceIdToIdSet($sourceId){
        if(!$sourceId && $sourceId !== 0){
            return false;
        }
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con)->whereRaw("FIND_IN_SET('".$sourceId."', source_id)")->value('id');
    }
    /**
     * 2023-02-25:复制数据
     */
    public function copy(){
        self::checkTransaction();
        $realFieldsArr = DbOperate::realFieldsArr( self::getTable() );
        Debug::debug('$realFieldsArr',$realFieldsArr);
        
        $con[] = ['id','=',$this->uuid];
        $dataInfo   = self::where( $con ) ->field(implode(',',$realFieldsArr))->find( );
        if(!$dataInfo){
            throw new Exception('数据不存在'.$this->uuid);
        }
        $res        = $dataInfo->toArray();
        //20230225:唯一字段复制加copy
        $columnId       = SystemColumnService::tableNameGetId(self::getTable());
        $uniqueFields   = SystemColumnListService::uniqueFields($columnId);
        foreach($uniqueFields as $v){
            if( isset($res[$v])){ 
                $res[$v] = $res[$v] . 'Copy';
            }
        }

        if( isset($res['id'])){ unset($res['id']);}
        if( isset($res['create_time'])){ unset($res['create_time']);}
        if( isset($res['update_time'])){ unset($res['update_time']);}

        Debug::debug('$res',$res);
        //保存
        $resp   = self::save( $res );
        return $resp;
    }
    
    /**
     * 20230416：关联数据
     * @param type $thingId
     */
    public static function uniDel($key,$keyIds){
        self::checkTransaction();
        $con[] = [$key,'in', $keyIds];
        return self::where($con)->delete();
    }
    /**
     * 20230425：清除字段
     * 应用场景：
     * 1、删除包车订单后，清关联表的订单编号字段
     * 2、删审批单后，清原始表审批单号字段
     * @param type $fieldName
     * @param type $fieldValue
     */
    public static function clearField($fieldName,$fieldValue){
        $con[] = [$fieldName,'=',$fieldValue];
        return self::where($con)->update([$fieldName=>'']);
    }
    /**
     * 20230519:模板消息发送
     * @param type $id          id
     * @param type $methodName  方法名
     * @return type
     */
    public static function doTemplateMsgSend($id, $methodName){
        $tableName = self::getTable();
        $res = SendTemplateMsg::doMethodSend($tableName, $methodName, $id);
        return $res;
    }
    
    
    
    
    /**********【20230531】注入触发器 ***********************************/
    /**
     * 20230518：提取配置数组
     * 
    protected static $trigger = [
        'afterOrderPay'=>[
            'dealMethod'    =>'customer_id',
            'dealClass'     =>'xjryanse\dev\service\DevProjectExtService'
        ]
    ];
     * 
     */
    public static function confArrTrigger(){
        $lists  = property_exists(__CLASS__, 'trigger') ? self::$trigger : [];
        $resArr = [];
        foreach($lists as $k=>$v){
            $tmp                = $v;
            $tmp['class']       = __CLASS__;
            $tmp['property']    = $k;

            $resArr[]           = $tmp;
        }
        return $resArr;
    }
    /**
     * 20230531:执行触发器
     * @param type $triggerKey      钩子key
     * @param type $con             钩子条件
     * @param type $data            入参数据
     */
    public static function doTrigger($triggerKey, $con = [], $data = []){
        $triggers = DbOperate::triggerArr();
        $con[] = ['property','=',$triggerKey];
        $lists = Arrays2d::listFilter($triggers, $con);
        foreach($lists as $v){
            $dealClass  = Arrays::value($v, 'dealClass');
            $dealMethod = Arrays::value($v, 'dealMethod');
            if(class_exists($dealClass) && method_exists($dealClass, $dealMethod)){
                call_user_func([ $dealClass , $dealMethod], $data );
            }
        }
    }
    
    
}
