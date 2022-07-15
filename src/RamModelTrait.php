<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
/**
 * 基于内存的数据查询处理
 * 20220702
 * (配置式数据表)
 */
trait RamModelTrait {
    // 全量数据清单
    public static $ramList = [];
    // 全量加载数据
    public static function ramListsAll($companyId = ''){
        // TODO 优化跨端取数据情况
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        if(!self::$ramList){
            $con = [];
            if($companyId && self::mainModel()->hasField('company_id')){
                $con[] = ['company_id','=',$companyId];
            }
            self::$ramList =  self::selectX($con);
        }
        return self::$ramList;
    }
    /**
     * 条件查询(list)
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function ramConList($con = [],$companyId = '',$sort="",$field=[]){
        $listsAll = self::ramListsAll($companyId);
        $res = Arrays2d::listFilter($listsAll, $con);
        if($sort){
            $res = Arrays2d::sort($res, 'sort');
        }
        if($field){
            $res = Arrays2d::getByKeys($res, $field);
        }
        return $res;
    }
    /**
     * 20220619:增加count
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function ramConCount($con = [],$companyId = ''){
        $listsAll = self::ramListsAll($companyId);
        $res = Arrays2d::listFilter($listsAll, $con);
        return count($res);
    }
    /**
     * find()
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function ramConFind($con = [],$companyId = ''){
        $listsAll = self::ramListsAll($companyId);
        foreach($listsAll as $data){
            if(Arrays::isConMatch($data, $con)){
                return $data;
            }
        }
        return [];
    }
    /**
     * 取单一列
     * @param type $con
     * @param type $companyId
     */
    public static function ramConColumn($field, $con = [],$companyId = ''){
        $listsAll = self::ramListsAll($companyId);
        // 过滤后的数组
        $listFilter =  Arrays2d::listFilter($listsAll, $con);
        $fields = explode(',', $field);
        if(count($fields) > 1){
            //多个
            return Arrays2d::getByKeys($listFilter, $fields);
        } else {
            //单个
            return array_column($listFilter, $field);
        }
    }
    
    /**
     * 静态get方法
     */
    public function ramGet($companyId = ''){
        $con[] = ['id','=',$this->uuid];
        $info = self::ramConFind($con, $companyId);
        return $info;
    }
}
