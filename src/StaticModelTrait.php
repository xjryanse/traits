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
            $lists = self::mainModel()->where($con)->select();
            return $lists ? $lists->toArray() : [] ;
        });
    }
    /**
     * 条件查询(list)
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConList($con = [],$companyId = ''){
        $listsAll = self::staticListsAll($companyId);
        return Arrays2d::listFilter($listsAll, $con);
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
        return array_column($listFilter, $field);
    }
}
