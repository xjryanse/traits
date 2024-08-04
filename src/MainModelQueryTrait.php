<?php

namespace xjryanse\traits;

use xjryanse\user\logic\AuthLogic;
use xjryanse\logic\Arrays;
// use xjryanse\logic\Strings;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\Datetime;
use xjryanse\logic\DataCheck;
use xjryanse\logic\Cachex;
use xjryanse\system\service\SystemColumnService;
use xjryanse\system\service\SystemColumnListService;
// use xjryanse\system\service\SystemTableCacheTimeService;
use xjryanse\system\service\SystemColumnListForeignService;
use xjryanse\universal\service\UniversalItemTableService;
use xjryanse\universal\service\UniversalPageItemService;
use xjryanse\generate\service\GenerateTemplateLogService;
use xjryanse\generate\service\GenerateTemplateService;
use think\facade\Request;
use think\Db;
use think\facade\Cache;
use Exception;

/**
 * 主模型复用(只放查询方法)
 * 20230805
 */
trait MainModelQueryTrait {

    //是否直接执行后续触发动作
    //protected static $directAfter;
    //20220617:考虑get没取到值的情况，可以不用重复查询
    protected $hasUuDataQuery = false;
    protected $uuData = [];

    /**
     * 20220921 主模型带where参数
     */
    public static function where($con = []) {
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }

        // 20231020：增加分表逻辑（体检板块）
        if (property_exists(self::mainModel(), 'isSeprate') && self::mainModel()::$isSeprate) {
            self::mainModel()->setConTable($con);
        }

