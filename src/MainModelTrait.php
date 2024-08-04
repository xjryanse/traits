<?php

namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Strings;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\system\service\SystemServiceMethodLogService;
use xjryanse\system\service\SystemColumnService;
use xjryanse\system\service\SystemColumnListService;
use xjryanse\system\service\SystemAsyncTriggerService;
use app\system\AsyncOperate\SendTemplateMsg;
use think\Db;
use Exception;

/**
 * 主模型复用
 */
trait MainModelTrait {

    protected static $mainModel;
    
    // 魔术方法次数
    protected static $mgCallCount = 0;
    // 20230819:更新时，存储差异数组
    protected static $updateDiffs = [];
    
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
    /**
     * 20230711改写
     * @param type $methodName
     * @param type $arguments
     * @return type
     */
    public function __call($methodName, $arguments) {
        global $glMgCallCount;$glMgCallCountArr;
        $glMgCallCount ++;
        //首字母f，且第二个字母大写，表示字段
        if (method_exists(__CLASS__, $methodName)) {
            Debug::debug('__call', $methodName);
            $trace = debug_backtrace();
            //调用者方法
            $caller = $trace[1];
            // 20230731:记录调用者信息
            $logData['caller_class']    = Arrays::value($caller, 'class');
            $logData['caller_method']   = Arrays::value($caller, 'function');
            $logData['sort']            = $glMgCallCount;
            $glMgCallCountArr[]         = $glMgCallCount;
            // 此处增加统计次数逻辑；
            // 调用指定的函数
            $res = $this->$methodName(...$arguments);
            // 20230711:记录请求日志
            SystemServiceMethodLogService::log(__CLASS__, $methodName, $arguments, $res, $logData);
            return $res;
        } else {
            throw new Exception($methodName . '不存在');
        }
    }
    /**
     * 20230710:统计静态方法调用次数
     *     
     * @param type $methodName
     * @param type $arguments
     */
    public static function __callStatic($methodName, $arguments) {
        global $glMgCallCount;
        $glMgCallCount ++;
        
        if (method_exists(__CLASS__, $methodName)) {            
            $trace = debug_backtrace();
            //调用者方法
            $caller = $trace[1];
            // 20230731:记录调用者信息
            $logData['caller_class']    = Arrays::value($caller, 'class');
            $logData['caller_method']   = Arrays::value($caller, 'function');
            $logData['sort']          = $glMgCallCount;
            Debug::debug('__callStatic执行', $methodName);
            // 此处增加统计次数逻辑；
            // 调用指定的函数
            $res = self::$methodName(...$arguments);
            // 20230711:记录请求日志
            SystemServiceMethodLogService::log(__CLASS__, $methodName, $arguments, $res, $logData);
            return $res;
        } else {
            throw new Exception(__CLASS__.'的'.$methodName . '不存在');
        }
    }

    /**
     * 条件给字段添加索引
     */
    protected static function condAddColumnIndex($con = []) {
        return true;
        // 无条件或非开发环境，不加索引
        if (!$con || !Debug::isDevIp()) {
            return false;
        }
        // 加索引动作
        foreach ($con as $conArr) {
            // 20240505:只有等号才加索引:一些比较短的还是不加吧
            if (is_array($conArr) && $conArr[1] == '=' && mb_strlen($conArr[2]) > 10) {
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
        /**
         * 2022-12-15:增加静态配置清缓存
         */
        self::dataCacheClear();
        
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
        self::dataCacheClear();        

        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'extraAfterUpdate')) {
            if (session(SESSION_DIRECT_AFTER) || (property_exists(__CLASS__, 'directAfter') && self::$directAfter)) {
                // 20220609:尝试替换：影响较大，请跟踪
                self::extraAfterUpdate($data, $data['id']);
            } else {
                //20210821改异步
                $fromTable = self::mainModel()->getTable();
                $addTask = SystemAsyncTriggerService::addTask('update', $fromTable, $data['id']);
                Debug::debug('extraAfterUpdate', $addTask);
            }
        }

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

        $res = self::mainModel()->where('id', $this->uuid)->delete();
        self::dataCacheClear();

        return $res;
    }

    /*     * ************************操作方法******************************* */

    public static function save($data) {
        return self::commSave($data);
    }

