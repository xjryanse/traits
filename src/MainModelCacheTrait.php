<?php

namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Runtime;
use think\facade\Cache;

/**
 * 一些缓存的复用
 */
trait MainModelCacheTrait {
    /**
     * 缓存get数据的key值
     */
    protected function cacheGetKey() {
        $tableName = self::mainModel()->getRawTable();
        return 'mainModelGet_' . $tableName . '-' . $this->uuid;
    }

    /**
     * 数据统计的cache值
     * @return type
     */
    protected static function cacheCountKey() {
        $tableName = self::mainModel()->getRawTable();
        return 'mainModelCount_' . $tableName;
    }
    
    /**
     * 20230709:带缓存查询统计数据
     */
    public static function countCache($conAll) {
        $keyMd5 = md5(json_encode($conAll));
        $countArr = Cache::get(self::cacheCountKey()) ?: [];
        if (!isset($countArr[$keyMd5])) {
            $count = self::mainModel()->where($conAll)->count(1);
            $countArr[$keyMd5] = $count;
            Cache::set(self::cacheCountKey(), $countArr);
        }
        return Arrays::value($countArr, $keyMd5, 0);
    }

    /**
     * 20230709:统计数据清除
     */
    public static function countCacheClear() {
        $key = self::cacheCountKey();
        Cache::rm($key);
    }
    
    /**
     * 重新载入数据到缓存文件
     */
    public static function reloadCacheToFile() {
        $mainModel = self::mainModel();
        if (property_exists($mainModel, 'cacheToFile') && $mainModel::$cacheToFile) {
            $tableName = self::getTable();
            Runtime::tableCacheDel($tableName);
            // Runtime::tableFullCache($tableName);
        }
    }
    
    /*
     * 20230729:数据缓存清理
     * 一般用于增删改之后清理缓存数据
     */
    protected static function dataCacheClear(){
        /**
         * 2022-12-15:增加静态配置清缓存
         */
        if (method_exists(__CLASS__, 'staticCacheClear')) {
            self::staticCacheClear();
        }
        //20230729:全量缓存表重载
        self::reloadCacheToFile();
        // 20230709:清除统计数据缓存
        self::countCacheClear();
    }
}
