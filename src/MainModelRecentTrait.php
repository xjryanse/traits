<?php
namespace xjryanse\traits;

use Exception;
/**
 * 最近的逻辑
 * 20240521
 */
trait MainModelRecentTrait {
    /**
     * 用于维修查询最新的数据
     */
    public static function recentPaginate($con = [], $order = '', $perPage = 10, $having = '', $field = "*", $withSum = false) {

        return self::paginate($con , $order, $perPage, $having, $field, $withSum);
    }
    /**
     * 20240715:是否有最近的记录
     * @param type $data
     * @param type $second
     */
    public static function hasRecent($data, $second = 10){
        $con = [];
        foreach ($data as $k => $v) {
            $con[] = [$k, '=', $v];
        }
        $con[] = ['create_time','>=',date('Y-m-d H:i:s',time()-$second)];
        
        $count = self::where($con)->count();
        return $count;
    }
    
}