    /**
     * 数据带获取器进行转换
     * 
     */
    public static function dataDealAttr($data){
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
        if($data){
            foreach($data as &$dVal){
                foreach($dVal as $kk=>&$vv){
                    $attrClass = 'get'.ucfirst(Strings::camelize($kk)).'Attr';
                    if(method_exists(self::mainModel(),$attrClass)){
                        $vv = self::mainModel()->$attrClass($vv,$dVal);
                    }
                }
            }
        }
        return $data;
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
        // 20231204:导入时是否覆盖
        $isCover = property_exists(__CLASS__, 'isSaveAllCover')? self::$isSaveAllCover : false;
        
        $res = self::saveAllX($tmpArr, $isCover);
        // 2022-12-16
        self::dataCacheClear();
        
        // 20230403:批量保存后处理逻辑
        if (method_exists(__CLASS__, 'extraAfterSaveAll')) {
            self::extraAfterSaveAll($tmpArr);      //注：id在preSaveData方法中生成
        }
        
        return $res;
    }
    /**
     * 20220621优化性能
     * @param array $data
     * @param type $preData
     * @return type
     */
    public static function saveAllRam(array &$data, $preData = []) {
        // 20230923:批量保存前处理
        if (method_exists(__CLASS__, 'ramPreSaveAll')) {
            //注：id在preSaveData方法中生成
            self::ramPreSaveAll($data);      
        }

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
    public static function saveAllX($dataArr, $isCover = false) {
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
            $sql = DbOperate::saveAllSql($tableName, array_values($arr),[],$isCover);
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
        // $tableName = self::getTable();
        $tableName = self::getRawTable();
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

    /*
     * 20220619 只保存到内存中
     */
    public static function saveRam($data) {
        self::queryCountCheck(__METHOD__);
        global $glSaveData;
        // 20240319:在此写入一些参数，is_delete等，供筛选条件查询
        self::preSaveData($data);
        // 20230730：增，在ramPreSave中， updateAuditStatusRam 有循环调用(报销)；
        self::getInstance($data['id'])->setUuData($data, true);
        if (method_exists(__CLASS__, 'ramPreSave')) {
            self::ramPreSave($data, $data['id']);      //注：id在preSaveData方法中生成
        }
        self::doSaveRam($data);

        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'ramAfterSave')) {
            self::ramAfterSave($data, $data['id']);
        }
        //20230729
        self::dataCacheClear();

        return $data;
    }
    
