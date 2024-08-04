<?php

namespace xjryanse\traits;

/**
 * 20230711
 * 日志表复用逻辑（存前后关联表的id）
 */
trait LogModelTrait {
    /*
     * 日志数据清理
     * @param type $mainTainDays    保留天数：默认7
     */

    public static function logClear($mainTainDays = 7) {
        $con[] = ['create_time', '<=', date('Y-m-d H:i:s', strtotime('-' . $mainTainDays . ' day'))];
        self::mainModel()->where($con)->delete();
    }
}
