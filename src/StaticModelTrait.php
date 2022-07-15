<?php
namespace xjryanse\traits;

use xjryanse\logic\Cachex;
use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
/**
 * 静态模型复用
 * (配置式数据表)
 */
trait StaticModelTrait {
    // 全量加载数据
    public static function staticListsAll($companyId = ''){
        // TODO 优化跨端取数据情况
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        $tableName  = self::mainModel()->getTable();
        $key        = $tableName.'_'.__METHOD__.$companyId;
        return Cachex::funcGet( $key, function() use ($companyId){
            $con = [];
            if($companyId && self::mainModel()->hasField('company_id')){
                $con[] = ['company_id','=',$companyId];
            }
//            $lists = self::mainModel()->where($con)->select();
//            return $lists ? $lists->toArray() : [] ;
            return self::selectX($con);
        });
    }
    /**
     * 条件查询(list)
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConList($con = [],$companyId = '',$sort="",$field=[]){
        $listsAll = self::staticListsAll($companyId);
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
    public static function staticConCount($con = [],$companyId = ''){
        $listsAll = self::staticListsAll($companyId);
        $res = Arrays2d::listFilter($listsAll, $con);
        return count($res);
    }
    /**
     * find()
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConFind($con = [],$companyId = ''){
        $listsAll = self::staticListsAll($companyId);
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
    public static function staticConColumn($field, $con = [],$companyId = ''){
        $listsAll = self::staticListsAll($companyId);
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
    public function staticGet($companyId = ''){
        $con[] = ['id','=',$this->uuid];
        $info = self::staticConFind($con, $companyId);
        return $info;
    }
}