    public static function doSaveRam($data){
        // 20240422 
        if (!isset($data['id']) || !$data['id']) {
            $data['id'] = self::mainModel()->newId();
        }
        
        global $glSaveData;        
        // 原，再次写入更新
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
        // 20240503
        if (method_exists(__CLASS__, 'savePreCheck')) {
            self::savePreCheck($data);      //注：id在preSaveData方法中生成
        }
        $glSaveData[$tableName][$data['id']] = $data;
        // Debug::dump('这里');
        // 20230519
        // 20240306 发现体检模块会卡??
        self::pushObjAttrs($data);
        return $data;
    }
    /**
     * 20230519:写入关联类库内存
     * @param type $data
     */
    protected static function pushObjAttrs($data){
        // dump('-----');
        // dump(self::mainModel()->getTable());
        // dump($data);
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property, $conf) use ($data){
            $condition = Arrays::value($conf, 'condition' , []);
            // dump($baseClass);
            // dump('-----------');
            // dump($baseClass::getInstance($keyId)->objAttrsHasData($property));
            // 20230730
            // 20240313 改$baseClass::getInstance($keyId)->objAttrsHasData($property) 为 objAttrsHasQuery
            if (Arrays::isMatch($data, $condition) && method_exists($baseClass, 'objAttrsPush')
                // 20240306：发现体检板块卡顿
                && $baseClass::getInstance($keyId)->objAttrsHasQuery($property)){
                $baseClass::getInstance($keyId)->objAttrsPush($property,$data);
            }
        });
    }
    
    /**
     * 20230819：获取字段更新的差异部分
     * @param type $newData
     * @return type
     */
    protected function calUpdateDiffs($newData){
        $info = $this->get();
        // 20230815:获取有变化的内容
        // 20230815:校验增加字段判断
        self::$updateDiffs =  Arrays::diffArr($info, $newData);
        return self::$updateDiffs;
    }
    /**
     * 验证更新的这些字段是否包含指定字段数组中的一个
     */
    protected function updateDiffsHasField(array $checkFields){
        if(!self::$updateDiffs){
            return false;
        }
        $diffKeys = array_keys(self::$updateDiffs);
        return array_intersect($diffKeys,$checkFields);
    }

    /**
     * 20220619
     * @global array $glUpdateData
     * @param array $data
     * @return type
     * @throws Exception
     */
    public function updateRam(array $data) {
        // 20230819：计算更新数组:一般仅用于ramPreUpdate和ramAfterUpdate方法调用
        $this->calUpdateDiffs($data);

        self::queryCountCheck(__METHOD__);        
        $tableName = self::mainModel()->getTable();
        $info = $this->get(0);
        if (!$info) {
            throw new Exception('记录不存在' . $tableName . '表' . $this->uuid);
        }
        if (isset($info['is_lock']) && $info['is_lock']) {
            throw new Exception('记录已锁定不可修改' . $tableName . '表' . $this->uuid);
        }
        //20220624:剔除id，防止误更新。
        if(isset($data['id'])){
            unset($data['id']);
        }

        $data['updater'] = session(SESSION_USER_ID);
        $data['update_time'] = date('Y-m-d H:i:s');
        //额外添加详情信息：固定为extraDetail方法；更新前执行
        if (method_exists(__CLASS__, 'ramPreUpdate')) {
            self::ramPreUpdate($data, $this->uuid);
        }
        //20220620:封装
        $dataSave = $this->doUpdateRam($data);
        // 20231107
        $infoArr = is_object($info) ? $info->toArray() : $info;
        $objAttrData = array_merge($infoArr,$data);
        // 20230519:更新
        self::updateObjAttrs($objAttrData, $this->uuid);

        //更新完后执行：类似触发器
        if (method_exists(__CLASS__, 'ramAfterUpdate')) {
            self::ramAfterUpdate($data, $this->uuid);
        }
        //20230729
        self::dataCacheClear();

        return $dataSave;
    }
    /**
     * 20230519:更新关联
     * @param type $data    更新数组内容
     * @param type $uuid    id单传
     */
    protected static function updateObjAttrs($data, $uuid){
        // dump($data);
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property, $conf) use ($data,$uuid){
            $condition = Arrays::value($conf, 'condition' , []);
            if (Arrays::isMatch($data, $condition) && method_exists($baseClass, 'objAttrsUpdate')
                    && $baseClass::getInstance($keyId)->objAttrsHasData($property)){
                $baseClass::getInstance($keyId)->objAttrsUpdate($property,$uuid,$data);            
            }
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
        $lists1 = DbOperate::objAttrConfArr($con);
        //20230608:TODO临时过渡
        foreach($lists1 as &$v){
            $v['inList']    = true;
            $v['inStatics'] = true;
            $v['inExist']   = true;
        }
        // 20230730:逐步淘汰上方objAttrConfArr
        $lists2 = DbOperate::uniAttrConfArr($con);
        $lists = array_merge($lists1, $lists2);

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
                $func($baseClass, $keyId, $property, $v);
//                $baseClass::getInstance($keyId)->objAttrsUpdate($property,$uuid,$data);            
            }
        }
    }
    /**
     * 20230807:更新并清理缓存
     */
    protected function doUpdateRamClearCache($data){
        $res = $this->doUpdateRam($data);
        //20230729
        self::dataCacheClear();
        return $res;
    }
    
    /**
     * 不关联执行前后触发的更新
     */
    public function doUpdateRam($data){
        // 20240503
        // 20240507:财务反馈无法销账注释
        // self::queryCountCheck(__METHOD__, 2000);
        global $glUpdateData,$glSaveData;
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
        // 20230730:如果还没写入数据库，则合并
        if(isset($glSaveData[$tableName]) && Arrays::value($glSaveData[$tableName], $this->uuid)){
            $glSaveData[$tableName][$this->uuid] =  array_merge($glSaveData[$tableName][$this->uuid], $dataSave);
        } else {
            // 原来的逻辑
            $glUpdateData[$tableName][$this->uuid] = isset($glUpdateData[$tableName][$this->uuid]) 
                    ? array_merge($glUpdateData[$tableName][$this->uuid], $dataSave) 
                    : $dataSave;
        }

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
        // 20230912:谨慎测试
        // $tableName = self::getTable();
        $tableName = self::getRawTable();
        DbOperate::checkCanDelete($tableName, $this->uuid);

        $this->doDeleteRam();
        // 20230519:更新
        self::delObjAttrs($rawData, $this->uuid);
        //删除后
        if (method_exists(__CLASS__, 'ramAfterDelete')) {
            $this->ramAfterDelete($rawData);
        }
        //20230729
        self::dataCacheClear();
        
        return $this->uuid;
    }
    /**
     * 
     * @param type $data    原始数据，get 提取
     * @param type $uuid
     */
    protected static function delObjAttrs($data, $uuid){
        self::dealObjAttrsFunc($data, function($baseClass, $keyId, $property, $conf) use ($data, $uuid){
            $condition = Arrays::value($conf, 'condition' , []);
            if (Arrays::isMatch($data, $condition) && method_exists($baseClass, 'objAttrsUnSet')
                // 20240306：发现体检板块卡顿
                && $baseClass::getInstance($keyId)->objAttrsHasData($property)){
                $baseClass::getInstance($keyId)->objAttrsUnSet($property,$uuid);
            }
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
     * 20220624:方法停用：用于过渡
     */
    public static function stopUse($method){
        throw new Exception($method.'方法停用');
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
    public static function doTemplateMsgSend($id, $methodName, $param = []){
        $tableName = self::getTable();
        $res = SendTemplateMsg::doMethodSend($tableName, $methodName, $id, $param);
        return $res;
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
