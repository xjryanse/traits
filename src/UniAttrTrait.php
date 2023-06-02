<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\Debug;
use Exception;
/**
 * 对象属性复用
 * (配置式数据表)
 */
trait UniAttrTrait {
    
    /**
     * 20230518：提取配置数组
     */
    public static function uniAttrConfArr(){
        $lists  = property_exists(__CLASS__, 'uniAttrConf') ? self::$uniAttrConf : [];
        $resArr = [];
        foreach($lists as $k=>$v){
            $tmp                = $v;
            $tmp['class']       = __CLASS__;
            $tmp['property']    = $k;
            $tmp['master']      = true;
            
            $resArr[]           = $tmp;
        }
        return $resArr;
    }
}
