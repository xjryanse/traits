<?php
namespace xjryanse\traits;

use xjryanse\logic\Cachex;
use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
use think\facade\Cache;
use xjryanse\logic\Runtime;
/**
 * 静态模型复用
 * (配置式数据表)
 */
trait StaticModelTrait {
    /**
     * 20221109：清除缓存
     * @param type $companyId
     * @return type
     */
    public static function staticCacheClear($companyId = ''){
        $key        = self::staticBaseCacheKey($companyId);
        Cache::rm($key); 
    }
    
    public static function staticBaseCacheKey($companyId = ''){
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        $tableName  = self::mainModel()->getTable();
        return $tableName.'_staticListsAll'.$companyId;
    }
    
    // 全量加载数据
    public static function staticListsAll($companyId = ''){
        // TODO 优化跨端取数据情况
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        // $tableName  = self::mainModel()->getTable();
        // $key        = $tableName.'_'.__METHOD__.$companyId;
        $key        = self::staticBaseCacheKey($companyId);
        return Cachex::funcGet( $key, function() use ($companyId){
            $con = [];
            if($companyId && self::mainModel()->hasField('company_id')){
                $con[] = ['company_id','=',$companyId];
            }
            // 2022-11-16: 增加文件缓存。
            $tableName  = self::mainModel()->getTable();
            $cacheFile = Runtime::tableFullCacheFileName($tableName);
            if(is_file($cacheFile)){
                $dataAll = include $cacheFile;
                return Arrays2d::listFilter($dataAll, $con);
            } else {
                return self::selectX($con);
            }
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
        // Debug::debug(__METHOD__.'$listsAll', $listsAll);
        $res = Arrays2d::listFilter($listsAll, $con);
        // Debug::debug(__METHOD__.'$res', $res);
        if($sort){
            $sotArr = explode(' ',$sort);
            $res = Arrays2d::sort($res, $sotArr[0],Arrays::value($sotArr, 1));
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
        if($listsAll){
            foreach($listsAll as $data){
                if(Arrays::isConMatch($data, $con)){
                    return $data;
                }
            }
        }
        return [];
    }
    /**
     * 20221020带键过滤
     * @param type $con
     * @param type $keys
     * @param type $companyId
     * @return type
     */
    public static function staticConFindKeys($con = [], $keys = [], $companyId = ''){
        $info = self::staticConFind($con, $companyId);
        if($info && is_object($info)){
            $info = $info->toArray();
        }
        return $info ? Arrays::getByKeys($info, $keys) : [];
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
     * 20230429：静态的分组统计
     */
    public static function staticGroupBatchCount($key, $keyIds, $con = []){
        $con[] = [$key, 'in', $keyIds];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        $lists = self::staticConList($con);
        return array_count_values(array_column($lists, $key));
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
