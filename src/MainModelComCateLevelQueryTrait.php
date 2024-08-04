<?php

namespace xjryanse\traits;

use xjryanse\system\service\SystemCompanyService;
use xjryanse\logic\Arrays;
/**
 * 系统类型 + 版本复用类库
 * 20230805
 */
trait MainModelComCateLevelQueryTrait {
    
    /**
     * 返回记录id数组
     */
    public static function comCateLevelIds($con = []){
        $conAll    = self::comCateLevelCommCon($con);
        return  self::mainModel()->where($conAll)->column('id');
    }
    /**
     * 返回结果直接就是数组可用
     */
    public static function comCateLevelListArr($con = []){
        $conAll     = self::comCateLevelCommCon($con);
        $lists      =  self::mainModel()->where($conAll)->cache(1)->select();
        return $lists ? $lists->toArray() : [];
    }
    
    public static function comCateLevelFind($con = []){
        $conAll    = self::comCateLevelCommCon($con);
        $lists =  self::mainModel()->where($conAll)->cache(1)->find();
        return $lists ? $lists->toArray() : [];
    }
    /**
     * 端口分类和版本通用条件
     */
    protected static function comCateLevelCommCon($con = []){
        $compInfo   = SystemCompanyService::current();
        $cateArr    = explode(',',Arrays::value($compInfo, 'cate'));
        $level      = Arrays::value($compInfo, 'level');

        $con[]  = ['comp_cate','in',$cateArr];
        $con[]  = ['comp_level','in',$level];
        return $con;
    }

}
