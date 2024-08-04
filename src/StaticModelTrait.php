<?php
namespace xjryanse\traits;

use xjryanse\logic\Cachex;
use xjryanse\logic\Debug;
use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
use think\facade\Cache;
use xjryanse\logic\Runtime;
use think\Db;
/**
 * 静态模型复用
 * (配置式数据表)
 */
trait StaticModelTrait {
    // 20230619:增加静态变量，提升性能
    protected static $staticListsAll = [];
    /**
     * 20221109：清除缓存
     * @param type $companyId
     * @return type
     */
    public static function staticCacheClear($companyId = ''){
        $key            = self::staticBaseCacheKey($companyId);
        Cache::rm($key); 
        // 20230805：删除一些关联的缓存
        $keyName        = self::staticBaseCacheKey($companyId).'_KEYS';
        $keyNameArr     = Cache::get($keyName) ? : [];
        foreach($keyNameArr as &$v){
            Cache::rm($v); 
        }
        Cache::rm($keyName); 
    }
    /**
     * 写入key，方便删
     * @param type $key
     */
    private static function staticCacheKeysSet($companyId, $key){
        $keyName        = self::staticBaseCacheKey($companyId).'_KEYS';
        $keyNameArr     = Cache::get($keyName) ? : [];
        $keyNameArr[]   = $key;
        Cache::set($keyName, array_unique($keyNameArr));
    }
    
    /**
     * 
     * @useFul 1
     * @param type $companyId
     * @return type
     */
    public static function staticBaseCacheKey($companyId = ''){
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        $tableName  = self::mainModel()->getTable();
        return $tableName.'_staticListsAllDb'.$companyId;
    }
    
    /**
     * 仅从数据表查询出原始数据（不做字段触发器处理）
     * @useFul 1
     * @describe 解决触发器死循环，兼顾性能考虑
     * @param type $companyId
     * @return type
     */
    public static function staticListsAllDb($companyId = ''){
        // TODO 优化跨端取数据情况
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        // $tableName  = self::mainModel()->getTable();
        // $key        = $tableName.'_'.__METHOD__.$companyId;
        $key        = self::staticBaseCacheKey($companyId);
        $res = Cachex::funcGet( $key, function() use ($companyId){
            $con = [];
            if($companyId && self::mainModel()->hasField('company_id')){
                $con[] = ['company_id','=',$companyId];
            }
            // 2022-11-16: 增加文件缓存。
            $tableName  = self::mainModel()->getTable();
            $cacheFile = Runtime::tableFullCacheFileName($tableName);
            if(is_file($cacheFile)){
                $dataAll = Runtime::dataFromFile($cacheFile);
                // $dataAll = include $cacheFile;
                return Arrays2d::listFilter($dataAll, $con);
            } else {
                // return self::selectX($con);
                // 20230621:OSS图片路径是动态的，原方法有bug
                return self::selectDb($con);
            }
        });
        return $res;
    }
    
    /**
     * 全量加载数据
     * @useFul 1
     * @param type $companyId
     * @return type
     */
    public static function staticListsAll($companyId = ''){
        $res = self::staticListsAllDb($companyId);
        // 20231223
        if (method_exists(__CLASS__, 'comCateLevelCommCon')) {
            $comCateLevelCon = self::comCateLevelCommCon();
            $tableName = self::mainModel()->getTable();
            $arr = Db::table($tableName)->where($comCateLevelCon)->select();
            // $arr = self::comCateLevelListArr();
            $res = array_merge($arr,$res);
        }

        // 20230619:存静态变量
        self::$staticListsAll = self::dataDealAttr($res);
        return self::$staticListsAll ;
    }
    /**
     * 条件查询(list)
     * @useFul 1
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConList($con = [],$companyId = '',$sort="",$field=[]){
        $keyMd5     = md5(json_encode($con));
        $key        = self::staticBaseCacheKey($companyId).$sort.'_List'.$keyMd5;
        self::staticCacheKeysSet($companyId, $key);
        return Cachex::funcGet( $key, function() use ($con, $companyId, $sort, $field ){
            // 判断数据库量是否过大
            if(self::staticIsLarge($companyId)){
                // 20230905增加，若数据量过大，直接查数据库，再缓存
                if($companyId && self::mainModel()->hasField('company_id')){
                    $con[] = ['company_id','=',$companyId];
                }
                $res = self::selectDb($con, $sort);
            } else {
                // 原来的全量缓存
                $listsAll = self::staticListsAll($companyId);
                $res = Arrays2d::listFilter($listsAll, $con);
                if($sort){
                    $sotArr = explode(' ',$sort);
                    $res = Arrays2d::sort($res, $sotArr[0],Arrays::value($sotArr, 1));
                }
                if($field){
                    $res = Arrays2d::getByKeys($res, $field);
                }                
            }
            return $res;
            
        });
    }
    /**
     * 20231212:只取id，静态
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConIds($con = [],$companyId = ''){
        $arr = self::staticConList($con, $companyId);
        return Arrays2d::uniqueColumn($arr, 'id');
    }
    
    /**
     * 20220619:增加count
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConCount($con = [],$companyId = ''){
//        $listsAll = self::staticListsAll($companyId);
//        $res = Arrays2d::listFilter($listsAll, $con);
        // 20230801
        $res = self::staticConList($con, $companyId);
        return count($res);
    }
    /**
     * find()
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticConFind($con = [],$companyId = ''){
        $keyMd5     = md5(json_encode($con));
        $key        = self::staticBaseCacheKey($companyId).'_Find'.$keyMd5;
        self::staticCacheKeysSet($companyId, $key);        
        return Cachex::funcGet( $key, function() use ($con, $companyId){
            $listsAll = self::staticListsAll($companyId);
            if(!$listsAll){
                return [];
            }
            foreach($listsAll as $data){
                if(Arrays::isConMatch($data, $con)){
                    return $data;
                }
            }
            return [];
        });
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
//        $listsAll = self::staticListsAll($companyId);
//        // 过滤后的数组
//        $listFilter =  Arrays2d::listFilter($listsAll, $con);
        // 20230801
        $listFilter = self::staticConList($con, $companyId);        
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
        // 20230730：处理其他
        $result = array_fill_keys($keyIds, 0);
        $res    = array_count_values(array_column($lists, $key));
        // 20230730：处理其他
        return Arrays::concat($result, $res);
    }
    
    /**
     * 静态get方法
     */
    public function staticGet($companyId = ''){
        $con[] = ['id','=',$this->uuid];
        $info = self::staticConFind($con, $companyId);
        return $info;
    }
    
    /**
     * 20230905
     * 数据表全部记录数量
     * 当数据表记录数量过大时，性能
     * @useFul 1
     * @param type $con
     * @param type $companyId
     * @return type
     */
    public static function staticAllRecordCount($companyId = ''){
        if(!$companyId){
            $companyId = session(SESSION_COMPANY_ID);
        }
        $key        = self::staticBaseCacheKey($companyId).'_AllRecordCount';
        self::staticCacheKeysSet($companyId, $key);
        return Cachex::funcGet( $key, function(){
            return self::where()->count();
        });
    }
    /**
     * 数据库量是否过大
     * 以1000条为界限
     * 超过1000条的，查询直接查数据库
     * @useFul 1
     */
    public static function staticIsLarge($companyId){
        return self::staticAllRecordCount($companyId) > 1000;
    }
    
}
