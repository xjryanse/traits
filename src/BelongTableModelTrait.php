<?php
namespace xjryanse\traits;

/**
 * 有归属表的数据编号
 * 依赖StaticModelTrait;
 */
trait BelongTableModelTrait {
    // protected static $keyFieldName = ''
    // 20230425:key 转id
    public static function belongTableIdToId($belongTableId, $con = []){
        $con[] = ['belong_table_id','=',$belongTableId];
        return self::where($con)->value('id');
    }


}
