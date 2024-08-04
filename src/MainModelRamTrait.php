<?php

namespace xjryanse\traits;

use xjryanse\logic\Debug;
use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;

/**
 * 提供一个集中的方法，管理内存中的待提交数据
 */
trait MainModelRamTrait {
    /**
     * 20240601:全局保存data
     */
    protected static function ramList(){
        global $glSaveData;
        $tableName = self::getRawTable();
        $arr = $glSaveData && $tableName 
                ? Arrays::value($glSaveData, $tableName, []) 
                : [];
        return array_values($arr);
    }
    
    /**
     * 
     */
    public static function ramFind($con) {
//        global $glSaveData;
//        $tableName = self::getRawTable();
//        $arrObj = Arrays::value($glSaveData, $tableName) ?: [];
//        $arr = array_values($arrObj);
        
        $arr = self::ramList();
        return Arrays2d::listFind($arr, $con);
    }
    
    /**
     * 从列表中，提取一个值，例如id
     * 20240601
     * @param type $field
     */
    protected static function ramValue($field, $con = []){
        $info = self::ramFind($con);
        return Arrays::value($info, $field);
    }
    
}
