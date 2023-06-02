<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;

/**
 * 模型联动字段复用
 */
trait ModelUniTrait {
    /**
     * 20230516:关联字段数据
        array(1) {
            [0] => array(6) {
              ["field"] => string(7) "user_id"
              ["uni_field"] => string(2) "id"
              ["del_check"] => bool(true)
              ["thisTable"] => string(7) "w_order"
              ["uniTable"] => string(6) "w_user"
            }
        }
     */
    public static function uniFieldsArr(){
        // if(!property_exists($class, $property))
        if (!property_exists(__CLASS__, 'uniFields')) {
            return [];
        }
        $prefix = config('database.prefix');
        $uniFields = self::$uniFields;
        foreach($uniFields as &$v){
            $v['thisTable'] = self::getTable();
            $v['uniTable'] = $prefix. Arrays::value($v, 'uni_name');
            // 20230516：联动字段默认用id
            $v['uni_field'] = Arrays::value($v, 'uni_field','id');
            // 20230516：删除限制默认否
            $v['del_check'] = Arrays::value($v, 'del_check',false);
            // 删除消息
            $v['del_msg']   = Arrays::value($v, 'del_msg') ? : '数据在'.$v['thisTable'].'表'.$v['field'].'字段使用，不可删';
            // 没有用的字段
            unset($v['uni_name']);
        }

        return $uniFields;
    }
}
