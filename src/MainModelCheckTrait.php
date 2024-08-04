<?php

namespace xjryanse\traits;

use xjryanse\finance\service\FinanceTimeService;
use xjryanse\logic\DbOperate;
use xjryanse\logic\DataCheck;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\Arrays;
use Exception;
/**
 * 一些校验性的逻辑
 */
trait MainModelCheckTrait {
    public static $queryCount = 0;   //末个节点执行次数
    //20220803优化
    public static $queryCountArr = [];   //末个节点执行次数
    /**
     * 20220620：死循环调试专用
     * @useFul 1
     * @throws Exception
     */
    protected static function queryCountCheck($method, $limitTimes = 20000) {
        // self::$queryCount               = self::$queryCount + 1;
        self::$queryCountArr[$method] = Arrays::value(self::$queryCountArr, $method, 0) + 1;
        // 20220312;因为检票，从20调到200；TODO检票的更优方案呢？？
        if (self::$queryCountArr[$method] > $limitTimes) {
            throw new Exception(__CLASS__ . '中' . $method . '执行次数超限' . $limitTimes);
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
     * 校验系统账期
     * 写在这里方便不需要每个类都引入FinanceTimeService 包
     */
    public static function checkFinanceTimeLock($time) {
        FinanceTimeService::checkLock($time);
    }
    
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
     * 写入数据库，保存前校验
     * @param type $data
     * @throws Exception
     */
    public static function savePreCheck($data){
        // return true;
        // return true;
        $tableName  = self::mainModel()->getTable();
        $res        = DbOperate::columns($tableName);
        $fArr       = Arrays2d::fieldSetKey($res, 'Field');

        foreach($data as $k=>$v){
            $ar = Arrays::value($fArr, $k);
            if(!$ar){
                continue;
            }
            //【1】字符长度校验
            DataCheck::maxCharLength($v, $ar['charMaxLength'], $k.'长度超过限制'.$ar['charMaxLength']);
            //【2】校验是否数值类型
            if($ar['DATA_TYPE'] == 'int' && ($v && !is_numeric($v))){
                throw new Exception($k.'"'.$v.'"不是有效的数字');
            }
        }
    }
}
