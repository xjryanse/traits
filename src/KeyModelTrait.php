<?php
namespace xjryanse\traits;

/**
 * 带key的配置复用
 * 依赖StaticModelTrait;
 */
trait KeyModelTrait {
    // protected static $keyFieldName = ''
    // 20230425:key 转id
    public static function keyToId($key){
        $con[] = [self::$keyFieldName,'=',$key];
        $info = self::staticConFind($con);
        return $info ? $info['id'] : '';
    }

}