        return self::mainModel()->where($con);
    }

    /**
     * 表名
     */
    public static function getTable() {
        return self::mainModel()->getTable();
    }

    /**
     * 20231019:源表（是分表的转为源表）
     * @return type
     */
    public static function getRawTable() {
        if (method_exists(self::mainModelClass(), 'getRawTable')) {
            $table = self::mainModel()->getRawTable();
        } else {
            $table = self::mainModel()->getTable();
        }

        $arr = explode('_', $table);
        if (Datetime::isYear(end($arr))) {
            array_pop($arr);
        }
        return implode('_', $arr);
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
     * @useFul 1
     */
    protected static function preSaveData(&$data) {
        return self::commPreSaveData($data);
    }

    protected static function commPreSaveData(&$data) {
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
        if (!isset($data['create_time']) || !$data['create_time']) {
            $data['create_time'] = date('Y-m-d H:i:s');
        }
        $data['update_time'] = date('Y-m-d H:i:s');
        $data['status'] = 1;
        $data['is_delete'] = 0;

        return $data;
    }

    /*
     * 数据库查询
     * @useFul 1
     * @describe 解决oss图片动态路径封装
     * @createTime 2023-06-21 13:52:00
     */
    public static function selectDb($con = [], $order = "", $field = "", $hidden = []) {
        $tableName = self::mainModel()->getTable();
        $inst = Db::table($tableName);
        // 20240312
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
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

        return $data;
    }

    /**
     * 20220305
     * 替代TP框架的select方法，在查询带图片数据上效率更高
     * @param type $inst    组装好的db查询类
     */
    public static function selectX($con = [], $order = "", $field = "", $hidden = []) {
        // 20230621：使用Db方法从数据库中查询
        $data = self::selectDb($con, $order, $field, $hidden);
        // 20230621：模型获取器处理数据
        return self::dataDealAttr($data);
    }

    /**
     * 20230429：增强的筛选，自动判断是否有静态。
     * @useFul 1
     */
    public static function selectXS($con = [], $order = "", $field = "", $hidden = []) {
        if (method_exists(__CLASS__, 'staticConList')) {
            $lists = self::staticConList($con);
        } else {
            $lists = self::selectX($con, $order, $field, $hidden);
        }
        return $lists;
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
            return Cachex::funcGet($cacheKey, function () use ($fieldName, $default) {
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

    public static function lists($con = [], $order = '', $field = "*", $cache = 1) {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        // $cache = $cache < 0 ? self::defaultCacheTime() : $cache;
        // 20240311
        if (method_exists(__CLASS__, 'preListDeal')) {
            self::preListDeal($con);
        }

        return self::commLists($con, $order, $field, $cache)->each(function ($item, $key) {
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
     * @useFul 1
     * @param type $con
     */
    public static function listSetUudata($con = [], $master = false) {
        global $glSaveData, $glUpdateData, $glDeleteData;
//  ["w_order_bao_bus_driver"] =&gt; array(1) {
//    [5572710300365492224] =&gt; array(2) {
//      ["distribute_prize"] =&gt; string(2) "88"
//      ["update_time"] =&gt; string(19) "2024-03-05 16:26:09"
//    }
//  }
//  {
//  ["w_bus_fix_item"] =&gt; array(1) {
//    [0] =&gt; string(19) "5583986115896299520"
//  }
//}
        // 20240224：增加分表逻辑（体检板块）
        if (property_exists(self::mainModel(), 'isSeprate') && self::mainModel()::$isSeprate) {
            self::mainModel()->setConTable($con);
        }

        if ($master) {
            // 20230728:查询前校验（方便开发查错）;20231112:发现加油判断是否存在前次加油记录，关联当前表异常
            // DbOperate::checkConFields(self::getTable(), $con);
            $lists = self::mainModel()->master()->where($con)->select();
        } else {
            // $lists = self::mainModel()->where($con)->select();
            // 20230501：优化性能
            $lists = self::selectXS($con);
        }

        // 20240305:更新在内存中未提交的数据
        // 包车发现更新驾驶员金额，财务端不同步
        //写入内存
        $tableName = self::mainModel()->getTable();

        // 20240319
        if ($glSaveData) {
            $glSavesRaw = Arrays::value($glSaveData, $tableName, []);
            // 将id key整合为序号
            $glSaves = array_values($glSavesRaw);
            $listsN = Arrays2d::listFilter($glSaves, $con);
            foreach ($listsN as $vn) {
                $lists[] = $vn;
            }
        }

        foreach ($lists as $k => $v) {
            // 20240305:更新在内存中未提交的数据
            $glUpdates = Arrays::value($glUpdateData, $tableName, []);
            $thisVal = Arrays::value($glUpdates, $v['id'], []);
            if ($thisVal) {
                foreach ($thisVal as $key => $value) {
                    $v[$key] = $value;
                }
            }
            //20230807：先处理歪写（更新时）
            if (!self::getInstance($v['id'])->uuData) {
                self::getInstance($v['id'])->setUuData($v, true);  //强制写入
            }
            // 20240325
            $glDelIds = Arrays::value($glDeleteData, $tableName, []);
            if (in_array(Arrays::value($v, 'id'), $glDelIds)) {
                unset($lists[$k]);
            }
        }

        return $lists;
    }

    /**
     * 20220919动态数组列表
     */
    public static function dynDataList($dataArr) {
        $columnId = SystemColumnService::tableNameGetId(self::getTable());
        $dynFields = SystemColumnListService::columnTypeFields($columnId, 'dynenum');
        $dynDatas = [];
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
        // 20240505:自动添加索引，让系统越跑越快
        self::condAddColumnIndex($con);

        $res = self::paginateX($con, $order, $perPage, $having, $field, $withSum);
        // 关联表id，提取相应的字段
        $uTableId = Request::param('uTableId');
        if ($uTableId && UniversalPageItemService::getInstance($uTableId)->fFieldFilter()) {
            $fieldArr = UniversalItemTableService::pageItemFieldsForDataFilter($uTableId);
            if ($fieldArr) {
                $res['data'] = Arrays2d::getByKeys($res['data'], $fieldArr);
            }
        }

        return $res;

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
        // 20230609:增加关联存在字段的查询
        $baseTable = 'test';
        if (method_exists(self::mainModelClass(), 'uniSetTable')) {
            $baseTable = self::mainModel()->uniSetTable($conAll);
        }
        // 一定要放在setCustTable前面
        $columnId = SystemColumnService::tableNameGetId(self::getRawTable());
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
            // dump(self::mainModel()->getTable());exit;
            $res = self::mainModel()->where($conAll)->order($order)->field($field)->limit($start, intval($perPage))->select();
            // 20231020:便利调试
            if (Debug::isDevIp()) {
                $resp['$sql'] = self::mainModel()->getLastSql();
            }

            //20220619：如果查询结果数小于分页条数，则结果数即总数
            $total = $page == 1 && count($res) < $perPage ? count($res) : self::countCache($conAll);
            // : self::mainModel()->where($conAll)->count(1);
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
        if (Debug::isDevIp()) {
            $resp['$con'] = $conAll;
            $resp['$baseSql'] = $baseTable;
            // $resp['$baseSql'] = self::mainModel()->getTable();
        }
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
    public static function fieldGetIds($fieldName, $value) {
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
        if (!$this->uuid) {
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

    /*     * *** 20230728清除数据表全量缓存 ****** */

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
                $this->uuData = Cachex::funcGet($cacheKey, function () use ($master) {
                            return $this->commGet($master);
                        });
            } else {
                //没有缓存的
                $this->uuData = $this->commGet($master);
            }
            //20220617:增加已查询判断，查空可以不用重复查
            $this->hasUuDataQuery = true;
        }

        // 20230727 ??? 
        if (is_object($this->uuData)) {
            $this->uuData = $this->uuData->toArray();
        }

        return $this->uuData;
    }

    /**
     * 20230516:仅从缓存中提取get
     */
    protected function getFromCache() {
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
            $infoArr = is_object($info) ? $info->toArray() : $info;
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
     * 修改数据时，同步调整实例内的数据
     * @useFul 1
     * @param type $newData
     * @param type $force       数据不存在时，是否强制写入（用于从其他渠道获取的数据，直接赋值，不走get方法）
     * @return type
     */
    public function setUuData($newData, $force = false) {
//        $trace = debug_backtrace();
//        //调用者方法
//        $caller = $trace[1];
//        Debug::dump($caller);
//        Debug::dump('数据更新');
//        Debug::dump($newData);
//        Debug::dump('数据更新前');
//        Debug::dump($this->uuData);
        //强制写入模式，直接赋值
        Debug::debug(self::mainModel()->getTable() . '的setUuData', $newData);
        if ($force) {
            $this->uuData = $newData;
        } else if ($this->uuData) {
            foreach ($newData as $key => $value) {
                $this->uuData[$key] = $value;
            }
        }
//        Debug::dump('数据更新后');
//        Debug::dump($this->uuData);
        return $this->uuData;
    }

    /**
     * 【弃】逐步废弃：20220606
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
    public static function clearCommExtraDetailsCache($ids) {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        foreach ($ids as $id) {
            $cacheKey = self::commExtraDetailsCacheKey($id);
            Cache::rm($cacheKey);
        }
    }

    /**
     * 2023-01-08：获取数据缓存key
     */
    protected static function commExtraDetailsCacheKey($id) {
        $tableName = self::mainModel()->getTable();
        $baseCacheKey = $tableName . 'commExtraDetails';
        return $baseCacheKey . $id;
    }

    /**
     * 2023-01-08:带缓存查询详情数据
     */
    protected static function commExtraDetailsWithCache($ids, $func = null, $expire = 0) {
        //数组返回多个，非数组返回一个
        $isMulti = is_array($ids);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        // ====
        $needDbQuery = false;
        $cacheRes = [];
        // 先从缓存数据中提取；
        foreach ($ids as $id) {
            $cacheKey = self::commExtraDetailsCacheKey($id);
            $cacheInfo = Cache::get($cacheKey);
            if (!$cacheInfo) {
                $needDbQuery = true;
            }
            $cacheRes[] = $cacheInfo;
        }
        // 进行数据库查询
        if ($needDbQuery) {
            $lists = self::commExtraDetails($ids, $func);
            foreach ($lists as $v) {
                $cacheKey = self::commExtraDetailsCacheKey($v['id']);
                Cache::set($cacheKey, $v, $expire);
            }

            $cacheRes = $lists;
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
        // 20230727??
        $isMulti = is_array($ids);
        if (is_string($ids)) {
            $res = self::getInstance($ids)->get();
            $ids = [$ids];
            //20230728
            $listsRaw = [$res];
            // return is_object($res) ? $res->toArray() : $res;
        } else {
            //数组返回多个，非数组返回一个
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            //20220619:优化性能
            if (!$ids) {
                return [];
            }
            $con[] = ['id', 'in', $ids];
            //20220706:增加数据隔离
            if (self::mainModel()->hasField('company_id')) {
                $con[] = ['company_id', 'in', session(SESSION_COMPANY_ID)];
            }
            // $listsRaw = self::selectX($con);      
            if (method_exists(__CLASS__, 'staticConList')) {
                $listsRaw = self::staticConList($con);
            } else {
                $listsRaw = self::selectX($con);
            }
            // 20221104:增？？写入内存
            foreach ($listsRaw as &$dataItem) {
                self::getInstance($dataItem['id'])->setUuData($dataItem, true);  //强制写入
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
        }

        // 20220919:返回结果按原顺序输出
        $listsObj = Arrays2d::fieldSetKey($listsRaw, 'id');
        $listsA = [];
        foreach ($ids as &$id) {
            // 20230516：增加isset判断
            if (isset($listsObj[$id])) {
                $listsA[] = $listsObj[$id];
            }
        }
        // 20230528：添加框架的关联统计
        if ($withUniStatics) {
            $listsA = self::listAddUniStatics($listsA);
        }
        // 2022-12-14:【公共的配置式拼接统计数据】
        $lists = SystemColumnListForeignService::listAddStatics(self::getTable(), $listsA);
        //自定义方法：
        $listsNew = $lists ? ($func ? $func($lists) : $lists) : [];

        return $isMulti ? $listsNew : $listsNew[0];
    }

    /**
     * 20230528：列表添加框架的关联统计
     */
    protected static function listAddUniStatics($lists) {
        if (!$lists || !method_exists(__CLASS__, 'objAttrConfList')) {
            return $lists;
        }

        $ids = $lists ? array_column($lists, 'id') : [];
        //【1】批量查询属性列表
        $resList = self::objAttrConfListInList();
        foreach ($resList as $key => $val) {
            // 20230608:
            self::objAttrsListBatch($key, $ids);
        }
        //【2】批量查询统计数据【20230608】
        $resStatics = self::objAttrConfListInStatics();
        $statics = [];
        foreach ($resStatics as $k => $v) {
            // 20231026:增加判断 uniField
            if (!$v['inList'] || $v['uniField'] != 'id') {
                $uniField = Arrays::value($v, 'uniField') ?: 'id';
                $statics[$k] = $v['class']::groupBatchCount($v['keyField'], array_column($lists, $uniField));
            }
        }
        //【3】批量查询存在数据【20230608】
        // 注意：这个是纯数组的，跟上面的不一样，（上面的需要过渡优化成数组）
        $resExist = self::objAttrConfListInExist();
        // dump($resExist);
        $exists = [];
        foreach ($resExist as $v) {
            $uniField = Arrays::value($v, 'uniField') ?: 'id';
            $exists[$v['existField']] = $v['baseClass']::groupBatchCount($uniField, array_column($lists, $v['keyField']));
        }
        // Debug::dump('111');
        //【最终】拼接属性列表
        foreach ($lists as &$v) {
            // $key即objAttrs的key
            // 【统计子项数量】
            foreach ($resStatics as $key => $val) {
                $vKey = 'uni' . ucfirst($key) . 'Count';
                if ($val['inList'] && $val['uniField'] == 'id') {
                    $v[$vKey] = self::getInstance($v['id'])->objAttrsCount($key);
                } else {
                    // 20230608:
                    $staticsData = $statics[$key];
                    // 20230902:改为联动字段
                    $uniField = Arrays::value($val, 'uniField') ?: 'id';
                    $v[$vKey] = Arrays::value($staticsData, $v[$uniField]);
                }
            }
            // 【存在否】
            foreach ($resExist as &$vv) {
                $fieldName = $vv['existField'];
                $existStaticsArr = Arrays::value($exists, $fieldName, []);
                $value = Arrays::value($v, $vv['keyField'], '');
                $v[$fieldName] = Arrays::value($existStaticsArr, $value, 0);
            }
        }

        return $lists;
    }

    /**
     * 【弃】额外信息获取
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
    protected function commInfo() {
        //额外添加详情信息：固定为extraDetails方法
        if (method_exists(__CLASS__, 'extraDetails')) {
            $info = self::extraDetails($this->uuid);
        } else {
            $infoRaw = $this->get();
            // 2022-11-20???
            if (is_object($infoRaw)) {
                $info = $infoRaw ? $infoRaw->toArray() : [];
            } else {
                $info = $infoRaw ?: [];
            }
        }
        /** 20220514；增加动态枚举数据返回 ************ */
        $info = $this->pushDynDataList($info);

        return $info;
    }
    /**
     * 20240802
     * @param type $info
     * @return type
     */
    protected function pushDynDataList(&$info) {
        $columnId = SystemColumnService::tableNameGetId(self::getRawTable());
        $dynFields = SystemColumnListService::columnTypeFields($columnId, 'dynenum');
        // dump($dynFields);
        $dynDatas = [];
        foreach ($dynFields as $key) {
            $dynDatas[$key] = Arrays::value($info, $key);    // array_unique(array_column($info,$key));
        }
        // dump($info);
        // 固定dynDataList
        if ($info) {
            $info['dynDataList'] = SystemColumnListService::sDynDataList($columnId, $dynDatas);
        }

        return $info;
    }
    /**
     * 详情
     * @param type $cache
     * @return type
     */
    public function info() {
        //如果cache小于-1，表示外部没有传cache,取配置的cache值
        // $cache = $cache < 0 ? self::defaultCacheTime() : $cache;
        return $this->commInfo();
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

    public static function lastVal($field, $con = []) {
        return self::mainModel()->where($con)->order('id desc')->value($field);
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
     * 20220620：是否有前序数据（单条）
     * 前序订单，前序账单
     * @param type $fieldName
     */
    public function getPreData($fieldName) {
        $info = $this->get();
        $preId = Arrays::value($info, $fieldName);
        if (!$preId) {
            return false;
        }
        return self::getInstance($preId)->get();
    }

    /**
     * 20231019 
     * @return type
     */
    public static function getTimeField() {
        return property_exists(self::mainModel(), 'timeField') ? self::mainModel()::$timeField : '';
    }

    /**
     * 20240402:固定字段
     * @return type
     */
    public static function getFixedFields() {
        return property_exists(self::mainModel(), 'fixedField') ? self::mainModel()::$fixedField : [];
    }

    /**
     * 20240402:源头字段
     * 当源头字段发生变化时，应通知上下文进行更新动作 
     * @return type
     */
    public static function getSourceFields() {
        return property_exists(self::mainModel(), 'sourceField') ? self::mainModel()::$sourceField : [];
    }

    /**
     * 20220620 获取后续数据清单
     * 后序订单，后序账单……
     * 20220622 未入库的取不到……
     */
    public function getAfterDataArr($fieldName) {
        global $glSaveData;
        $tableName = self::mainModel()->getTable();

        $con[] = [$fieldName, '=', $this->uuid];
        //提取未入库数据
        $noSaveArrs = array_values(Arrays::value($glSaveData, $tableName, []));
        $idsNoSave = array_column(Arrays2d::listFilter($noSaveArrs, $con), 'id');
        //提取已入库数据
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        // 2022-11-20: 增加cache(1)缓存
        $idsSaved = self::mainModel()->where($con)->cache(1)->column('id');
        //合并未入库和已入库数据
        $ids = array_merge($idsNoSave, $idsSaved);
        $info = $this->get();
        if (Arrays::value($info, 'afterIds', [])) {
            $ids = array_merge($ids, $info['afterIds']);
        }
        $dataArr = [];
        foreach ($ids as $id) {
            $dataArr[$id] = self::getInstance($id)->get();
        }
        return $dataArr;
    }

    /*     * ********【20230531】注入触发器 ********************************** */

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
    public static function confArrTrigger() {
        $lists = property_exists(__CLASS__, 'trigger') ? self::$trigger : [];
        $resArr = [];
        foreach ($lists as $k => $v) {
            $tmp = $v;
            $tmp['class'] = __CLASS__;
            $tmp['property'] = $k;

            $resArr[] = $tmp;
        }
        return $resArr;
    }

    /**
     * 20230609:提取关联的删除数组
     */
    public static function uniExistFields() {
        if (!property_exists(self::mainModelClass(), 'uniFields')) {
            return [];
        }
        $fields = self::mainModel()::$uniFields;
        $existFields = [];
        foreach ($fields as $v) {
            // $existFields[] = DbOperate::fieldNameForExist($v['field']);
            // 20230726
            $existFields[] = Arrays::value($v, 'exist_field') ?: DbOperate::fieldNameForExist($v['field']);
        }
        return $existFields;
    }

    /**
     * 20231113:提取关联的映射字段
     */
    public static function uniReflectFields() {
        if (!property_exists(self::mainModelClass(), 'uniFields')) {
            return [];
        }
        $fields = self::mainModel()::$uniFields;
        $reflectFields = [];
        foreach ($fields as $v) {
            // 20231113:映射字段
            /*
              'reflect_field' => [
              // hasStatement 映射到表finance_statement_order的has_statement
              'hasStatement'  => 'has_statement',
              'hasSettle'     => 'has_settle'
              ],
             */
            $reflects = Arrays::value($v, 'reflect_field') ?: [];

            $reflectFields = array_merge($reflectFields, array_keys($reflects));
        }
        return $reflectFields;
    }

    /**
     * 20220711:用于跨系统迁移数据
     * @param type $sourceId
     * @return boolean
     */
    public static function sourceIdToId($sourceId) {
        if (!$sourceId && $sourceId !== 0) {
            return false;
        }
        $con[] = ['source_id', '=', $sourceId];
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con)->value('id');
    }

    /**
     * 20221116,从逗号分隔中查询数据
     */
    public static function sourceIdToIdSet($sourceId) {
        if (!$sourceId && $sourceId !== 0) {
            return false;
        }
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        return self::mainModel()->where($con)->whereRaw("FIND_IN_SET('" . $sourceId . "', source_id)")->value('id');
    }

    /**
     * 校验是否有来源数据。
     * @param type $sourceId
     * @return boolean
     */
    public static function hasSource($sourceId) {
        if (!$sourceId || !self::mainModel()->hasField('source_id')) {
            return false;
        }
        $con[] = ['source_id', '=', $sourceId];
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        return self::mainModel()->where($con)->count();
    }




    /*     * *
     * 一个列表，提取动态数据
     * @param array $arr        列表
     * @param type $arrField    列表的key
     * @param type $columnField 本表的key
     * @param type $keyField    本表关联，默认id
     */

    public static function arrDynenum(array $arr, $arrField, $columnField, $keyField = 'id') {
        $ids = Arrays2d::uniqueColumn($arr, $arrField);
        $con[] = [$keyField, 'in', $ids];
        return self::where($con)->column($columnField, $keyField);
    }

    /**
     * 数据导出逻辑：
     * 方法列表 + 写入模板key
     */

    /**
     * 导出数据到模板
     */
    public static function exportListToTpl($param) {
        DataCheck::must($param, ['listMethod', 'generateTplKey']);
        // 列表方法
        $listMethod = Arrays::value($param, 'listMethod');
        // excel模板key
        $templateKey = Arrays::value($param, 'generateTplKey');
        // 步骤1：提取列表数据
        $lists = self::$listMethod($param);
        // 步骤2：拼接到模板
        $templateId = GenerateTemplateService::keyToId($templateKey);

        if (!$templateId) {
            throw new Exception('模板不存在:' . $templateKey);
        }
        // 20231229
        foreach ($lists as $k => &$v) {
            $v['i'] = $k + 1;
        }
        // 步骤3：返回前台下载
        $resp = GenerateTemplateLogService::export($templateId, $lists);

        $res['url'] = $resp['file_path'];
        $res['fileName'] = date('YmdHis') . '.xlsx';

        return $res;
    }

    /**
     * 数据，公共取id，无时新增
     * 20231230
     */
    public static function commGetIdEG($data) {
        $id = self::commGetId($data);
        if (!$id) {
            $id = self::saveGetIdRam($data);
        }
        // Debug::dump(self::ramGlobalSaveData());
        return $id;
    }

    /**
     * 数据，公共取id
     * @createTime 2023-12-30 13:52:00
     * @param type $data
     * @return type
     */
    protected static function commGetId($data) {
        $con = [];
        foreach ($data as $k => $v) {
            $con[] = [$k, '=', $v];
        }
        $id = self::where($con)->cache(1)->value('id');
        if(!$id){
            // 20240601:增加ram获取
            $id = self::ramValue('id',$con);
        }
        return $id;
    }
    /**
     * 带条件global
     * @describe 使用listSetUUData替代
     */
    public static function listWithGlobal($con) {
        global $glSaveData, $glUpdateData, $glDeleteData;
        $lists = self::where($con)->select();
        $listsArr = $lists ? $lists->toArray() : [];
        // 有新增的数据，把新增的数据写入；
        // 有修改的数据，把修改的数据写入；
        // 有删除的数据，把删除的数据剔除
        foreach ($listsArr as $k => $v) {
            if (DbOperate::isGlobalDelete(self::getTable(), $v['id'])) {
                unset($listsArr[$k]);
            }
        }

        return array_values($listsArr);
    }
}
