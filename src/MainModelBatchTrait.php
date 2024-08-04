<?php

namespace xjryanse\traits;

use xjryanse\logic\DbOperate;
/**
 * 主模型 批处理 复用
 */
trait MainModelBatchTrait {

    /**
     * 20231004 批删逻辑
     * @param type $ids
     */
    public static function deleteAllRam($ids) {
        self::queryCountCheck(__METHOD__);
        //删除前
        if (method_exists(__CLASS__, 'ramPreDeleteBatch')) {
            self::ramPreDeleteBatch($ids);      //注：id在preSaveData方法中生成
        }
        $con    = [];
        $con[]  = ['id','in',$ids];

        $rawDataObj = self::where($con)->select();
		$rawDataArr = $rawDataObj ? $rawDataObj->toArray() : [];
        // 20230912:谨慎测试
        $tableName = self::getTable();
        // 20231004:替换为批删方法
        DbOperate::checkCanDeleteBatch($tableName, $ids);
        foreach($rawDataArr as $rawData){
            // 删除
            self::getInstance($rawData['id'])->doDeleteRam();
        }
        // 20231004:更新属性
        self::delObjAttrsBatch($rawDataArr);

        //删除后
        if (method_exists(__CLASS__, 'ramAfterDeleteBatch')) {
            self::ramAfterDeleteBatch($rawDataArr);
        }
        //20230729
        self::dataCacheClear();

        return $ids;
    }
    /***
     * 批量移除属性
     */
    protected static function delObjAttrsBatch($rawDataArr){
        foreach($rawDataArr as $rawData){
            self::delObjAttrs($rawData, $rawData['id']);
        }
    }
}
