<?php

namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use Exception;
/**
 * 主模型统计复用
 * 依赖于MainModelTrait
 */
trait MainStaticsTrait {
    /**
     * 通用的，按时间分组聚合统计逻辑
     * 按年；按月； 按日
     */
    protected static function commStaticsTimeGroup($staticsBy='date',$con = [],$func = null, $orderBy = '') {
        $staticsArr['date']     = '%Y-%m-%d';
        $staticsArr['month']    = '%Y-%m';
        $staticsArr['year']     = '%Y';
        if(!in_array($staticsBy, array_keys($staticsArr))){
            throw new Exception('不支持的$staticsBy值-'.$staticsBy);
        }
        $groupField = Arrays::value($staticsArr, $staticsBy);
        $orderByStr = $orderBy ? "order by ".$orderBy : '';
        // 调用闭包查询
        /**
         * $con:        查询条件
         * $groupField  分组聚合字段
         * $$orderByStr 排序字段
         */
        $lists = $func ? $func($con, $groupField, $orderByStr) : [];

        return $lists;
    }
}
